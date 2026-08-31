<?php
/**
 * The two content types the games serve, and every meta key they carry.
 *
 * Both types are private, and `'show_in_rest' => false` on the type and on
 * every one of its meta keys is an ANTI-CHEAT CONTROL, not a style choice.
 * A scenario post holds the 40 outcome candles the player is deciding about;
 * a case post holds the company name, the year and the five-year return the
 * player is meant to guess. Turning REST on would publish a second surface —
 * `/wp-json/wp/v2/hti_stc_scenario` — from which any of that could be read
 * before a decision is recorded, and no amount of care in our own endpoints
 * would close it. There is exactly one way to reach this content from the
 * outside, and it is the games' own REST layer, which withholds the outcome
 * until the run row exists.
 *
 * `hti_stc_symbol` deserves the same reading: it is stored so an editor can
 * trace a chart back to its instrument, and it is never emitted anywhere on
 * the front end, because naming an instrument would break CLAUDE.md invariant
 * 2 and would also hand the player the answer.
 *
 * Modelled on `htinvest_profile` in hti-engine — the same "private, no
 * archive, no rewrite, no query var, no REST" posture — with `show_ui` on,
 * because unlike a profile these two are content a human curates.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `hti_stc_scenario`, `hti_reveal_case` and their meta.
 */
class CPT {

	/**
	 * Longest series a scenario may hold, in candles.
	 *
	 * A scenario is 80 visible + 40 outcome; the ceiling is generous enough
	 * for a longer variant and low enough that a malformed import cannot put
	 * a megabyte of JSON into a meta row.
	 */
	private const MAX_TICKS = 1000;

	/**
	 * The three classes a scenario can be, as the generator labels them.
	 */
	public const SCENARIO_CLASSES = array( 'reasonable', 'ambiguous', 'trap' );

	/**
	 * The tints a fundamentals row can carry in The Reveal's dossier.
	 */
	public const TINTS = array( 'good', 'warn', 'bad' );

	/**
	 * What a case's figures ARE, as the reveal screen has to describe them.
	 *
	 * 'verified' means every number beside the company's name was read out of
	 * a document somebody can open, and the case cannot be published without
	 * that document and a tick. 'illustrative' means the company, the period
	 * and the direction of what happened are real and the figures are a
	 * reconstruction of the pattern — publishable, but only with the whole
	 * dossier filled and with the reveal screen saying so in as many words.
	 */
	public const PROVENANCE = array( 'illustrative', 'verified' );

	/**
	 * Hook registration on `init`.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register both types and all of their meta.
	 */
	public static function register(): void {
		self::register_scenario();
		self::register_case();
		self::register_meta();
	}

	/* ---------------------------------------------------------------------
	 * Post types
	 * ------------------------------------------------------------------- */

