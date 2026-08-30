<?php
/**
 * Seeds and syncs the /games/ section: the hub, the two game pages, the
 * leaderboard and the player profile, as ordinary bilingual WordPress pages
 * whose block markup wraps the Frontend shortcodes.
 *
 * Same architecture as HTI\Forex\Seeder — pages are upserted (matched by
 * path) under a per-page content hash, so an unchanged page is skipped
 * entirely: no revision, no post_modified churn, and an owner's unrelated
 * editor changes survive a deploy. A cheap deploy gate schedules one
 * background sync when this file changes, and auto mode only UPDATES pages
 * that already exist. Creating them stays a deliberate owner action (the
 * admin button or `wp hti-games seed`), because a plugin that silently
 * publishes five pages on a site is a plugin nobody trusts on a deploy.
 *
 * Two things differ from hti-forex, which is English-only:
 *
 * 1. Every page is a pair. The EN page is authoritative and the PT page is
 *    linked to it through Polylang, with its own slug from Config::pages().
 *    Every pll_* call is guarded with function_exists() (project rule): with
 *    Polylang inactive the section still seeds, English-only, rather than
 *    fataling.
 * 2. The copy is bilingual and comes from a table, never from __(). The site
 *    runs pt_PT_ao90 against pt_PT translation files and WordPress does not
 *    fall back between them, so a missing __() translation renders in English
 *    on a Portuguese page without warning anybody. Strings::get() supplies
 *    everything the game screens already say; copy() below adds the
 *    page-level editorial prose, in the same both-languages-side-by-side
 *    shape so a gap shows up in the diff and in tests/test-seeder.php.
 *
 * WHY THE EDITORIAL HALF EXISTS — do not strip it as "just marketing".
 * A page whose whole body is a <canvas> and a shortcode is a thin page: it
 * gives a crawler nothing to read, nothing to rank and nothing to quote, and
 * it would deserve the noindex it would eventually earn. The prose either
 * side of the game mount — what the game teaches, how a day works, the rules,
 * the stat tiles, the FAQ and the disclaimer — is what makes these pages
 * indexable at all, and the FAQ is the same array the FAQPage JSON-LD is
 * built from, so page and schema cannot disagree. Removing it does not
 * "clean up" the page; it deletes the reason the page can rank.
 *
 * The landing claim on the Survive the Charts page is chosen by
 * Library::is_real(), never by a setting. A checkbox saying "the charts are
 * real" stays ticked long after somebody tops the pool up with generated
 * scenarios on a busy day — i.e. it is a false claim with a plausible excuse.
 * Deriving it from the data means the sentence can only be on the page while
 * it is true.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Upserting bilingual page seeder (admin button, WP-CLI, deploy sync).
 */
class Seeder {

	/**
	 * Marks a page as ours, and records the plugin version that wrote it.
	 */
	private const SEED_FLAG = '_hti_games_seeded';

	/**
	 * Meta key holding the hash of the repo content last written to a page.
	 * When it matches the current build the page is skipped untouched.
	 */
	private const HASH_META = '_hti_games_synced_hash';

	/**
	 * Cron hook fired once, shortly after a deploy changes this file.
	 */
	public const HOOK = 'hti_games_content_sync';

	/**
	 * Option holding the last observed content signature.
	 */
	private const OPTION_SIG = 'hti_games_sync_sig';

	/**
	 * Option holding the last sync report, for the admin panel.
	 */
	public const OPTION_LAST = 'hti_games_last_sync';

	/**
	 * Transient throttling the filesystem check to once per interval.
	 */
	private const THROTTLE = 'hti_games_sync_checked';

	/**
	 * Polylang's URL prefix for the Portuguese tree.
	 *
	 * Root-relative internal links (rather than home_url() calls) are what let
	 * plan() stay pure and fully assertable without a database. The site lives
	 * at the domain root; a subdirectory install would need home_url() here
	 * and would lose that property.
	 */
	private const PT_PREFIX = 'pt';

	/**
	 * Theme template the seeded pages use. The games are wide interactive
	 * surfaces, so the sidebar layout would squeeze the chart.
	 */
	public const TEMPLATE = 'page-no-sidebar';

	/**
	 * Hook the admin surface, the deploy gate and the profile noindex.
	 */
	public static function init(): void {
		add_action( 'hti_games_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_hti_games_seed', array( __CLASS__, 'handle_form' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'run_auto' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::register_cli();
		}
	}

	/* ---------------------------------------------------------------------
	 * Robots
	 * ------------------------------------------------------------------- */

	/**
	 * Keep the non-indexable pages out of the index, following their links.
	 *
	 * Driven by the `index` flag in Config::pages() rather than by a hardcoded
	 * slug, so the decision lives in one table. Today that is the player
	 * profile: one visitor's own capital, streak and calendar is a different
	 * page for every reader and a thin one for all of them — nothing to rank,
	 * and a crawl budget spent on nobody. `follow` stays on so the links out
	 * of it (the games, the board) still carry.
	 *
	 * Same shape as HTI\Engine\Subscribe::robots() for the confirmation page.
	 *
	 * Which page this is comes from Schema::detect_page() and not from a
	 * second reading of the meta here. The two used to differ: the schema
	 * fell back to sniffing the shortcode when the meta was absent and this
	 * did not, so a profile page an editor had rebuilt by hand correctly
	 * emitted no JSON-LD and was indexed anyway. One detector, one answer.
	 *
	 * @param array<string,bool|string> $robots Robots directives.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( ! is_page() ) {
			return $robots;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $robots;
		}
		$page = Schema::detect_page( $post );
		$defs = Config::pages();
		if ( '' === $page || ! isset( $defs[ $page ] ) || false !== $defs[ $page ]['index'] ) {
			return $robots;
		}

		unset( $robots['index'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	}

	/* ---------------------------------------------------------------------
	 * Deploy detection (mirrors hti-forex's gate, scoped to the games).
	 * ------------------------------------------------------------------- */

	/**
	 * Cheap gate, run on init at most once per 10 minutes: when the content
	 * signature no longer matches the stored one, record it and schedule one
	 * background sync event.
	 */
	public static function maybe_schedule(): void {
		if ( false !== get_transient( self::THROTTLE ) ) {
			return;
		}
		set_transient( self::THROTTLE, 1, 10 * MINUTE_IN_SECONDS );

		$sig = self::signature();
		if ( (string) get_option( self::OPTION_SIG ) === $sig ) {
			return;
		}
		update_option( self::OPTION_SIG, $sig, false );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Signature of everything the page content is built from: this file, the
	 * page table and the copy table, keyed by mtime|size, plus the plugin
	 * version. The cPanel deploy rewrites files, so a deploy always moves an
	 * mtime even when the bytes are identical — which costs one no-op sync
	 * and is the cheap side of the trade.
	 */
	public static function signature(): string {
		$entries = array();
		foreach ( array( __FILE__, __DIR__ . '/class-config.php', __DIR__ . '/class-strings.php' ) as $path ) {
			$entries[] = is_readable( $path )
				? $path . '|' . (int) filemtime( $path ) . '|' . (int) filesize( $path )
				: $path . '|missing';
		}
		sort( $entries );
		return md5( implode( "\n", $entries ) . "\n" . VERSION );
	}

	/**
	 * Background sync: update-only, so a site where the section was never
	 * seeded (or where the owner deleted a page) is left alone.
	 */
	public static function run_auto(): void {
		self::seed( false, 'auto' );
	}

	/* ---------------------------------------------------------------------
	 * The plan. Pure: no database, no options, no hooks — only the two pure
	 * config classes and the escaping helpers. Every assertion about slugs,
	 * titles, meta descriptions and content can therefore run in the harness.
	 * ------------------------------------------------------------------- */

	/**
	 * The full page plan, in seed order (the hub first, so children can hang
	 * off it).
	 *
	 * @param bool $stc_real Whether the Survive the Charts pool is entirely
	 *                       real market data — Library::is_real( 'stc' ) at
	 *                       the call site. Passed in rather than read here so
	 *                       the plan stays pure; false is the conservative
	 *                       claim, which is the right default for a caller
	 *                       that cannot answer.
	 * @return array<string,array<string,mixed>>
	 */
	public static function plan( bool $stc_real = false ): array {
		$plan = array();

		foreach ( Config::pages() as $key => $page ) {
			$def = array(
				'key'       => $key,
				'parent'    => $page['parent'],
				'index'     => $page['index'],
				'slug'      => array(
					'en' => $page['en'],
					'pt' => $page['pt'],
				),
				'path'      => array(
					'en' => self::path( $key, 'en' ),
					'pt' => self::path( $key, 'pt' ),
				),
				'title'     => array(),
				'seo_title' => array(),
				'seo_desc'  => array(),
				'content'   => array(),
				'faqs'      => array(),
				'hash'      => array(),
			);

			foreach ( Strings::LANGS as $lang ) {
				$def['title'][ $lang ]     = self::c( $key . '_title', $lang );
				$def['seo_title'][ $lang ] = self::c( $key . '_seo_title', $lang );
				$def['seo_desc'][ $lang ]  = self::c( $key . '_seo_desc', $lang );
				$def['faqs'][ $lang ]      = self::faqs( $key, $lang, $stc_real );
				$def['content'][ $lang ]   = self::content( $key, $lang, $stc_real );
			}

			foreach ( Strings::LANGS as $lang ) {
				$def['hash'][ $lang ] = self::sync_hash( $def, $lang );
			}

			$plan[ $key ] = $def;
		}

		return $plan;
	}

	/**
	 * The hierarchical path of one page in one language, without leading or
	 * trailing slashes — the form get_page_by_path() wants.
	 *
	 * Slugs come from Config::pages() and never from a literal here: the page
	 * table is the only place a path is decided.
	 *
	 * @param string $key  Page key.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function path( string $key, string $lang ): string {
		$pages = Config::pages();
		if ( ! isset( $pages[ $key ] ) ) {
			return '';
		}
		$lang = in_array( $lang, Strings::LANGS, true ) ? $lang : 'en';

		$parts  = array( $pages[ $key ][ $lang ] );
		$parent = $pages[ $key ]['parent'];
		while ( null !== $parent && isset( $pages[ $parent ] ) ) {
			array_unshift( $parts, $pages[ $parent ][ $lang ] );
			$parent = $pages[ $parent ]['parent'];
		}

		return implode( '/', $parts );
	}

	/**
	 * The root-relative URL of one page, for internal links inside the copy.
	 *
	 * @param string $key  Page key.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function url( string $key, string $lang ): string {
		$path = self::path( $key, $lang );
		if ( '' === $path ) {
			return '/';
		}
		return 'pt' === $lang ? '/' . self::PT_PREFIX . '/' . $path . '/' : '/' . $path . '/';
	}

	/**
	 * Deterministic hash of the fields the sync manages, for one language.
	 * Pure, and the reason an unchanged page costs one meta read.
	 *
	 * @param array<string,mixed> $def  Page definition from plan().
	 * @param string              $lang 'en' or 'pt'.
	 */
	public static function sync_hash( array $def, string $lang ): string {
		return md5(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure helper, must run without WordPress.
				array(
					'key'       => (string) ( $def['key'] ?? '' ),
					'lang'      => $lang,
					'title'     => (string) ( $def['title'][ $lang ] ?? '' ),
					'content'   => (string) ( $def['content'][ $lang ] ?? '' ),
					'seo_title' => (string) ( $def['seo_title'][ $lang ] ?? '' ),
					'seo_desc'  => (string) ( $def['seo_desc'][ $lang ] ?? '' ),
				)
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Upsert
	 * ------------------------------------------------------------------- */

	/**
	 * Upsert every page in both languages. Safe to re-run: a page whose hash
	 * still matches is skipped without a write.
	 *
	 * @param bool   $create_missing Create pages that don't exist (manual
	 *                               seed). False in auto mode: update-only.
	 * @param string $mode           'manual' or 'auto', recorded in the report.
	 * @return array{created:int,updated:int,unchanged:int,missing:int,translated:int,mode:string,time:string}
	 */
	public static function seed( bool $create_missing = true, string $mode = 'manual' ): array {
		$report = array(
			'created'    => 0,
			'updated'    => 0,
			'unchanged'  => 0,
			'missing'    => 0,
			'translated' => 0,
			'mode'       => 'auto' === $mode ? 'auto' : 'manual',
			'time'       => gmdate( 'Y-m-d H:i' ),
		);

		$plan = self::plan( self::stc_is_real() );
		$ids  = array(
			'en' => array(),
			'pt' => array(),
		);

		// The hub first: everything else is its child, and a child inserted
		// before its parent exists would land at the top level with the wrong
		// permalink and stay there.
		foreach ( $plan as $key => $def ) {
			$parent_en = null === $def['parent'] ? 0 : ( $ids['en'][ $def['parent'] ] ?? 0 );
			$parent_pt = null === $def['parent'] ? 0 : ( $ids['pt'][ $def['parent'] ] ?? 0 );

			$en_id = self::upsert( $def, 'en', $parent_en, $create_missing, $report );
			if ( $en_id > 0 ) {
				$ids['en'][ $key ] = $en_id;
			}

			if ( ! self::polylang_active() ) {
				continue;
			}

			$pt_id = self::upsert_translation( $def, $en_id, $parent_pt, $create_missing, $report );
			if ( $pt_id > 0 ) {
				$ids['pt'][ $key ] = $pt_id;
			}
		}

		// Applied on every run rather than only on a write: a page seeded
		// before the template existed, or one whose hash still matches, picks
		// it up on the next sync instead of staying on the default layout
		// forever. Same reasoning as hti-engine's ensure_page_template().
		foreach ( array_keys( $plan ) as $key ) {
			self::ensure_page_template( $key );
		}

		update_option( self::OPTION_LAST, $report, false );

		return $report;
	}

	/**
	 * Create or update the English page, matched by path.
	 *
	 * An update writes only the title, the content, the schema key and the SEO
	 * meta: the slug, the status and the parent are the owner's, not ours.
	 *
	 * @param array<string,mixed>  $def            Page definition.
	 * @param string               $lang           Language of this post.
	 * @param int                  $parent         Parent page ID (0 for the hub).
	 * @param bool                 $create_missing Create the page if absent.
	 * @param array<string,mixed>  $report         Running report, by reference.
	 * @return int Page ID, or 0 when there is none.
	 */
	private static function upsert( array $def, string $lang, int $parent, bool $create_missing, array &$report ): int {
		$hash     = (string) $def['hash'][ $lang ];
		$existing = get_page_by_path( (string) $def['path'][ $lang ], OBJECT, 'page' );

		if ( $existing instanceof \WP_Post ) {
			$id = (int) $existing->ID;
			if ( get_post_meta( $id, self::HASH_META, true ) === $hash ) {
				++$report['unchanged'];
				return $id;
			}
			$res = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_title'   => $def['title'][ $lang ],
						'post_content' => $def['content'][ $lang ],
					)
				),
				true
			);
			if ( is_wp_error( $res ) || 0 === $res ) {
				++$report['unchanged'];
				return $id;
			}
			self::write_meta( $id, $def, $lang, $hash );
			++$report['updated'];
			return $id;
		}

		if ( ! $create_missing ) {
			++$report['missing'];
			return 0;
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def['title'][ $lang ],
					'post_name'    => $def['slug'][ $lang ],
					'post_parent'  => $parent,
					'post_content' => $def['content'][ $lang ],
				)
			),
			true
		);
		if ( is_wp_error( $id ) || 0 === $id ) {
			++$report['missing'];
			return 0;
		}
		$id = (int) $id;

