<?php
/**
 * Settings → HTI Games: the section's kill-switches, the board size, the
 * retention window, and a readiness panel that answers the one question an
 * owner actually has — "is this section safe to link to yet?".
 *
 * Same shape as HTI\Forex\Settings: one option array, a pure normalizer that
 * the harness can exercise, and a `hti_games_settings_panels` action at the
 * foot of the screen so the importer, the case verification queue and the
 * seeder each own their own admin surface without anybody editing this file.
 *
 * The readiness panel deliberately reports state that is DERIVED from the
 * content rather than typed into a field. The landing claim on the Survive
 * the Charts page is the clearest case: there is no "these charts are real"
 * checkbox anywhere, because such a box stays ticked long after somebody tops
 * the pool up with generated scenarios. The panel therefore says which claim
 * is live AND why it is live, so the way to change the sentence is to change
 * the data — which is the only way a claim on a public page stays true.
 *
 * The same rows are contributed, in one summary line, to hti-engine's
 * `hti_readiness_rows` filter, so the games show up on the main readiness
 * screen next to the mailer and the lead magnets rather than only here.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen, readiness panel and the pure normalizer.
 */
class Settings {

	/**
	 * Settings API group.
	 */
	private const GROUP = 'hti_games_settings_group';

	/**
	 * Options-page slug. The seeder redirects back to this after a run.
	 */
	public const PAGE = 'hti-games';

	/**
	 * The single option row everything is stored in.
	 */
	public const OPTION = 'hti_games_settings';

	/**
	 * Rows the leaderboard may show. Below three there is no board; above a
	 * hundred it is a scroll nobody reads and a query nobody needs.
	 */
	public const BOARD_MIN = 3;
	public const BOARD_MAX = 100;

	/**
	 * Bounds on the retention window, in days. A floor of 30 exists because a
	 * shorter one would delete a run while its player is still on the streak
	 * it belongs to; the ceiling is three years, which is as long as an
	 * educational game has any business remembering anything.
	 */
	public const RETENTION_MIN = 30;
	public const RETENTION_MAX = 1095;

	/**
	 * Hook the admin page, the setting registration and the readiness row.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'hti_readiness_rows', array( __CLASS__, 'readiness_row' ) );
	}

	/* ---------------------------------------------------------------------
	 * Values
	 * ------------------------------------------------------------------- */

	/**
	 * Defaults.
	 *
	 * Everything the section needs to work is ON, because the section works
	 * with none of it configured. `newsletter_optin` is the exception: an
	 * opt-in offered inside a game is a marketing surface, and a marketing
	 * surface defaults to off until somebody decides it belongs there.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'stc_enabled'         => true,
			'reveal_enabled'      => true,
			'leaderboard_enabled' => true,
			'share_enabled'       => true,
			'email_link_enabled'  => true,
			'newsletter_optin'    => false,
			'board_size'          => 20,
			'retention_days'      => 400,
		);
	}

	/**
	 * The boolean settings, in one place so the normalizer and the form
	 * cannot disagree about which keys are flags.
	 *
	 * @return array<int,string>
	 */
	public static function flags(): array {
		return array( 'stc_enabled', 'reveal_enabled', 'leaderboard_enabled', 'share_enabled', 'email_link_enabled', 'newsletter_optin' );
	}

