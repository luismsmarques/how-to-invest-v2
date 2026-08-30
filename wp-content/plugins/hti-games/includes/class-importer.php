<?php
/**
 * Turning a file of real candles into draft scenarios.
 *
 * The whole judgement of the importer is pure: parse(), validate(), slice(),
 * screen(), atr() and checksum() take strings and arrays and return arrays,
 * with no WordPress and no database anywhere near them. That is what makes
 * "does this file contain a usable chart?" a question a test can ask a
 * thousand times in a second, and it is why the WordPress half at the bottom
 * of this file is thin enough to read in one sitting.
 *
 * Two of the rules deserve their reason stated:
 *
 *  - A window whose ATR is zero is a flat line — a market holiday, a padded
 *    series, a broken feed. It cannot be traded, so a player asked to call it
 *    is being asked to guess, and the game is not a guessing game.
 *  - A window whose ATR is more than ten times the median of the file is a
 *    gap, a split, a redenomination or a bad tick. On a chart it looks like a
 *    signal, which is exactly the problem: it teaches the player to read an
 *    artefact of the data as a market event.
 *
 * Everything the importer creates is a DRAFT. A human publishes. The pool the
 * game serves is published posts only, so nothing here can put a chart in
 * front of a player without somebody having looked at it.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * CSV/JSON candle import: pure validation, then draft scenarios.
 */
class Importer {

	/**
	 * A scenario is the visible candles plus the outcome candles.
	 */
	public const WINDOW = Config::STC_VISIBLE + Config::STC_OUTCOME;

	/**
	 * Default gap between two consecutive windows, in candles.
	 *
	 * Smaller than the window on purpose: overlapping cuts get more scenarios
	 * out of one file, and two windows sharing 80 candles still pose different
	 * questions because the decision point differs.
	 */
	public const STRIDE = 40;

	/**
	 * A window whose ATR exceeds this multiple of the file's median is junk.
	 */
	public const ATR_MAX_RATIO = 10;

	/**
	 * Scales we accept. Ticks are price × scale, and the scale has to be
	 * declared: the same file of "1.0912" values means one thing at 100000 and
	 * something 100× different at 1000, and nothing in the file says which.
	 */
	public const SCALES = array( 1, 10, 100, 1000, 10000, 100000, 1000000 );

	/**
	 * Stop collecting errors past this many — a broken file produces one per
	 * row, and 40 000 error strings help nobody.
	 */
	private const MAX_ERRORS = 25;

	/**
	 * Largest upload we will read, in bytes.
	 */
	private const MAX_UPLOAD = 8388608;

	/**
	 * Where the import report waits for the next page load.
	 */
	private const NOTICE_PREFIX = 'hti_games_import_';

	/**
	 * Hook the admin screen and its handler.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_games_import', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure: parsing and validation.
	 * ------------------------------------------------------------------- */