		self::write_meta( $id, $def, $lang, $hash );
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $id, self::lang_slug( $lang ) );
		}
		++$report['created'];

		return $id;
	}

	/**
	 * Create or update the Portuguese counterpart and link the pair.
	 *
	 * The translation is resolved through Polylang first and only then by
	 * path: a PT page an editor renamed is still the translation, and finding
	 * it by its old slug would create a duplicate underneath the real one.
	 *
	 * @param array<string,mixed> $def            Page definition.
	 * @param int                 $en_id          English page ID (0 when absent).
	 * @param int                 $parent         PT parent page ID.
	 * @param bool                $create_missing Create the page if absent.
	 * @param array<string,mixed> $report         Running report, by reference.
	 * @return int PT page ID, or 0.
	 */
	private static function upsert_translation( array $def, int $en_id, int $parent, bool $create_missing, array &$report ): int {
		$pt   = self::lang_slug( 'pt' );
		$hash = (string) $def['hash']['pt'];

		$pt_id = 0;
		if ( $en_id > 0 && function_exists( 'pll_get_post' ) ) {
			$pt_id = (int) pll_get_post( $en_id, $pt );
		}
		if ( 0 === $pt_id ) {
			$found = get_page_by_path( (string) $def['path']['pt'], OBJECT, 'page' );
			$pt_id = $found instanceof \WP_Post ? (int) $found->ID : 0;
		}

		if ( $pt_id > 0 ) {
			if ( get_post_meta( $pt_id, self::HASH_META, true ) !== $hash ) {
				$res = wp_update_post(
					wp_slash(
						array(
							'ID'           => $pt_id,
							'post_title'   => $def['title']['pt'],
							'post_content' => $def['content']['pt'],
						)
					),
					true
				);
				if ( ! is_wp_error( $res ) && 0 !== $res ) {
					self::write_meta( $pt_id, $def, 'pt', $hash );
					++$report['updated'];
				} else {
					++$report['unchanged'];
				}
			} else {
				++$report['unchanged'];
			}
			self::link_pair( $en_id, $pt_id );
			return $pt_id;
		}

		if ( ! $create_missing || 0 === $en_id ) {
			++$report['missing'];
			return 0;
		}

		// Insert without a slug, set the language, then set the slug: Polylang
		// only allows a per-language post_name once the post has a language,
		// and a PT page sharing the EN slug is a worse URL than the one the
		// page table already specifies.
		$new = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def['title']['pt'],
					'post_parent'  => $parent,
					'post_content' => $def['content']['pt'],
				)
			),
			true
		);
		if ( is_wp_error( $new ) || 0 === $new ) {
			++$report['missing'];
			return 0;
		}
		$pt_id = (int) $new;

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $pt_id, $pt );
		}
		wp_update_post(
			array(
				'ID'        => $pt_id,
				'post_name' => $def['slug']['pt'],
			)
		);
		self::link_pair( $en_id, $pt_id );
		self::write_meta( $pt_id, $def, 'pt', $hash );

		++$report['created'];
		++$report['translated'];

		return $pt_id;
	}

	/**
	 * Point a seeded page — and its Portuguese translation — at the theme
	 * template the section wants. Idempotent.
	 *
	 * The PT half is the part that is easy to forget: Polylang keeps a wholly
	 * separate post, so a template set only on the English page leaves the
	 * Portuguese one rendering in the default layout, which on a wide chart is
	 * visible immediately and only to Portuguese readers.
	 *
	 * @param string $key Page key.
	 */
	private static function ensure_page_template( string $key ): void {
		/**
		 * Filter the theme template the seeded game pages use. An empty
		 * string leaves the page on the theme default.
		 *
		 * @param string $template Template name, without the extension.
		 * @param string $key      Page key.
		 */
		$template = (string) apply_filters( 'hti_games_page_template', self::TEMPLATE, $key );
		if ( '' === $template ) {
			return;
		}

		$en = get_page_by_path( self::path( $key, 'en' ), OBJECT, 'page' );
		if ( ! $en instanceof \WP_Post ) {
			return;
		}
		update_post_meta( (int) $en->ID, '_wp_page_template', $template );

		if ( ! self::polylang_active() ) {
			return;
		}
		$pt_id = (int) pll_get_post( (int) $en->ID, self::lang_slug( 'pt' ) );
		if ( $pt_id > 0 ) {
			update_post_meta( $pt_id, '_wp_page_template', $template );
		}
	}

	/**
	 * Link an EN/PT pair in Polylang. No-op without both ids or the API.
	 *
	 * @param int $en_id English page ID.
	 * @param int $pt_id Portuguese page ID.
	 */
	private static function link_pair( int $en_id, int $pt_id ): void {
		if ( $en_id <= 0 || $pt_id <= 0 || ! function_exists( 'pll_save_post_translations' ) ) {
			return;
		}
		pll_save_post_translations(
			array(
				self::lang_slug( 'en' ) => $en_id,
				self::lang_slug( 'pt' ) => $pt_id,
			)
		);
	}

	/**
	 * Write the seed flag, the schema page key, the page template and the SEO
	 * meta for both of the SEO plugins the site has carried.
	 *
	 * @param int                 $id   Page ID.
	 * @param array<string,mixed> $def  Page definition.
	 * @param string              $lang Language of this post.
	 * @param string              $hash Sync hash for the definition.
	 */
	private static function write_meta( int $id, array $def, string $lang, string $hash ): void {
		update_post_meta( $id, self::SEED_FLAG, VERSION );
		update_post_meta( $id, self::HASH_META, $hash );
		update_post_meta( $id, Schema::PAGE_META, $def['key'] );

		if ( '' !== (string) $def['seo_title'][ $lang ] ) {
			update_post_meta( $id, 'rank_math_title', $def['seo_title'][ $lang ] );
			update_post_meta( $id, '_yoast_wpseo_title', $def['seo_title'][ $lang ] );
		}
		if ( '' !== (string) $def['seo_desc'][ $lang ] ) {
			update_post_meta( $id, 'rank_math_description', $def['seo_desc'][ $lang ] );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $def['seo_desc'][ $lang ] );
		}
	}

	/**
	 * Whether Polylang's public API is available. Guarded call by call as
	 * well, because a partial API is worse than none.
	 */
	private static function polylang_active(): bool {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_default_language' )
			&& function_exists( 'pll_get_post' );
	}

	/**
	 * Resolve our 'en'/'pt' to the language slugs Polylang is configured with.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function lang_slug( string $lang ): string {
		if ( 'en' === $lang ) {
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
			return '' !== $default ? $default : 'en';
		}

		if ( ! function_exists( 'pll_languages_list' ) ) {
			return 'pt';
		}
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : 'en';
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );

		foreach ( $slugs as $i => $slug ) {
			if ( $slug !== $default && 0 === stripos( (string) ( $locales[ $i ] ?? '' ), 'pt' ) ) {
				return (string) $slug;
			}
		}
		return 'pt';
	}

	/**
	 * Whether the Survive the Charts pool may be described as real market
	 * data. Delegated to Library, which derives it from the content; false
	 * while that class has not landed, which is the conservative claim.
	 */
	public static function stc_is_real(): bool {
		return class_exists( __NAMESPACE__ . '\\Library' ) && Library::is_real( Config::GAME_STC );
	}

	/* ---------------------------------------------------------------------
	 * Page content
	 * ------------------------------------------------------------------- */

	/**
	 * The block markup of one page in one language.
	 *
	 * @param string $key      Page key.
	 * @param string $lang     'en' or 'pt'.
	 * @param bool   $stc_real Whether the STC pool is real market data.
	 */
	public static function content( string $key, string $lang, bool $stc_real = false ): string {
		switch ( $key ) {
			case 'hub':
				return self::content_hub( $lang );
			case 'stc':
				return self::content_stc( $lang, $stc_real );
			case 'reveal':
				return self::content_reveal( $lang );
			case 'leaderboard':
				return self::content_leaderboard( $lang );
			case 'profile':
				return self::content_profile( $lang );
		}
		return '';
	}

	/**
	 * The hub: what the section is, both games, and the promises that make it
	 * safe to link to from anywhere on the site.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function content_hub( string $lang ): string {
		return self::p( esc_html( self::c( 'hub_lede', $lang ) ) )
			. self::tiles(
				array(
					array( self::money( Config::CAPITAL_START, $lang ), self::c( 'tile_capital', $lang ) ),
					array( '2', self::c( 'tile_games', $lang ) ),
					array( '0', self::c( 'tile_signup', $lang ) ),
				)
			)
			. self::shortcode( '[hti_games_hub]' )
			. self::h2( self::c( 'hub_h_games', $lang ) )
			. self::h3( Strings::get( 'stc_name', $lang ) )
			. self::p(
				esc_html( Strings::get( 'stc_ob1_body', $lang ) ) . ' '
				. self::link( 'stc', $lang, self::c( 'hub_link_stc', $lang ) )
			)
			. self::h3( Strings::get( 'rev_name', $lang ) )
			. self::p(
				esc_html( Strings::get( 'rev_ob1_body', $lang ) ) . ' '
				. self::link( 'reveal', $lang, self::c( 'hub_link_reveal', $lang ) )
			)
			. self::h2( self::c( 'h_how_day', $lang ) )
			. self::ol( self::steps( 'hub', $lang ) )
			. self::h2( self::c( 'hub_h_promises', $lang ) )
			. self::ul(
				array(
					esc_html( Strings::get( 'no_brokers', $lang ) ),
					esc_html( self::c( 'promise_virtual', $lang ) ),
					esc_html( self::c( 'promise_privacy', $lang ) ),
				)
			)
			. self::faq_section( 'hub', $lang )
			. self::disclaimer( $lang )
			. self::p(
				self::link( 'leaderboard', $lang, self::c( 'link_board', $lang ) ) . ' · '
				. self::link( 'profile', $lang, self::c( 'link_profile', $lang ) )
			);
	}

	/**
	 * Survive the Charts.
	 *
	 * The second sentence of the lede is the landing claim, and which of the
	 * two it is comes from Library::is_real() — see the file docblock.
	 *
	 * The H2s are questions on purpose. Every one of them is a query somebody
	 * actually types ("what is an ATR", "how much should I risk per trade"),
	 * and an answering paragraph directly under a question heading is the one
	 * shape both a featured snippet and an AI answer can lift whole. The
	 * sections are written to survive that lifting: each answers its own
	 * heading without the rest of the page around it.
	 *
	 * The runway list is the page's centre of gravity — the section that says
	 * in six lines what the whole game is arguing — and every figure in it is
	 * asked of STC_Engine rather than typed. The prototype had these numbers
	 * hand-written and out by roughly a factor of four, on the one page whose
	 * entire case is arithmetic.
	 *
	 * @param string $lang     'en' or 'pt'.
	 * @param bool   $stc_real Whether the pool is entirely real market data.
	 */
	private static function content_stc( string $lang, bool $stc_real ): string {
		$claim = Strings::get( $stc_real ? 'stc_claim_real' : 'stc_claim_generated', $lang );

		return self::p( esc_html( Strings::get( 'stc_ob1_body', $lang ) ) . ' ' . esc_html( $claim ) )
			. self::tiles(
				array(
					array( self::money( Config::CAPITAL_START, $lang ), self::c( 'tile_capital', $lang ) ),
					array( (string) Config::STC_VISIBLE, self::c( 'tile_candles', $lang ) ),
					array( self::money( Config::CAPITAL_FLOOR, $lang ), self::c( 'tile_floor', $lang ) ),
				)
			)
			. self::shortcode( '[hti_game name="' . Config::GAME_STC . '"]' )
			. self::h2( self::c( 'stc_h_teaches', $lang ) )
			. self::p( esc_html( self::c( 'stc_teaches', $lang ) ) )
			. self::h2( self::c( 'stc_h_how', $lang ) )
			. self::ol( self::steps( 'stc', $lang ) )
			. self::h2( self::c( 'stc_h_rules', $lang ) )
			. self::ul(
				array(
					esc_html( Strings::get( 'stc_ob2_r1', $lang ) ),
					esc_html( Strings::get( 'stc_ob2_r2', $lang ) ),
					esc_html( self::c( 'stc_rule_entry', $lang ) ),
					esc_html( self::c( 'stc_rule_tie', $lang ) ),
					esc_html( Strings::get( 'stc_ob2_r3', $lang ) ),
					esc_html( Strings::get( 'stc_ob2_r4', $lang ) ),
				)
			)
			. self::p( esc_html( self::c( 'stc_tie_why', $lang ) ) )
			. self::h2( self::c( 'stc_h_size', $lang ) )
			. self::p( esc_html( self::c( 'stc_size_1', $lang ) ) )
			. self::p( esc_html( self::c( 'stc_size_2', $lang ) ) )
			. self::h2( self::c( 'stc_h_runway', $lang ) )
			. self::p( esc_html( self::c( 'stc_runway_lede', $lang ) ) )
			. self::ul( self::runway_rows( $lang ) )
			. self::p( esc_html( self::c( 'stc_runway_note', $lang ) ) )
			. self::faq_section( 'stc', $lang, $stc_real )
			. self::h2( self::c( 'h_not', $lang ) )
			. self::p( esc_html( self::c( 'stc_not', $lang ) ) )
			. self::disclaimer( $lang )
			. self::p(
				self::link( 'reveal', $lang, self::c( 'link_reveal', $lang ) ) . ' · '
				. self::link( 'leaderboard', $lang, self::c( 'link_board', $lang ) ) . ' · '
				. self::link( 'hub', $lang, self::c( 'link_hub', $lang ) )
			);
	}

	/**
	 * One line per risk tier: what it is, and how many losing trades in a row
	 * the account absorbs at it before the floor.
	 *
	 * Both halves are derived. The tiers are Config::STC_RISK_BP, so adding or
	 * removing one changes the page; the counts are STC_Engine::losses_to_ruin(),
	 * the same function the tier button warns with, so the page and the game
	 * cannot say different things about the same number.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<int,string> Escaped list items.
	 */
	private static function runway_rows( string $lang ): array {
		$row = self::c( 'stc_runway_row', $lang );
		$out = array();

		foreach ( Config::STC_RISK_BP as $bp ) {
			$out[] = esc_html( sprintf( $row, self::pct( $bp, $lang ), self::ruin( $bp ) ) );
		}

		return $out;
	}

	/**
	 * How many losses in a row a tier survives, from the engine.
	 *
	 * The guarded require is for the pure-PHP test harness, which loads the
	 * seeder on its own; in production STC_Engine is already loaded well
	 * before this file by the plugin's class map, so the branch never runs.
	 * Same pattern as class-stc-generator.php's CLI-only requires.
	 *
	 * @param int $risk_bp Risk tier in basis points.
	 */
	private static function ruin( int $risk_bp ): int {
		if ( ! class_exists( __NAMESPACE__ . '\\STC_Engine' ) ) {
			require_once __DIR__ . '/class-stc-engine.php';
		}

		return STC_Engine::losses_to_ruin( $risk_bp );
	}

	/**
	 * A basis-point tier written as a percentage, the way each language writes
	 * one — "0.5%" in English, "0,5%" in Portuguese, matching the tier labels
	 * the game screen already shows.
	 *
	 * Integer arithmetic rather than a division, for the same reason the rest
	 * of the plugin avoids floats: 50/100 is exact here and only approximately
	 * exact in binary floating point.
	 *
	 * @param int    $bp   Tier in basis points.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function pct( int $bp, string $lang ): string {
		$whole = intdiv( $bp, 100 );
		$frac  = $bp % 100;

		if ( 0 === $frac ) {
			return $whole . '%';
		}

		$text = rtrim( sprintf( '%d.%02d', $whole, $frac ), '0' );

		return ( 'pt' === $lang ? str_replace( '.', ',', $text ) : $text ) . '%';
	}

	/**
	 * The Reveal.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function content_reveal( string $lang ): string {
		return self::p( esc_html( Strings::get( 'rev_ob1_body', $lang ) ) )
			. self::tiles(
				array(
					array( self::money( Config::CAPITAL_START, $lang ), self::c( 'tile_capital', $lang ) ),
					array( '6', self::c( 'tile_fundamentals', $lang ) ),
					array( (string) Config::REVEAL_MIN_AGE_YEARS, self::c( 'tile_years', $lang ) ),
				)
			)
			. self::shortcode( '[hti_game name="' . Config::GAME_REVEAL . '"]' )
			. self::h2( self::c( 'h_teaches', $lang ) )
			. self::p( esc_html( self::c( 'reveal_teaches', $lang ) ) )
			. self::h2( self::c( 'h_how_day', $lang ) )
			. self::ol( self::steps( 'reveal', $lang ) )
			. self::h2( self::c( 'h_rules', $lang ) )
			. self::ul(
				array(
					esc_html( Strings::get( 'rev_ob2_r1', $lang ) ),
					esc_html( Strings::get( 'rev_ob2_r2', $lang ) ),
					esc_html( Strings::get( 'rev_ob2_r3', $lang ) ),
					esc_html( Strings::get( 'rev_ob2_r4', $lang ) ),
				)
			)
			. self::faq_section( 'reveal', $lang )
			. self::h2( self::c( 'h_not', $lang ) )
			. self::p( esc_html( Strings::get( 'rev_historical', $lang ) ) )
			. self::disclaimer( $lang )
			. self::p(
				self::link( 'stc', $lang, self::c( 'link_stc', $lang ) ) . ' · '
				. self::link( 'leaderboard', $lang, self::c( 'link_board', $lang ) ) . ' · '
				. self::link( 'hub', $lang, self::c( 'link_hub', $lang ) )
			);
	}

	/**
	 * The leaderboard. Indexable, so it carries the explanation of the
	 * scoring — which is also the answer to "why is a bigger position not a
	 * shortcut up the board".
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function content_leaderboard( string $lang ): string {
		return self::p( esc_html( self::c( 'leaderboard_lede', $lang ) ) )
			. self::shortcode( '[hti_games_leaderboard]' )
			. self::h2( self::c( 'board_h_scoring', $lang ) )
			. self::p( esc_html( Strings::get( 'board_score_note', $lang ) ) )
			. self::h2( self::c( 'board_h_privacy', $lang ) )
			. self::ul(
				array(
					esc_html( Strings::get( 'board_privacy', $lang ) ),
					esc_html( Strings::get( 'board_reset', $lang ) ),
					esc_html( self::c( 'promise_virtual', $lang ) ),
				)
			)
			. self::faq_section( 'leaderboard', $lang )
			. self::disclaimer( $lang )
			. self::p(
				self::link( 'stc', $lang, self::c( 'link_stc', $lang ) ) . ' · '
				. self::link( 'reveal', $lang, self::c( 'link_reveal', $lang ) ) . ' · '
				. self::link( 'hub', $lang, self::c( 'link_hub', $lang ) )
			);
	}

	/**
	 * The player profile. Deliberately the thin one — it is noindexed, so it
	 * carries what its own reader needs and nothing written for a crawler.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function content_profile( string $lang ): string {
		return self::p( esc_html( self::c( 'profile_lede', $lang ) ) )
			. self::shortcode( '[hti_games_profile]' )
			. self::p( esc_html( Strings::get( 'profile_risk_hint', $lang ) ) )
			. self::p( esc_html( Strings::get( 'forget_note', $lang ) ) )
			. self::disclaimer( $lang )
			. self::p(
				self::link( 'hub', $lang, self::c( 'link_hub', $lang ) ) . ' · '
				. self::link( 'leaderboard', $lang, self::c( 'link_board', $lang ) )
			);
	}

	/**
	 * The four steps of a day, per page.
	 *
	 * @param string $key  Page key.
	 * @param string $lang 'en' or 'pt'.
	 * @return array<int,string>
	 */
	public static function steps( string $key, string $lang ): array {
		$out = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$step = self::c( $key . '_step' . $i, $lang );
			if ( '' !== $step ) {
				$out[] = esc_html( $step );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * FAQ — the same array the FAQPage JSON-LD is built from.
	 * ------------------------------------------------------------------- */

	/**
	 * The FAQ of one page, in one language.
	 *
	 * Schema::emit() reads this too, which is the whole point: a question
	 * answered one way in the copy and another way in the structured data is
	 * a manual action waiting to happen, and the only reliable fix is that
	 * there is one array.
	 *
	 * @param string $key      Page key.
	 * @param string $lang     'en' or 'pt'.
	 * @param bool   $stc_real Whether the STC pool is real market data — the
	 *                         "are the charts real?" answer has to track the
	 *                         landing claim or the page contradicts itself.
	 * @return array<int,array{q:string,a:string}>
	 */
	public static function faqs( string $key, string $lang, bool $stc_real = false ): array {
		$lang = in_array( $lang, Strings::LANGS, true ) ? $lang : 'en';
		$out  = array();

		foreach ( self::faq_table()[ $key ] ?? array() as $pair ) {
			$out[] = array(
				'q' => (string) $pair['q'][ $lang ],
				'a' => (string) $pair['a'][ $lang ],
			);
		}

		if ( 'stc' === $key ) {
			$out[] = array(
				'q' => self::c( 'stc_faq_real_q', $lang ),
				'a' => self::c( $stc_real ? 'stc_faq_real_a_yes' : 'stc_faq_real_a_no', $lang ),
			);
		}

		return $out;
	}

	/**
	 * FAQ section rendered from the same array the schema uses.
	 *
	 * @param string $key      Page key.
	 * @param string $lang     'en' or 'pt'.
	 * @param bool   $stc_real Whether the STC pool is real market data.
	 */
	private static function faq_section( string $key, string $lang, bool $stc_real = false ): string {
		$faqs = self::faqs( $key, $lang, $stc_real );
		if ( array() === $faqs ) {
			return '';
		}
		$out = self::h2( self::c( 'h_faq', $lang ) );
		foreach ( $faqs as $faq ) {
			$out .= self::h3( $faq['q'] ) . self::p( esc_html( $faq['a'] ) );
		}
		return $out;
	}

	/**
	 * The canonical disclaimer, under its own heading so it is a section a
	 * reader can find rather than grey text at the bottom.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function disclaimer( string $lang ): string {
		return self::h2( self::c( 'h_disclaimer', $lang ) )
			. self::p( esc_html( Strings::get( 'disclaimer_full', $lang ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Block-markup helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Paragraph block.
	 *
	 * @param string $html Inner HTML (already escaped where needed).
	 */
	private static function p( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * H2 block.
	 *
	 * @param string $text Heading text (unescaped).
	 */
	private static function h2( string $text ): string {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * H3 block.
	 *
	 * @param string $text Heading text (unescaped).
	 */
	private static function h3( string $text ): string {
		return '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $text ) . '</h3><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * Unordered-list block.
	 *
	 * @param array<int,string> $items Item HTML (already escaped).
	 */
	private static function ul( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $li . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Ordered-list block.
	 *
	 * @param array<int,string> $items Item HTML (already escaped).
	 */
	private static function ol( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">' . $li . '</ol><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Stat tiles: a figure and a label each, in a group the section CSS
	 * styles. Plain block markup, so they still read as a short list if the
	 * stylesheet never loads.
	 *
	 * @param array<int,array{0:string,1:string}> $tiles Figure/label pairs.
	 */
	private static function tiles( array $tiles ): string {
		$out = '<!-- wp:group {"className":"hti-games-tiles"} --><div class="wp-block-group hti-games-tiles">';
		foreach ( $tiles as $tile ) {
			$out .= '<!-- wp:paragraph {"className":"hti-games-tile"} --><p class="hti-games-tile"><strong>'
				. esc_html( $tile[0] ) . '</strong> ' . esc_html( $tile[1] )
				. '</p><!-- /wp:paragraph -->';
		}
		return $out . '</div><!-- /wp:group -->' . "\n\n";
	}

	/**
	 * Shortcode block — the game mount itself.
	 *
	 * @param string $shortcode The shortcode, brackets included.
	 */
	private static function shortcode( string $shortcode ): string {
		return '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->' . "\n\n";
	}

	/**
	 * An internal link to another page of the section.
	 *
	 * @param string $key   Target page key.
	 * @param string $lang  'en' or 'pt'.
	 * @param string $label Link text (unescaped).
	 */
	private static function link( string $key, string $lang, string $label ): string {
		return '<a href="' . esc_url( self::url( $key, $lang ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * A whole-dollar amount, written the way each language writes it — the
	 * Portuguese copy in Strings uses "10 000 $", not "$10,000".
	 *
	 * @param int    $amount Whole dollars.
	 * @param string $lang   'en' or 'pt'.
	 */
	private static function money( int $amount, string $lang ): string {
		return 'pt' === $lang
			? number_format( $amount, 0, ',', ' ' ) . ' $'
			: '$' . number_format( $amount );
	}

	/* ---------------------------------------------------------------------
	 * Editorial copy
	 *
	 * Page-level prose only: everything the game screens themselves say lives
	 * in Strings and is read from there. Both languages sit side by side for
	 * the same reason they do in Strings — a gap is visible in the diff, and
	 * tests/test-seeder.php fails on one.
	 * ------------------------------------------------------------------- */

	/**
	 * One editorial string.
	 *
	 * @param string $key  Copy key.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function c( string $key, string $lang ): string {
		$copy = self::copy();
		if ( ! isset( $copy[ $key ] ) ) {
			return '';
		}
		$lang = in_array( $lang, Strings::LANGS, true ) ? $lang : 'en';
		return (string) ( $copy[ $key ][ $lang ] ?? $copy[ $key ]['en'] );
	}

	/**
	 * The editorial copy table.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	public static function copy(): array {
		return array_merge( self::copy_chrome(), self::copy_hub(), self::copy_stc(), self::copy_reveal(), self::copy_board() );
	}

	/**
	 * Headings, tile labels, link labels and the section-wide promises.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function copy_chrome(): array {
		return array(
			'h_teaches'         => array(
				'en' => 'What this game teaches',
				'pt' => 'O que este jogo ensina',
			),
			'h_how_day'         => array(
				'en' => 'How a day works',
				'pt' => 'Como funciona um dia',
			),
			'h_rules'           => array(
				'en' => 'The rules',
				'pt' => 'As regras',
			),
			'h_faq'             => array(
				'en' => 'Frequently asked questions',
				'pt' => 'Perguntas frequentes',
			),
			'h_not'             => array(
				'en' => 'What this is not',
				'pt' => 'O que isto não é',
			),
			'h_disclaimer'      => array(
				'en' => 'Read this before you read anything into a result',
				'pt' => 'Lê isto antes de tirares conclusões de um resultado',
			),
			'tile_capital'      => array(
				'en' => 'of virtual capital, carried from day to day',
				'pt' => 'de capital virtual, transportado de dia para dia',
			),
			'tile_candles'      => array(
				'en' => 'candles visible, with the market hidden',
				'pt' => 'velas visíveis, com o mercado escondido',
			),
			'tile_floor'        => array(
				'en' => 'is the floor: reach it and the run ends',
				'pt' => 'é o chão: chegar lá acaba com a corrida',
			),
			'tile_fundamentals' => array(
				'en' => 'fundamentals, each against its sector average',
				'pt' => 'fundamentais, cada um contra a média do setor',
			),
			'tile_years'        => array(
				'en' => 'years is the minimum age of a case',
				'pt' => 'anos é a idade mínima de um caso',
			),
			'tile_games'        => array(
				'en' => 'games, one challenge each per day',
				'pt' => 'jogos, um desafio de cada por dia',
			),
			'tile_signup'       => array(
				'en' => 'accounts to create before you can play',
				'pt' => 'contas para criar antes de jogares',
			),
			'promise_virtual'   => array(
				'en' => 'The capital is virtual and stays virtual: nothing is executed anywhere, there is nothing to win and nothing to lose but the point being made.',
				'pt' => 'O capital é virtual e continua virtual: nada é executado em lado nenhum, não há nada a ganhar nem a perder além da lição.',
			),
			'promise_privacy'   => array(
				'en' => 'Playing needs no account. Without one the run lives on your device and nothing that identifies you is stored on the site.',
				'pt' => 'Jogar não precisa de conta. Sem ela, a corrida vive no teu dispositivo e nada que te identifique fica guardado no site.',
			),
			'link_hub'          => array(
				'en' => 'Both games',
				'pt' => 'Os dois jogos',
			),
			'link_stc'          => array(
				'en' => 'Play Survive the Charts',
				'pt' => 'Jogar Sobreviver aos Gráficos',
			),
			'link_reveal'       => array(
				'en' => 'Play The Reveal',
				'pt' => 'Jogar A Revelação',
			),
			'link_board'        => array(
				'en' => 'See the leaderboard',
				'pt' => 'Ver a classificação',
			),
			'link_profile'      => array(
				'en' => 'See your own run',
				'pt' => 'Ver a tua corrida',
			),
		);
	}

	/**
	 * The hub page.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function copy_hub(): array {
		return array(
			'hub_title'       => array(
				'en' => 'Educational investing games',
				'pt' => 'Jogos educacionais de investimento',
			),
			'hub_seo_title'   => array(
				'en' => 'Educational Investing Games — Free, No Sign-Up',
				'pt' => 'Jogos de Investimento Educacionais — Sem Registo',
			),
			'hub_seo_desc'    => array(
				'en' => 'Two free browser games about investing: one chart a day and what position size does to an account, and a real company to judge blind. Virtual money.',
				'pt' => 'Dois jogos gratuitos sobre investir: um gráfico por dia e o que o tamanho da posição faz a uma conta, e uma empresa real para avaliar às cegas.',
			),
			'hub_lede'        => array(
				'en' => 'Two short games about the two decisions that actually move an account: how much to put behind a call, and whether a company deserves the money at all. Both take about two minutes, both run on virtual capital, and neither asks you to create anything.',
				'pt' => 'Dois jogos curtos sobre as duas decisões que mexem mesmo com uma conta: quanto pôr atrás de uma leitura, e se uma empresa merece sequer o dinheiro. Ambos demoram cerca de dois minutos, ambos correm sobre capital virtual, e nenhum te pede para criar seja o que for.',
			),
			'hub_h_games'     => array(
				'en' => 'The two games',
				'pt' => 'Os dois jogos',
			),
			'hub_h_promises'  => array(
				'en' => 'What this section promises',
				'pt' => 'O que esta secção promete',
			),
			'hub_link_stc'    => array(
				'en' => 'Open Survive the Charts',
				'pt' => 'Abrir Sobreviver aos Gráficos',
			),
			'hub_link_reveal' => array(
				'en' => 'Open The Reveal',
				'pt' => 'Abrir A Revelação',
			),
			'hub_step1'       => array(
				'en' => 'Pick a game. Each one publishes a single new challenge a day, the same one for everybody.',
				'pt' => 'Escolhe um jogo. Cada um publica um único desafio novo por dia, o mesmo para toda a gente.',
			),
			'hub_step2'       => array(
				'en' => 'Make the call, then decide how much of the account goes behind it. That second decision is the one being taught.',
				'pt' => 'Faz a leitura e decide depois quanto da conta vai atrás dela. É essa segunda decisão que está a ser ensinada.',
			),
			'hub_step3'       => array(
				'en' => 'Watch it settle against what actually happened, and read what the size of the position did to the balance.',
				'pt' => 'Vê como fecha contra o que aconteceu de facto, e lê o que o tamanho da posição fez ao saldo.',
			),
			'hub_step4'       => array(
				'en' => 'Come back tomorrow. The run carries over, and a run is what the games are about — a single day tells you nothing.',
				'pt' => 'Volta amanhã. A corrida transita, e é da corrida que os jogos tratam — um único dia não diz nada.',
			),
		);
	}

	/**
	 * Survive the Charts.
	 *
	 * The page has to do two jobs at once and the copy is shaped by both.
	 *
	 * For a reader: explain a game whose whole argument is arithmetic, without
	 * ever telling anybody what to do with money. Everything here is written
	 * about the account rather than at the player — "an account that risks a
	 * quarter of itself", never "you should risk one percent" — which is both
	 * the house voice and the line that keeps an educational page educational.
	 *
	 * For a search engine and an answer engine: be liftable. Question-form
	 * H2s, one self-contained answer under each, the runway figures as a list
	 * rather than buried in a paragraph, and an FAQ deep enough to answer the
	 * things people actually ask about a trading game — is it real data, is it
	 * real money, can I lose more than the account, what is an ATR. The FAQ
	 * array is the same one the FAQPage JSON-LD is built from.
	 *
	 * The Portuguese is written, not translated. The intent differs: an
	 * English searcher looks for "free trading simulator" and "position
	 * sizing"; a Portuguese one looks for "simulador de trading grátis",
	 * "jogo da bolsa" and "gestão de risco", and says "tamanho da posição"
	 * where the English says position sizing. The titles and the description
	 * are built around those terms rather than around a translation of the
	 * English ones.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function copy_stc(): array {
		return array(
			'stc_title'            => array(
				'en' => 'Survive the Charts: the daily position sizing game',
				'pt' => 'Sobreviver aos Gráficos: jogo diário de gestão de risco',
			),
			'stc_seo_title'        => array(
				'en' => 'Survive the Charts — Free Daily Trading Simulator',
				'pt' => 'Sobreviver aos Gráficos — Simulador de Trading Grátis',
			),
			'stc_seo_desc'         => array(
				'en' => 'Free daily trading game: buy, sell or pass on a hidden chart, then pick how much of a $10,000 virtual account to risk. Position size is the lesson.',
				'pt' => 'Jogo diário grátis: comprar, vender ou passar num gráfico anónimo e escolher quanto arriscar de uma conta virtual. A lição é o tamanho da posição.',
			),
			'stc_h_teaches'        => array(
				'en' => 'What does Survive the Charts teach?',
				'pt' => 'O que ensina o Sobreviver aos Gráficos?',
			),
			'stc_h_how'            => array(
				'en' => 'How does a day work?',
				'pt' => 'Como funciona um dia?',
			),
			'stc_h_rules'          => array(
				'en' => 'What are the rules?',
				'pt' => 'Quais são as regras?',
			),
			'stc_h_size'           => array(
				'en' => 'Why is position size the whole game?',
				'pt' => 'Porque é o tamanho da posição o jogo todo?',
			),
			'stc_h_runway'         => array(
				'en' => 'How many losing trades in a row does each level of risk survive?',
				'pt' => 'Quantas perdas seguidas aguenta cada nível de risco?',
			),
			'stc_teaches'          => array(
				'en' => 'That being right about direction is not what keeps an account alive. The stop and the target sit the same distance away every single day, so the one thing genuinely under your control is how much of the account stands behind the call. A run of ordinary bad luck at 10% a trade ends the account while the same run at 1% barely shows on the balance — and what separates those two players is not judgement, skill or the chart. It is one number, chosen before the candles moved. Most people learn this by blowing an account up once; here that costs a fortnight of two-minute sessions instead of savings.',
				'pt' => 'Que acertar na direção não é o que mantém uma conta viva. O stop e o alvo ficam exatamente à mesma distância todos os dias, por isso a única coisa que está mesmo sob o teu controlo é quanto da conta fica atrás da leitura. Uma série de azar banal a 10% por operação acaba com a conta, enquanto a mesma série a 1% quase não se vê no saldo — e o que separa esses dois jogadores não é juízo, nem perícia, nem o gráfico. É um número, escolhido antes de as velas se mexerem. A maioria das pessoas aprende isto rebentando uma conta uma vez; aqui isso custa uma quinzena de sessões de dois minutos em vez de poupanças.',
			),
			'stc_step1'            => array(
				'en' => 'You are shown 80 candles of a market whose name is hidden, and there is no way to look it up. The last close is the price you would be entering at.',
				'pt' => 'Vês 80 velas de um mercado cujo nome está tapado, e não há forma de o ir procurar. O último fecho é o preço a que entrarias.',
			),
			'stc_step2'            => array(
				'en' => 'Buy, sell, or pass. Passing costs nothing and never breaks the streak — on some days it is the whole answer.',
				'pt' => 'Comprar, vender, ou passar. Passar não custa nada e nunca quebra a série — há dias em que é essa a resposta toda.',
			),
			'stc_step3'            => array(
				'en' => 'If you take the trade, choose how much of the account to risk: 0.5%, 1%, 2%, 5%, 10% or 25%, with an optional doubled stake. The dollars that puts at stake, and how many losses in a row the tier would survive, are on the screen before you confirm rather than after.',
				'pt' => 'Se entrares, escolhe quanto da conta arriscar: 0,5%, 1%, 2%, 5%, 10% ou 25%, com a opção de dobrar a aposta. Os dólares que isso põe em jogo, e quantas perdas seguidas o escalão aguentaria, estão no ecrã antes de confirmares e não depois.',
			),
			'stc_step4'            => array(
				'en' => 'The next 40 candles play out against a stop one ATR away and a target one and a half, and the balance moves by exactly what you put behind the call — then the day tells you what the size, rather than the reading, did to the account.',
				'pt' => 'As 40 velas seguintes desenrolam-se contra um stop a um ATR e um alvo a um e meio, e o saldo move-se exatamente pelo que puseste atrás da leitura — e depois o dia diz-te o que o tamanho, e não a leitura, fez à conta.',
			),
			'stc_rule_entry'       => array(
				'en' => 'The entry is the close of the last visible candle, and the ATR is measured over the 14 candles before it. Nothing from the hidden window sets a level, so you are never stopped out by a number you could not have worked out yourself.',
				'pt' => 'A entrada é o fecho da última vela visível, e o ATR é medido sobre as 14 velas anteriores. Nada da janela escondida define um nível, por isso nunca levas stop por causa de um número que não pudesses ter calculado sozinho.',
			),
			'stc_rule_tie'         => array(
				'en' => 'A candle that reaches both the stop and the target is booked as a stop. The tie goes to the loss, every time.',
				'pt' => 'Uma vela que chega ao stop e ao alvo é registada como stop. O empate fica para a perda, sempre.',
			),
			'stc_tie_why'          => array(
				'en' => 'The tie rule is the one that needs explaining. A candle records an open, a high, a low and a close, and nothing in it says which of those prices came first — so a bar whose range contains both levels could honestly have been either outcome. The game reads it pessimistically and books the loss. That is the honest reading rather than the harsh one: it never flatters a position, it is the same rule for everybody, and it settles an argument about a chart you are looking at, which the generous reading would leave open forever.',
				'pt' => 'A regra do empate é a que precisa de explicação. Uma vela regista uma abertura, um máximo, um mínimo e um fecho, e nada nela diz qual desses preços veio primeiro — por isso uma barra cuja amplitude contém os dois níveis podia honestamente ter sido qualquer um dos dois resultados. O jogo lê-a de forma pessimista e regista a perda. É a leitura honesta e não a dura: nunca favorece uma posição, é a mesma regra para toda a gente, e fecha uma discussão sobre um gráfico que tens à frente, coisa que a leitura generosa deixaria aberta para sempre.',
			),
			'stc_size_1'           => array(
				'en' => 'Two players can make the same call on the same chart and finish the month in different worlds. The one who was right slightly more often and risked a quarter of the account each time is out of the game; the one who was wrong slightly more often and risked one percent is still playing, and still learning. Direction is the part everybody argues about. Size is the part that decides who is still around to argue.',
				'pt' => 'Dois jogadores podem fazer a mesma leitura no mesmo gráfico e acabar o mês em mundos diferentes. Quem acertou um pouco mais vezes e arriscou um quarto da conta de cada vez está fora do jogo; quem errou um pouco mais vezes e arriscou um por cento continua a jogar, e continua a aprender. A direção é a parte sobre a qual toda a gente discute. O tamanho é a parte que decide quem ainda cá está para discutir.',
			),
			'stc_size_2'           => array(
				'en' => 'A losing streak is not a punishment for reading a chart badly. It is the ordinary texture of any long sequence of uncertain decisions, and six losses in a row will find every player who stays long enough to meet them. What separates the accounts that walk through a streak like that from the accounts that end there is a single number, chosen calmly, before there was anything to feel about it.',
				'pt' => 'Uma série de perdas não é um castigo por se ler mal um gráfico. É a textura normal de qualquer sequência longa de decisões incertas, e seis perdas seguidas encontram todos os jogadores que fiquem tempo suficiente para as apanhar. O que separa as contas que atravessam uma série dessas das contas que acabam ali é um único número, escolhido com calma, antes de haver alguma coisa a sentir sobre ele.',
			),
			'stc_runway_lede'      => array(
				'en' => 'Each tier answers one question: how many losing trades in a row can the account take before it reaches the floor and the run ends? Every loss is a percentage of what is left, so the balance shrinks more slowly the further it falls — which is why the numbers below are larger than they feel. They are computed by the game engine, not written by hand, so the page and the tier buttons cannot drift apart.',
				'pt' => 'Cada escalão responde a uma pergunta: quantas operações perdedoras seguidas aguenta a conta antes de chegar ao chão e a corrida acabar? Cada perda é uma percentagem do que resta, por isso o saldo encolhe mais devagar quanto mais cai — e é por isso que os números abaixo são maiores do que parecem. São calculados pelo motor do jogo e não escritos à mão, para que a página e os botões dos escalões não se afastem um do outro.',
			),
			'stc_runway_row'       => array(
				'en' => '%1$s a trade — %2$d losing trades in a row before the floor.',
				'pt' => '%1$s por operação — %2$d operações perdedoras seguidas antes do chão.',
			),
			'stc_runway_note'      => array(
				'en' => 'None of that is a recommendation about how much to risk anywhere. It is arithmetic, it holds whether the reading was good or bad, and it is the reason the heavy tiers are on the screen at all: a player can find out in two minutes what they cost, on money that is not real. Doubling a tier roughly halves the row it sits on, which is the part most people are surprised by.',
				'pt' => 'Nada disto é uma recomendação sobre quanto arriscar seja onde for. É aritmética, vale seja a leitura boa ou má, e é a razão por que os escalões pesados estão sequer no ecrã: um jogador descobre em dois minutos o que custam, com dinheiro que não é real. Duplicar um escalão corta mais ou menos para metade a linha em que ele está, e é essa a parte que surpreende a maioria das pessoas.',
			),
			'stc_not'              => array(
				'en' => 'It is not advice, and it is not a signal service: nothing here says what to do with money outside the game, and no chart in it is a market anybody could act on. It is not a broker, an account, or a demo of one — there is nothing to deposit, nothing to pay for, nothing to win, and no company is promoted anywhere in this section. And it is not a measure of talent. Two months of it teaches what a sequence of decisions does to a balance, which is a different and rather more useful thing than finding out whether you can call a chart.',
				'pt' => 'Não é aconselhamento, nem é um serviço de sinais: nada aqui diz o que fazer com dinheiro fora do jogo, e nenhum gráfico dele é um mercado onde alguém pudesse agir. Não é uma corretora, uma conta, nem a demonstração de uma — não há nada para depositar, nada para pagar, nada a ganhar, e não há nenhuma empresa promovida em lado nenhum desta secção. E não é uma medida de talento. Dois meses disto ensinam o que uma sequência de decisões faz a um saldo, que é coisa diferente e bastante mais útil do que descobrir se sabes ler um gráfico.',
			),
			'stc_faq_real_q'       => array(
				'en' => 'Are the charts real market data?',
				'pt' => 'Os gráficos são dados reais de mercado?',
			),
			'stc_faq_real_a_yes'   => array(
				'en' => 'Yes. Every chart in the pool is imported historical price data, and the market it came from stays hidden so that recognising it cannot help. This answer is derived from the published pool rather than typed here, so it changes by itself if the pool ever stops being entirely real.',
				'pt' => 'Sim. Todos os gráficos do conjunto são dados históricos de preços importados, e o mercado de origem fica tapado para que reconhecê-lo não ajude. Esta resposta é derivada do conjunto publicado e não escrita à mão, por isso muda sozinha se o conjunto deixar de ser inteiramente real.',
			),
			'stc_faq_real_a_no'    => array(
				'en' => 'Not yet. The charts are generated to behave like real price action — the same volatility clustering, the same gaps and the same false breaks — while the pool of imported historical charts is being built. The page says which of the two it is because the answer is read from the published pool, not from a setting somebody ticks.',
				'pt' => 'Ainda não. Os gráficos são gerados para se comportarem como movimento real de preços — a mesma concentração de volatilidade, os mesmos saltos e as mesmas roturas falsas — enquanto o conjunto de gráficos históricos importados está a ser construído. A página diz qual dos dois é porque a resposta é lida do conjunto publicado, não de uma opção que alguém marca.',
			),
		);
	}

	/**
	 * The Reveal.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function copy_reveal(): array {
		return array(
			'reveal_title'     => array(
				'en' => 'The Reveal: judge a real company blind',
				'pt' => 'A Revelação: avaliar uma empresa real às cegas',
			),
			'reveal_seo_title' => array(
				'en' => 'The Reveal — Free Daily Company Analysis Game',
				'pt' => 'A Revelação — Jogo Diário de Análise de Empresas',
			),
			'reveal_seo_desc'  => array(
				'en' => 'A free daily game: an anonymised dossier of a real company, six fundamentals and three headlines. Invest or pass, then see the name and the real return.',
				'pt' => 'Um jogo diário grátis: o dossiê anónimo de uma empresa real, seis fundamentais e três manchetes. Investe ou passa, e vê depois o nome e o retorno real.',
			),
			'reveal_teaches'   => array(
				'en' => 'That a company you have heard of and a company worth owning are different questions. With the name removed there is no brand to lean on, no story you already believe and nothing to look up — only six numbers against their sector and three headlines from the year. What is left is the habit the game is trying to build: reading the figures before deciding how you feel about them.',
				'pt' => 'Que uma empresa de que já ouviste falar e uma empresa que vale a pena ter são perguntas diferentes. Sem o nome não há marca em que te encostares, nem história em que já acreditas, nem nada para ir procurar — só seis números contra o setor e três manchetes do ano. O que sobra é o hábito que o jogo quer construir: ler os números antes de decidir o que sentes sobre eles.',
			),
			'reveal_step1'     => array(
				'en' => 'A dossier opens on a real company at a real year: its sector, its revenue, and six fundamentals shown next to the average for that sector.',
				'pt' => 'Abre-se o dossiê de uma empresa real num ano real: o setor, a receita, e seis fundamentais mostrados ao lado da média desse setor.',
			),
			'reveal_step2'     => array(
				'en' => 'Three headlines from the period sit underneath. They are the mood of the time, and the mood of the time is frequently wrong.',
				'pt' => 'Por baixo ficam três manchetes da época. São o estado de espírito do momento, e o estado de espírito do momento engana-se muitas vezes.',
			),
			'reveal_step3'     => array(
				'en' => 'Commit 5%, 10%, 25% or 50% of the account, or pass. Passing keeps the capital intact and never breaks the streak.',
				'pt' => 'Compromete 5%, 10%, 25% ou 50% da conta, ou passa. Passar mantém o capital intacto e nunca quebra a série.',
			),
			'reveal_step4'     => array(
				'en' => 'Then the name, the year, and what the company actually returned over the five years that followed — next to what the index did over the same period.',
				'pt' => 'Depois o nome, o ano, e o que a empresa rendeu de facto nos cinco anos seguintes — ao lado do que o índice fez no mesmo período.',
			),
		);
	}

	/**
	 * The leaderboard and the profile.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function copy_board(): array {
		return array(
			'leaderboard_title'     => array(
				'en' => 'Games leaderboard',
				'pt' => 'Classificação dos jogos',
			),
			'leaderboard_seo_title' => array(
				'en' => 'Educational Games Leaderboard — HowToInvest',
				'pt' => 'Classificação dos Jogos — HowToInvest',
			),
			'leaderboard_seo_desc'  => array(
				'en' => 'The daily and survival boards for the HowToInvest games, scored per unit of risk taken. Nicknames only, virtual money only, and no prizes of any kind.',
				'pt' => 'As tabelas diária e de sobrevivência dos jogos da HowToInvest, pontuadas por unidade de risco. Só alcunhas, só dinheiro virtual, e sem prémios.',
			),
			'leaderboard_lede'      => array(
				'en' => 'Two boards: today, and how long people have kept an account alive. The daily one is scored per unit of risk taken rather than by profit, which is the only way a board about position size does not quietly reward the opposite of what the game teaches.',
				'pt' => 'Duas tabelas: hoje, e há quanto tempo cada pessoa mantém uma conta viva. A diária é pontuada por unidade de risco assumido e não por lucro, que é a única forma de uma tabela sobre tamanho de posição não premiar em silêncio o contrário do que o jogo ensina.',
			),
			'board_h_scoring'       => array(
				'en' => 'How the daily board is scored',
				'pt' => 'Como é pontuada a tabela diária',
			),
			'board_h_privacy'       => array(
				'en' => 'What appears here, and what never does',
				'pt' => 'O que aparece aqui, e o que nunca aparece',
			),
			'profile_title'         => array(
				'en' => 'Your run',
				'pt' => 'A tua corrida',
			),
			'profile_seo_title'     => array(
				'en' => 'Your Run — HowToInvest Games',
				'pt' => 'A Tua Corrida — Jogos HowToInvest',
			),
			'profile_seo_desc'      => array(
				'en' => 'Your own capital, streak, average risk per trade and the last 28 days. Visible only to you, on this device, and deliberately not indexed.',
				'pt' => 'O teu capital, a tua série, o risco médio por operação e os últimos 28 dias. Visível só para ti, neste dispositivo, e propositadamente não indexado.',
			),
			'profile_lede'          => array(
				'en' => 'Your own numbers across both games: what is left of the account, how long the run has lasted, and the line that actually matters — your average risk per trade over time.',
				'pt' => 'Os teus números nos dois jogos: o que resta da conta, há quanto tempo dura a corrida, e a linha que interessa mesmo — o teu risco médio por operação ao longo do tempo.',
			),
		);
	}

	/**
	 * The fixed FAQ table. Questions that depend on the state of the content
	 * (the "are the charts real?" one) are appended in faqs() instead.
	 *
	 * @return array<string,array<int,array{q:array{en:string,pt:string},a:array{en:string,pt:string}}>>
	 */
	private static function faq_table(): array {
		return array(
			'hub'         => array(
				array(
					'q' => array(
						'en' => 'Do I need an account to play?',
						'pt' => 'Preciso de conta para jogar?',
					),
					'a' => array(
						'en' => 'No. Both games run straight away and your run is kept on your device. An email address is optional and buys exactly one thing: carrying the same run to another device. It can be deleted from the profile page at any time.',
						'pt' => 'Não. Os dois jogos arrancam de imediato e a tua corrida fica guardada no teu dispositivo. Um endereço de email é opcional e serve exatamente para uma coisa: levar a mesma corrida para outro dispositivo. Pode ser apagado na página do perfil quando quiseres.',
					),
				),
				array(
					'q' => array(
						'en' => 'Is any real money involved?',
						'pt' => 'Há dinheiro real envolvido?',
					),
					'a' => array(
						'en' => 'None. The capital is virtual, no order is placed anywhere, and there is nothing to pay for and nothing to be won. The section is deliberately kept apart from anything commercial on the rest of the site.',
						'pt' => 'Nenhum. O capital é virtual, não é colocada nenhuma ordem em lado nenhum, e não há nada a pagar nem nada a ganhar. A secção é mantida de propósito à parte de tudo o que é comercial no resto do site.',
					),
				),
				array(
					'q' => array(
						'en' => 'How long does one day take?',
						'pt' => 'Quanto tempo demora um dia?',
					),
					'a' => array(
						'en' => 'About two minutes per game. There is one challenge a day, the same one for everybody, and it rolls over at 00:00 IST — which is the evening in Portugal and midnight in India.',
						'pt' => 'Cerca de dois minutos por jogo. Há um desafio por dia, o mesmo para toda a gente, e vira às 00:00 IST — que é ao fim do dia em Portugal e à meia-noite na Índia.',
					),
				),
			),
			'stc'         => array(
				array(
					'q' => array(
						'en' => 'Is any real money involved?',
						'pt' => 'Há dinheiro real envolvido?',
					),
					'a' => array(
						'en' => 'No real money at any point. The $10,000 is virtual, no order is placed anywhere, there is nothing to pay for and nothing to be won. Survive the Charts is a simulation of the decision, not a route to a market — the account exists so that a bad habit can cost something without costing anybody anything.',
						'pt' => 'Sem dinheiro real em momento nenhum. Os 10 000 $ são virtuais, não é colocada nenhuma ordem em lado nenhum, não há nada a pagar nem nada a ganhar. O Sobreviver aos Gráficos é uma simulação da decisão e não um caminho para um mercado — a conta existe para que um mau hábito custe alguma coisa sem custar nada a ninguém.',
					),
				),
				array(
					'q' => array(
						'en' => 'Do I need an account to play?',
						'pt' => 'Preciso de conta para jogar?',
					),
					'a' => array(
						'en' => 'No. The game opens and plays straight away, and your run is kept on your device. Giving an email address is optional and buys exactly one thing: carrying the same run to another browser or another device. There is no password to choose and no name to give.',
						'pt' => 'Não. O jogo abre e joga-se de imediato, e a tua corrida fica guardada no teu dispositivo. Dar um endereço de email é opcional e serve exatamente para uma coisa: levar a mesma corrida para outro navegador ou outro dispositivo. Não há palavra-passe para escolher nem nome para dar.',
					),
				),
				array(
					'q' => array(
						'en' => 'What is an ATR, and why does it set the stop?',
						'pt' => 'O que é o ATR, e porque é ele que define o stop?',
					),
					'a' => array(
						'en' => 'The ATR — average true range — is how far this market has been travelling between its high and its low, averaged over the last 14 candles you can see. The stop sits one ATR from the entry and the target one and a half, so a quiet market gets a tight stop and a violent one gets a wide stop. That is what lets every day risk the same share of the account whatever the chart is doing, and it is why the size, and not the market, is the variable.',
						'pt' => 'O ATR — amplitude média real — é a distância que este mercado tem percorrido entre o máximo e o mínimo, em média, ao longo das últimas 14 velas que consegues ver. O stop fica a um ATR da entrada e o alvo a um e meio, por isso um mercado calmo leva um stop apertado e um mercado violento leva um stop largo. É isso que permite que todos os dias arrisquem a mesma parte da conta faça o gráfico o que fizer, e é por isso que a variável é o tamanho e não o mercado.',
					),
				),
				array(
					'q' => array(
						'en' => 'Can I lose more than the account?',
						'pt' => 'Posso perder mais do que a conta?',
					),
					'a' => array(
						'en' => 'No. A losing day costs exactly the tier you chose — a share of the balance, doubled if you took the doubled stake — and never a cent more. There is no borrowing, no margin call and no debt: the balance cannot go below zero, and the run ends at the $1,000 floor long before it could get near it.',
						'pt' => 'Não. Um dia perdedor custa exatamente o escalão que escolheste — uma parte do saldo, a dobrar se tiveres dobrado a aposta — e nunca mais um cêntimo. Não há empréstimo, não há chamada de margem e não há dívida: o saldo não pode descer abaixo de zero, e a corrida acaba no chão dos 1 000 $ muito antes de lá chegar perto.',
					),
				),
				array(
					'q' => array(
						'en' => 'What happens when the account is blown?',
						'pt' => 'O que acontece quando a conta rebenta?',
					),
					'a' => array(
						'en' => 'At $1,000 or below the run ends. How long it lasted is kept as a record, a fresh $10,000 account starts the next day, and the summary shows the average risk per trade that got you there — which is almost always the actual cause.',
						'pt' => 'Nos 1 000 $ ou abaixo, a corrida acaba. Fica registado quanto tempo durou, começa uma conta nova de 10 000 $ no dia seguinte, e o resumo mostra o risco médio por operação que te levou até lá — que é quase sempre a causa verdadeira.',
					),
				),
				array(
					'q' => array(
						'en' => 'Does passing count against me?',
						'pt' => 'Passar conta contra mim?',
					),
					'a' => array(
						'en' => 'No, and that is deliberate. Passing never breaks the streak and never costs capital. A game that punished sitting out would be teaching people to trade when there is nothing to trade, which is a habit that empties accounts in the real world too.',
						'pt' => 'Não, e isso é de propósito. Passar nunca quebra a série nem custa capital. Um jogo que castigasse ficar de fora estava a ensinar a operar quando não há nada para operar, que é um hábito que também esvazia contas no mundo real.',
					),
				),
				array(
					'q' => array(
						'en' => 'Why only one challenge a day?',
						'pt' => 'Porquê só um desafio por dia?',
					),
					'a' => array(
						'en' => 'Because the lesson is what a sequence of decisions does to an account, and a sequence needs decisions that cost something. Unlimited retries would turn the same screen into a machine you pull until it pays, which teaches the opposite of the thing being taught.',
						'pt' => 'Porque a lição é o que uma sequência de decisões faz a uma conta, e uma sequência precisa de decisões que custem alguma coisa. Repetições sem limite transformavam o mesmo ecrã numa máquina que se puxa até pagar, o que ensina o contrário do que está a ser ensinado.',
					),
				),
				array(
					'q' => array(
						'en' => 'Do the same charts come back?',
						'pt' => 'Os mesmos gráficos voltam a aparecer?',
					),
					'a' => array(
						'en' => 'Not for a very long time. The rotation walks the entire published pool in a fixed order before it returns to the beginning, and the pool holds about a year of charts. A day already answered cannot be replayed either: the decision is recorded once and the outcome is shown once, which is what stops the game becoming a thing you retry until it pays.',
						'pt' => 'Só ao fim de muito tempo. A rotação percorre todo o conjunto publicado por uma ordem fixa antes de voltar ao início, e o conjunto tem cerca de um ano de gráficos. Um dia já respondido também não se repete: a decisão é registada uma vez e o resultado é mostrado uma vez, e é isso que impede o jogo de se tornar uma coisa que se repete até pagar.',
					),
				),
				array(
					'q' => array(
						'en' => 'What happens to my email address if I give one?',
						'pt' => 'O que acontece ao meu email se eu der um?',
					),
					'a' => array(
						'en' => 'It is used to send you a sign-in link, and that is the whole of it. The games tables hold no email address and no IP address; the address itself lives in the ordinary WordPress user record, so deleting the account deletes it. You can also remove everything — the run, the results and the nickname — from the profile page at any time, without asking anybody.',
						'pt' => 'Serve para te enviar uma ligação de entrada, e é só isso. As tabelas dos jogos não guardam nenhum endereço de email nem nenhum endereço IP; o endereço em si vive no registo normal de utilizador do WordPress, por isso apagar a conta apaga-o. Também podes remover tudo — a corrida, os resultados e a alcunha — na página do perfil, quando quiseres e sem pedir nada a ninguém.',
					),
				),
				array(
					'q' => array(
						'en' => 'Why does the leaderboard rank by risk-adjusted result instead of profit?',
						'pt' => 'Porque é a classificação por resultado ajustado ao risco e não por lucro?',
					),
					'a' => array(
						'en' => 'Because the biggest gain on any given day belongs to whoever risked the most, so a board ranked by profit would put "bet a quarter of the account" at the top of a public list — the exact habit the game exists to argue against. Instead every decision is scored as though it had been taken at 1% of the account, whatever tier was actually used. Two players who read the same chart the same way score the same, and a larger position buys nothing on the board except the drawdown that comes with it.',
						'pt' => 'Porque o maior ganho de um dia qualquer é de quem arriscou mais, e uma tabela ordenada por lucro punha "apostar um quarto da conta" no topo de uma lista pública — exatamente o hábito contra o qual o jogo existe. Em vez disso, cada decisão é pontuada como se tivesse sido tomada a 1% da conta, seja qual for o escalão usado. Dois jogadores que leem o mesmo gráfico da mesma maneira pontuam igual, e uma posição maior não compra nada na tabela a não ser a queda que vem com ela.',
					),
				),
			),
			'reveal'      => array(
				array(
					'q' => array(
						'en' => 'Are the companies real?',
						'pt' => 'As empresas são reais?',
					),
					'a' => array(
						'en' => 'Yes. Every case is a real company at a real year, at least five years in the past, with its figures and its five-year return checked against a published source before it can be served. A case that has not been verified is refused by the query that picks the day, not merely hidden.',
						'pt' => 'Sim. Cada caso é uma empresa real num ano real, com pelo menos cinco anos, e os números e o retorno a cinco anos são verificados contra uma fonte publicada antes de poder ser servido. Um caso não verificado é recusado pela própria consulta que escolhe o dia, não apenas escondido.',
					),
				),
				array(
					'q' => array(
						'en' => 'Is naming a company here a view on it?',
						'pt' => 'Nomear uma empresa aqui é uma opinião sobre ela?',
					),
					'a' => array(
						'en' => 'No. The name appears only after your decision, only for a moment at least five years in the past, and only alongside what already happened. Nothing in the game is a view on that company today, and nothing here is a recommendation to buy or sell anything.',
						'pt' => 'Não. O nome aparece só depois da tua decisão, só para um momento com pelo menos cinco anos, e só ao lado do que já aconteceu. Nada no jogo é uma opinião sobre essa empresa hoje, e nada aqui é recomendação de compra ou venda de seja o que for.',
					),
				),
				array(
					'q' => array(
						'en' => 'How is the result worked out?',
						'pt' => 'Como é calculado o resultado?',
					),
					'a' => array(
						'en' => 'From what the company actually returned over the five years after the year in the dossier, applied to the share of the account you committed, and shown next to what a broad index did over the same period. The index line is the point of comparison: doing nothing is a strategy, and it wins more often than people expect.',
						'pt' => 'A partir do que a empresa rendeu de facto nos cinco anos seguintes ao ano do dossiê, aplicado à parte da conta que comprometeste, e mostrado ao lado do que um índice largo fez no mesmo período. A linha do índice é o ponto de comparação: não fazer nada é uma estratégia, e ganha mais vezes do que se espera.',
					),
				),
			),
			'leaderboard' => array(
				array(
					'q' => array(
						'en' => 'Why is the daily board not simply the biggest gain?',
						'pt' => 'Porque não é a tabela diária simplesmente o maior ganho?',
					),
					'a' => array(
						'en' => 'Because the biggest gain on any one day belongs to whoever risked the most, and a board that rewarded that would teach exactly the habit the game exists to argue against. Scoring per unit of risk taken means a larger position is not a shortcut up the table.',
						'pt' => 'Porque o maior ganho num dia qualquer é de quem arriscou mais, e uma tabela que premiasse isso ensinava exatamente o hábito contra o qual o jogo existe. Pontuar por unidade de risco assumido faz com que uma posição maior não seja um atalho para subir.',
					),
				),
				array(
					'q' => array(
						'en' => 'What is shown about me?',
						'pt' => 'O que é mostrado sobre mim?',
					),
					'a' => array(
						'en' => 'A nickname you choose and the score for the day, and nothing else. No real name, no email address and no location ever appears on a board. Playing without a nickname is fine — you simply do not appear.',
						'pt' => 'Uma alcunha que escolhes e a pontuação do dia, e mais nada. Nenhum nome real, nenhum endereço de email e nenhuma localização aparecem alguma vez numa tabela. Jogar sem alcunha é perfeitamente possível — simplesmente não apareces.',
					),
				),
				array(
					'q' => array(
						'en' => 'Is there anything to win?',
						'pt' => 'Há alguma coisa a ganhar?',
					),
					'a' => array(
						'en' => 'Nothing at all. There is no prize, no reward and no ranking that entitles anybody to anything. The board exists to make a run feel worth continuing, and that is the whole of it.',
						'pt' => 'Nada. Não há prémio, não há recompensa e não há classificação que dê direito a coisa nenhuma. A tabela existe para que uma corrida valha a pena continuar, e é só isso.',
					),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin surface
	 * ------------------------------------------------------------------- */

	/**
	 * Seeder panel on the settings screen: the button, and what the last sync
	 * did.
	 */
	public static function render_panel(): void {
		$last = get_option( self::OPTION_LAST );
		$last = is_array( $last ) ? $last : array();
		?>
		<h2><?php esc_html_e( 'Seed / sync the /games/ pages', 'hti-games' ); ?></h2>
		<?php if ( isset( $_GET['hti_games_seeded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: pages created, 2: pages updated, 3: pages unchanged. */
					esc_html__( 'Seeder ran: %1$s created, %2$s updated, %3$s unchanged.', 'hti-games' ),
					esc_html( sanitize_key( wp_unslash( $_GET['hti_games_seeded'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_games_updated'] ?? '0' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_games_unchanged'] ?? '0' ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Creates the five pages of the section in English and Portuguese, links each pair in Polylang, and updates the ones whose repo content changed. Slugs, statuses and pages you deleted are never touched; after a deploy the update runs automatically in the background, but creating a missing page always stays a button press.', 'hti-games' ); ?></p>
		<?php if ( ! empty( $last['time'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: UTC timestamp, 2: auto|manual, 3: created, 4: updated, 5: unchanged, 6: missing. */
					esc_html__( 'Last sync %1$s UTC (%2$s): %3$s created, %4$s updated, %5$s unchanged, %6$s not created.', 'hti-games' ),
					esc_html( (string) $last['time'] ),
					esc_html( (string) ( $last['mode'] ?? 'auto' ) ),
					esc_html( (string) ( $last['created'] ?? 0 ) ),
					esc_html( (string) ( $last['updated'] ?? 0 ) ),
					esc_html( (string) ( $last['unchanged'] ?? 0 ) ),
					esc_html( (string) ( $last['missing'] ?? 0 ) )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'The seeder has not run on this site yet.', 'hti-games' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hti_games_seed" />
			<?php wp_nonce_field( 'hti_games_seed' ); ?>
			<?php submit_button( __( 'Seed games pages', 'hti-games' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the seeder form submission.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-games' ) );
		}
		check_admin_referer( 'hti_games_seed' );

		$report = self::seed( true, 'manual' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => Settings::PAGE,
					'hti_games_seeded'    => (string) $report['created'],
					'hti_games_updated'   => (string) $report['updated'],
					'hti_games_unchanged' => (string) $report['unchanged'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Register `wp hti-games seed`, the deploy-script path onto a site where
	 * clicking a button is not an option.
	 */
	private static function register_cli(): void {
		\WP_CLI::add_command(
			'hti-games seed',
			function () {
				$report = self::seed( true, 'manual' );
				\WP_CLI::success(
					sprintf(
						'%d pages created (%d Portuguese translations linked), %d updated, %d unchanged.',
						$report['created'],
						$report['translated'],
						$report['updated'],
						$report['unchanged']
					)
				);
			}
		);
	}
}