	/**
	 * Current settings, merged over defaults so a partial stored array
	 * behaves exactly like a complete one.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Whether one game is switched on. The kill-switch exists so a content
	 * problem — a case whose verification was withdrawn, say — can take a
	 * game off the site in one click instead of one deploy.
	 *
	 * @param string                   $game     Config::GAME_STC|GAME_REVEAL.
	 * @param array<string,mixed>|null $settings Optional settings override.
	 */
	public static function game_enabled( string $game, ?array $settings = null ): bool {
		$s = $settings ?? self::settings();
		if ( Config::GAME_STC === $game ) {
			return ! empty( $s['stc_enabled'] );
		}
		if ( Config::GAME_REVEAL === $game ) {
			return ! empty( $s['reveal_enabled'] );
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Pure normalizer (unit-tested; no WordPress)
	 * ------------------------------------------------------------------- */

	/**
	 * Normalize and validate a submitted settings array.
	 *
	 * Same contract as HTI\Engine\Settings::normalize_scoring(): a value that
	 * is always usable, plus the list of things that were wrong with the
	 * input. An out-of-range number is reverted to its default and reported
	 * rather than clamped — clamping 100000 to 100 looks like it worked, and
	 * the owner never finds out the figure they typed was refused.
	 *
	 * @param array<string,mixed> $raw Raw submitted settings.
	 * @return array{value:array<string,mixed>,errors:list<string>}
	 */
	public static function normalize( array $raw ): array {
		$defaults = self::defaults();
		$errors   = array();
		$out      = $defaults;

		foreach ( self::flags() as $flag ) {
			$out[ $flag ] = ! empty( $raw[ $flag ] );
		}

		foreach ( array(
			'board_size'     => array( self::BOARD_MIN, self::BOARD_MAX ),
			'retention_days' => array( self::RETENTION_MIN, self::RETENTION_MAX ),
		) as $key => $bounds ) {
			// An absent key is the form not submitting it, not a zero.
			if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
				$out[ $key ] = (int) $defaults[ $key ];
				continue;
			}
			$value = (int) $raw[ $key ];
			if ( $value < $bounds[0] || $value > $bounds[1] ) {
				$out[ $key ] = (int) $defaults[ $key ];
				$errors[]    = sprintf(
					'%1$s must be between %2$d and %3$d — %4$d was refused and the default (%5$d) kept.',
					$key,
					$bounds[0],
					$bounds[1],
					$value,
					(int) $defaults[ $key ]
				);
			} else {
				$out[ $key ] = $value;
			}
		}

		// Both games off is a valid state — it is how the section is taken
		// down — but it is never what somebody meant to do silently, so it is
		// said out loud on the screen where it happened.
		if ( empty( $out['stc_enabled'] ) && empty( $out['reveal_enabled'] ) ) {
			$errors[] = 'Both games are switched off: the /games/ pages will render their editorial content with no game on them.';
		}

		return array(
			'value'  => $out,
			'errors' => $errors,
		);
	}

	/* ---------------------------------------------------------------------
	 * Readiness
	 * ------------------------------------------------------------------- */

	/**
	 * The readiness rows for the games section: [ status, label, message ].
	 *
	 * Status is 'ok' | 'warn' | 'fail', the vocabulary hti-engine's panel
	 * already renders, so the same rows can be shown in either place.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function readiness(): array {
		$rows = array();

		// 1. Are the pages actually on the site? A section nobody seeded is
		// five 404s with working shortcodes behind them.
		$found = 0;
		foreach ( array_keys( Config::pages() ) as $key ) {
			if ( get_page_by_path( Seeder::path( $key, 'en' ), OBJECT, 'page' ) instanceof \WP_Post ) {
				++$found;
			}
		}
		$total  = count( Config::pages() );
		$rows[] = array(
			$found === $total ? 'ok' : ( 0 === $found ? 'fail' : 'warn' ),
			__( 'Section pages', 'hti-games' ),
			sprintf(
				/* translators: 1: pages found, 2: pages expected. */
				__( '%1$d of %2$d English pages exist. Use the seeder panel below to create the missing ones.', 'hti-games' ),
				$found,
				$total
			),
		);

		// 2 & 3. The two content pools.
		$stc    = self::pool_size( Config::GAME_STC );
		$rows[] = array(
			self::pool_status( $stc ),
			__( 'Survive the Charts scenarios', 'hti-games' ),
			sprintf(
				/* translators: 1: scenarios published, 2: pool size the real-data claim needs. */
				__( '%1$d scenarios published. The pool rotates by day, so it never runs out — but below %2$d the same chart comes round quickly.', 'hti-games' ),
				$stc,
				(int) Config::REAL_CLAIM_MIN_POOL
			),
		);

		$reveal = self::pool_size( Config::GAME_REVEAL );
		$rows[] = array(
			self::pool_status( $reveal ),
			__( 'The Reveal cases', 'hti-games' ),
			sprintf(
				/* translators: %d: verified cases published. */
				__( '%d verified cases published. Only cases with a source URL and a recorded verification are ever served.', 'hti-games' ),
				$reveal
			),
		);

		// 4. Which landing claim is live, and why — the reason matters more
		// than the state, because the reason is the only thing that can be
		// acted on. There is no setting to flip here by design.
		$rows[] = self::claim_row( $stc );

		// 5. Cases waiting on somebody. This is a queue, and a queue with
		// nobody watching it is how a game runs out of content on a Sunday.
		$waiting = self::unverified_cases();
		$rows[]  = array(
			0 === $waiting ? 'ok' : 'warn',
			__( 'Reveal cases awaiting verification', 'hti-games' ),
			0 === $waiting
				? __( 'Nothing waiting — every case in the library is verified against a published source.', 'hti-games' )
				: sprintf(
					/* translators: %d: number of unverified cases. */
					__( '%d case(s) drafted but not verified. They cannot be published or served until the figures are checked against a source.', 'hti-games' ),
					$waiting
				),
		);

		// 6. The tables. Everything above is content; this is whether a
		// decision can be recorded at all.
		$missing = self::missing_tables();
		$rows[]  = array(
			array() === $missing ? 'ok' : 'fail',
			__( 'Game tables', 'hti-games' ),
			array() === $missing
				? __( 'Players and runs tables are installed.', 'hti-games' )
				: sprintf(
					/* translators: %s: comma-separated table names. */
					__( 'Missing: %s. No decision can be recorded until they install — they are created on init, so one admin page load usually fixes this.', 'hti-games' ),
					implode( ', ', $missing )
				),
		);

		return $rows;
	}

	/**
	 * One summary row for hti-engine's readiness screen.
	 *
	 * The main screen is a glance, not an audit: it gets the worst status of
	 * ours and a one-line reason, and the detail stays on our own page.
	 *
	 * @param mixed $rows Existing readiness rows.
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function readiness_row( $rows ): array {
		$rows = (array) $rows;
		$ours = self::readiness();

		$status = 'ok';
		foreach ( $ours as $row ) {
			if ( 'fail' === $row[0] ) {
				$status = 'fail';
				break;
			}
			if ( 'warn' === $row[0] ) {
				$status = 'warn';
			}
		}

		$rows[] = array(
			$status,
			__( 'Educational games', 'hti-games' ),
			sprintf(
				/* translators: 1: STC scenarios, 2: Reveal cases, 3: cases awaiting verification. */
				__( '%1$d chart scenarios and %2$d verified cases published, %3$d case(s) awaiting verification. Details under Settings → HTI Games.', 'hti-games' ),
				self::pool_size( Config::GAME_STC ),
				self::pool_size( Config::GAME_REVEAL ),
				self::unverified_cases()
			),
		);

		return $rows;
	}

	/**
	 * The readiness row explaining which landing claim is live.
	 *
	 * @param int $pool Size of the published STC pool.
	 * @return array{0:string,1:string,2:string}
	 */
	private static function claim_row( int $pool ): array {
		$real = Seeder::stc_is_real();
		$lang = 'en';

		if ( $real ) {
			return array(
				'ok',
				__( 'Landing claim (Survive the Charts)', 'hti-games' ),
				sprintf(
					/* translators: %s: the sentence currently on the page. */
					__( 'The real-data claim is live: "%s". Every scenario in the pool is imported market data and the pool is large enough.', 'hti-games' ),
					Strings::get( 'stc_claim_real', $lang )
				),
			);
		}

		$generated = self::generated_scenarios();
		if ( $pool < Config::REAL_CLAIM_MIN_POOL ) {
			$why = sprintf(
				/* translators: 1: current pool size, 2: required pool size. */
				__( 'the pool holds %1$d scenarios and the claim needs %2$d', 'hti-games' ),
				$pool,
				(int) Config::REAL_CLAIM_MIN_POOL
			);
		} elseif ( $generated > 0 ) {
			$why = sprintf(
				/* translators: %d: number of generated scenarios in the pool. */
				__( '%d scenario(s) in the pool are generated rather than imported', 'hti-games' ),
				$generated
			);
		} else {
			$why = __( 'the pool has not been re-checked since it last changed', 'hti-games' );
		}

		return array(
			'warn',
			__( 'Landing claim (Survive the Charts)', 'hti-games' ),
			sprintf(
				/* translators: 1: the sentence currently on the page, 2: the reason. */
				__( 'The generated-data claim is live: "%1$s", because %2$s. There is no switch for this: import real scenarios and the sentence changes itself.', 'hti-games' ),
				Strings::get( 'stc_claim_generated', $lang ),
				$why
			),
		);
	}

	/**
	 * Size of one published pool, via Library when it is loaded.
	 *
	 * @param string $game Game id.
	 */
	private static function pool_size( string $game ): int {
		if ( ! class_exists( __NAMESPACE__ . '\\Library' ) ) {
			return 0;
		}
		return count( Library::published_ids( $game ) );
	}

	/**
	 * Status for a pool size: a week of content is the floor worth calling
	 * "ok", and an empty pool means the game cannot serve a day at all.
	 *
	 * @param int $size Pool size.
	 */
	private static function pool_status( int $size ): string {
		if ( 0 === $size ) {
			return 'fail';
		}
		return $size < 7 ? 'warn' : 'ok';
	}

	/**
	 * How many published scenarios are generated rather than imported.
	 *
	 * A loop over the pool with a meta read each — admin-only, at most a few
	 * hundred rows, and the alternative is a meta_query whose result would
	 * have to be cached and invalidated for a number nobody reads twice.
	 */
	private static function generated_scenarios(): int {
		if ( ! class_exists( __NAMESPACE__ . '\\Library' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( Library::published_ids( Config::GAME_STC ) as $id ) {
			if ( '1' !== (string) get_post_meta( (int) $id, 'hti_stc_real', true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Cases that exist but cannot be served: drafted, pending or published
	 * without a recorded verification.
	 */
	private static function unverified_cases(): int {
		$ids = get_posts(
			array(
				'post_type'              => Config::CPT_CASE,
				'post_status'            => array( 'draft', 'pending', 'future', 'publish' ),
				'numberposts'            => 200,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( '1' !== (string) get_post_meta( (int) $id, 'hti_rev_verified', true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Which of the plugin's tables are not installed.
	 *
	 * @return array<int,string>
	 */
	private static function missing_tables(): array {
		if ( ! class_exists( __NAMESPACE__ . '\\Store' ) ) {
			return array();
		}

		global $wpdb;
		$missing = array();
		foreach ( array( Store::players_table(), Store::runs_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an existence check on an admin screen; there is no API for it and caching a "does my table exist" answer is how a broken install reports itself healthy.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $found !== $table ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	/**
	 * Add the options page under Settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'HTI Games', 'hti-games' ),
			__( 'HTI Games', 'hti-games' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option with its sanitize callback.
	 */
	public static function register(): void {
		register_setting( self::GROUP, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Sanitize callback: pure normalization plus settings errors.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$result = self::normalize( is_array( $input ) ? $input : array() );
		foreach ( $result['errors'] as $i => $message ) {
			add_settings_error( self::OPTION, 'hti_games_' . $i, esc_html( $message ), 'warning' );
		}
		return $result['value'];
	}

	/**
	 * Render the readiness panel.
	 */
	private static function readiness_panel(): void {
		$colors = array(
			'ok'   => array( '#15803d', '#dcfce7', '✓' ),
			'warn' => array( '#92400e', '#fef3c7', '!' ),
			'fail' => array( '#b91c1c', '#fee2e2', '✕' ),
		);
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:4px;padding:12px 16px;margin:16px 0;max-width:760px">
			<strong style="display:block;margin-bottom:8px"><?php esc_html_e( 'Is the section ready?', 'hti-games' ); ?></strong>
			<ul style="margin:0;list-style:none;padding:0">
				<?php
				foreach ( self::readiness() as $row ) :
					$c = $colors[ $row[0] ] ?? $colors['warn'];
					?>
					<li style="display:flex;gap:10px;align-items:flex-start;padding:5px 0">
						<span style="flex:none;width:20px;height:20px;border-radius:50%;background:<?php echo esc_attr( $c[1] ); ?>;color:<?php echo esc_attr( $c[0] ); ?>;font-weight:700;text-align:center;line-height:20px"><?php echo esc_html( $c[2] ); ?></span>
						<span><strong><?php echo esc_html( $row[1] ); ?>:</strong> <?php echo esc_html( $row[2] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Games', 'hti-games' ); ?></h1>
			<p><?php esc_html_e( 'Two educational games under /games/, on virtual capital. Nothing in this section carries a partner link, a prize or a payment of any kind, and nothing here can be configured to.', 'hti-games' ); ?></p>

			<?php self::readiness_panel(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'What is switched on', 'hti-games' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Games', 'hti-games' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[stc_enabled]" value="1" <?php checked( ! empty( $s['stc_enabled'] ) ); ?> />
								<?php echo esc_html( Strings::get( 'stc_name', 'en' ) ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[reveal_enabled]" value="1" <?php checked( ! empty( $s['reveal_enabled'] ) ); ?> />
								<?php echo esc_html( Strings::get( 'rev_name', 'en' ) ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Unticking one takes that game off the site immediately — its page keeps its editorial content and explains that the challenge is paused. This is the switch to use when a case has to be pulled, rather than a deploy.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Leaderboard', 'hti-games' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[leaderboard_enabled]" value="1" <?php checked( ! empty( $s['leaderboard_enabled'] ) ); ?> />
								<?php esc_html_e( 'Publish the daily and survival boards', 'hti-games' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The daily board is scored per unit of risk taken, never by raw profit — a board that rewarded the largest position would teach the opposite of the game.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-games-board-size"><?php esc_html_e( 'Rows on a board', 'hti-games' ); ?></label></th>
						<td>
							<input type="number" id="hti-games-board-size" name="<?php echo esc_attr( self::OPTION ); ?>[board_size]" value="<?php echo esc_attr( (string) $s['board_size'] ); ?>" min="<?php echo esc_attr( (string) self::BOARD_MIN ); ?>" max="<?php echo esc_attr( (string) self::BOARD_MAX ); ?>" step="1" />
							<p class="description">
								<?php
								printf(
									/* translators: 1: minimum rows, 2: maximum rows. */
									esc_html__( 'Between %1$d and %2$d. A value outside that range is refused and the default kept.', 'hti-games' ),
									(int) self::BOARD_MIN,
									(int) self::BOARD_MAX
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sharing', 'hti-games' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[share_enabled]" value="1" <?php checked( ! empty( $s['share_enabled'] ) ); ?> />
								<?php esc_html_e( 'Offer the spoiler-free result card', 'hti-games' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Accounts and data', 'hti-games' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cross-device link', 'hti-games' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[email_link_enabled]" value="1" <?php checked( ! empty( $s['email_link_enabled'] ) ); ?> />
								<?php esc_html_e( 'Let a player email themselves a link that carries the run to another device', 'hti-games' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Optional for the player and off the critical path: with it disabled, a run lives on one device and no email address is ever collected by this section.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Newsletter opt-in', 'hti-games' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[newsletter_optin]" value="1" <?php checked( ! empty( $s['newsletter_optin'] ) ); ?> />
								<?php esc_html_e( 'Offer the newsletter alongside the cross-device link', 'hti-games' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. It must stay a separate, unticked choice from the link itself — consent bundled into another action is not consent.', 'hti-games' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-games-retention"><?php esc_html_e( 'Keep finished runs for', 'hti-games' ); ?></label></th>
						<td>
							<input type="number" id="hti-games-retention" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>" min="<?php echo esc_attr( (string) self::RETENTION_MIN ); ?>" max="<?php echo esc_attr( (string) self::RETENTION_MAX ); ?>" step="1" />
							<?php esc_html_e( 'days', 'hti-games' ); ?>
							<p class="description"><?php esc_html_e( 'Data minimisation: a game does not need to remember a decision forever. A player can also delete everything from their own profile page at any time, which does not wait for this window.', 'hti-games' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php
			/**
			 * Extra panels — the seeder button, the scenario importer, the
			 * case verification queue — hook in here so each feature owns its
			 * own admin surface without editing this file.
			 */
			do_action( 'hti_games_settings_panels' );
			?>
		</div>
		<?php
	}
}
