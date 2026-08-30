<?php
/**
 * The five shortcodes the games section mounts, and the shell they render.
 *
 * `[hti_game name="stc"]`, `[hti_game name="reveal"]`, `[hti_games_hub]`,
 * `[hti_games_leaderboard]` and `[hti_games_profile]` — the same five
 * Seeder::plan() embeds in the pages it owns.
 *
 * ---------------------------------------------------------------------------
 * Why the shell is server-rendered
 * ---------------------------------------------------------------------------
 *
 * The obvious build is `<div id="game"></div>` plus a bundle. That page is
 * empty to a crawler, empty to a reader whose script failed, and empty for the
 * second and a half before the bundle parses — on the two pages the whole
 * section exists to rank. So every heading, every button, the rules, the
 * disclaimer and a <noscript> are HTML before a byte of JavaScript runs, and
 * the JS takes over markup that is already there rather than creating it.
 *
 * What is NOT server-rendered is anything that varies per visitor: capital,
 * streak, the board rows, the calendar. These pages are ordinary cacheable
 * WordPress pages, so a per-player number baked into the HTML would be served
 * to the next visitor. The placeholders below are the neutral starting values
 * (Config::CAPITAL_START, streak zero) and the client replaces them from
 * `GET /today`, which is the only thing that knows who is asking.
 *
 * ---------------------------------------------------------------------------
 * Copy
 * ---------------------------------------------------------------------------
 *
 * Everything a player reads comes from Strings, never from `__()`: the site
 * runs pt_PT_ao90 against pt_PT translation files and WordPress does not fall
 * back between them, so a missing `__()` translation renders silently in
 * English on a Portuguese page. The one exception is labels(), a nine-key
 * bilingual table at the bottom of this file for the accessibility furniture
 * the copy table does not yet carry (the screen-reader table's row headings,
 * the board's column names, and the no-JavaScript notice). They are written in
 * the same both-languages-side-by-side shape and belong in Strings the next
 * time that file is open; they live here so this file does not have to edit
 * one it does not own.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes, asset wiring and the server-rendered shell.
 */
class Frontend {

	/**
	 * The shortcodes, as they appear in the seeded pages.
	 */
	public const SC_GAME    = 'hti_game';
	public const SC_HUB     = 'hti_games_hub';
	public const SC_BOARD   = 'hti_games_leaderboard';
	public const SC_PROFILE = 'hti_games_profile';

	/**
	 * How long one candle takes to reveal during the replay, in milliseconds.
	 *
	 * From the design handoff. It lives here rather than in the JavaScript so
	 * the number the client animates at and the number this file documents are
	 * the same one.
	 */
	public const REPLAY_MS = 300;

	/**
	 * Memoised result of kinds() — it is asked for three times per request
	 * (body class, enqueue, render) and each answer is a post-content scan.
	 *
	 * @var array<int,string>|null
	 */
	private static ?array $kinds = null;

	/**
	 * A hash of the content the memo above was computed from.
	 *
	 * A request has one queried object, so in production this never changes.
	 * Keying the cache on it anyway costs one md5 and means the answer can
	 * never be stale — which is also what makes the function testable.
	 *
	 * @var string|null
	 */
	private static ?string $kinds_for = null;