	/**
	 * Read a candle file into rows.
	 *
	 * Expects `timestamp,open,high,low,close`, as CSV (with or without a
	 * header row) or as JSON — either a list of five-element lists or a list
	 * of objects with those keys.
	 *
	 * Prices in the file are human prices; the rows that come back are integer
	 * ticks (price × $scale), because no float ever reaches the decision path.
	 * The multiplication happens exactly once, here, at import time.
	 *
	 * @param string $raw    File contents.
	 * @param string $format 'csv' or 'json'.
	 * @param int    $scale  Declared tick scale.
	 * @return array{rows:array<int,array{ts:int,o:int,h:int,l:int,c:int}>,errors:array<int,string>}
	 */
	public static function parse( string $raw, string $format, int $scale = Config::TICK_SCALE ): array {
		$errors = array();
		$rows   = array();

		$records = 'json' === strtolower( $format )
			? self::records_from_json( $raw, $errors )
			: self::records_from_csv( $raw );

		foreach ( $records as $line => $record ) {
			if ( count( $errors ) >= self::MAX_ERRORS ) {
				$errors[] = 'too many errors; stopped reading';
				break;
			}

			$row = self::row( $record, $scale, (int) $line + 1, $errors );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return array(
			'rows'   => $rows,
			'errors' => array_merge( $errors, self::validate( $rows, $scale ) ),
		);
	}

	/**
	 * Series-level validation: length, ordering, duplicates, declared scale.
	 *
	 * @param array<int,array{ts:int,o:int,h:int,l:int,c:int}> $rows  Parsed rows.
	 * @param int                                              $scale Declared scale.
	 * @return array<int,string>
	 */
	public static function validate( array $rows, int $scale ): array {
		$errors = array();

		if ( ! in_array( $scale, self::SCALES, true ) ) {
			$errors[] = 'no usable scale was declared (' . $scale . ')';
		}

		$count = count( $rows );
		if ( $count < self::WINDOW ) {
			$errors[] = 'a scenario needs ' . self::WINDOW . ' candles; the file has ' . $count;
			return $errors;
		}

		$previous = null;
		foreach ( $rows as $i => $row ) {
			if ( null !== $previous ) {
				if ( $row['ts'] === $previous ) {
					$errors[] = 'duplicate timestamp at row ' . ( (int) $i + 1 );
				} elseif ( $row['ts'] < $previous ) {
					$errors[] = 'timestamps go backwards at row ' . ( (int) $i + 1 );
				}
			}
			if ( count( $errors ) >= self::MAX_ERRORS ) {
				break;
			}
			$previous = $row['ts'];
		}

		return $errors;
	}

	/**
	 * Cut a long series into windows.
	 *
	 * Pure, and deliberately dumb: it cuts, it measures, it fingerprints. What
	 * is worth keeping is screen()'s decision.
	 *
	 * @param array<int,array{ts:int,o:int,h:int,l:int,c:int}> $rows   Parsed rows.
	 * @param int                                              $window Candles per window.
	 * @param int                                              $stride Candles between windows.
	 * @return array<int,array{start:int,rows:array<int,array{ts:int,o:int,h:int,l:int,c:int}>,atr:int,checksum:string}>
	 */
	public static function slice( array $rows, int $window, int $stride ): array {
		$rows   = array_values( $rows );
		$count  = count( $rows );
		$window = max( 1, $window );
		$stride = max( 1, $stride );

		if ( $count < $window ) {
			return array();
		}

		$out = array();
		for ( $start = 0; $start + $window <= $count; $start += $stride ) {
			$slice = array_slice( $rows, $start, $window );
			$out[] = array(
				'start'    => $start,
				'rows'     => $slice,
				// The average true range of the WHOLE window, not the
				// 14-period ATR the engine sizes a stop with. Screening asks
				// "is anything in these 120 candles an artefact?", and a
				// trailing 14-candle reading is blind to a split 100 bars
				// back — which is exactly where a split tends to be.
				'atr'      => self::atr( $slice, count( $slice ) ),
				'checksum' => self::checksum( $slice ),
			);
		}

		return $out;
	}

	/**
	 * Keep the tradeable windows, drop the flat ones and the artefacts.
	 *
	 * @param array<int,array{start:int,rows:array,atr:int,checksum:string}> $windows From slice().
	 * @return array{keep:array<int,array>,dropped:array<int,array{start:int,reason:string}>}
	 */
	public static function screen( array $windows ): array {
		if ( array() === $windows ) {
			return array(
				'keep'    => array(),
				'dropped' => array(),
			);
		}

		// The median rather than the mean: one 50× gap in a file would drag a
		// mean up far enough to let itself through.
		$median = self::median( array_column( $windows, 'atr' ) );
		$ceil   = $median * self::ATR_MAX_RATIO;

		$keep    = array();
		$dropped = array();

		foreach ( $windows as $window ) {
			$atr = (int) $window['atr'];
			if ( $atr <= 0 ) {
				$dropped[] = array(
					'start'  => (int) $window['start'],
					'reason' => 'flat: ATR is zero',
				);
				continue;
			}
			if ( $median > 0 && $atr > $ceil ) {
				$dropped[] = array(
					'start'  => (int) $window['start'],
					'reason' => 'ATR ' . $atr . ' is more than ' . self::ATR_MAX_RATIO . '× the file median (' . $median . ')',
				);
				continue;
			}
			$keep[] = $window;
		}

		return array(
			'keep'    => $keep,
			'dropped' => $dropped,
		);
	}

	/**
	 * Average true range, in ticks, over the last $period candles.
	 *
	 * Pass the length of the series to average the whole thing, which is what
	 * screening wants; pass Config::STC_ATR_PERIOD for the trailing reading a
	 * stop is sized from.
	 *
	 * Integer arithmetic throughout, like everything else in the decision
	 * path: intdiv, not a float division that PHP and JavaScript would round
	 * differently on the same input.
	 *
	 * @param array<int,array{ts:int,o:int,h:int,l:int,c:int}> $rows   Candles.
	 * @param int                                              $period Lookback.
	 */
	public static function atr( array $rows, int $period ): int {
		$rows  = array_values( $rows );
		$count = count( $rows );
		if ( $count < 2 || $period < 1 ) {
			return 0;
		}

		$ranges = array();
		for ( $i = 1; $i < $count; $i++ ) {
			$high  = $rows[ $i ]['h'];
			$low   = $rows[ $i ]['l'];
			$close = $rows[ $i - 1 ]['c'];
			// True range: the high-low bar, extended to swallow any gap from
			// the previous close.
			$ranges[] = max( $high - $low, abs( $high - $close ), abs( $low - $close ) );
		}

		$window = array_slice( $ranges, -$period );

		return intdiv( (int) array_sum( $window ), max( 1, count( $window ) ) );
	}

	/**
	 * The median of a list of integers.
	 *
	 * @param array<int,int> $values Values.
	 */
	public static function median( array $values ): int {
		$values = array_values( array_map( 'intval', $values ) );
		$count  = count( $values );
		if ( 0 === $count ) {
			return 0;
		}
		sort( $values );
		$mid = intdiv( $count, 2 );

		return 0 === $count % 2
			? intdiv( $values[ $mid - 1 ] + $values[ $mid ], 2 )
			: $values[ $mid ];
	}

	/**
	 * A fingerprint of a window's candles.
	 *
	 * Re-importing the same file must not create a second copy of the same
	 * chart, and "same chart" cannot mean "same filename" — the same series
	 * arrives twice under two names all the time. It means the same numbers.
	 *
	 * @param array<int,array{ts:int,o:int,h:int,l:int,c:int}> $rows Candles.
	 */
	public static function checksum( array $rows ): string {
		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = $row['ts'] . ':' . $row['o'] . ',' . $row['h'] . ',' . $row['l'] . ',' . $row['c'];
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * A window's candles as the integer OHLC quads the meta key stores.
	 *
	 * @param array<int,array{ts:int,o:int,h:int,l:int,c:int}> $rows Candles.
	 * @return array<int,array<int,int>>
	 */
	public static function quads( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array( (int) $row['o'], (int) $row['h'], (int) $row['l'], (int) $row['c'] );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Pure: the readers behind parse().
	 * ------------------------------------------------------------------- */

	/**
	 * Split CSV into records, skipping a header row and blank lines.
	 *
	 * @param string $raw File contents.
	 * @return array<int,array<int,string>>
	 */
	private static function records_from_csv( string $raw ): array {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$fields = array_map( 'trim', explode( ',', $line ) );
			// A header is any first field that is not a number and not a date:
			// cheaper and more forgiving than matching column names.
			if ( array() === $out && ! is_numeric( $fields[0] ) && false === strtotime( $fields[0] ) ) {
				continue;
			}
			$out[] = $fields;
		}

		return $out;
	}

	/**
	 * Read JSON into records.
	 *
	 * @param string             $raw    File contents.
	 * @param array<int,string>  $errors Collected errors, by reference.
	 * @return array<int,array<int,string>>
	 */
	private static function records_from_json( string $raw, array &$errors ): array {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$errors[] = 'the file is not valid JSON';
			return array();
		}

		$out = array();
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['open'] ) ) {
				$out[] = array(
					(string) ( $entry['timestamp'] ?? $entry['time'] ?? '' ),
					(string) $entry['open'],
					(string) ( $entry['high'] ?? '' ),
					(string) ( $entry['low'] ?? '' ),
					(string) ( $entry['close'] ?? '' ),
				);
				continue;
			}
			$out[] = array_map( 'strval', array_values( $entry ) );
		}

		return $out;
	}

	/**
	 * One record into one validated row of integer ticks.
	 *
	 * @param array<int,string> $record Raw fields.
	 * @param int               $scale  Declared scale.
	 * @param int               $line   1-based record number, for the message.
	 * @param array<int,string> $errors Collected errors, by reference.
	 * @return array{ts:int,o:int,h:int,l:int,c:int}|null
	 */
	private static function row( array $record, int $scale, int $line, array &$errors ): ?array {
		if ( count( $record ) < 5 ) {
			$errors[] = 'row ' . $line . ' has fewer than five fields';
			return null;
		}

		$ts = is_numeric( $record[0] ) ? (int) $record[0] : (int) strtotime( (string) $record[0] . ' UTC' );
		if ( $ts <= 0 ) {
			$errors[] = 'row ' . $line . ' has an unreadable timestamp';
			return null;
		}

		$prices = array();
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			if ( ! is_numeric( $record[ $i ] ) ) {
				$errors[] = 'row ' . $line . ' has a non-numeric price';
				return null;
			}
			// The only float in the whole plugin, and it dies on this line.
			$prices[] = (int) round( (float) $record[ $i ] * max( 1, $scale ) );
		}

		list( $open, $high, $low, $close ) = $prices;

		if ( $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0 ) {
			$errors[] = 'row ' . $line . ' has a non-positive price';
			return null;
		}
		if ( $low > min( $open, $close ) ) {
			$errors[] = 'row ' . $line . ': low is above the open or the close';
			return null;
		}
		if ( $high < max( $open, $close ) ) {
			$errors[] = 'row ' . $line . ': high is below the open or the close';
			return null;
		}
		if ( $high < $low ) {
			$errors[] = 'row ' . $line . ': high is below the low';
			return null;
		}

		return array(
			'ts' => $ts,
			'o'  => $open,
			'h'  => $high,
			'l'  => $low,
			'c'  => $close,
		);
	}

	/* ---------------------------------------------------------------------
	 * The WordPress half.
	 * ------------------------------------------------------------------- */

	/**
	 * The import screen, under the scenarios menu where it is used.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Config::CPT_SCENARIO,
			__( 'Import candles', 'hti-games' ),
			__( 'Import candles', 'hti-games' ),
			'manage_options',
			'hti-games-import',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The upload form.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import candles', 'hti-games' ); ?></h1>
			<p><?php esc_html_e( 'A CSV or JSON file of timestamp,open,high,low,close. It is cut into 120-candle windows; flat windows and windows whose range is wildly out of line with the rest of the file are dropped. Everything created is a draft — review it, then publish.', 'hti-games' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hti_games_import' ); ?>
				<input type="hidden" name="action" value="hti_games_import" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti_games_file"><?php esc_html_e( 'File', 'hti-games' ); ?></label></th>
						<td><input type="file" id="hti_games_file" name="hti_games_file" accept=".csv,.json,text/csv,application/json" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti_games_format"><?php esc_html_e( 'Format', 'hti-games' ); ?></label></th>
						<td>
							<select id="hti_games_format" name="hti_games_format">
								<option value="csv">CSV</option>
								<option value="json">JSON</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti_games_scale"><?php esc_html_e( 'Scale', 'hti-games' ); ?></label></th>
						<td>
							<select id="hti_games_scale" name="hti_games_scale">
								<?php foreach ( self::SCALES as $scale ) : ?>
									<option value="<?php echo esc_attr( (string) $scale ); ?>" <?php selected( Config::TICK_SCALE, $scale ); ?>>×<?php echo esc_html( (string) $scale ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Prices are stored as integers: price × scale. 1.09120 at ×100000 is 109120.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti_games_symbol"><?php esc_html_e( 'Symbol', 'hti-games' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="hti_games_symbol" name="hti_games_symbol" />
							<p class="description"><?php esc_html_e( 'Admin-only provenance. Never shown to a player.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti_games_stride"><?php esc_html_e( 'Stride', 'hti-games' ); ?></label></th>
						<td><input type="number" id="hti_games_stride" name="hti_games_stride" value="<?php echo esc_attr( (string) self::STRIDE ); ?>" min="1" max="1000" /></td>
					</tr>
				</table>

				<?php submit_button( __( 'Import as drafts', 'hti-games' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the upload: parse, screen, create drafts.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_games_import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-games' ) );
		}

		$back   = admin_url( 'edit.php?post_type=' . Config::CPT_SCENARIO . '&page=hti-games-import' );
		$upload = isset( $_FILES['hti_games_file'] ) ? array_map( 'strval', wp_unslash( (array) $_FILES['hti_games_file'] ) ) : array();

		if ( empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) || 0 !== (int) ( $upload['error'] ?? 1 ) ) {
			self::report( array( 'errors' => array( 'the upload did not arrive' ) ) );
			wp_safe_redirect( $back );
			exit;
		}
		// Measured on disk as well as from the multipart part: $_FILES['size']
		// is PHP's own count of the bytes it wrote, but the value that decides
		// how much is about to be read into memory should be the one taken from
		// the file that is about to be read.
		$bytes = max( (int) ( $upload['size'] ?? 0 ), (int) filesize( $upload['tmp_name'] ) );
		if ( $bytes > self::MAX_UPLOAD ) {
			self::report( array( 'errors' => array( 'the file is larger than ' . self::MAX_UPLOAD . ' bytes' ) ) );
			wp_safe_redirect( $back );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file; WP_Filesystem is for the site's own files and would need credentials here.
		$raw = (string) file_get_contents( $upload['tmp_name'] );

		$format = 'json' === sanitize_key( wp_unslash( $_POST['hti_games_format'] ?? '' ) ) ? 'json' : 'csv';
		$scale  = (int) ( wp_unslash( $_POST['hti_games_scale'] ?? Config::TICK_SCALE ) );
		$scale  = in_array( $scale, self::SCALES, true ) ? $scale : Config::TICK_SCALE;
		$stride = max( 1, min( 1000, (int) ( wp_unslash( $_POST['hti_games_stride'] ?? self::STRIDE ) ) ) );
		$symbol = sanitize_text_field( wp_unslash( $_POST['hti_games_symbol'] ?? '' ) );
		$name   = sanitize_file_name( (string) ( $upload['name'] ?? 'upload' ) );

		$parsed = self::parse( $raw, $format, $scale );
		if ( array() !== $parsed['errors'] ) {
			self::report( array( 'errors' => $parsed['errors'] ) );
			wp_safe_redirect( $back );
			exit;
		}

		$screened = self::screen( self::slice( $parsed['rows'], self::WINDOW, $stride ) );
		$source   = 'import:' . $name . '@' . gmdate( 'Y-m-d' );

		$created = 0;
		$skipped = 0;
		foreach ( $screened['keep'] as $window ) {
			if ( self::exists( (string) $window['checksum'] ) ) {
				// The same candles are already here under some title: this is
				// a re-import, and a re-import is a no-op by design.
				++$skipped;
				continue;
			}
			if ( self::create( $window, $scale, $symbol, $source ) ) {
				++$created;
			}
		}

		if ( $created > 0 ) {
			Library::flush( Config::GAME_STC );
		}

		self::report(
			array(
				'created' => $created,
				'skipped' => $skipped,
				'dropped' => array_slice( $screened['dropped'], 0, 10 ),
				'errors'  => array(),
			)
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Whether a window with these exact candles is already stored.
	 *
	 * Any status, not just published: a chart an editor deliberately left in
	 * the drafts is still a chart we have, and re-importing must not smuggle
	 * a second copy of it past that decision.
	 *
	 * @param string $checksum Window fingerprint.
	 */
	private static function exists( string $checksum ): bool {
		$found = get_posts(
			array(
				'post_type'        => Config::CPT_SCENARIO,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => 'hti_stc_checksum', // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaKey -- an exact-match lookup on an indexed meta key, once per candidate window.
				'meta_value'       => $checksum, // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaValue -- as above.
			)
		);

		return array() !== $found;
	}

	/**
	 * Create one draft scenario from a screened window.
	 *
	 * @param array{start:int,rows:array,atr:int,checksum:string} $window Window.
	 * @param int                                                 $scale  Declared scale.
	 * @param string                                              $symbol Admin-only symbol.
	 * @param string                                              $source Source string.
	 */
	private static function create( array $window, int $scale, string $symbol, string $source ): bool {
		$rows  = $window['rows'];
		$first = $rows[0]['ts'] ?? 0;

		$post_id = wp_insert_post(
			array(
				'post_type'   => Config::CPT_SCENARIO,
				// Draft, always. Publishing is a human act.
				'post_status' => 'draft',
				'post_title'  => trim( ( '' !== $symbol ? $symbol . ' ' : '' ) . gmdate( 'Y-m-d', (int) $first ) . ' #' . substr( (string) $window['checksum'], 0, 6 ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return false;
		}

		$meta = array(
			'hti_stc_ticks'    => (string) wp_json_encode( self::quads( $rows ) ),
			'hti_stc_scale'    => $scale,
			'hti_stc_visible'  => Config::STC_VISIBLE,
			'hti_stc_outcome'  => Config::STC_OUTCOME,
			// Imported candles are real by definition; the generator is the
			// only thing that writes a 0 here.
			'hti_stc_real'     => '1',
			'hti_stc_source'   => $source,
			'hti_stc_checksum' => (string) $window['checksum'],
			'hti_stc_symbol'   => $symbol,
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $post_id, $key, $value );
		}

		return true;
	}

	/**
	 * Stash the import report for the next page load.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	private static function report( array $report ): void {
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $report, 120 );
	}

	/**
	 * Show the import report, once.
	 */
	public static function notice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );

		$errors = (array) ( $report['errors'] ?? array() );
		if ( array() !== $errors ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Nothing was imported.', 'hti-games' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( array_slice( $errors, 0, 25 ) as $error ) {
				printf( '<li>%s</li>', esc_html( (string) $error ) );
			}
			echo '</ul></div>';
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: drafts created, 2: duplicates skipped, 3: windows dropped. */
					__( 'Imported %1$d scenarios as drafts. %2$d were already here, %3$d windows were dropped as unusable.', 'hti-games' ),
					(int) ( $report['created'] ?? 0 ),
					(int) ( $report['skipped'] ?? 0 ),
					count( (array) ( $report['dropped'] ?? array() ) )
				)
			)
		);
	}
}