	/**
	 * Survive the Charts: one chart, its outcome, and the lesson it teaches.
	 */
	private static function register_scenario(): void {
		register_post_type(
			Config::CPT_SCENARIO,
			array(
				'label'               => __( 'Chart scenarios', 'hti-games' ),
				'labels'              => array(
					'name'          => __( 'Chart scenarios', 'hti-games' ),
					'singular_name' => __( 'Chart scenario', 'hti-games' ),
					'menu_name'     => __( 'Chart scenarios', 'hti-games' ),
					'add_new_item'  => __( 'Add new scenario', 'hti-games' ),
					'edit_item'     => __( 'Edit scenario', 'hti-games' ),
					'all_items'     => __( 'All scenarios', 'hti-games' ),
					'search_items'  => __( 'Search scenarios', 'hti-games' ),
					'not_found'     => __( 'No scenarios found.', 'hti-games' ),
				),
				'description'         => __( 'One daily chart for Survive the Charts: visible candles, outcome candles and the lesson.', 'hti-games' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				// Anti-cheat: the outcome candles live in this post's meta.
				'show_in_rest'        => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'hierarchical'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-chart-line',
				// Title only: the body of a scenario is structured meta, and an
				// editor field would invite prose that nothing ever renders.
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'can_export'          => true,
			)
		);
	}

	/**
	 * The Reveal: an anonymised dossier of a real company at a real year.
	 */
	private static function register_case(): void {
		register_post_type(
			Config::CPT_CASE,
			array(
				'label'               => __( 'Reveal cases', 'hti-games' ),
				'labels'              => array(
					'name'          => __( 'Reveal cases', 'hti-games' ),
					'singular_name' => __( 'Reveal case', 'hti-games' ),
					'menu_name'     => __( 'Reveal cases', 'hti-games' ),
					'add_new_item'  => __( 'Add new case', 'hti-games' ),
					'edit_item'     => __( 'Edit case', 'hti-games' ),
					'all_items'     => __( 'All cases', 'hti-games' ),
					'search_items'  => __( 'Search cases', 'hti-games' ),
					'not_found'     => __( 'No cases found.', 'hti-games' ),
				),
				'description'         => __( 'One dossier for The Reveal: sector, fundamentals, headlines, and the company behind them.', 'hti-games' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				// Anti-cheat: this post's meta IS the answer — company, year,
				// and the five-year return the player is being asked to guess.
				'show_in_rest'        => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'hierarchical'        => false,
				'menu_position'       => 27,
				'menu_icon'           => 'dashicons-visibility',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'can_export'          => true,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Meta
	 * ------------------------------------------------------------------- */

	/**
	 * Scenario meta: key => [ registered type, sanitizer method ].
	 *
	 * Public because the admin screen and the importer both walk this table
	 * rather than repeating the key list — a field added here shows up in the
	 * meta box without a second edit, and cannot be saved unsanitized.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function scenario_meta(): array {
		return array(
			// The whole series as integer OHLC quads: [[o,h,l,c], …].
			'hti_stc_ticks'      => array( 'string', 'san_ticks' ),
			// Ticks are price × scale; see Config::TICK_SCALE.
			'hti_stc_scale'      => array( 'integer', 'san_uint' ),
			'hti_stc_visible'    => array( 'integer', 'san_uint' ),
			'hti_stc_outcome'    => array( 'integer', 'san_uint' ),
			'hti_stc_class'      => array( 'string', 'san_class' ),
			// Whether passing was the right call — a scenario where it is has
			// to exist, or the game teaches that there is always a trade.
			'hti_stc_pass_right' => array( 'string', 'san_bool' ),
			// 1 = real market data, 0 = generated. Library::is_real() reads
			// this across the whole pool before the page may claim "real".
			'hti_stc_real'       => array( 'string', 'san_bool' ),
			'hti_stc_source'     => array( 'string', 'san_text' ),
			'hti_stc_seed'       => array( 'string', 'san_seed' ),
			'hti_stc_checksum'   => array( 'string', 'san_checksum' ),
			// Admin-only provenance. NEVER emitted: naming the instrument
			// would break invariant 2 and give away the answer.
			'hti_stc_symbol'     => array( 'string', 'san_text' ),
			'hti_stc_lesson_en'  => array( 'string', 'san_block' ),
			'hti_stc_lesson_pt'  => array( 'string', 'san_block' ),
			// Optional pinned day index; see Library::pick_pinned().
			'hti_stc_slot'       => array( 'integer', 'san_uint' ),
		);
	}

	/**
	 * Case meta: key => [ registered type, sanitizer method ].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function case_meta(): array {
		return array(
			'hti_rev_company'            => array( 'string', 'san_text' ),
			'hti_rev_year'               => array( 'integer', 'san_year' ),
			'hti_rev_sector_en'          => array( 'string', 'san_text' ),
			'hti_rev_sector_pt'          => array( 'string', 'san_text' ),
			'hti_rev_revenue_band_en'    => array( 'string', 'san_text' ),
			'hti_rev_revenue_band_pt'    => array( 'string', 'san_text' ),
			// Which SHAPE of dossier this is — 'fraud', 'cyclical_peak',
			// 'boring_compounder' and the rest of Reveal_Lessons::patterns().
			// It is what hangs a ready-written, company-free lesson on a case,
			// and it is a hypothesis for the editor to test against the filing
			// rather than a verdict on anybody.
			'hti_rev_pattern'            => array( 'string', 'san_key' ),
			// The editor-facing research brief: which document to open, which
			// line item feeds which of the six labels, where the sector
			// comparison comes from. Bilingual in one field, admin-only, and
			// never emitted to a player. Longer than san_block allows because
			// it carries both languages and six line-item mappings.
			'hti_rev_brief'              => array( 'string', 'san_brief' ),
			// Six rows of {key,label,value,sector average,tint} in both
			// languages — the dossier the player actually reads.
			'hti_rev_fundamentals'       => array( 'string', 'san_fundamentals' ),
			'hti_rev_headlines'          => array( 'string', 'san_headlines' ),
			// Returns in basis points, signed: a company that lost 80% is
			// -8000, and that is the more instructive half of the archive.
			'hti_rev_return_5y_bp'       => array( 'integer', 'san_int' ),
			'hti_rev_index_return_5y_bp' => array( 'integer', 'san_int' ),
			'hti_rev_context_en'         => array( 'string', 'san_block' ),
			'hti_rev_context_pt'         => array( 'string', 'san_block' ),
			'hti_rev_lesson_en'          => array( 'string', 'san_block' ),
			'hti_rev_lesson_pt'          => array( 'string', 'san_block' ),
			// What the figures above are: a reconstruction of the pattern, or
			// numbers read out of a document. It decides WHICH publish gate a
			// case has to pass and which sentence the reveal screen shows, so
			// it is registered beside the sourcing fields it governs.
			'hti_rev_provenance'         => array( 'string', 'san_provenance' ),
			// The four fields the publish gate is built on.
			'hti_rev_source_url'         => array( 'string', 'san_url' ),
			'hti_rev_source_label'       => array( 'string', 'san_text' ),
			'hti_rev_source_accessed'    => array( 'string', 'san_date' ),
			'hti_rev_verified'           => array( 'string', 'san_bool' ),
			'hti_rev_verified_by'        => array( 'string', 'san_text' ),
			'hti_rev_verified_at'        => array( 'string', 'san_datetime' ),
			'hti_rev_slot'               => array( 'integer', 'san_uint' ),
		);
	}

	/**
	 * Register every meta key on both types.
	 *
	 * Registered rather than left implicit so that each key has a declared
	 * sanitizer (nothing reaches the meta table unfiltered, whoever writes it)
	 * and an auth callback (protected keys need one, and the default denies).
	 */
	private static function register_meta(): void {
		$map = array(
			Config::CPT_SCENARIO => self::scenario_meta(),
			Config::CPT_CASE     => self::case_meta(),
		);

		foreach ( $map as $post_type => $fields ) {
			foreach ( $fields as $key => $spec ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => $spec[0],
						'single'            => true,
						// Anti-cheat, again: no meta key of either type is
						// readable or writable over the core REST API.
						'show_in_rest'      => false,
						'sanitize_callback' => array( __CLASS__, $spec[1] ),
						'auth_callback'     => array( __CLASS__, 'can_edit' ),
					)
				);
			}
		}
	}

	/**
	 * Who may write game meta. Editors and above, never a subscriber.
	 */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/* ---------------------------------------------------------------------
	 * Sanitizers — every one of them returns a storable string or int, never
	 * null, so a rejected value reads as "empty" and never as "unset".
	 * ------------------------------------------------------------------- */

	/**
	 * One line of plain text.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_text( $value ): string {
		return substr( sanitize_text_field( (string) $value ), 0, 240 );
	}

	/**
	 * A paragraph: newlines survive, markup does not.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_block( $value ): string {
		return substr( sanitize_textarea_field( (string) $value ), 0, 2000 );
	}

	/**
	 * An editorial brief: several paragraphs of instructions, both languages.
	 *
	 * Its own ceiling rather than san_block's, because a brief carries the
	 * English and the Portuguese of the same instructions plus one line per
	 * fundamental, and a brief silently cut in half at 2000 characters would
	 * lose the Portuguese entirely — with nothing to tell the editor that the
	 * missing half ever existed. Still bounded: a meta row is not a document
	 * store.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_brief( $value ): string {
		return substr( sanitize_textarea_field( (string) $value ), 0, 12000 );
	}

	/**
	 * A machine key: lowercase, identifier-shaped, or empty.
	 *
	 * Not validated against the list of patterns it usually holds, because
	 * that list lives in Reveal_Lessons — a copy library this file has no
	 * business loading on every page view. The list is enforced where it
	 * belongs: tests/test-seed-cases.php fails if a seeded case names a
	 * pattern the lesson library does not know.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_key( $value ): string {
		return substr( sanitize_key( (string) $value ), 0, 40 );
	}

	/**
	 * A signed integer (returns bp, which are routinely negative).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_int( $value ): int {
		return (int) $value;
	}

	/**
	 * A non-negative integer.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_uint( $value ): int {
		return max( 0, (int) $value );
	}

	/**
	 * A flag, stored as the literal '1' or '0'.
	 *
	 * Stored rather than deleted when false, because Library's pool query
	 * compares against `hti_rev_verified = 1` on its verified branch, and a
	 * missing row and a false row should not need two different comparisons to
	 * tell apart.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_bool( $value ): string {
		return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
	}

	/**
	 * One of the three scenario classes.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_class( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::SCENARIO_CLASSES, true ) ? $value : '';
	}

	/**
	 * The provenance of a case's figures: 'illustrative' or 'verified'.
	 *
	 * ANYTHING that is not the literal 'illustrative' comes back as
	 * 'verified' — an unset key, an empty string, a typo, a row written before
	 * this key existed. That is deliberate and it is the whole point of the
	 * field: 'verified' is the STRICT path, the one that needs a source URL
	 * and a checked tick before Case_Admin will let the case be published. A
	 * default that fails open is how a gate stops being a gate — a case
	 * created by hand in the admin, or any pre-existing row, would otherwise
	 * escape the source requirement by saying nothing at all.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_provenance( $value ): string {
		return 'illustrative' === (string) $value ? 'illustrative' : 'verified';
	}

	/**
	 * A generator seed: identifiers only, so it is safe in a log line.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_seed( $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_:.\-]/', '', (string) $value ), 0, 64 );
	}

	/**
	 * A hexadecimal digest.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_checksum( $value ): string {
		$value = strtolower( (string) preg_replace( '/[^A-Fa-f0-9]/', '', (string) $value ) );
		return substr( $value, 0, 64 );
	}

	/**
	 * A four-digit year, bounded by what a real dossier could describe.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_year( $value ): int {
		$year = (int) $value;
		if ( $year < 1800 || $year > (int) gmdate( 'Y' ) ) {
			return 0;
		}
		return $year;
	}

	/**
	 * An http(s) URL, or empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_url( $value ): string {
		$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		return substr( (string) $url, 0, 300 );
	}

	/**
	 * A 'Y-m-d' date, or empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_date( $value ): string {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		return gmdate( 'Y-m-d', (int) strtotime( $value . ' 00:00:00 UTC' ) ) === $value ? $value : '';
	}

	/**
	 * A 'Y-m-d H:i:s' UTC timestamp, or empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function san_datetime( $value ): string {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * The candle series: a JSON list of integer [open, high, low, close].
	 *
	 * Anything that is not exactly that is rejected wholesale rather than
	 * repaired — a half-parsed chart is a wrong chart, and the importer's
	 * pure validator is where a real series is supposed to be proven.
	 *
	 * @param mixed $value Raw JSON string.
	 */
	public static function san_ticks( $value ): string {
		$rows = json_decode( (string) $value, true );
		if ( ! is_array( $rows ) || array() === $rows || count( $rows ) > self::MAX_TICKS ) {
			return '';
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || 4 !== count( $row ) ) {
				return '';
			}
			$quad = array();
			foreach ( array( 0, 1, 2, 3 ) as $i ) {
				if ( ! isset( $row[ $i ] ) || ! is_numeric( $row[ $i ] ) ) {
					return '';
				}
				$quad[] = (int) $row[ $i ];
			}
			$out[] = $quad;
		}

		return (string) wp_json_encode( $out );
	}

	/**
	 * The six fundamentals rows, each bilingual and tinted.
	 *
	 * @param mixed $value Raw JSON string.
	 */
	public static function san_fundamentals( $value ): string {
		$rows = json_decode( (string) $value, true );
		if ( ! is_array( $rows ) ) {
			return '';
		}

		$fields = array( 'key', 'label_en', 'label_pt', 'value_en', 'value_pt', 'sector_avg_en', 'sector_avg_pt' );
		$out    = array();
		foreach ( array_slice( $rows, 0, 6 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $fields as $field ) {
				$clean[ $field ] = self::san_text( $row[ $field ] ?? '' );
			}
			$tint          = sanitize_key( (string) ( $row['tint'] ?? '' ) );
			$clean['tint'] = in_array( $tint, self::TINTS, true ) ? $tint : 'warn';
			$out[]         = $clean;
		}

		return array() === $out ? '' : (string) wp_json_encode( $out );
	}

	/**
	 * The three period headlines, each in both languages.
	 *
	 * @param mixed $value Raw JSON string.
	 */
	public static function san_headlines( $value ): string {
		$rows = json_decode( (string) $value, true );
		if ( ! is_array( $rows ) ) {
			return '';
		}

		$out = array();
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'en' => self::san_text( $row['en'] ?? '' ),
				'pt' => self::san_text( $row['pt'] ?? '' ),
			);
		}

		return array() === $out ? '' : (string) wp_json_encode( $out );
	}
}