	/**
	 * Hook the shortcodes, the assets and the body class.
	 *
	 * The enqueue runs at priority 20 so hti-engine's `hti-track` is already
	 * registered when the dependency is declared; the dependency is still
	 * guarded below, because a dependency WordPress cannot resolve makes it
	 * drop the script entirely and take the game with it.
	 */
	public static function init(): void {
		add_shortcode( self::SC_GAME, array( __CLASS__, 'render_game' ) );
		add_shortcode( self::SC_HUB, array( __CLASS__, 'render_hub' ) );
		add_shortcode( self::SC_BOARD, array( __CLASS__, 'render_board' ) );
		add_shortcode( self::SC_PROFILE, array( __CLASS__, 'render_profile' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/* ---------------------------------------------------------------------
	 * Which page is this
	 * ------------------------------------------------------------------- */

	/**
	 * Every games surface embedded in the queried post.
	 *
	 * A list rather than a single kind: nothing stops an editor putting the
	 * board under a game, and loading half the assets for such a page would be
	 * a bug that only shows up on somebody else's page.
	 *
	 * @return array<int,string> Subset of stc|reveal|hub|leaderboard|profile.
	 */
	public static function kinds(): array {
		$post    = is_singular() ? get_queried_object() : null;
		$content = $post instanceof \WP_Post ? (string) $post->post_content : '';
		$key     = md5( $content );

		if ( null !== self::$kinds && self::$kinds_for === $key ) {
			return self::$kinds;
		}

		self::$kinds     = array();
		self::$kinds_for = $key;

		if ( '' === $content ) {
			return self::$kinds;
		}

		if ( has_shortcode( $content, self::SC_GAME ) ) {
			// Which game, from the attribute the seeder writes. Both, if an
			// editor mounted both.
			if ( preg_match_all( '/\[' . self::SC_GAME . '\s+name=["\']?([a-z_]+)/', $content, $m ) ) {
				foreach ( $m[1] as $name ) {
					if ( Config::is_game( $name ) && ! in_array( $name, self::$kinds, true ) ) {
						self::$kinds[] = $name;
					}
				}
			}
			if ( ! self::$kinds ) {
				self::$kinds[] = Config::GAME_STC;
			}
		}

		foreach ( array(
			self::SC_HUB     => 'hub',
			self::SC_BOARD   => 'leaderboard',
			self::SC_PROFILE => 'profile',
		) as $shortcode => $kind ) {
			if ( has_shortcode( $content, $shortcode ) ) {
				self::$kinds[] = $kind;
			}
		}

		return self::$kinds;
	}

	/**
	 * Whether this request renders any part of the games section.
	 */
	public static function is_game_page(): bool {
		return array() !== self::kinds();
	}

	/**
	 * The language of the page being viewed.
	 *
	 * The URL prefix first, for the same reason the theme's current_lang()
	 * trusts it first: Polylang can report the default language under /pt/ on
	 * some views, and the visitor believes the URL.
	 */
	public static function lang(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- compared against a literal pattern, never output or stored.
		if ( '' !== $uri && 1 === preg_match( '#^/pt(/|$|\?)#', $uri ) ) {
			return 'pt';
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = (string) pll_current_language( 'slug' );
			if ( '' !== $slug ) {
				return Player::lang( $slug );
			}
		}
		return Player::lang( function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en' );
	}

	/**
	 * Tag the page so the CSS can neutralise the theme's 680px content cap and
	 * hide the site chrome during a run.
	 *
	 * The theme does exactly this for `.hti-page-quiz` (style.css:488-510); the
	 * rules for `.hti-page-game` live in our own games.css rather than in the
	 * theme, so removing this plugin removes its styling with it.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( array $classes ): array {
		$kinds = self::kinds();
		if ( ! $kinds ) {
			return $classes;
		}

		$classes[] = 'hti-page-game';
		foreach ( $kinds as $kind ) {
			$classes[] = 'hti-page-game--' . $kind;
		}

		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue only what this page actually mounts.
	 *
	 * Same gate as HTI\Forex\Tools::enqueue(): nothing here loads site-wide.
	 * A page with the hub or a board gets the shared shell only; a game page
	 * additionally gets that game's engine mirror and its own sheet.
	 */
	public static function enqueue(): void {
		$kinds = self::kinds();
		if ( ! $kinds ) {
			return;
		}

		$lang = self::lang();

		wp_enqueue_style( 'hti-games', HTI_GAMES_URL . 'assets/css/games.css', array(), VERSION );

		// games-shared.js reports game_view / game_board_view through
		// window.HTITrack, so it has to load after hti-track or the call is a
		// silent no-op. Declared only when the handle exists — a dependency
		// WordPress cannot resolve makes it drop the script.
		$shared_deps = array();
		if ( wp_script_is( 'hti-track', 'registered' ) ) {
			$shared_deps[] = 'hti-track';
		}

		wp_enqueue_script(
			'hti-games-shared',
			HTI_GAMES_URL . 'assets/js/games-shared.js',
			$shared_deps,
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script( 'hti-games-shared', 'HTI_GAMES', self::data( $lang ) );

		if ( in_array( Config::GAME_STC, $kinds, true ) ) {
			wp_enqueue_style( 'hti-games-stc', HTI_GAMES_URL . 'assets/css/stc.css', array( 'hti-games' ), VERSION );
			wp_enqueue_script(
				'hti-games-stc-core',
				HTI_GAMES_URL . 'assets/js/stc-core.js',
				array(),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_enqueue_script(
				'hti-games-stc',
				HTI_GAMES_URL . 'assets/js/stc.js',
				array( 'hti-games-shared', 'hti-games-stc-core' ),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( in_array( Config::GAME_REVEAL, $kinds, true ) ) {
			wp_enqueue_style( 'hti-games-reveal', HTI_GAMES_URL . 'assets/css/reveal.css', array( 'hti-games' ), VERSION );
			wp_enqueue_script(
				'hti-games-reveal-core',
				HTI_GAMES_URL . 'assets/js/reveal-core.js',
				array(),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_enqueue_script(
				'hti-games-reveal',
				HTI_GAMES_URL . 'assets/js/reveal.js',
				array( 'hti-games-shared', 'hti-games-reveal-core' ),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * The one localized object: `HTI_GAMES`.
	 *
	 * The risk and size tiers arrive with their ruin counts ALREADY COMPUTED.
	 * The warning copy carries a `%d` placeholder precisely so that the number
	 * on the screen is the engine's answer and not a sentence somebody typed —
	 * the design prototype hardcoded "30 losses in a row" at 2%, which comes
	 * from a linear model; compounding says 114. Computing it here means the
	 * client cannot get it wrong and cannot drift from the server.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<string,mixed>
	 */
	public static function data( string $lang ): array {
		$settings = Settings::settings();

		return array(
			'root'    => esc_url_raw( rest_url( 'htinvest/v1/games' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'lang'    => $lang,
			'strings' => Strings::table( $lang ),
			'labels'  => self::labels( $lang ),
			'urls'    => array(
				'hub'         => Seeder::url( 'hub', $lang ),
				'stc'         => Seeder::url( 'stc', $lang ),
				'reveal'      => Seeder::url( 'reveal', $lang ),
				'leaderboard' => Seeder::url( 'leaderboard', $lang ),
				'profile'     => Seeder::url( 'profile', $lang ),
			),
			'config'  => array(
				'capital_start' => Config::CAPITAL_START,
				'capital_floor' => Config::CAPITAL_FLOOR,
				'visible'       => Config::STC_VISIBLE,
				'outcome'       => Config::STC_OUTCOME,
				'atr_period'    => Config::STC_ATR_PERIOD,
				'tick_scale'    => Config::TICK_SCALE,
				'double'        => Config::STC_DOUBLE,
				'replay_ms'     => self::REPLAY_MS,
				'board_size'    => (int) $settings['board_size'],
			),
			'risk'    => self::risk_tiers( $lang ),
			'sizes'   => self::size_tiers(),
			// The line the death screen shows: what 2% would have cost.
			'ruin2'   => STC_Engine::losses_to_ruin( 200 ),
			'flags'   => array(
				'stc'         => Settings::game_enabled( Config::GAME_STC, $settings ),
				'reveal'      => Settings::game_enabled( Config::GAME_REVEAL, $settings ),
				'leaderboard' => ! empty( $settings['leaderboard_enabled'] ),
				'share'       => ! empty( $settings['share_enabled'] ),
				'link'        => ! empty( $settings['email_link_enabled'] ),
				'newsletter'  => ! empty( $settings['newsletter_optin'] ),
			),
		);
	}

	/**
	 * The six Survive the Charts tiers, with their copy key and ruin counts.
	 *
	 * `tone` is the escalation the design calls for — green, green, amber,
	 * orange, red, red — and it is a class name, never a colour: the sheet
	 * owns the palette. Amber is UI, never profit; the greens and reds here
	 * are the trade-outcome family and mean exactly what they mean elsewhere.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function risk_tiers( string $lang ): array {
		$tone = array(
			50   => 'up',
			100  => 'up',
			200  => 'brand',
			500  => 'warn',
			1000 => 'down',
			2500 => 'down',
		);

		$out = array();
		foreach ( Config::STC_RISK_BP as $bp ) {
			$out[] = array(
				'bp'      => $bp,
				'label'   => self::pct_label( $bp, $lang ),
				'warn'    => 'stc_warn_' . $bp,
				'losses'  => STC_Engine::losses_to_ruin( $bp ),
				'losses2' => STC_Engine::losses_to_ruin( $bp, true ),
				'tone'    => $tone[ $bp ] ?? 'down',
				// 25% is the tier the design marks with a skull and relabels
				// the confirm button for. Derived from the tier itself so the
				// two never disagree.
				'grave'   => $bp >= 1000,
			);
		}

		return $out;
	}

	/**
	 * The four Reveal commitment sizes, same shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function size_tiers(): array {
		$tone = array(
			5  => 'up',
			10 => 'up',
			25 => 'warn',
			50 => 'down',
		);

		$out = array();
		foreach ( Config::REVEAL_SIZES as $pct ) {
			$out[] = array(
				'pct'    => $pct,
				'label'  => $pct . '%',
				'warn'   => 'rev_warn_' . $pct,
				'losses' => STC_Engine::losses_to_ruin( $pct * 100 ),
				'tone'   => $tone[ $pct ] ?? 'down',
				'grave'  => $pct >= 50,
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------- */

	/**
	 * `[hti_game name="stc|reveal"]`.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function render_game( $atts ): string {
		$atts = shortcode_atts(
			array( 'name' => Config::GAME_STC ),
			is_array( $atts ) ? $atts : array(),
			self::SC_GAME
		);

		$game = sanitize_key( (string) $atts['name'] );
		if ( ! Config::is_game( $game ) ) {
			$game = Config::GAME_STC;
		}

		$lang = self::lang();

		// The kill-switch: a case whose verification was withdrawn should be
		// able to leave the site in one click, and the page around it — which
		// is the part that ranks — should survive that click intact.
		if ( ! Settings::game_enabled( $game ) ) {
			return '<div class="hti-g hti-g--off"><p class="hti-g__empty">' . esc_html( Strings::get( 'st_no_content', $lang ) ) . '</p></div>';
		}

		return Config::GAME_STC === $game ? self::shell_stc( $lang ) : self::shell_reveal( $lang );
	}

	/**
	 * `[hti_games_hub]` — the two games, side by side, as ordinary links.
	 *
	 * Entirely static: the hub has nothing per-visitor on it, so it ships as
	 * HTML and the JavaScript only counts the view.
	 */
	public static function render_hub(): string {
		$lang     = self::lang();
		$settings = Settings::settings();

		$cards = '';
		foreach ( array(
			Config::GAME_STC    => array( 'stc_name', 'stc_tagline', 'stc' ),
			Config::GAME_REVEAL => array( 'rev_name', 'rev_tagline', 'reveal' ),
		) as $game => $card ) {
			if ( ! Settings::game_enabled( $game, $settings ) ) {
				continue;
			}
			$cards .= sprintf(
				'<li class="hti-hub__card hti-hub__card--%1$s"><h3 class="hti-hub__name"><a class="hti-hub__link" href="%2$s">%3$s</a></h3><p class="hti-hub__tag">%4$s</p><p class="hti-hub__go" aria-hidden="true">%5$s</p></li>',
				esc_attr( $game ),
				esc_url( Seeder::url( $card[2], $lang ) ),
				esc_html( Strings::get( $card[0], $lang ) ),
				esc_html( Strings::get( $card[1], $lang ) ),
				esc_html( Strings::get( 'cta_play_today', $lang ) )
			);
		}

		return '<section class="hti-g hti-hub" data-hti-hub>'
			. '<ul class="hti-hub__chips">'
			. '<li class="hti-hub__chip">' . esc_html( Strings::get( 'chip_no_signup', $lang ) ) . '</li>'
			. '<li class="hti-hub__chip">' . esc_html( Strings::get( 'chip_no_money', $lang ) ) . '</li>'
			. '<li class="hti-hub__chip">' . esc_html( Strings::get( 'chip_two_minutes', $lang ) ) . '</li>'
			. '</ul>'
			. '<ul class="hti-hub__cards">' . $cards . '</ul>'
			. '<p class="hti-hub__promise">' . esc_html( Strings::get( 'no_brokers', $lang ) ) . '</p>'
			. self::disclaimer( $lang )
			. '</section>';
	}

	/**
	 * `[hti_games_leaderboard]`.
	 *
	 * The tabs are real buttons and the empty state is the server-rendered
	 * default, so the page says something true before the rows arrive and
	 * keeps saying it if they never do.
	 */
	public static function render_board(): string {
		$lang     = self::lang();
		$settings = Settings::settings();

		if ( empty( $settings['leaderboard_enabled'] ) ) {
			return '<div class="hti-g hti-g--off"><p class="hti-g__empty">' . esc_html( Strings::get( 'st_no_content', $lang ) ) . '</p></div>';
		}

		$tabs = '';
		foreach ( array(
			'daily'    => 'board_today',
			'survival' => 'board_survival',
		) as $board => $key ) {
			$tabs .= sprintf(
				'<button type="button" class="hti-board__tab" role="tab" id="hti-board-tab-%1$s" aria-controls="hti-board-panel" aria-selected="%2$s" tabindex="%3$s" data-hti-board="%1$s">%4$s</button>',
				esc_attr( $board ),
				'daily' === $board ? 'true' : 'false',
				'daily' === $board ? '0' : '-1',
				esc_html( Strings::get( $key, $lang ) )
			);
		}

		$games = '';
		foreach ( array(
			Config::GAME_STC    => 'stc_name',
			Config::GAME_REVEAL => 'rev_name',
		) as $game => $key ) {
			if ( ! Settings::game_enabled( $game, $settings ) ) {
				continue;
			}
			$games .= sprintf(
				'<button type="button" class="hti-board__gtab" aria-pressed="%1$s" data-hti-bgame="%2$s">%3$s</button>',
				Config::GAME_STC === $game ? 'true' : 'false',
				esc_attr( $game ),
				esc_html( Strings::get( $key, $lang ) )
			);
		}

		return '<section class="hti-g hti-board" data-hti-board-mount>'
			. '<h2 class="hti-g__h2">' . esc_html( Strings::get( 'board_title', $lang ) ) . '</h2>'
			. '<div class="hti-board__tabs" role="tablist" aria-label="' . esc_attr( Strings::get( 'board_title', $lang ) ) . '">' . $tabs . '</div>'
			. '<div class="hti-board__gtabs">' . $games . '</div>'
			. '<div class="hti-board__panel" id="hti-board-panel" role="tabpanel" aria-labelledby="hti-board-tab-daily" tabindex="-1">'
			. '<p class="hti-board__head" data-hti="board-head">' . esc_html( Strings::get( 'board_score_head', $lang ) ) . '</p>'
			. '<ol class="hti-board__rows" data-hti="board-rows"></ol>'
			. '<div class="hti-board__empty" data-hti="board-empty">'
			. '<p class="hti-board__emptytitle">' . esc_html( Strings::get( 'board_empty', $lang ) ) . '</p>'
			. '<p class="hti-board__emptybody">' . esc_html( Strings::get( 'board_empty_body', $lang ) ) . '</p>'
			. '<p><a class="hti-g__btn hti-g__btn--primary" href="' . esc_url( Seeder::url( 'stc', $lang ) ) . '">' . esc_html( Strings::get( 'cta_play_today', $lang ) ) . '</a></p>'
			. '</div>'
			. '<div class="hti-board__me" data-hti="board-me" hidden></div>'
			. '</div>'
			. '<p class="hti-g__status" role="status" aria-live="polite" data-hti="board-status"></p>'
			. '<p class="hti-g__note">' . esc_html( Strings::get( 'board_score_note', $lang ) ) . '</p>'
			. '<p class="hti-g__note">' . esc_html( Strings::get( 'board_privacy', $lang ) ) . '</p>'
			. '<p class="hti-g__note">' . esc_html( Strings::get( 'board_reset', $lang ) ) . '</p>'
			. self::nickname_form( $lang )
			. self::disclaimer( $lang )
			. '</section>';
	}

	/**
	 * `[hti_games_profile]` — one player's own numbers, and their exit.
	 *
	 * The profile page is the one that is noindexed (Config::pages()), so it
	 * carries what its reader needs and nothing written for a crawler: the
	 * run, the learning metric, the calendar, and the two RGPD controls.
	 */
	public static function render_profile(): string {
		$lang     = self::lang();
		$settings = Settings::settings();

		$tabs = '';
		foreach ( array(
			Config::GAME_STC    => 'stc_name',
			Config::GAME_REVEAL => 'rev_name',
		) as $game => $key ) {
			if ( ! Settings::game_enabled( $game, $settings ) ) {
				continue;
			}
			$tabs .= sprintf(
				'<button type="button" class="hti-board__gtab" aria-pressed="%1$s" data-hti-pgame="%2$s">%3$s</button>',
				Config::GAME_STC === $game ? 'true' : 'false',
				esc_attr( $game ),
				esc_html( Strings::get( $key, $lang ) )
			);
		}

		return '<section class="hti-g hti-profile" data-hti-profile-mount>'
			. '<h2 class="hti-g__h2">' . esc_html( Strings::get( 'profile_title', $lang ) ) . '</h2>'
			. '<div class="hti-board__gtabs">' . $tabs . '</div>'
			. '<dl class="hti-profile__stats" data-hti="profile-stats">'
			. self::stat( 'capital_label', self::money( Config::CAPITAL_START, $lang ), 'capital', $lang )
			. self::stat( 'streak_label', '0', 'streak', $lang )
			. self::stat( 'record_label', '0', 'record', $lang )
			. self::stat( 'profile_win_rate', '—', 'winrate', $lang, 'profile_win_note' )
			. '</dl>'
			. '<section class="hti-profile__block">'
			. '<h3 class="hti-g__h3">' . esc_html( Strings::get( 'profile_risk', $lang ) ) . '</h3>'
			. '<div class="hti-profile__risk" data-hti="profile-risk"></div>'
			. '<p class="hti-g__note">' . esc_html( Strings::get( 'profile_risk_hint', $lang ) ) . '</p>'
			. '</section>'
			. '<section class="hti-profile__block">'
			. '<h3 class="hti-g__h3">' . esc_html( Strings::get( 'profile_calendar', $lang ) ) . '</h3>'
			. '<ol class="hti-profile__cal" data-hti="profile-cal"></ol>'
			. '</section>'
			. '<section class="hti-profile__block" data-hti="profile-badgeblock" hidden>'
			. '<h3 class="hti-g__h3">' . esc_html( Strings::get( 'profile_badges', $lang ) ) . '</h3>'
			. '<ul class="hti-profile__badges" data-hti="profile-badges"></ul>'
			. '</section>'
			. '<p class="hti-g__status" role="status" aria-live="polite" data-hti="profile-status"></p>'
			. self::nickname_form( $lang )
			. self::link_form( $lang )
			. self::forget_form( $lang )
			. self::disclaimer( $lang )
			. '</section>';
	}

	/* ---------------------------------------------------------------------
	 * The Survive the Charts shell
	 * ------------------------------------------------------------------- */

	/**
	 * Every phase of Survive the Charts, as HTML, with the inactive ones
	 * hidden.
	 *
	 * `hidden` and not `display:none` in a class: the attribute is what
	 * removes a phase from the accessibility tree as well as from the page,
	 * and it is the one thing both the browser and the screen reader agree on
	 * without a stylesheet having loaded.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function shell_stc( string $lang ): string {
		$labels = self::labels( $lang );

		$out = '<section class="hti-g hti-stc" id="hti-stc" data-hti-game="' . esc_attr( Config::GAME_STC ) . '">';

		$out .= self::hud( Config::GAME_STC, $lang );

		// Account health. The bar is decoration with a text equivalent beside
		// it, so it carries no ARIA of its own.
		$out .= '<div class="hti-g__health">'
			. '<div class="hti-g__bar"><span class="hti-g__fill" data-hti="survival"></span></div>'
			. '<p class="hti-g__healthrow"><span>' . esc_html( Strings::get( 'stc_survival', $lang ) ) . '</span>'
			. '<span class="hti-num" data-hti="fromstart"></span></p>'
			. '</div>';

		// The chart. The canvas is an image with a label, and never a control:
		// every button in this game is a real <button> outside it.
		$out .= '<div class="hti-stc__panel">'
			. '<div class="hti-stc__panelhead">'
			. '<h2 class="hti-g__h2" data-hti="charttitle">' . esc_html( Strings::get( 'stc_chart_decide', $lang ) ) . '</h2>'
			. '<span class="hti-g__pill" data-hti="charttag">' . esc_html( Strings::get( 'stc_chart_tag', $lang ) ) . '</span>'
			. '</div>'
			. '<div class="hti-stc__canvaswrap">'
			. '<canvas class="hti-stc__canvas" data-hti="canvas" role="img" aria-label="' . esc_attr( Strings::get( 'stc_name', $lang ) . ' — ' . Strings::get( 'stc_chart_tag', $lang ) ) . '"></canvas>'
			. '<span class="hti-stc__live" data-hti="live" hidden>' . esc_html( Strings::get( 'stc_chart_replay', $lang ) ) . '</span>'
			. '</div>'
			. self::chart_table( $lang, $labels )
			. '</div>';

		$out .= '<div class="hti-g__phases">';

		// Phase: decide.
		$out .= '<div class="hti-g__phase" data-hti-phase="decide">'
			. '<h3 class="hti-g__phasehead" tabindex="-1">' . esc_html( Strings::get( 'stc_chart_decide', $lang ) ) . '</h3>'
			. '<div class="hti-g__sides">'
			. '<button type="button" class="hti-g__choice hti-g__choice--up" data-hti-decide="buy">' . esc_html( Strings::get( 'stc_buy', $lang ) ) . '</button>'
			. '<button type="button" class="hti-g__choice hti-g__choice--down" data-hti-decide="sell">' . esc_html( Strings::get( 'stc_sell', $lang ) ) . '</button>'
			. '</div>'
			. '<button type="button" class="hti-g__choice hti-g__choice--pass" data-hti-decide="pass">' . esc_html( Strings::get( 'stc_pass', $lang ) ) . '</button>'
			. '</div>';

		// Phase: risk. The tiles are a radiogroup with roving tabindex — the
		// same pattern the questionnaire uses — because six mutually exclusive
		// options are a radio group whatever they are drawn as.
		$tiles = '';
		foreach ( self::risk_tiers( $lang ) as $i => $tier ) {
			$tiles .= sprintf(
				'<button type="button" class="hti-g__tile hti-g__tile--%1$s" role="radio" aria-checked="%2$s" tabindex="%3$s" data-hti-risk="%4$d"><span class="hti-g__tilelabel hti-num">%5$s</span><span class="hti-g__tilesub hti-num" data-hti-risk-amount="%4$d"></span></button>',
				esc_attr( $tier['tone'] ),
				200 === $tier['bp'] ? 'true' : 'false',
				200 === $tier['bp'] ? '0' : '-1',
				(int) $tier['bp'],
				esc_html( $tier['label'] )
			);
			unset( $i );
		}

		$out .= '<div class="hti-g__phase" data-hti-phase="risk" hidden>'
			. '<h3 class="hti-g__phasehead" tabindex="-1">' . esc_html( Strings::get( 'stc_risk_title', $lang ) ) . '</h3>'
			. '<div class="hti-g__tiles" role="radiogroup" aria-label="' . esc_attr( Strings::get( 'stc_risk_title', $lang ) ) . '" data-hti="risk-group">' . $tiles . '</div>'
			. '<p class="hti-g__warn" data-hti="risk-warn"></p>'
			. '<div class="hti-g__toggle">'
			. '<button type="button" class="hti-g__switch" role="switch" aria-checked="false" data-hti="double">'
			. '<span class="hti-g__switchtrack" aria-hidden="true"></span>'
			. '<span class="hti-g__switchtext"><span class="hti-g__switchlabel">' . esc_html( Strings::get( 'stc_double', $lang ) ) . '</span>'
			. '<span class="hti-g__switchnote">' . esc_html( Strings::get( 'stc_double_note', $lang ) ) . '</span></span>'
			. '</button>'
			. '</div>'
			. '<p class="hti-g__atrisk"><span>' . esc_html( Strings::get( 'stc_at_risk', $lang ) ) . '</span><span class="hti-num" data-hti="atrisk"></span></p>'
			. '<div class="hti-g__actions">'
			. '<button type="button" class="hti-g__btn hti-g__btn--ghost" data-hti="risk-back">' . esc_html( Strings::get( 'cta_back', $lang ) ) . '</button>'
			. '<button type="button" class="hti-g__btn hti-g__btn--primary" data-hti="risk-confirm">' . esc_html( Strings::get( 'stc_confirm', $lang ) ) . '</button>'
			. '</div>'
			. '</div>';

		// Phase: replay. "Skip to the result" is deliberately the first thing
		// in the DOM, so it is the first thing a keyboard reaches — WCAG 2.2.1
		// is satisfied by making the timing removable, not adjustable.
		$out .= '<div class="hti-g__phase" data-hti-phase="replay" hidden>'
			. '<button type="button" class="hti-g__btn hti-g__btn--ghost hti-g__skip" data-hti="skip">' . esc_html( Strings::get( 'stc_skip_replay', $lang ) ) . '</button>'
			. '<p class="hti-g__position"><span data-hti="position"></span><span class="hti-num" data-hti="position-risk"></span></p>'
			. '</div>';

		// Phase: result.
		$out .= '<div class="hti-g__phase" data-hti-phase="result" hidden>'
			. '<div class="hti-g__card" data-hti="result-card">'
			. '<p class="hti-g__kicker" data-hti="result-kicker"></p>'
			. '<h3 class="hti-g__resulttitle" tabindex="-1" data-hti="result-title"></h3>'
			. '<p class="hti-g__pnl hti-num" data-hti="result-pnl"></p>'
			. '<p class="hti-g__row"><span>' . esc_html( Strings::get( 'capital_label', $lang ) ) . '</span><span class="hti-num" data-hti="result-capital"></span></p>'
			. '<p class="hti-g__row" data-hti="crowd-row" hidden><span data-hti="crowd-label"></span><span class="hti-num" data-hti="crowd-value"></span></p>'
			. '</div>'
			. '<div class="hti-g__lesson" data-hti="lesson-block" hidden>'
			. '<p class="hti-g__kicker">' . esc_html( Strings::get( 'lesson_of_the_day', $lang ) ) . '</p>'
			. '<div class="hti-g__lessonbody" data-hti="lesson"></div>'
			. '</div>'
			. self::result_actions( $lang )
			. '<p class="hti-g__note hti-num" data-hti="reset"></p>'
			. '</div>';

		// Phase: dead.
		$out .= '<div class="hti-g__phase hti-g__phase--dead" data-hti-phase="dead" hidden>'
			. '<div class="hti-g__card hti-g__card--dead">'
			. '<p class="hti-g__kicker">' . esc_html( Strings::get( 'stc_dead_title', $lang ) ) . '</p>'
			. '<h3 class="hti-g__resulttitle" tabindex="-1" data-hti="dead-title"></h3>'
			. '<dl class="hti-g__deadrows">'
			. self::dead_row( 'stc_dead_days', 'dead-days', $lang )
			. self::dead_row( 'stc_dead_avg', 'dead-avg', $lang )
			. self::dead_row( 'record_label', 'dead-record', $lang )
			. '</dl>'
			. '<p class="hti-g__deadnote" data-hti="dead-counter"></p>'
			. self::size_tool( $lang )
			. '</div>'
			. self::dead_actions( $lang )
			. '</div>';

		$out .= '</div>'; // .hti-g__phases

		$out .= '<p class="hti-g__status" role="status" aria-live="polite" data-hti="say"></p>';
		$out .= self::rules( $lang, 'stc_ob2_title', array( 'stc_ob2_r1', 'stc_ob2_r2', 'stc_ob2_r3', 'stc_ob2_r4' ) );
		$out .= self::disclaimer( $lang );
		$out .= self::noscript( $lang );
		$out .= '</section>';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * The Reveal shell
	 * ------------------------------------------------------------------- */

	/**
	 * Every phase of The Reveal, as HTML.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function shell_reveal( string $lang ): string {
		$out = '<section class="hti-g hti-rv" id="hti-reveal" data-hti-game="' . esc_attr( Config::GAME_REVEAL ) . '">';

		$out .= self::hud( Config::GAME_REVEAL, $lang );

		$out .= '<div class="hti-g__phases">';

		// Phase: the dossier.
		$out .= '<div class="hti-g__phase" data-hti-phase="dossier">'
			. '<article class="hti-rv__file">'
			. '<div class="hti-rv__tape" aria-hidden="true"></div>'
			. '<div class="hti-rv__filehead">'
			. '<p class="hti-g__kicker hti-num" data-hti="dossier"></p>'
			. '<h2 class="hti-rv__unnamed" tabindex="-1">' . esc_html( Strings::get( 'rev_unnamed', $lang ) ) . '</h2>'
			. '<p class="hti-rv__stamp" aria-hidden="true">' . esc_html( Strings::get( 'rev_confidential', $lang ) ) . '</p>'
			. '</div>'
			. '<dl class="hti-rv__meta">'
			. '<div class="hti-rv__metacell"><dt>' . esc_html( Strings::get( 'rev_sector', $lang ) ) . '</dt><dd data-hti="sector"></dd></div>'
			. '<div class="hti-rv__metacell"><dt>' . esc_html( Strings::get( 'rev_revenue', $lang ) ) . '</dt><dd data-hti="revenue"></dd></div>'
			. '</dl>'
			. '<h3 class="hti-rv__sub">' . esc_html( Strings::get( 'rev_fundamentals', $lang ) ) . '</h3>'
			. '<table class="hti-rv__fund">'
			. '<caption class="hti-g__sr">' . esc_html( Strings::get( 'rev_fundamentals', $lang ) ) . '</caption>'
			. '<tbody data-hti="fundamentals"></tbody>'
			. '</table>'
			. '<h3 class="hti-rv__sub">' . esc_html( Strings::get( 'rev_headlines', $lang ) ) . '</h3>'
			. '<ul class="hti-rv__heads" data-hti="headlines"></ul>'
			. '</article>'
			. '<div class="hti-g__sides">'
			. '<button type="button" class="hti-g__choice hti-g__choice--pass" data-hti-decide="pass">' . esc_html( Strings::get( 'rev_pass', $lang ) ) . '</button>'
			. '<button type="button" class="hti-g__choice hti-g__choice--invest" data-hti-decide="invest">' . esc_html( Strings::get( 'rev_invest', $lang ) ) . '</button>'
			. '</div>'
			. '</div>';

		// Phase: size.
		$tiles = '';
		foreach ( self::size_tiers() as $tier ) {
			$tiles .= sprintf(
				'<button type="button" class="hti-g__tile hti-g__tile--%1$s" role="radio" aria-checked="%2$s" tabindex="%3$s" data-hti-size="%4$d"><span class="hti-g__tilelabel hti-num">%5$s</span><span class="hti-g__tilesub hti-num" data-hti-size-amount="%4$d"></span></button>',
				esc_attr( $tier['tone'] ),
				10 === $tier['pct'] ? 'true' : 'false',
				10 === $tier['pct'] ? '0' : '-1',
				(int) $tier['pct'],
				esc_html( $tier['label'] )
			);
		}

		$out .= '<div class="hti-g__phase" data-hti-phase="size" hidden>'
			. '<h3 class="hti-g__phasehead" tabindex="-1">' . esc_html( Strings::get( 'rev_size_title', $lang ) ) . '</h3>'
			. '<div class="hti-g__tiles" role="radiogroup" aria-label="' . esc_attr( Strings::get( 'rev_size_title', $lang ) ) . '" data-hti="size-group">' . $tiles . '</div>'
			. '<p class="hti-g__warn" data-hti="size-warn"></p>'
			. '<div class="hti-g__actions">'
			. '<button type="button" class="hti-g__btn hti-g__btn--ghost" data-hti="size-back">' . esc_html( Strings::get( 'cta_back', $lang ) ) . '</button>'
			. '<button type="button" class="hti-g__btn hti-g__btn--primary" data-hti="size-confirm"></button>'
			. '</div>'
			. '</div>';

		// Phase: the reveal itself. Skippable, and skipped outright under
		// prefers-reduced-motion.
		$out .= '<div class="hti-g__phase hti-rv__stage" data-hti-phase="reveal" hidden>'
			. '<button type="button" class="hti-g__btn hti-g__btn--ghost hti-g__skip" data-hti="skip">' . esc_html( Strings::get( 'cta_skip', $lang ) ) . '</button>'
			. '<p class="hti-rv__name" data-hti="reveal-name"></p>'
			. '<p class="hti-rv__year hti-num" data-hti="reveal-year"></p>'
			. '<p class="hti-rv__count hti-num" data-hti="reveal-count"></p>'
			. '</div>';

		// Phase: result — the three lines.
		$out .= '<div class="hti-g__phase" data-hti-phase="result" hidden>'
			. '<div class="hti-g__card">'
			. '<p class="hti-g__kicker" data-hti="result-kicker"></p>'
			. '<h3 class="hti-g__resulttitle" tabindex="-1" data-hti="result-title"></h3>'
			. '<p class="hti-g__pnl hti-num" data-hti="result-pnl"></p>'
			. '<div class="hti-g__context" data-hti="context"></div>'
			. '</div>'
			. '<div class="hti-rv__lines">'
			. '<h3 class="hti-g__h3">' . esc_html( Strings::get( 'rev_three_lines', $lang ) ) . '</h3>'
			. '<dl class="hti-rv__linerows" data-hti="lines"></dl>'
			. '</div>'
			. '<div class="hti-g__lesson" data-hti="lesson-block" hidden>'
			. '<p class="hti-g__kicker">' . esc_html( Strings::get( 'lesson_of_the_day', $lang ) ) . '</p>'
			. '<div class="hti-g__lessonbody" data-hti="lesson"></div>'
			. '</div>'
			. '<p class="hti-g__row" data-hti="crowd-row" hidden><span data-hti="crowd-label"></span><span class="hti-num" data-hti="crowd-value"></span></p>'
			. '<p class="hti-g__note" data-hti="source"></p>'
			. self::result_actions( $lang )
			. '<p class="hti-g__note hti-num" data-hti="reset"></p>'
			. '<p class="hti-g__note">' . esc_html( Strings::get( 'rev_historical', $lang ) ) . '</p>'
			. '</div>';

		// Phase: dead.
		$out .= '<div class="hti-g__phase hti-g__phase--dead" data-hti-phase="dead" hidden>'
			. '<div class="hti-g__card hti-g__card--dead">'
			. '<p class="hti-g__kicker">' . esc_html( Strings::get( 'rev_dead_title', $lang ) ) . '</p>'
			. '<h3 class="hti-g__resulttitle" tabindex="-1" data-hti="dead-title"></h3>'
			. '<dl class="hti-g__deadrows">'
			. self::dead_row( 'stc_dead_days', 'dead-days', $lang )
			. self::dead_row( 'rev_dead_avg', 'dead-avg', $lang )
			. self::dead_row( 'rev_dead_index', 'dead-index', $lang )
			. self::dead_row( 'record_label', 'dead-record', $lang )
			. '</dl>'
			. '</div>'
			. self::dead_actions( $lang )
			. '</div>';

		$out .= '</div>'; // .hti-g__phases

		$out .= '<p class="hti-g__status" role="status" aria-live="polite" data-hti="say"></p>';
		$out .= self::rules( $lang, 'rev_ob2_title', array( 'rev_ob2_r1', 'rev_ob2_r2', 'rev_ob2_r3', 'rev_ob2_r4' ) );
		$out .= self::disclaimer( $lang );
		$out .= self::noscript( $lang );
		$out .= '</section>';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Shared fragments
	 * ------------------------------------------------------------------- */

	/**
	 * The sticky head: what this is, whose capital, what streak.
	 *
	 * The figures are the neutral defaults — see the file docblock on why a
	 * cached page may never carry a visitor's own numbers.
	 *
	 * @param string $game Game id.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function hud( string $game, string $lang ): string {
		$name = Config::GAME_STC === $game ? 'stc_name' : 'rev_name';

		$out = '<header class="hti-g__hud">'
			. '<div class="hti-g__brand">'
			. '<p class="hti-g__title">' . esc_html( Strings::get( $name, $lang ) ) . '</p>'
			. '<p class="hti-g__sub">' . esc_html( Strings::get( 'disclaimer_short', $lang ) ) . '</p>'
			. '</div>'
			. '<dl class="hti-g__meters">'
			. '<div class="hti-g__meter"><dt>' . esc_html( Strings::get( 'capital_label', $lang ) ) . '</dt>'
			. '<dd class="hti-num" data-hti="capital">' . esc_html( self::money( Config::CAPITAL_START, $lang ) ) . '</dd></div>';

		if ( Config::GAME_REVEAL === $game ) {
			$out .= '<div class="hti-g__meter"><dt>' . esc_html( Strings::get( 'rev_index_label', $lang ) ) . '</dt>'
				. '<dd class="hti-num" data-hti="index">' . esc_html( self::money( Config::CAPITAL_START, $lang ) ) . '</dd></div>';
		}

		$out .= '<div class="hti-g__meter hti-g__meter--streak"><dt>' . esc_html( Strings::get( 'streak_label', $lang ) ) . '</dt>'
			. '<dd class="hti-num" data-hti="streak">0</dd></div>'
			. '</dl>'
			. '</header>';

		return $out;
	}

	/**
	 * The chart's text equivalent: the same five numbers the picture carries.
	 *
	 * Visually hidden rather than absent, because the point is that a reader
	 * who cannot see the canvas gets the levels and the outcome — and, as a
	 * side effect, that anybody can select and paste them.
	 *
	 * @param string                $lang   'en' or 'pt'.
	 * @param array<string,string>  $labels Local label table.
	 */
	private static function chart_table( string $lang, array $labels ): string {
		$rows = array(
			'entry'   => $labels['lbl_entry'],
			'stop'    => $labels['lbl_stop'],
			'target'  => $labels['lbl_target'],
			'outcome' => $labels['lbl_outcome'],
			'pnl'     => $labels['lbl_pnl'],
		);

		$body = '';
		foreach ( $rows as $key => $label ) {
			$body .= '<tr><th scope="row">' . esc_html( $label ) . '</th>'
				. '<td class="hti-num" data-hti="tbl-' . esc_attr( $key ) . '">—</td></tr>';
		}

		return '<table class="hti-g__sr" data-hti="chart-table">'
			. '<caption>' . esc_html( Strings::get( 'stc_chart_decide', $lang ) ) . '</caption>'
			. '<tbody>' . $body . '</tbody></table>';
	}

	/**
	 * One label/value pair in the death report.
	 *
	 * @param string $key  Strings key for the label.
	 * @param string $hook `data-hti` hook for the value.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function dead_row( string $key, string $hook, string $lang ): string {
		return '<div class="hti-g__deadrow"><dt>' . esc_html( Strings::get( $key, $lang ) ) . '</dt>'
			. '<dd class="hti-num" data-hti="' . esc_attr( $hook ) . '">—</dd></div>';
	}

	/**
	 * One statistic tile on the profile.
	 *
	 * @param string $key  Strings key for the label.
	 * @param string $seed Placeholder value.
	 * @param string $hook `data-hti` hook.
	 * @param string $lang 'en' or 'pt'.
	 * @param string $note Optional Strings key for a footnote.
	 */
	private static function stat( string $key, string $seed, string $hook, string $lang, string $note = '' ): string {
		$out = '<div class="hti-profile__stat"><dt>' . esc_html( Strings::get( $key, $lang ) ) . '</dt>'
			. '<dd class="hti-num" data-hti="stat-' . esc_attr( $hook ) . '">' . esc_html( $seed ) . '</dd>';
		if ( '' !== $note ) {
			$out .= '<dd class="hti-profile__statnote">' . esc_html( Strings::get( $note, $lang ) ) . '</dd>';
		}
		return $out . '</div>';
	}

	/**
	 * Share and next-day, the two buttons every result ends on.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function result_actions( string $lang ): string {
		$share = ! empty( Settings::settings()['share_enabled'] )
			? '<button type="button" class="hti-g__btn hti-g__btn--ghost" data-hti="share">' . esc_html( Strings::get( 'cta_share', $lang ) ) . '</button>'
			: '';

		return '<div class="hti-g__actions">' . $share
			. '<button type="button" class="hti-g__btn hti-g__btn--primary" data-hti="next">' . esc_html( Strings::get( 'cta_next_day', $lang ) ) . '</button>'
			. '</div>';
	}

	/**
	 * Share and start-again, on the death screen.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function dead_actions( string $lang ): string {
		$share = ! empty( Settings::settings()['share_enabled'] )
			? '<button type="button" class="hti-g__btn hti-g__btn--ghost" data-hti="share">' . esc_html( Strings::get( 'cta_share', $lang ) ) . '</button>'
			: '';

		return '<div class="hti-g__actions">' . $share
			. '<button type="button" class="hti-g__btn hti-g__btn--primary" data-hti="next">' . esc_html( Strings::get( 'cta_start_again', $lang ) ) . '</button>'
			. '</div>';
	}

	/**
	 * The "see what position size does" line on the death screen.
	 *
	 * Rendered as plain text unless a site explicitly supplies a destination.
	 * The obvious destination is the position-size calculator in the /forex/
	 * section, and /forex/ pages carry partner CTAs — so the games do not link
	 * there by default. The filter leaves the decision with the site owner
	 * rather than baking a route out of a section that is sealed on purpose.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function size_tool( string $lang ): string {
		$label = Strings::get( 'stc_dead_tool', $lang );

		/**
		 * Filter the destination of the death screen's position-size link.
		 *
		 * Empty (the default) renders the line as text and links nowhere.
		 *
		 * @param string $url  Destination, '' for none.
		 * @param string $lang 'en' or 'pt'.
		 */
		$url = (string) apply_filters( 'hti_games_size_tool_url', '', $lang );

		if ( '' === $url ) {
			return '<p class="hti-g__deadtool">' . esc_html( $label ) . '</p>';
		}

		return '<p class="hti-g__deadtool"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * The rules, collapsed.
	 *
	 * In the DOM on every render — a crawler and a reader without JavaScript
	 * both get them — but folded away, because the page around the game
	 * already sets them out at length and a player mid-run wants the chart.
	 *
	 * @param string            $lang    'en' or 'pt'.
	 * @param string            $summary Strings key for the summary.
	 * @param array<int,string> $keys    Strings keys of the rules.
	 */
	private static function rules( string $lang, string $summary, array $keys ): string {
		$items = '';
		foreach ( $keys as $key ) {
			$items .= '<li>' . esc_html( Strings::get( $key, $lang ) ) . '</li>';
		}

		return '<details class="hti-g__rules">'
			. '<summary>' . esc_html( Strings::get( $summary, $lang ) ) . '</summary>'
			. '<ol class="hti-g__ruleslist">' . $items . '</ol>'
			. '</details>';
	}

	/**
	 * The nickname form — the only way onto a public board.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function nickname_form( string $lang ): string {
		return '<form class="hti-g__form" data-hti="nick-form" hidden>'
			. '<h3 class="hti-g__h3"><label for="hti-g-nick">' . esc_html( Strings::get( 'nick_title', $lang ) ) . '</label></h3>'
			. '<p class="hti-g__note" id="hti-g-nick-note">' . esc_html( Strings::get( 'nick_note', $lang ) ) . '</p>'
			. '<div class="hti-g__field">'
			. '<input type="text" id="hti-g-nick" name="nickname" class="hti-g__input" maxlength="24" autocomplete="off"'
			. ' aria-describedby="hti-g-nick-note hti-g-nick-err" data-hti="nick-input" />'
			. '<button type="submit" class="hti-g__btn hti-g__btn--primary">' . esc_html( Strings::get( 'ob_accept', $lang ) ) . '</button>'
			. '</div>'
			. '<p class="hti-g__err" id="hti-g-nick-err" role="alert" data-hti="nick-err"></p>'
			. '</form>';
	}

	/**
	 * The magic-link form. Hidden entirely when the owner has switched the
	 * email link off.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function link_form( string $lang ): string {
		$settings = Settings::settings();
		if ( empty( $settings['email_link_enabled'] ) ) {
			return '';
		}

		$news = ! empty( $settings['newsletter_optin'] )
			? '<p class="hti-g__check"><label><input type="checkbox" name="newsletter" data-hti="link-news" /> <span>' . esc_html( Strings::get( 'news_optin', $lang ) ) . '</span></label></p>'
			: '';

		return '<form class="hti-g__form" data-hti="link-form">'
			. '<h3 class="hti-g__h3"><label for="hti-g-email">' . esc_html( Strings::get( 'link_title', $lang ) ) . '</label></h3>'
			. '<p class="hti-g__note" id="hti-g-email-note">' . esc_html( Strings::get( 'link_body', $lang ) ) . '</p>'
			. '<div class="hti-g__field">'
			. '<input type="email" id="hti-g-email" name="email" class="hti-g__input" autocomplete="email"'
			. ' aria-describedby="hti-g-email-note hti-g-email-err" data-hti="link-input" />'
			. '<button type="submit" class="hti-g__btn hti-g__btn--primary">' . esc_html( Strings::get( 'link_send', $lang ) ) . '</button>'
			. '</div>'
			// The honeypot. Off-screen, hidden from the accessibility tree and
			// out of the tab order, so it carries no label: a bot fills every
			// field it finds and nobody else can find this one.
			. '<p class="hti-g__hp" aria-hidden="true">'
			. '<input type="text" name="hti_hp" tabindex="-1" autocomplete="off" data-hti="link-hp" /></p>'
			. $news
			. '<p class="hti-g__err" id="hti-g-email-err" role="alert" data-hti="link-err"></p>'
			. '</form>';
	}

	/**
	 * The RGPD exit. A real button, always present, never behind a menu.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function forget_form( string $lang ): string {
		return '<div class="hti-g__form hti-g__form--danger">'
			. '<h3 class="hti-g__h3">' . esc_html( Strings::get( 'forget_me', $lang ) ) . '</h3>'
			. '<p class="hti-g__note" id="hti-g-forget-note">' . esc_html( Strings::get( 'forget_note', $lang ) ) . '</p>'
			. '<button type="button" class="hti-g__btn hti-g__btn--danger" aria-describedby="hti-g-forget-note" data-hti="forget">'
			. esc_html( Strings::get( 'forget_me', $lang ) ) . '</button>'
			. '</div>';
	}

	/**
	 * The disclaimer. Every screen carries it; nothing here is ever the
	 * shorter version alone.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function disclaimer( string $lang ): string {
		return '<p class="hti-g__disclaimer">' . esc_html( Strings::get( 'disclaimer_full', $lang ) ) . '</p>';
	}

	/**
	 * The no-JavaScript notice.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function noscript( string $lang ): string {
		$labels = self::labels( $lang );
		return '<noscript><p class="hti-g__noscript">' . esc_html( $labels['needs_js'] ) . '</p></noscript>';
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------- */

	/**
	 * A whole-dollar amount, written the way each language writes it — the
	 * Portuguese copy in Strings uses "10 000 $", not "$10,000". Same
	 * formatting as Seeder::money(), and games-shared.js mirrors it.
	 *
	 * @param int    $amount Whole dollars.
	 * @param string $lang   'en' or 'pt'.
	 */
	public static function money( int $amount, string $lang ): string {
		return 'pt' === $lang
			? number_format( $amount, 0, ',', ' ' ) . ' $'
			: '$' . number_format( $amount );
	}

	/**
	 * A basis-point tier as the percentage a player reads. Pure.
	 *
	 * 50 → "0.5%" / "0,5%"; 200 → "2%". The decimal is only shown when there
	 * is one, so five of the six tiers stay whole numbers.
	 *
	 * @param int    $bp   Basis points.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function pct_label( int $bp, string $lang ): string {
		if ( 0 === $bp % 100 ) {
			return intdiv( $bp, 100 ) . '%';
		}
		return number_format( $bp / 100, 1, 'pt' === $lang ? ',' : '.', '' ) . '%';
	}

	/**
	 * The nine accessibility labels the copy table does not carry yet.
	 *
	 * Both languages side by side, exactly as Strings does it, and for the
	 * same reason: a gap shows up in the diff instead of at runtime. They are
	 * here rather than in Strings so this file does not have to edit a file it
	 * does not own; they belong there.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<string,string>
	 */
	public static function labels( string $lang ): array {
		$table = array(
			'needs_js'    => array(
				'en' => 'This game needs JavaScript to run. The rules, the lesson and the disclaimer are on this page either way — turn JavaScript on to play today’s challenge.',
				'pt' => 'Este jogo precisa de JavaScript para funcionar. As regras, a lição e o aviso estão nesta página de qualquer forma — ativa o JavaScript para jogar o desafio de hoje.',
			),
			'lbl_entry'   => array(
				'en' => 'Entry',
				'pt' => 'Entrada',
			),
			'lbl_stop'    => array(
				'en' => 'Stop',
				'pt' => 'Stop',
			),
			'lbl_target'  => array(
				'en' => 'Target',
				'pt' => 'Alvo',
			),
			'lbl_outcome' => array(
				'en' => 'Outcome',
				'pt' => 'Resultado',
			),
			'lbl_pnl'     => array(
				'en' => 'Result in dollars',
				'pt' => 'Resultado em dólares',
			),
			'lbl_rank'    => array(
				'en' => 'Position',
				'pt' => 'Posição',
			),
			'lbl_player'  => array(
				'en' => 'Player',
				'pt' => 'Jogador',
			),
			'lbl_capital' => array(
				'en' => 'Capital',
				'pt' => 'Capital',
			),
		);

		$lang = in_array( $lang, Strings::LANGS, true ) ? $lang : 'en';
		$out  = array();
		foreach ( $table as $key => $pair ) {
			$out[ $key ] = $pair[ $lang ] ?? $pair['en'];
		}

		return $out;
	}
}
