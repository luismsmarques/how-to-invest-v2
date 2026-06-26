<?php
/**
 * Content seeder for the SEO foundation (Phase 1, task 1.5).
 *
 * Creates the starter glossary terms (the curated per-asset-class notes,
 * Textos Finais §2) and the institutional/legal pages that the legacy 301s
 * point to. Idempotent: entries are matched by slug and skipped if they
 * already exist, so user edits are never overwritten.
 *
 * Bilingual: the English variant is the post title/content; the Portuguese
 * variant travels in the same entry (and is also stored in post meta —
 * `hti_title_pt`, `hti_content_pt`, `hti_excerpt_pt`). When Polylang is
 * active, the seeder additionally creates a real Portuguese post for each
 * entry and links it to the English one as a translation (the EN/PT glossary
 * topic terms are linked too). Without Polylang it degrades gracefully to the
 * English posts plus the PT meta.
 *
 * Run it from WP-CLI (`wp hti seed`) or Tools → Seed content in wp-admin.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds starter content. Safe to run repeatedly.
 */
class Seeder {

	/**
	 * Meta key flagging a seeded entry (for traceability).
	 */
	private const SEED_FLAG = '_hti_seeded';

	/**
	 * Register the admin tools page and its form handler.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_run_seeder', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Seed everything. Returns a report: created/skipped counts.
	 *
	 * @return array{glossary_created:int,pages_created:int,articles_created:int,translations_created:int,skipped:int}
	 */
	public static function seed(): array {
		$report = array(
			'glossary_created'     => 0,
			'pages_created'        => 0,
			'articles_created'     => 0,
			'translations_created' => 0,
			'skipped'              => 0,
		);

		foreach ( self::glossary_terms() as $entry ) {
			$id = self::insert( 'glossary', $entry );
			if ( $id > 0 ) {
				++$report['glossary_created'];
			} else {
				++$report['skipped'];
			}
		}

		// Group the seeded glossary terms under an "Asset classes" topic so the
		// internal-linking hub works out of the box.
		self::assign_glossary_topic();

		foreach ( self::pages() as $entry ) {
			$id = self::insert( 'page', $entry );
			if ( $id > 0 ) {
				++$report['pages_created'];

				// Register the privacy page with WordPress (GDPR alignment).
				if ( 'privacy-policy' === $entry['slug'] ) {
					update_option( 'wp_page_for_privacy_policy', $id );
				}
			} else {
				++$report['skipped'];
			}
		}

		// Move any legacy seeded articles (old `post` type) to the `learn` CPT.
		self::migrate_legacy_articles();

		foreach ( self::articles() as $entry ) {
			$id = self::insert( 'learn', $entry );
			if ( $id > 0 ) {
				++$report['articles_created'];
			} else {
				++$report['skipped'];
			}
		}

		// Group the seeded articles under their Learn categories.
		self::assign_learn_topic();

		// Ensure the News categories exist for the RSS-AI pipeline to file into.
		self::assign_news_category();

		// If Polylang is active, mirror every seeded entry into a linked
		// Portuguese translation (built from the hti_*_pt variants).
		$report['translations_created'] = self::seed_translations();

		// Render the About page(s) with the designed template/block.
		self::ensure_about_template();

		// Ensure the contact form shortcode is present on existing Contact pages
		// (the seeder is otherwise create-only).
		self::ensure_contact_form();

		// Final pass: re-localize internal links in every PT post now that all
		// EN+PT posts exist (fixes cross-links whose target was seeded later).
		self::relocalize_pt();

		return $report;
	}

	/**
	 * Re-run link localization on every seeded PT post. Idempotent: links that
	 * are already Portuguese won't match the EN patterns. No-op without Polylang.
	 */
	private static function relocalize_pt(): void {
		if ( ! self::polylang_active() ) {
			return;
		}
		$pt = self::portuguese_slug( (string) pll_default_language( 'slug' ) );
		if ( '' === $pt ) {
			return;
		}

		$groups = array(
			'glossary' => self::glossary_terms(),
			'page'     => self::pages(),
			'learn'    => self::articles(),
		);
		foreach ( $groups as $type => $entries ) {
			foreach ( $entries as $entry ) {
				$en = get_page_by_path( $entry['slug'], OBJECT, $type );
				if ( ! $en instanceof \WP_Post ) {
					continue;
				}
				$pt_id = pll_get_post( (int) $en->ID, $pt );
				if ( ! $pt_id ) {
					continue;
				}
				$post = get_post( (int) $pt_id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$new = self::localize_links( (string) $post->post_content, $pt );
				if ( $new !== $post->post_content ) {
					wp_update_post( array( 'ID' => (int) $pt_id, 'post_content' => wp_slash( $new ) ) );
				}
			}
		}
	}

	/**
	 * Append the [hti_contact] form shortcode to the Contact page (and its PT
	 * translation) when it isn't there yet. Idempotent — runs even when the
	 * page already exists (the seeder otherwise never updates existing posts).
	 */
	private static function ensure_contact_form(): void {
		if ( ! function_exists( 'has_shortcode' ) ) {
			return;
		}
		$en = get_page_by_path( 'contact', OBJECT, 'page' );
		if ( ! $en instanceof \WP_Post ) {
			return;
		}

		$targets = array( (int) $en->ID );
		if ( self::polylang_active() ) {
			$default = (string) pll_default_language( 'slug' );
			$pt      = self::portuguese_slug( '' !== $default ? $default : 'en' );
			if ( '' !== $pt ) {
				$pt_id = pll_get_post( (int) $en->ID, $pt );
				if ( $pt_id ) {
					$targets[] = (int) $pt_id;
				}
			}
		}

		foreach ( $targets as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || has_shortcode( (string) $post->post_content, 'hti_contact' ) ) {
				continue;
			}
			$new = rtrim( (string) $post->post_content ) . "\n\n" . '<!-- wp:shortcode -->[hti_contact]<!-- /wp:shortcode -->';
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ) );
		}
	}

	/**
	 * Point the About page (and its PT translation) at the custom
	 * "About page" template, which renders the designed howtoinvest/about
	 * block. Idempotent.
	 */
	private static function ensure_about_template(): void {
		$en = get_page_by_path( 'about', OBJECT, 'page' );
		if ( ! $en instanceof \WP_Post ) {
			return;
		}
		update_post_meta( $en->ID, '_wp_page_template', 'page-about' );

		if ( self::polylang_active() ) {
			$default = (string) pll_default_language( 'slug' );
			$pt      = self::portuguese_slug( '' !== $default ? $default : 'en' );
			if ( '' !== $pt ) {
				$pt_id = pll_get_post( (int) $en->ID, $pt );
				if ( $pt_id ) {
					update_post_meta( (int) $pt_id, '_wp_page_template', 'page-about' );
				}
			}
		}
	}

	/**
	 * Create the Portuguese translations of the seeded content and link them
	 * to their English counterparts in Polylang. No-op when Polylang is
	 * inactive. Idempotent: an entry that already has a PT translation is
	 * skipped, so re-running is safe.
	 *
	 * @return int Number of PT posts created.
	 */
	public static function seed_translations(): int {
		if ( ! self::polylang_active() ) {
			return 0;
		}

		$en = (string) pll_default_language( 'slug' );
		if ( '' === $en ) {
			$en = 'en';
		}
		$pt = self::portuguese_slug( $en );
		if ( '' === $pt || $pt === $en ) {
			return 0;
		}

		// Translate the "Asset classes" glossary topic first, so the PT
		// glossary posts can be filed under its PT term.
		self::translate_glossary_topic( $en, $pt );
		self::translate_learn_topic( $en, $pt );
		self::translate_news_category( $en, $pt );

		$created = 0;
		$groups  = array(
			'glossary' => self::glossary_terms(),
			'page'     => self::pages(),
			'learn'    => self::articles(),
		);

		foreach ( $groups as $type => $entries ) {
			foreach ( $entries as $entry ) {
				$en_post = get_page_by_path( $entry['slug'], OBJECT, $type );
				if ( ! $en_post instanceof \WP_Post ) {
					continue;
				}
				$en_id = (int) $en_post->ID;

				// Every post must carry a language; assign the default if missing.
				if ( ! pll_get_post_language( $en_id ) ) {
					pll_set_post_language( $en_id, $en );
				}

				$pt_data = $entry['pt'] ?? array();

				// Already translated → make sure its slug is the translated one
				// (older seeds reused the EN slug), then move on.
				$existing_pt = pll_get_post( $en_id, $pt );
				if ( $existing_pt ) {
					if ( ! empty( $pt_data['title'] ) ) {
						$want = self::pt_slug( $entry['slug'], (string) $pt_data['title'] );
						$post = get_post( (int) $existing_pt );
						if ( $post instanceof \WP_Post && $post->post_name !== $want ) {
							wp_update_post( array( 'ID' => (int) $existing_pt, 'post_name' => $want ) );
						}
					}
					// Re-file existing PT glossary posts under the correct PT topic.
					if ( 'glossary' === $type ) {
						$pt_term = get_term_by( 'slug', self::glossary_topic_of( (string) $entry['slug'] ) . '-' . $pt, 'glossary_topic' );
						if ( $pt_term instanceof \WP_Term ) {
							wp_set_object_terms( (int) $existing_pt, array( (int) $pt_term->term_id ), 'glossary_topic', false );
						}
					}
					// Re-file existing PT articles under the correct PT category.
					if ( 'learn' === $type ) {
						$pt_term = get_term_by( 'slug', self::learn_category_of( (string) $entry['slug'] ) . '-' . $pt, 'learn_topic' );
						if ( $pt_term instanceof \WP_Term ) {
							wp_set_object_terms( (int) $existing_pt, array( (int) $pt_term->term_id ), 'learn_topic', false );
						}
					}
					continue;
				}

				if ( empty( $pt_data['title'] ) ) {
					continue;
				}

				if ( self::insert_translation( $type, $entry, $pt_data, $en_id, $en, $pt ) > 0 ) {
					++$created;
				}
			}
		}

		return $created;
	}

	/**
	 * Curated Portuguese slug for a seeded entry (keyword-rich, for SEO).
	 * Falls back to a sanitized PT title for anything not in the map.
	 *
	 * @param string $en_slug  English slug (the entry key).
	 * @param string $pt_title Portuguese title (fallback source).
	 */
	private static function pt_slug( string $en_slug, string $pt_title ): string {
		$map = array(
			// Glossary.
			'global-equities'                  => 'acoes-globais',
			'bonds'                            => 'obrigacoes',
			'cash'                             => 'liquidez',
			'reits-and-alternatives'           => 'imobiliario-e-alternativos',
			'crypto'                           => 'cripto',
			// Pages.
			'investor-profile-quiz'            => 'questionario-perfil-investidor',
			'my-account'                       => 'a-minha-conta',
			'about'                            => 'sobre',
			'contact'                          => 'contacto',
			'how-to-start-investing'           => 'como-comecar-a-investir',
			'privacy-policy'                   => 'politica-de-privacidade',
			'terms-and-conditions'             => 'termos-e-condicoes',
			// Articles.
			'what-is-an-investor-profile'      => 'o-que-e-um-perfil-de-investidor',
			'asset-classes-explained'          => 'classes-de-ativos-explicadas',
			'why-your-time-horizon-matters'    => 'porque-o-horizonte-temporal-importa',
			'staying-calm-when-markets-fall'   => 'manter-a-calma-quando-os-mercados-caem',
			'why-an-emergency-fund-comes-first' => 'fundo-de-emergencia-primeiro',
			'what-is-diversification'          => 'o-que-e-diversificacao',
			'risk-and-reward-explained'        => 'risco-e-retorno-explicado',
			'what-is-esg-investing'            => 'o-que-e-investimento-esg',
			// Explainer hubs + pages.
			'investor-types'                   => 'perfis-de-investidor',
			'asset-classes'                    => 'classes-de-ativos',
			'preservation-investor'            => 'investidor-de-preservacao',
			'balanced-income-investor'         => 'investidor-de-rendimento-equilibrado',
			'balanced-investor'                => 'investidor-equilibrado',
			'growth-investor'                  => 'investidor-de-crescimento',
			'aggressive-growth-investor'       => 'investidor-de-crescimento-agressivo',
			'global-equities-explained'        => 'acoes-globais-explicadas',
			'bonds-explained'                  => 'obrigacoes-explicadas',
			'cash-explained'                   => 'liquidez-explicada',
			'reits-alternatives-explained'     => 'imobiliario-e-alternativos-explicados',
			'crypto-explained'                 => 'cripto-explicada',
			// Tools hub + calculators.
			'tools'                            => 'ferramentas',
			'compound-interest-calculator'     => 'calculadora-de-juro-composto',
			'inflation-calculator'             => 'calculadora-de-inflacao',
			'savings-goal-calculator'          => 'calculadora-de-meta-de-poupanca',
			'cost-of-waiting-calculator'       => 'calculadora-do-custo-de-esperar',
			'emergency-fund-calculator'        => 'calculadora-de-fundo-de-emergencia',
			'rule-of-72-calculator'            => 'calculadora-da-regra-dos-72',
			'fee-impact-calculator'            => 'calculadora-do-impacto-das-comissoes',
			'allocation-visualizer'            => 'visualizador-de-alocacao',
		);
		return $map[ $en_slug ] ?? sanitize_title( $pt_title );
	}

	/**
	 * Whether Polylang's public API is available.
	 */
	private static function polylang_active(): bool {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_default_language' )
			&& function_exists( 'pll_get_post' );
	}

	/**
	 * Resolve the Portuguese language slug configured in Polylang.
	 *
	 * @param string $default The default language slug (excluded).
	 */
	private static function portuguese_slug( string $default ): string {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return '';
		}
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );

		// Prefer a language whose locale is Portuguese.
		foreach ( $slugs as $i => $slug ) {
			if ( $slug === $default ) {
				continue;
			}
			if ( 0 === stripos( (string) ( $locales[ $i ] ?? '' ), 'pt' ) ) {
				return (string) $slug;
			}
		}
		// Otherwise, the first non-default language.
		foreach ( $slugs as $slug ) {
			if ( $slug !== $default ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Insert one PT post, set its language, share the EN slug and link the pair.
	 *
	 * @param string                $type    Post type.
	 * @param array<string,mixed>   $entry   The full seed entry (for slug/terms).
	 * @param array<string,string>  $pt_data PT title/content/excerpt.
	 * @param int                   $en_id   English post id.
	 * @param string                $en      English language slug.
	 * @param string                $pt      Portuguese language slug.
	 * @return int New PT post id, or 0 on failure.
	 */
	private static function insert_translation( string $type, array $entry, array $pt_data, int $en_id, string $en, string $pt ): int {
		// Re-point internal glossary links to the Portuguese translations'
		// permalinks (the PT glossary posts are seeded before the articles).
		$content = self::localize_links( (string) ( $pt_data['content'] ?? '' ), $pt );

		$postarr = array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $pt_data['title'],
			'post_content' => $content,
			'post_excerpt' => $pt_data['excerpt'] ?? '',
		);

		$pt_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $pt_id ) || 0 === $pt_id ) {
			return 0;
		}
		$pt_id = (int) $pt_id;

		// Set the language first; Polylang then allows a per-language slug.
		// Give the PT post its own translated slug (better SEO than reusing EN).
		pll_set_post_language( $pt_id, $pt );
		wp_update_post( array( 'ID' => $pt_id, 'post_name' => self::pt_slug( $entry['slug'], (string) $pt_data['title'] ) ) );
		pll_save_post_translations( array( $en => $en_id, $pt => $pt_id ) );

		update_post_meta( $pt_id, self::SEED_FLAG, VERSION );
		self::write_seo_meta( $pt_id, (array) ( $pt_data['seo'] ?? array() ) );

		// Mirror the privacy-page option for the PT page (GDPR alignment).
		if ( 'page' === $type && 'privacy-policy' === $entry['slug'] && function_exists( 'pll_set_post_language' ) ) {
			update_option( 'wp_page_for_privacy_policy_' . $pt, $pt_id );
		}

		// File PT glossary posts under the PT topic matching the EN entry.
		if ( 'glossary' === $type ) {
			$pt_term = get_term_by( 'slug', self::glossary_topic_of( (string) $entry['slug'] ) . '-' . $pt, 'glossary_topic' );
			if ( $pt_term instanceof \WP_Term ) {
				wp_set_object_terms( $pt_id, array( (int) $pt_term->term_id ), 'glossary_topic', false );
			}
		}

		// File PT articles under the PT Learn category matching the EN entry.
		if ( 'learn' === $type ) {
			$pt_term = get_term_by( 'slug', self::learn_category_of( (string) $entry['slug'] ) . '-' . $pt, 'learn_topic' );
			if ( $pt_term instanceof \WP_Term ) {
				wp_set_object_terms( $pt_id, array( (int) $pt_term->term_id ), 'learn_topic', false );
			}
		}

		return $pt_id;
	}

	/**
	 * Rewrite internal glossary links in a body of content to point at the
	 * Portuguese translations' permalinks. Matches any
	 * `…/investing-glossary/<slug>/` URL and swaps it for the PT post's
	 * permalink (resolved through Polylang). Slugs with no PT translation are
	 * left untouched.
	 *
	 * @param string $content Block/HTML content.
	 * @param string $pt      Portuguese language slug.
	 */
	private static function localize_links( string $content, string $pt ): string {
		if ( '' === $content || ! function_exists( 'pll_get_post' ) ) {
			return $content;
		}

		$content = (string) preg_replace_callback(
			'#https?://[^"\'\s]*?/investing-glossary/([a-z0-9-]+)/#i',
			static function ( array $m ) use ( $pt ): string {
				$glossary = get_page_by_path( $m[1], OBJECT, 'glossary' );
				if ( ! $glossary instanceof \WP_Post ) {
					return $m[0];
				}
				// pll_get_post returns the PT post for an EN id, or the same id
				// when the matched post is already the PT one — either way the
				// permalink we want is the Portuguese one.
				$pt_id = pll_get_post( (int) $glossary->ID, $pt );
				if ( ! $pt_id ) {
					return $m[0];
				}
				$url = get_permalink( (int) $pt_id );
				return $url ? $url : $m[0];
			},
			$content
		);

		// Internal page/post links: swap the EN root URL for the PT permalink.
		// Cheap strpos guard first; the DB lookup only runs when a link is
		// actually present. The final relocalize pass (run after every PT post
		// exists) guarantees links resolve regardless of seed order.
		foreach ( self::internal_link_slugs() as $slug ) {
			$needle = self::internal_url( $slug );
			if ( false === strpos( $content, $needle ) ) {
				continue;
			}
			$en = self::resolve_en_post( $slug );
			if ( ! $en instanceof \WP_Post ) {
				continue;
			}
			$pt_id = pll_get_post( (int) $en->ID, $pt );
			if ( ! $pt_id ) {
				continue;
			}
			$pt_url = get_permalink( (int) $pt_id );
			if ( $pt_url ) {
				$content = str_replace( $needle, $pt_url, $content );
			}
		}

		return $content;
	}

	/**
	 * Create (once) the PT translation of the "Asset classes" glossary topic
	 * and link it to the EN term.
	 *
	 * @param string $en English language slug.
	 * @param string $pt Portuguese language slug.
	 */
	private static function translate_glossary_topic( string $en, string $pt ): void {
		if ( ! taxonomy_exists( 'glossary_topic' )
			|| ! function_exists( 'pll_set_term_language' )
			|| ! function_exists( 'pll_get_term' )
			|| ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		foreach ( self::glossary_topics() as $slug => $names ) {
			$en_term = get_term_by( 'slug', $slug, 'glossary_topic' );
			if ( ! $en_term instanceof \WP_Term ) {
				continue;
			}

			if ( ! pll_get_term_language( $en_term->term_id ) ) {
				pll_set_term_language( (int) $en_term->term_id, $en );
			}

			if ( pll_get_term( (int) $en_term->term_id, $pt ) ) {
				continue;
			}

			$res = wp_insert_term( $names['pt'], 'glossary_topic', array( 'slug' => $slug . '-' . $pt ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}

			$pt_term_id = (int) $res['term_id'];
			pll_set_term_language( $pt_term_id, $pt );
			pll_save_term_translations( array( $en => (int) $en_term->term_id, $pt => $pt_term_id ) );
		}
	}

	/* -------------------------------------------------------------------------
	 * Learn (articles) categories — mirrors the glossary topic system.
	 * ---------------------------------------------------------------------- */

	/**
	 * Learn category slugs → bilingual names.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function learn_topics(): array {
		return array(
			'getting-started' => array( 'en' => 'Getting started', 'pt' => 'Começar' ),
			'concepts'        => array( 'en' => 'Investing concepts', 'pt' => 'Conceitos de investimento' ),
			'mindset'         => array( 'en' => 'Behaviour & mindset', 'pt' => 'Comportamento e mentalidade' ),
			'planning'        => array( 'en' => 'Planning', 'pt' => 'Planeamento' ),
		);
	}

	/**
	 * Map each article slug to its Learn category (default "concepts").
	 *
	 * @param string $slug Article slug.
	 */
	private static function learn_category_of( string $slug ): string {
		$map = array(
			'what-is-an-investor-profile'       => 'getting-started',
			'asset-classes-explained'           => 'concepts',
			'why-your-time-horizon-matters'     => 'concepts',
			'staying-calm-when-markets-fall'    => 'mindset',
			'why-an-emergency-fund-comes-first' => 'planning',
			'what-is-diversification'           => 'concepts',
			'risk-and-reward-explained'         => 'concepts',
			'what-is-esg-investing'             => 'concepts',
		);
		return $map[ $slug ] ?? 'concepts';
	}

	/**
	 * Convert any legacy seeded article (old `post` type) to the `learn` CPT,
	 * preserving the post (and its links/IDs). Idempotent.
	 */
	private static function migrate_legacy_articles(): void {
		if ( ! function_exists( 'get_posts' ) ) {
			return;
		}
		$legacy = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::SEED_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		foreach ( $legacy as $id ) {
			set_post_type( (int) $id, 'learn' );
		}
	}

	/**
	 * Ensure the Learn categories exist and assign each seeded article to its
	 * category. Idempotent; replaces (so a re-run corrects the category).
	 */
	private static function assign_learn_topic(): void {
		if ( ! taxonomy_exists( 'learn_topic' ) ) {
			return;
		}

		$term_id = array();
		foreach ( self::learn_topics() as $slug => $names ) {
			$existing = term_exists( $slug, 'learn_topic' );
			if ( ! $existing ) {
				$existing = wp_insert_term( $names['en'], 'learn_topic', array( 'slug' => $slug ) );
			}
			if ( is_wp_error( $existing ) ) {
				continue;
			}
			$tid = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			update_term_meta( $tid, 'hti_name_pt', $names['pt'] );
			$term_id[ $slug ] = $tid;
		}

		foreach ( self::articles() as $entry ) {
			$cat = self::learn_category_of( (string) $entry['slug'] );
			if ( ! isset( $term_id[ $cat ] ) ) {
				continue;
			}
			$post = get_page_by_path( $entry['slug'], OBJECT, 'learn' );
			if ( $post instanceof \WP_Post ) {
				wp_set_object_terms( $post->ID, array( $term_id[ $cat ] ), 'learn_topic', false );
			}
		}
	}

	/**
	 * Create the PT translations of the Learn categories and link them.
	 *
	 * @param string $en EN language slug.
	 * @param string $pt PT language slug.
	 */
	private static function translate_learn_topic( string $en, string $pt ): void {
		if ( ! taxonomy_exists( 'learn_topic' )
			|| ! function_exists( 'pll_set_term_language' )
			|| ! function_exists( 'pll_get_term' )
			|| ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		foreach ( self::learn_topics() as $slug => $names ) {
			$en_term = get_term_by( 'slug', $slug, 'learn_topic' );
			if ( ! $en_term instanceof \WP_Term ) {
				continue;
			}
			if ( ! pll_get_term_language( $en_term->term_id ) ) {
				pll_set_term_language( (int) $en_term->term_id, $en );
			}
			if ( pll_get_term( (int) $en_term->term_id, $pt ) ) {
				continue;
			}
			$res = wp_insert_term( $names['pt'], 'learn_topic', array( 'slug' => $slug . '-' . $pt ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$pt_term_id = (int) $res['term_id'];
			pll_set_term_language( $pt_term_id, $pt );
			pll_save_term_translations( array( $en => (int) $en_term->term_id, $pt => $pt_term_id ) );
		}
	}

	/* ----------------------------------------------------------------------
	 * News categories — the taxonomy the RSS-AI generator files articles under.
	 * News posts are AI-generated (not seeded), so we only create the terms;
	 * the generator assigns them by matching the AI's category name.
	 * ---------------------------------------------------------------------- */

	/**
	 * News category slugs → bilingual names. Derived from the RSS feed mix
	 * (broad markets, macro/central banks, companies/earnings, crypto,
	 * commodities/FX, personal finance). The first two mirror the terms the
	 * editor created by hand ("Market analysis", "Stock Analysis").
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function news_categories(): array {
		return array(
			'market-analysis'        => array( 'en' => 'Market analysis', 'pt' => 'Análise de mercado' ),
			'stock-analysis'         => array( 'en' => 'Stock Analysis', 'pt' => 'Análise de ações' ),
			'economy-central-banks'  => array( 'en' => 'Economy & Central Banks', 'pt' => 'Economia e Bancos Centrais' ),
			'companies-earnings'     => array( 'en' => 'Companies & Earnings', 'pt' => 'Empresas e Resultados' ),
			'commodities-currencies' => array( 'en' => 'Commodities & Currencies', 'pt' => 'Matérias-primas e Câmbios' ),
			'cryptocurrencies'       => array( 'en' => 'Cryptocurrencies', 'pt' => 'Criptomoedas' ),
			'personal-finance'       => array( 'en' => 'Personal Finance', 'pt' => 'Finanças Pessoais' ),
		);
	}

	/**
	 * Ensure the News categories exist (idempotent). Matches the editor's
	 * existing terms by name first, so we never duplicate the two they created
	 * by hand; otherwise creates the term with our curated slug. Stores the PT
	 * name as meta for the translation pass.
	 */
	private static function assign_news_category(): void {
		if ( ! taxonomy_exists( 'news_category' ) ) {
			return;
		}

		foreach ( self::news_categories() as $slug => $names ) {
			$existing = get_term_by( 'name', $names['en'], 'news_category' );
			if ( ! $existing instanceof \WP_Term ) {
				$by_slug = term_exists( $slug, 'news_category' );
				if ( $by_slug ) {
					$existing = get_term( (int) ( is_array( $by_slug ) ? $by_slug['term_id'] : $by_slug ), 'news_category' );
				}
			}
			if ( ! $existing instanceof \WP_Term ) {
				$res = wp_insert_term( $names['en'], 'news_category', array( 'slug' => $slug ) );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				$existing = get_term( (int) $res['term_id'], 'news_category' );
			}
			if ( $existing instanceof \WP_Term ) {
				update_term_meta( (int) $existing->term_id, 'hti_name_pt', $names['pt'] );
			}
		}
	}

	/**
	 * Create the PT translations of the News categories and link them in
	 * Polylang. Idempotent; mirrors translate_learn_topic().
	 *
	 * @param string $en EN language slug.
	 * @param string $pt PT language slug.
	 */
	private static function translate_news_category( string $en, string $pt ): void {
		if ( ! taxonomy_exists( 'news_category' )
			|| ! function_exists( 'pll_set_term_language' )
			|| ! function_exists( 'pll_get_term' )
			|| ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		foreach ( self::news_categories() as $slug => $names ) {
			$en_term = get_term_by( 'name', $names['en'], 'news_category' );
			if ( ! $en_term instanceof \WP_Term ) {
				continue;
			}
			if ( ! pll_get_term_language( (int) $en_term->term_id ) ) {
				pll_set_term_language( (int) $en_term->term_id, $en );
			}
			if ( pll_get_term( (int) $en_term->term_id, $pt ) ) {
				continue;
			}
			$pt_existing = get_term_by( 'name', $names['pt'], 'news_category' );
			if ( $pt_existing instanceof \WP_Term ) {
				$pt_term_id = (int) $pt_existing->term_id;
			} else {
				$res = wp_insert_term( $names['pt'], 'news_category', array( 'slug' => $slug . '-' . $pt ) );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				$pt_term_id = (int) $res['term_id'];
			}
			pll_set_term_language( $pt_term_id, $pt );
			pll_save_term_translations( array( $en => (int) $en_term->term_id, $pt => $pt_term_id ) );
		}
	}

	/**
	 * Store SEO title/description as meta for both RankMath and Yoast, so
	 * whichever plugin is active picks them up. No-op when empty.
	 *
	 * @param int                                $id  Post id.
	 * @param array{title?:string,desc?:string}  $seo SEO fields.
	 */
	private static function write_seo_meta( int $id, array $seo ): void {
		$title = trim( (string) ( $seo['title'] ?? '' ) );
		$desc  = trim( (string) ( $seo['desc'] ?? '' ) );
		if ( '' !== $title ) {
			update_post_meta( $id, 'rank_math_title', $title );
			update_post_meta( $id, '_yoast_wpseo_title', $title );
		}
		if ( '' !== $desc ) {
			update_post_meta( $id, 'rank_math_description', $desc );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
		}
	}

	/**
	 * Insert one entry if a post with that slug+type doesn't already exist.
	 *
	 * @param string                                                                         $type  Post type.
	 * @param array{slug:string,title:string,content:string,excerpt?:string,pt:array<string,string>} $entry Entry.
	 * @return int New post ID, or 0 if skipped/failed.
	 */
	private static function insert( string $type, array $entry ): int {
		if ( get_page_by_path( $entry['slug'], OBJECT, $type ) instanceof \WP_Post ) {
			return 0;
		}

		$postarr = array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $entry['title'],
			'post_name'    => $entry['slug'],
			'post_content' => $entry['content'],
			'post_excerpt' => $entry['excerpt'] ?? '',
		);

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) || 0 === $id ) {
			return 0;
		}

		update_post_meta( $id, self::SEED_FLAG, VERSION );
		self::write_seo_meta( (int) $id, (array) ( $entry['seo'] ?? array() ) );

		$pt = $entry['pt'] ?? array();
		if ( ! empty( $pt['title'] ) ) {
			update_post_meta( $id, 'hti_title_pt', wp_slash( $pt['title'] ) );
		}
		if ( ! empty( $pt['content'] ) ) {
			update_post_meta( $id, 'hti_content_pt', wp_slash( $pt['content'] ) );
		}
		if ( ! empty( $pt['excerpt'] ) ) {
			update_post_meta( $id, 'hti_excerpt_pt', wp_slash( $pt['excerpt'] ) );
		}

		return (int) $id;
	}

	/**
	 * Ensure the glossary topics exist and assign each seeded term to its topic
	 * ("Asset classes" or "Key terms"). Idempotent (append, never replaces).
	 */
	private static function assign_glossary_topic(): void {
		if ( ! taxonomy_exists( 'glossary_topic' ) ) {
			return;
		}

		$term_id = array();
		foreach ( self::glossary_topics() as $slug => $names ) {
			$existing = term_exists( $slug, 'glossary_topic' );
			if ( ! $existing ) {
				$existing = wp_insert_term( $names['en'], 'glossary_topic', array( 'slug' => $slug ) );
			}
			if ( is_wp_error( $existing ) ) {
				continue;
			}
			$tid = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			update_term_meta( $tid, 'hti_name_pt', $names['pt'] );
			$term_id[ $slug ] = $tid;
		}

		foreach ( self::glossary_terms() as $entry ) {
			$topic = self::glossary_topic_of( (string) $entry['slug'] );
			if ( ! isset( $term_id[ $topic ] ) ) {
				continue;
			}
			$post = get_page_by_path( $entry['slug'], OBJECT, 'glossary' );
			if ( $post instanceof \WP_Post ) {
				// Replace (not append) so a re-run moves terms to the right topic.
				wp_set_object_terms( $post->ID, array( $term_id[ $topic ] ), 'glossary_topic', false );
			}
		}
	}

	/**
	 * Glossary topic slugs → bilingual names.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	private static function glossary_topics(): array {
		return array(
			'asset-classes' => array( 'en' => 'Asset classes', 'pt' => 'Classes de ativos' ),
			'stocks'        => array( 'en' => 'Stocks & equities', 'pt' => 'Ações e capital próprio' ),
			'bonds-income'  => array( 'en' => 'Bonds & income', 'pt' => 'Obrigações e rendimento' ),
			'funds'         => array( 'en' => 'Funds & ETFs', 'pt' => 'Fundos e ETFs' ),
			'markets'       => array( 'en' => 'Markets & indices', 'pt' => 'Mercados e índices' ),
			'trading'       => array( 'en' => 'Trading & strategies', 'pt' => 'Negociação e estratégias' ),
			'risk'          => array( 'en' => 'Risk & portfolio', 'pt' => 'Risco e carteira' ),
			'economy'       => array( 'en' => 'Economy & policy', 'pt' => 'Economia e política monetária' ),
			'fundamentals'  => array( 'en' => 'Fundamentals & analysis', 'pt' => 'Fundamentos e análise' ),
			'compliance'    => array( 'en' => 'Process & compliance', 'pt' => 'Processos e conformidade' ),
		);
	}

	/**
	 * Map each glossary term slug to its topic. Centralized so a term lands in
	 * exactly one topic. Unknown slugs fall back to "fundamentals".
	 *
	 * @param string $slug Glossary term slug.
	 */
	private static function glossary_topic_of( string $slug ): string {
		static $map = null;
		if ( null === $map ) {
			$groups = array(
				'asset-classes' => array( 'global-equities', 'bonds', 'cash', 'reits-and-alternatives', 'crypto', 'commodities' ),
				'stocks'        => array( 'stock', 'dividend', 'ipo', 'market-capitalization', 'capital-gain' ),
				'bonds-income'  => array( 'fixed-income', 'variable-income', 'zero-coupon-bond', 'yield' ),
				'funds'         => array( 'etf', 'investment-fund' ),
				'markets'       => array( 'bull-market', 'bear-market', 'benchmark', 'nasdaq', 'wall-street', 'spread' ),
				'trading'       => array( 'leverage', 'margin', 'hedge', 'option', 'short-selling', 'value-investing' ),
				'risk'          => array( 'volatility', 'diversification', 'portfolio' ),
				'economy'       => array( 'inflation', 'interest-rate', 'quantitative-easing' ),
				'fundamentals'  => array( 'asset', 'compound-interest', 'cash-flow', 'pe-ratio', 'ebitda' ),
				'compliance'    => array( 'kyc', 'underwriting' ),
			);
			$map = array();
			foreach ( $groups as $topic => $slugs ) {
				foreach ( $slugs as $s ) {
					$map[ $s ] = $topic;
				}
			}
		}
		return $map[ $slug ] ?? 'fundamentals';
	}

	/**
	 * Glossary seed data with the cross-link blocks ("Related terms" /
	 * "Learn more") appended to each term's content.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function glossary_terms(): array {
		return self::with_glossary_links( self::glossary_terms_raw() );
	}

	/**
	 * Glossary seed data — the curated asset-class notes (Textos Finais §2)
	 * plus the A–Z key terms. Raw definitions, before cross-link blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function glossary_terms_raw(): array {
		$terms = array(
			array(
				'slug'    => 'global-equities',
				'title'   => 'Global equities',
				'excerpt' => 'Shares in companies around the world — the growth engine of a portfolio.',
				'content' => self::paragraph( 'Global equities are the growth engine of a portfolio — shares in companies around the world. Over long periods they\'ve tended to grow the most, but they also swing the most along the way. The longer your horizon, the more time they have to ride out the bumps.' ),
				'pt'      => array(
					'title'   => 'Ações globais',
					'excerpt' => 'Participações em empresas de todo o mundo — o motor de crescimento de uma carteira.',
					'content' => self::paragraph( 'As ações globais são o motor de crescimento de uma carteira — participações em empresas de todo o mundo. Em períodos longos, tendem a crescer mais, mas também oscilam mais pelo caminho. Quanto maior o teu horizonte, mais tempo têm para absorver os altos e baixos.' ),
				),
			),
			array(
				'slug'    => 'bonds',
				'title'   => 'Bonds',
				'excerpt' => 'Loans to governments or companies that pay you interest.',
				'content' => self::paragraph( 'Bonds are loans to governments or companies that pay you interest. They usually move more gently than shares, which is why they\'re often used to add stability and soften the ups and downs of a portfolio.' ),
				'pt'      => array(
					'title'   => 'Obrigações',
					'excerpt' => 'Empréstimos a governos ou empresas que te pagam juros.',
					'content' => self::paragraph( 'As obrigações são empréstimos a governos ou empresas que te pagam juros. Costumam mexer-se de forma mais suave do que as ações, por isso são muitas vezes usadas para dar estabilidade e atenuar os altos e baixos de uma carteira.' ),
				),
			),
			array(
				'slug'    => 'cash',
				'title'   => 'Cash',
				'excerpt' => 'Money you can reach quickly without much risk to its value.',
				'content' => self::paragraph( 'Cash and equivalents are money you can reach quickly without much risk to its value. It grows little, but it\'s there when you need it — useful for short-term needs and peace of mind.' ),
				'pt'      => array(
					'title'   => 'Liquidez',
					'excerpt' => 'Dinheiro a que consegues chegar depressa sem grande risco para o seu valor.',
					'content' => self::paragraph( 'A liquidez e equivalentes são dinheiro a que consegues chegar depressa sem grande risco para o seu valor. Cresce pouco, mas está lá quando precisas — útil para necessidades de curto prazo e tranquilidade.' ),
				),
			),
			array(
				'slug'    => 'reits-and-alternatives',
				'title'   => 'REITs & alternatives',
				'excerpt' => 'Real estate and other assets that don\'t always move in step with shares and bonds.',
				'content' => self::paragraph( 'This bucket covers things like real estate or other assets that don\'t always move in step with shares and bonds. A small slice can add variety, which sometimes helps smooth the overall ride.' ),
				'pt'      => array(
					'title'   => 'Imobiliário e alternativos',
					'excerpt' => 'Imobiliário e outros ativos que nem sempre se movem ao ritmo das ações e obrigações.',
					'content' => self::paragraph( 'Este grupo cobre coisas como imobiliário ou outros ativos que nem sempre se movem ao mesmo ritmo das ações e obrigações. Uma pequena fatia pode acrescentar variedade, o que por vezes ajuda a suavizar o percurso global.' ),
				),
			),
			array(
				'slug'    => 'crypto',
				'title'   => 'Crypto',
				'excerpt' => 'A young, highly volatile category — only ever a very small, optional slice.',
				'content' => self::paragraph( 'Crypto is a young, highly volatile category — it can rise and fall sharply in short periods. If it appears in an example at all, it\'s only as a very small, optional slice, and only for profiles with a long horizon and a solid financial base.' ),
				'pt'      => array(
					'title'   => 'Cripto',
					'excerpt' => 'Categoria jovem e muito volátil — sempre apenas uma fatia muito pequena e opcional.',
					'content' => self::paragraph( 'A cripto é uma categoria jovem e muito volátil — pode subir e descer bruscamente em curtos períodos. Se aparecer num exemplo, é apenas como uma fatia muito pequena e opcional, e só para perfis com horizonte longo e uma base financeira sólida.' ),
				),
			),
		);

		return array_merge( $terms, self::glossary_key_terms() );
	}

	/**
	 * Append the cross-link blocks to each glossary term's EN + PT content.
	 *
	 * @param array<int,array<string,mixed>> $terms Raw glossary entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_glossary_links( array $terms ): array {
		foreach ( $terms as &$entry ) {
			$slug             = (string) $entry['slug'];
			$entry['content'] = (string) $entry['content'] . self::glossary_related( $slug, 'en' );
			if ( isset( $entry['pt']['content'] ) ) {
				$entry['pt']['content'] = (string) $entry['pt']['content'] . self::glossary_related( $slug, 'pt' );
			}
		}
		unset( $entry );
		return $terms;
	}

	/**
	 * The "Related terms" (same-topic siblings) + "Learn more" (deep page)
	 * block for one glossary term.
	 *
	 * @param string $slug Glossary slug.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function glossary_related( string $slug, string $lang ): string {
		$siblings = array();
		foreach ( self::glossary_topic_members( self::glossary_topic_of( $slug ) ) as $other ) {
			if ( $other === $slug ) {
				continue;
			}
			$siblings[] = array( self::gloss_url( $other ), self::glossary_title( $other, $lang ) );
			if ( count( $siblings ) >= 4 ) {
				break;
			}
		}

		$out = self::links_para(
			'pt' === $lang ? 'Termos relacionados' : 'Related terms',
			$siblings
		);

		$deep = self::glossary_deep_link( $slug, $lang );
		if ( $deep ) {
			$out .= self::links_para( 'pt' === $lang ? 'Saber mais' : 'Learn more', array( $deep ) );
		}
		return $out;
	}

	/**
	 * Curated deep link from a glossary term to its best explainer/tool/article.
	 *
	 * @param string $slug Glossary slug.
	 * @param string $lang 'en' or 'pt'.
	 * @return array{0:string,1:string}|null [url, label]
	 */
	private static function glossary_deep_link( string $slug, string $lang ): ?array {
		$map = array(
			'global-equities'        => array( 'global-equities-explained', 'Global equities, explained', 'Ações globais, explicadas' ),
			'bonds'                  => array( 'bonds-explained', 'Bonds, explained', 'Obrigações, explicadas' ),
			'cash'                   => array( 'cash-explained', 'Cash & equivalents, explained', 'Liquidez e equivalentes, explicada' ),
			'reits-and-alternatives' => array( 'reits-alternatives-explained', 'Real estate & alternatives, explained', 'Imobiliário e alternativos, explicados' ),
			'crypto'                 => array( 'crypto-explained', 'Crypto, explained', 'Cripto, explicada' ),
			'commodities'            => array( 'asset-classes', 'Asset classes', 'Classes de ativos' ),
			'asset'                  => array( 'asset-classes', 'Asset classes', 'Classes de ativos' ),
			'fixed-income'           => array( 'bonds-explained', 'Bonds, explained', 'Obrigações, explicadas' ),
			'variable-income'        => array( 'global-equities-explained', 'Global equities, explained', 'Ações globais, explicadas' ),
			'yield'                  => array( 'bonds-explained', 'Bonds, explained', 'Obrigações, explicadas' ),
			'dividend'               => array( 'global-equities-explained', 'Global equities, explained', 'Ações globais, explicadas' ),
			'compound-interest'      => array( 'compound-interest-calculator', 'Compound interest calculator', 'Calculadora de juro composto' ),
			'inflation'              => array( 'inflation-calculator', 'Inflation calculator', 'Calculadora de inflação' ),
			'diversification'        => array( 'what-is-diversification', 'What is diversification?', 'O que é diversificação?' ),
			'portfolio'              => array( 'investor-types', 'Investor types', 'Perfis de investidor' ),
			'volatility'             => array( 'staying-calm-when-markets-fall', 'Staying calm when markets fall', 'Manter a calma quando os mercados caem' ),
			'bull-market'            => array( 'staying-calm-when-markets-fall', 'Staying calm when markets fall', 'Manter a calma quando os mercados caem' ),
			'bear-market'            => array( 'staying-calm-when-markets-fall', 'Staying calm when markets fall', 'Manter a calma quando os mercados caem' ),
			'value-investing'        => array( 'investor-types', 'Investor types', 'Perfis de investidor' ),
		);
		if ( ! isset( $map[ $slug ] ) ) {
			return null;
		}
		[ $target, $en, $pt ] = $map[ $slug ];
		return array( self::internal_url( $target ), 'pt' === $lang ? $pt : $en );
	}

	/**
	 * All glossary slugs in a topic (cached).
	 *
	 * @param string $topic Topic slug.
	 * @return array<int,string>
	 */
	private static function glossary_topic_members( string $topic ): array {
		static $by_topic = null;
		if ( null === $by_topic ) {
			$by_topic = array();
			foreach ( self::glossary_terms_raw() as $entry ) {
				$by_topic[ self::glossary_topic_of( (string) $entry['slug'] ) ][] = (string) $entry['slug'];
			}
		}
		return $by_topic[ $topic ] ?? array();
	}

	/**
	 * Glossary term title (cached), by language.
	 *
	 * @param string $slug Glossary slug.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function glossary_title( string $slug, string $lang ): string {
		static $titles = null;
		if ( null === $titles ) {
			$titles = array();
			foreach ( self::glossary_terms_raw() as $entry ) {
				$titles[ (string) $entry['slug'] ] = array(
					'en' => (string) $entry['title'],
					'pt' => (string) ( $entry['pt']['title'] ?? $entry['title'] ),
				);
			}
		}
		return $titles[ $slug ][ $lang ] ?? ( $titles[ $slug ]['en'] ?? $slug );
	}

	/**
	 * Glossary term archive URL.
	 *
	 * @param string $slug Glossary slug.
	 */
	private static function gloss_url( string $slug ): string {
		return home_url( '/investing-glossary/' . $slug . '/' );
	}

	/**
	 * A small "Label: a · b · c" paragraph of links. Empty when no items.
	 *
	 * @param string                                $label Localized label.
	 * @param array<int,array{0:string,1:string}>   $items [url, text] pairs.
	 */
	private static function links_para( string $label, array $items ): string {
		if ( ! $items ) {
			return '';
		}
		$parts = array();
		foreach ( $items as $item ) {
			$parts[] = '<a href="' . esc_url( $item[0] ) . '">' . esc_html( $item[1] ) . '</a>';
		}
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">'
			. '<strong>' . esc_html( $label ) . ':</strong> ' . implode( ' · ', $parts )
			. '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * A "Key terms: a · b · c" block linking glossary terms (for articles/pages).
	 *
	 * @param array<int,string> $slugs Glossary slugs.
	 * @param string            $lang  'en' or 'pt'.
	 */
	private static function glossary_links_block( array $slugs, string $lang ): string {
		$items = array();
		foreach ( $slugs as $slug ) {
			$items[] = array( self::gloss_url( $slug ), self::glossary_title( $slug, $lang ) );
		}
		return self::links_para( 'pt' === $lang ? 'Termos-chave' : 'Key terms', $items );
	}

	/**
	 * Glossary A–Z: key financial terms (EN+PT) with SEO title/description.
	 * Filed under the "Key terms" topic. Invariant-safe: definitions only,
	 * no named instruments to buy/sell, no advice.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function glossary_key_terms(): array {
		return array(
			array(
				'slug'    => 'stock',
				'topic'   => 'key-terms',
				'title'   => 'Stock',
				'excerpt' => 'A fraction of a company\'s social capital. The buyer becomes a shareholder or partner in it.',
				'content' => self::paragraph( 'A fraction of a company\'s social capital. The buyer becomes a shareholder or partner in it.' ),
				'seo'     => array( 'title' => 'What is a Stock? A Complete Financial Guide', 'desc' => 'Discover what a stock is, how the stock market works, and how to start investing in shares today. Essential financial literacy guide.' ),
				'pt'      => array(
					'title'   => 'Ação',
					'excerpt' => 'Fração do capital social de uma empresa. Quem a compra torna-se acionista ou sócio da mesma.',
					'content' => self::paragraph( 'Fração do capital social de uma empresa. Quem a compra torna-se acionista ou sócio da mesma.' ),
					'seo'     => array( 'title' => 'O Que é uma Ação Financeira? Guia Completo', 'desc' => 'Descubra o que é uma ação, como funciona o mercado de ações e como se tornar acionista de grandes empresas. Guia essencial de literacia...' ),
				),
			),
			array(
				'slug'    => 'leverage',
				'topic'   => 'key-terms',
				'title'   => 'Leverage',
				'excerpt' => 'The use of borrowed capital (debt) to increase the potential return of an investment.',
				'content' => self::paragraph( 'The use of borrowed capital (debt) to increase the potential return of an investment.' ),
				'seo'     => array( 'title' => 'Financial Leverage: What It Is and How It Works', 'desc' => 'Understand financial leverage, its risks, and how investors use borrowed money to maximize potential returns in the market.' ),
				'pt'      => array(
					'title'   => 'Alavancagem',
					'excerpt' => 'Uso de dívida (capital emprestado) para aumentar o retorno potencial de um investimento.',
					'content' => self::paragraph( 'Uso de dívida (capital emprestado) para aumentar o retorno potencial de um investimento.' ),
					'seo'     => array( 'title' => 'Alavancagem Financeira: O Que É e Como Funciona?', 'desc' => 'Entenda o conceito de alavancagem financeira, os seus riscos e como os investidores usam capital emprestado para maximizar os retornos.' ),
				),
			),
			array(
				'slug'    => 'asset',
				'topic'   => 'key-terms',
				'title'   => 'Asset',
				'excerpt' => 'A resource with economic value that an individual or corporation owns with the expectation that it will provide a future benefit.',
				'content' => self::paragraph( 'A resource with economic value that an individual or corporation owns with the expectation that it will provide a future benefit.' ),
				'seo'     => array( 'title' => 'What Are Financial Assets? Types and Examples', 'desc' => 'Learn the definition of a financial asset, the different asset classes, and how to build a profitable and diversified investment portfo...' ),
				'pt'      => array(
					'title'   => 'Ativo',
					'excerpt' => 'Bem ou direito que possui valor económico e pode ser convertido em dinheiro.',
					'content' => self::paragraph( 'Bem ou direito que possui valor económico e pode ser convertido em dinheiro.' ),
					'seo'     => array( 'title' => 'O Que São Ativos Financeiros? Tipos e Exemplos', 'desc' => 'Aprenda o que é um ativo no mundo financeiro, os diferentes tipos de ativos e como criar um portfólio rentável e diversificado.' ),
				),
			),
			array(
				'slug'    => 'bear-market',
				'topic'   => 'key-terms',
				'title'   => 'Bear Market',
				'excerpt' => 'A prolonged period of declining market prices, typically characterized by pessimism and significant asset devaluation.',
				'content' => self::paragraph( 'A prolonged period of declining market prices, typically characterized by pessimism and significant asset devaluation.' ),
				'seo'     => array( 'title' => 'Bear Market Explained: How to Invest During a Downturn', 'desc' => 'Learn what a bear market is, what causes it, and discover the best strategies to protect your wealth during a financial crisis.' ),
				'pt'      => array(
					'title'   => 'Bear Market',
					'excerpt' => 'Mercado em queda prolongada, geralmente caracterizado por pessimismo e forte desvalorização dos ativos.',
					'content' => self::paragraph( 'Mercado em queda prolongada, geralmente caracterizado por pessimismo e forte desvalorização dos ativos.' ),
					'seo'     => array( 'title' => 'Bear Market: Como Investir num Mercado em Queda?', 'desc' => 'Saiba o que significa Bear Market, as suas causas e conheça as melhores estratégias para proteger o seu dinheiro durante crises finance...' ),
				),
			),
			array(
				'slug'    => 'bull-market',
				'topic'   => 'key-terms',
				'title'   => 'Bull Market',
				'excerpt' => 'A prolonged period of rising market prices, characterized by optimism and constant asset appreciation.',
				'content' => self::paragraph( 'A prolonged period of rising market prices, characterized by optimism and constant asset appreciation.' ),
				'seo'     => array( 'title' => 'Bull Market: What It Is and How to Maximize Profits', 'desc' => 'What is a bull market? Understand this phase of financial optimism and learn how to maximize your gains during an extended market rally.' ),
				'pt'      => array(
					'title'   => 'Bull Market',
					'excerpt' => 'Mercado em alta prolongada, caracterizado por otimismo e valorização constante dos ativos.',
					'content' => self::paragraph( 'Mercado em alta prolongada, caracterizado por otimismo e valorização constante dos ativos.' ),
					'seo'     => array( 'title' => 'Bull Market: O Que É e Como Aproveitar a Alta?', 'desc' => 'O que é um Bull Market? Entenda este conceito de otimismo financeiro e saiba como maximizar os seus lucros num mercado em alta.' ),
				),
			),
			array(
				'slug'    => 'benchmark',
				'topic'   => 'key-terms',
				'title'   => 'Benchmark',
				'excerpt' => 'A standard or index against which the performance of an asset or investment fund can be measured.',
				'content' => self::paragraph( 'A standard or index against which the performance of an asset or investment fund can be measured.' ),
				'seo'     => array( 'title' => 'What is a Financial Benchmark in Investing?', 'desc' => 'Discover what a financial benchmark is and how to use it to accurately evaluate the performance of your investments and mutual funds.' ),
				'pt'      => array(
					'title'   => 'Benchmark',
					'excerpt' => 'Índice de referência utilizado para avaliar o desempenho de um ativo ou fundo de investimento.',
					'content' => self::paragraph( 'Índice de referência utilizado para avaliar o desempenho de um ativo ou fundo de investimento.' ),
					'seo'     => array( 'title' => 'O Que é um Benchmark nos Investimentos?', 'desc' => 'Descubra o que é um benchmark financeiro e como utilizá-lo para avaliar corretamente a rentabilidade dos seus investimentos e fundos.' ),
				),
			),
			array(
				'slug'    => 'market-capitalization',
				'topic'   => 'key-terms',
				'title'   => 'Market Capitalization',
				'excerpt' => 'The total market value of a company\'s outstanding shares of stock.',
				'content' => self::paragraph( 'The total market value of a company\'s outstanding shares of stock.' ),
				'seo'     => array( 'title' => 'Market Capitalization: The Ultimate Guide', 'desc' => 'Learn what market cap is, how to calculate it, and why it\'s a crucial indicator for evaluating stocks and companies.' ),
				'pt'      => array(
					'title'   => 'Capitalização de Mercado',
					'excerpt' => 'Valor total das ações de uma empresa negociadas no mercado.',
					'content' => self::paragraph( 'Valor total das ações de uma empresa negociadas no mercado.' ),
					'seo'     => array( 'title' => 'Capitalização de Mercado: O Guia Definitivo', 'desc' => 'Aprenda o que é o Market Cap, como calcular a capitalização de mercado e por que é um indicador crucial para escolher ações.' ),
				),
			),
			array(
				'slug'    => 'commodities',
				'topic'   => 'key-terms',
				'title'   => 'Commodities',
				'excerpt' => 'Basic raw materials (e.g., gold, oil, wheat) standardized and traded on the global financial market.',
				'content' => self::paragraph( 'Basic raw materials (e.g., gold, oil, wheat) standardized and traded on the global financial market.' ),
				'seo'     => array( 'title' => 'What Are Commodities and How to Invest in Them?', 'desc' => 'Understand the commodities market. Learn about tradable raw materials and how to invest in gold, oil, and agricultural products.' ),
				'pt'      => array(
					'title'   => 'Commodities',
					'excerpt' => 'Matérias-primas básicas (ex: ouro, petróleo, trigo) padronizadas e negociadas no mercado financeiro global.',
					'content' => self::paragraph( 'Matérias-primas básicas (ex: ouro, petróleo, trigo) padronizadas e negociadas no mercado financeiro global.' ),
					'seo'     => array( 'title' => 'O Que São Commodities e Como Investir?', 'desc' => 'Entenda o mercado de commodities. Saiba o que são matérias-primas transacionáveis e como investir em ouro, petróleo e produtos agrícola...' ),
				),
			),
			array(
				'slug'    => 'dividend',
				'topic'   => 'key-terms',
				'title'   => 'Dividend',
				'excerpt' => 'A portion of a company\'s net earnings distributed to its shareholders.',
				'content' => self::paragraph( 'A portion of a company\'s net earnings distributed to its shareholders.' ),
				'seo'     => array( 'title' => 'What Are Dividends? How to Earn Passive Income', 'desc' => 'Find out how dividends work, payout dates, and how to build a portfolio focused on generating monthly passive income.' ),
				'pt'      => array(
					'title'   => 'Dividendo',
					'excerpt' => 'Parcela dos lucros líquidos de uma empresa que é distribuída aos seus acionistas.',
					'content' => self::paragraph( 'Parcela dos lucros líquidos de uma empresa que é distribuída aos seus acionistas.' ),
					'seo'     => array( 'title' => 'O Que São Dividendos? Como Viver de Renda Passiva', 'desc' => 'Descubra como funcionam os dividendos, as datas de pagamento e como construir uma carteira focada em renda passiva mensal.' ),
				),
			),
			array(
				'slug'    => 'diversification',
				'topic'   => 'key-terms',
				'title'   => 'Diversification',
				'excerpt' => 'A risk management strategy that mixes a wide variety of investments within a portfolio.',
				'content' => self::paragraph( 'A risk management strategy that mixes a wide variety of investments within a portfolio.' ),
				'seo'     => array( 'title' => 'Portfolio Diversification: How to Reduce Risk', 'desc' => 'Learn how to diversify your investments. See how spreading your capital across various asset classes can protect your wealth.' ),
				'pt'      => array(
					'title'   => 'Diversificação',
					'excerpt' => 'Estratégia de gestão de risco que consiste em distribuir os investimentos por diferentes tipos de ativos.',
					'content' => self::paragraph( 'Estratégia de gestão de risco que consiste em distribuir os investimentos por diferentes tipos de ativos.' ),
					'seo'     => array( 'title' => 'Diversificação de Carteira: Como Reduzir Riscos', 'desc' => 'Aprenda a diversificar os seus investimentos. Veja como distribuir o seu capital por várias classes de ativos para proteger o seu patri...' ),
				),
			),
			array(
				'slug'    => 'etf',
				'topic'   => 'key-terms',
				'title'   => 'ETF',
				'excerpt' => 'An investment fund traded on stock exchanges, much like stocks.',
				'content' => self::paragraph( 'An investment fund traded on stock exchanges, much like stocks.' ),
				'seo'     => array( 'title' => 'What is an ETF? Benefits and How to Start Investing', 'desc' => 'Everything you need to know about ETFs. Discover index funds, their low costs, and how to invest simply and passively.' ),
				'pt'      => array(
					'title'   => 'ETF',
					'excerpt' => 'Fundo de investimento negociado em bolsa como se fosse uma ação.',
					'content' => self::paragraph( 'Fundo de investimento negociado em bolsa como se fosse uma ação.' ),
					'seo'     => array( 'title' => 'O Que é um ETF? Vantagens e Como Começar', 'desc' => 'Tudo o que precisa de saber sobre ETFs. Descubra os fundos de índice, os seus baixos custos e como investir de forma simples e passiva.' ),
				),
			),
			array(
				'slug'    => 'ebitda',
				'topic'   => 'key-terms',
				'title'   => 'EBITDA',
				'excerpt' => 'Earnings Before Interest, Taxes, Depreciation, and Amortization.',
				'content' => self::paragraph( 'Earnings Before Interest, Taxes, Depreciation, and Amortization.' ),
				'seo'     => array( 'title' => 'What Does EBITDA Mean in Financial Analysis?', 'desc' => 'Learn how to analyze a company\'s financial health. What EBITDA is, how to calculate it, and its importance in corporate valuation.' ),
				'pt'      => array(
					'title'   => 'EBITDA',
					'excerpt' => 'Lucro antes de juros, impostos, depreciação e amortização.',
					'content' => self::paragraph( 'Lucro antes de juros, impostos, depreciação e amortização.' ),
					'seo'     => array( 'title' => 'O Que Significa EBITDA na Análise Financeira?', 'desc' => 'Aprenda a analisar a saúde de uma empresa. O que é o EBITDA, como calculá-lo e a sua importância na avaliação corporativa.' ),
				),
			),
			array(
				'slug'    => 'cash-flow',
				'topic'   => 'key-terms',
				'title'   => 'Cash Flow',
				'excerpt' => 'The net amount of cash and cash-equivalents being transferred into and out of a business.',
				'content' => self::paragraph( 'The net amount of cash and cash-equivalents being transferred into and out of a business.' ),
				'seo'     => array( 'title' => 'Cash Flow: The Engine of Financial Health', 'desc' => 'Understand the importance of Cash Flow. Learn about free cash flow and how to analyze a company\'s actual liquidity.' ),
				'pt'      => array(
					'title'   => 'Fluxo de Caixa',
					'excerpt' => 'Diferença entre as entradas e saídas de dinheiro de uma empresa num determinado período.',
					'content' => self::paragraph( 'Diferença entre as entradas e saídas de dinheiro de uma empresa num determinado período.' ),
					'seo'     => array( 'title' => 'Fluxo de Caixa: O Motor da Saúde Financeira', 'desc' => 'Compreenda a importância do Cash Flow. Saiba o que é fluxo de caixa livre e como analisar a liquidez real de uma empresa.' ),
				),
			),
			array(
				'slug'    => 'investment-fund',
				'topic'   => 'key-terms',
				'title'   => 'Investment Fund',
				'excerpt' => 'A supply of capital belonging to numerous investors used to collectively purchase securities.',
				'content' => self::paragraph( 'A supply of capital belonging to numerous investors used to collectively purchase securities.' ),
				'seo'     => array( 'title' => 'Investment Funds: A Beginner\'s Guide', 'desc' => 'Discover what mutual funds are, the associated fees, and how professional managers can grow your savings.' ),
				'pt'      => array(
					'title'   => 'Fundo de Investimento',
					'excerpt' => 'Veículo gerido por profissionais que reúne capital de vários investidores para aplicar numa carteira.',
					'content' => self::paragraph( 'Veículo gerido por profissionais que reúne capital de vários investidores para aplicar numa carteira.' ),
					'seo'     => array( 'title' => 'Fundos de Investimento: Guia para Principiantes', 'desc' => 'Descubra o que são fundos de investimento, as taxas envolvidas e como os gestores profissionais podem rentabilizar as suas poupanças.' ),
				),
			),
			array(
				'slug'    => 'capital-gain',
				'topic'   => 'key-terms',
				'title'   => 'Capital Gain',
				'excerpt' => 'A profit from the sale of property or an investment.',
				'content' => self::paragraph( 'A profit from the sale of property or an investment.' ),
				'seo'     => array( 'title' => 'Capital Gains: What They Are and How They Work', 'desc' => 'Understand how capital gains work. Learn how to calculate profits from selling stocks or real estate and the associated tax implications.' ),
				'pt'      => array(
					'title'   => 'Ganho de Capital',
					'excerpt' => 'Lucro obtido com a venda de um ativo financeiro ou imobiliário por um preço superior ao seu custo de aquisição.',
					'content' => self::paragraph( 'Lucro obtido com a venda de um ativo financeiro ou imobiliário por um preço superior ao seu custo de aquisição.' ),
					'seo'     => array( 'title' => 'Ganho de Capital: O Que É e Como Funciona?', 'desc' => 'Entenda como funcionam as mais-valias. Saiba como se calcula o ganho de capital na venda de ações ou imóveis e a respetiva tributação....' ),
				),
			),
			array(
				'slug'    => 'hedge',
				'topic'   => 'key-terms',
				'title'   => 'Hedge',
				'excerpt' => 'An investment made with the intention of reducing the risk of adverse price movements in an asset.',
				'content' => self::paragraph( 'An investment made with the intention of reducing the risk of adverse price movements in an asset.' ),
				'seo'     => array( 'title' => 'Hedging: Strategies for Financial Protection', 'desc' => 'What does it mean to hedge? Learn how to protect your investment portfolio against market crashes and high volatility.' ),
				'pt'      => array(
					'title'   => 'Hedge',
					'excerpt' => 'Estratégia de proteção financeira utilizada para reduzir ou anular o risco de flutuações adversas de preços.',
					'content' => self::paragraph( 'Estratégia de proteção financeira utilizada para reduzir ou anular o risco de flutuações adversas de preços.' ),
					'seo'     => array( 'title' => 'Hedging: Estratégias de Proteção Financeira', 'desc' => 'O que é fazer um hedge? Aprenda a proteger a sua carteira de investimentos contra quedas de mercado e forte volatilidade.' ),
				),
			),
			array(
				'slug'    => 'inflation',
				'topic'   => 'key-terms',
				'title'   => 'Inflation',
				'excerpt' => 'The rate at which the general level of prices for goods and services is rising.',
				'content' => self::paragraph( 'The rate at which the general level of prices for goods and services is rising.' ),
				'seo'     => array( 'title' => 'What is Inflation and How to Protect Your Money?', 'desc' => 'Everything about inflation: what causes price hikes, its impact on the cost of living, and the best assets to preserve purchasing power.' ),
				'pt'      => array(
					'title'   => 'Inflação',
					'excerpt' => 'Aumento contínuo e generalizado dos preços de bens e serviços na economia.',
					'content' => self::paragraph( 'Aumento contínuo e generalizado dos preços de bens e serviços na economia.' ),
					'seo'     => array( 'title' => 'O Que é a Inflação e Como Protege o Seu Dinheiro?', 'desc' => 'Tudo sobre inflação: o que causa o aumento dos preços, o impacto no custo de vida e os melhores ativos para manter o poder de compra.' ),
				),
			),
			array(
				'slug'    => 'ipo',
				'topic'   => 'key-terms',
				'title'   => 'IPO',
				'excerpt' => 'The process of offering shares of a private corporation to the public in a new stock issuance.',
				'content' => self::paragraph( 'The process of offering shares of a private corporation to the public in a new stock issuance.' ),
				'seo'     => array( 'title' => 'What is an IPO? How to Participate in Public Offerings', 'desc' => 'Learn what an Initial Public Offering is, its benefits for companies, and how retail investors can buy into them.' ),
				'pt'      => array(
					'title'   => 'IPO',
					'excerpt' => 'Primeira vez que uma empresa de capital fechado vende as suas ações ao público geral numa bolsa.',
					'content' => self::paragraph( 'Primeira vez que uma empresa de capital fechado vende as suas ações ao público geral numa bolsa.' ),
					'seo'     => array( 'title' => 'O Que é um IPO? Como Participar Numa Oferta Pública', 'desc' => 'Saiba o que é uma Oferta Pública Inicial (IPO), as suas vantagens para as empresas e como os investidores de retalho podem participar.' ),
				),
			),
			array(
				'slug'    => 'compound-interest',
				'topic'   => 'key-terms',
				'title'   => 'Compound Interest',
				'excerpt' => 'Interest calculated on the initial principal, which also includes all of the accumulated interest from previous periods.',
				'content' => self::paragraph( 'Interest calculated on the initial principal, which also includes all of the accumulated interest from previous periods.' ),
				'seo'     => array( 'title' => 'The Power of Compound Interest in Investing', 'desc' => 'The 8th wonder of the world: understand the math behind compound interest and how time can multiply your wealth exponentially.' ),
				'pt'      => array(
					'title'   => 'Juros Compostos',
					'excerpt' => 'Juros calculados sobre o capital inicial e também sobre os juros acumulados de períodos anteriores.',
					'content' => self::paragraph( 'Juros calculados sobre o capital inicial e também sobre os juros acumulados de períodos anteriores.' ),
					'seo'     => array( 'title' => 'O Poder dos Juros Compostos nos Investimentos', 'desc' => 'A 8ª maravilha do mundo: entenda a matemática dos juros compostos e como o tempo pode multiplicar o seu património de forma exponencial...' ),
				),
			),
			array(
				'slug'    => 'kyc',
				'topic'   => 'key-terms',
				'title'   => 'KYC',
				'excerpt' => 'The standard practice of verifying the identity of clients to assess potential risks of illegal intentions.',
				'content' => self::paragraph( 'The standard practice of verifying the identity of clients to assess potential risks of illegal intentions.' ),
				'seo'     => array( 'title' => 'What is KYC? Its Importance in Financial Security', 'desc' => 'Understand the Know Your Customer (KYC) process. Discover why banks and brokers require ID verification to prevent fraud and money laun...' ),
				'pt'      => array(
					'title'   => 'KYC',
					'excerpt' => 'Processo regulatório para verificar a identidade, perfil e legalidade dos clientes.',
					'content' => self::paragraph( 'Processo regulatório para verificar a identidade, perfil e legalidade dos clientes.' ),
					'seo'     => array( 'title' => 'O Que é KYC? A Importância na Segurança Financeira', 'desc' => 'Entenda o processo Know Your Customer (KYC). Descubra por que bancos e corretoras exigem documentos de identificação para prevenção de ...' ),
				),
			),
			array(
				'slug'    => 'margin',
				'topic'   => 'key-terms',
				'title'   => 'Margin',
				'excerpt' => 'The money borrowed from a brokerage firm to purchase an investment.',
				'content' => self::paragraph( 'The money borrowed from a brokerage firm to purchase an investment.' ),
				'seo'     => array( 'title' => 'Margin Trading: Risks and Benefits Explained', 'desc' => 'What is margin trading? Discover the dangers and advantages of borrowing money from a brokerage to invest in the stock market.' ),
				'pt'      => array(
					'title'   => 'Margem',
					'excerpt' => 'Dinheiro exigido por uma corretora como garantia para operações alavancadas.',
					'content' => self::paragraph( 'Dinheiro exigido por uma corretora como garantia para operações alavancadas.' ),
					'seo'     => array( 'title' => 'Trading com Margem: Riscos e Benefícios', 'desc' => 'O que é operar com margem? Conheça os perigos e as vantagens de pedir dinheiro emprestado à corretora para investir na bolsa.' ),
				),
			),
			array(
				'slug'    => 'nasdaq',
				'topic'   => 'key-terms',
				'title'   => 'NASDAQ',
				'excerpt' => 'An American stock exchange based in New York City, heavily weighted towards information technology companies.',
				'content' => self::paragraph( 'An American stock exchange based in New York City, heavily weighted towards information technology companies.' ),
				'seo'     => array( 'title' => 'What is the NASDAQ Exchange? Top Companies', 'desc' => 'A guide to the NASDAQ tech index. Discover how the electronic stock exchange works and the largest tech companies listed in the US.' ),
				'pt'      => array(
					'title'   => 'NASDAQ',
					'excerpt' => 'Bolsa de valores dos EUA conhecida por listar as principais empresas tecnológicas globais.',
					'content' => self::paragraph( 'Bolsa de valores dos EUA conhecida por listar as principais empresas tecnológicas globais.' ),
					'seo'     => array( 'title' => 'O Que é a Bolsa NASDAQ? Principais Empresas', 'desc' => 'Guia sobre o índice tecnológico NASDAQ. Descubra como funciona a bolsa eletrónica e as maiores empresas de tech listadas nos EUA.' ),
				),
			),
			array(
				'slug'    => 'option',
				'topic'   => 'key-terms',
				'title'   => 'Option',
				'excerpt' => 'A financial derivative that represents a contract sold by one party to another, offering the right to buy or sell a security.',
				'content' => self::paragraph( 'A financial derivative that represents a contract sold by one party to another, offering the right to buy or sell a security.' ),
				'seo'     => array( 'title' => 'What Are Financial Options? Calls and Puts', 'desc' => 'Intro to derivatives: learn how Call and Put options work and how to use them effectively for speculation or portfolio hedging.' ),
				'pt'      => array(
					'title'   => 'Opção',
					'excerpt' => 'Contrato derivativo que dá o direito de comprar ou vender um ativo a um preço fixo numa data futura.',
					'content' => self::paragraph( 'Contrato derivativo que dá o direito de comprar ou vender um ativo a um preço fixo numa data futura.' ),
					'seo'     => array( 'title' => 'O Que São Opções Financeiras? Calls e Puts', 'desc' => 'Introdução aos derivativos: aprenda como funcionam as opções de compra (Call) e de venda (Put) e como usá-las para especulação ou prote...' ),
				),
			),
			array(
				'slug'    => 'pe-ratio',
				'topic'   => 'key-terms',
				'title'   => 'P/E Ratio',
				'excerpt' => 'The ratio for valuing a company that measures its current share price relative to its per-share earnings.',
				'content' => self::paragraph( 'The ratio for valuing a company that measures its current share price relative to its per-share earnings.' ),
				'seo'     => array( 'title' => 'What is the P/E Ratio? Valuing Stock Prices', 'desc' => 'A practical guide to the Price-to-Earnings Ratio. Learn how investors use P/E to determine if a stock is overvalued or undervalued.' ),
				'pt'      => array(
					'title'   => 'P/E Ratio',
					'excerpt' => 'Rácio Preço/Lucro. Mede a relação entre o preço atual da ação e os lucros.',
					'content' => self::paragraph( 'Rácio Preço/Lucro. Mede a relação entre o preço atual da ação e os lucros.' ),
					'seo'     => array( 'title' => 'O Que é o P/E Ratio? Avaliando o Preço das Ações', 'desc' => 'Guia prático sobre o Rácio Preço/Lucro. Saiba como os investidores usam o P/E para determinar se uma ação está cara ou barata.' ),
				),
			),
			array(
				'slug'    => 'portfolio',
				'topic'   => 'key-terms',
				'title'   => 'Portfolio',
				'excerpt' => 'A collection of financial investments like stocks, bonds, commodities, cash, and cash equivalents.',
				'content' => self::paragraph( 'A collection of financial investments like stocks, bonds, commodities, cash, and cash equivalents.' ),
				'seo'     => array( 'title' => 'What is an Investment Portfolio?', 'desc' => 'How to build and manage your financial portfolio. Asset allocation and portfolio management tips to achieve financial independence.' ),
				'pt'      => array(
					'title'   => 'Portfólio',
					'excerpt' => 'O conjunto de investimentos detidos por um indivíduo ou instituição.',
					'content' => self::paragraph( 'O conjunto de investimentos detidos por um indivíduo ou instituição.' ),
					'seo'     => array( 'title' => 'O Que é um Portfólio de Investimentos?', 'desc' => 'Como construir e gerir o seu portfólio financeiro. Dicas de alocação de ativos e gestão de carteiras para atingir a independência finan...' ),
				),
			),
			array(
				'slug'    => 'quantitative-easing',
				'topic'   => 'key-terms',
				'title'   => 'Quantitative Easing',
				'excerpt' => 'A monetary policy whereby a central bank purchases predetermined amounts of government bonds or other financial assets.',
				'content' => self::paragraph( 'A monetary policy whereby a central bank purchases predetermined amounts of government bonds or other financial assets.' ),
				'seo'     => array( 'title' => 'Quantitative Easing (QE) Explained Simply', 'desc' => 'Understand how central banks inject liquidity into the economy through Quantitative Easing (QE) and its impact on global financial mark...' ),
				'pt'      => array(
					'title'   => 'Quantitative Easing',
					'excerpt' => 'Política monetária em que um banco central cria dinheiro para comprar ativos financeiros.',
					'content' => self::paragraph( 'Política monetária em que um banco central cria dinheiro para comprar ativos financeiros.' ),
					'seo'     => array( 'title' => 'Quantitative Easing (Flexibilização Quantitativa) Explicado', 'desc' => 'Entenda como os bancos centrais injetam liquidez na economia através do Quantitative Easing (QE) e o impacto nos mercados globais.' ),
				),
			),
			array(
				'slug'    => 'fixed-income',
				'topic'   => 'key-terms',
				'title'   => 'Fixed Income',
				'excerpt' => 'A type of investment in which real return rates or periodic income is received at regular intervals at reasonably predictable levels.',
				'content' => self::paragraph( 'A type of investment in which real return rates or periodic income is received at regular intervals at reasonably predictable levels.' ),
				'seo'     => array( 'title' => 'What is Fixed Income and Why You Should Invest?', 'desc' => 'A guide for conservative investors: what fixed income is, how to guarantee predictable returns, and shield yourself from stock market v...' ),
				'pt'      => array(
					'title'   => 'Renda Fixa',
					'excerpt' => 'Investimento onde a rentabilidade é previamente conhecida no momento da aplicação.',
					'content' => self::paragraph( 'Investimento onde a rentabilidade é previamente conhecida no momento da aplicação.' ),
					'seo'     => array( 'title' => 'O Que é a Renda Fixa e Porque Deve Investir?', 'desc' => 'Guia para investidores conservadores: o que é a renda fixa, como garantir retornos previsíveis e proteger-se da volatilidade da bolsa.' ),
				),
			),
			array(
				'slug'    => 'variable-income',
				'topic'   => 'key-terms',
				'title'   => 'Variable Income',
				'excerpt' => 'Investments where the future return cannot be guaranteed or anticipated, such as stocks, being subject to market fluctuations.',
				'content' => self::paragraph( 'Investments where the future return cannot be guaranteed or anticipated, such as stocks, being subject to market fluctuations.' ),
				'seo'     => array( 'title' => 'Variable Income: The Complete Guide for Beginners', 'desc' => 'Learn what variable income is, the main assets (stocks, REITs), and how to invest safely to maximize long-term returns.' ),
				'pt'      => array(
					'title'   => 'Renda Variável',
					'excerpt' => 'Investimentos cuja rentabilidade futura não pode ser garantida nem antecipada, como ações, estando sujeitos às oscilações do mercado.',
					'content' => self::paragraph( 'Investimentos cuja rentabilidade futura não pode ser garantida nem antecipada, como ações, estando sujeitos às oscilações do mercado.' ),
					'seo'     => array( 'title' => 'Renda Variável: O Guia Completo para Iniciantes', 'desc' => 'Saiba o que é renda variável, quais os principais ativos (ações, FIIs) e como investir com segurança para maximizar retornos a longo prazo.' ),
				),
			),
			array(
				'slug'    => 'short-selling',
				'topic'   => 'key-terms',
				'title'   => 'Short Selling',
				'excerpt' => 'A financial strategy where one bets on the decline of an asset\'s price.',
				'content' => self::paragraph( 'A financial strategy where one bets on the decline of an asset\'s price.' ),
				'seo'     => array( 'title' => 'Short Selling: How to Profit from Falling Stocks', 'desc' => 'Understand short selling. Discover the risks and opportunities of betting against the market.' ),
				'pt'      => array(
					'title'   => 'Short Selling',
					'excerpt' => 'Estratégia financeira onde se aposta na queda do preço de um ativo.',
					'content' => self::paragraph( 'Estratégia financeira onde se aposta na queda do preço de um ativo.' ),
					'seo'     => array( 'title' => 'Short Selling: Como Lucrar com a Qeda das Ações', 'desc' => 'Entenda a venda a descoberto (short selling). Descubra os riscos e as oportunidades de apostar contra o mercado.' ),
				),
			),
			array(
				'slug'    => 'spread',
				'topic'   => 'key-terms',
				'title'   => 'Spread',
				'excerpt' => 'The mathematical difference between the price at which someone is willing to buy an asset (bid) and sell (ask).',
				'content' => self::paragraph( 'The mathematical difference between the price at which someone is willing to buy an asset (bid) and sell (ask).' ),
				'seo'     => array( 'title' => 'What is Spread and How Does It Affect Investments?', 'desc' => 'Discover what the bid-ask spread is and how brokers profit from your trades.' ),
				'pt'      => array(
					'title'   => 'Spread',
					'excerpt' => 'A diferença matemática entre o preço pelo qual alguém está disposto a comprar um ativo (bid) e o preço pelo qual alguém está disposto a vender (ask).',
					'content' => self::paragraph( 'A diferença matemática entre o preço pelo qual alguém está disposto a comprar um ativo (bid) e o preço pelo qual alguém está disposto a vender (ask).' ),
					'seo'     => array( 'title' => 'O Que é o Spread e Como Afeta os Investimentos?', 'desc' => 'Descubra o que é o spread bid-ask e como as corretoras lucram com as suas transações.' ),
				),
			),
			array(
				'slug'    => 'interest-rate',
				'topic'   => 'key-terms',
				'title'   => 'Interest Rate',
				'excerpt' => 'The cost of borrowing money or the return on investment for lending money.',
				'content' => self::paragraph( 'The cost of borrowing money or the return on investment for lending money.' ),
				'seo'     => array( 'title' => 'What Are Interest Rates and How They Impact the Economy', 'desc' => 'Understand interest rates, how they affect inflation, credit, and the value of your investments.' ),
				'pt'      => array(
					'title'   => 'Taxa de Juro',
					'excerpt' => 'O custo do dinheiro. Representa a taxa cobrada pelo empréstimo de um capital, ou a remuneração paga pela aplicação de fundos.',
					'content' => self::paragraph( 'O custo do dinheiro. Representa a taxa cobrada pelo empréstimo de um capital, ou a remuneração paga pela aplicação de fundos.' ),
					'seo'     => array( 'title' => 'O Que São Taxas de Juro e Como Impactam a Economia', 'desc' => 'Entenda a taxa de juro, como afeta a inflação, o crédito e o valor dos seus investimentos.' ),
				),
			),
			array(
				'slug'    => 'underwriting',
				'topic'   => 'key-terms',
				'title'   => 'Underwriting',
				'excerpt' => 'The process through which financial institutions evaluate and assume the risk of issuing new securities.',
				'content' => self::paragraph( 'The process through which financial institutions evaluate and assume the risk of issuing new securities.' ),
				'seo'     => array( 'title' => 'Underwriting: The Role of Investment Banks', 'desc' => 'What is underwriting? Learn how banks ensure the success of an IPO by assuming market risks.' ),
				'pt'      => array(
					'title'   => 'Underwriting',
					'excerpt' => 'Processo através do qual instituições financeiras avaliam e assumem o risco na emissão de novos títulos.',
					'content' => self::paragraph( 'Processo através do qual instituições financeiras avaliam e assumem o risco na emissão de novos títulos.' ),
					'seo'     => array( 'title' => 'Underwriting: O Papel dos Bancos de Investimento', 'desc' => 'O que é o underwriting? Saiba como os bancos garantem o sucesso de um IPO assumindo os riscos de mercado.' ),
				),
			),
			array(
				'slug'    => 'volatility',
				'topic'   => 'key-terms',
				'title'   => 'Volatility',
				'excerpt' => 'A statistical measure of the dispersion of returns for a given security or market index.',
				'content' => self::paragraph( 'A statistical measure of the dispersion of returns for a given security or market index.' ),
				'seo'     => array( 'title' => 'Volatility: What It Is and How to Deal With It?', 'desc' => 'Learn what volatility is in financial markets and how to use it to your advantage when investing.' ),
				'pt'      => array(
					'title'   => 'Volatilidade',
					'excerpt' => 'Medida estatística que indica a dispersão dos retornos de um ativo, refletindo a frequência e a intensidade com que o seu preço varia ao longo do tempo.',
					'content' => self::paragraph( 'Medida estatística que indica a dispersão dos retornos de um ativo, refletindo a frequência e a intensidade com que o seu preço varia ao longo do tempo.' ),
					'seo'     => array( 'title' => 'Volatilidade: O Que É e Como Lidar com Ela?', 'desc' => 'Aprenda o que é a volatilidade no mercado financeiro e como usá-la a seu favor na hora de investir.' ),
				),
			),
			array(
				'slug'    => 'value-investing',
				'topic'   => 'key-terms',
				'title'   => 'Value Investing',
				'excerpt' => 'An investment strategy that involves picking stocks that appear to be trading for less than their intrinsic or book value.',
				'content' => self::paragraph( 'An investment strategy that involves picking stocks that appear to be trading for less than their intrinsic or book value.' ),
				'seo'     => array( 'title' => 'Value Investing: Warren Buffett\'s Strategy', 'desc' => 'What is Value Investing? Learn how to identify undervalued stocks and invest like the top billionaires.' ),
				'pt'      => array(
					'title'   => 'Value Investing',
					'excerpt' => 'Estratégia de investimento que consiste em identificar e comprar ações que o mercado está a subvalorizar.',
					'content' => self::paragraph( 'Estratégia de investimento que consiste em identificar e comprar ações que o mercado está a subvalorizar.' ),
					'seo'     => array( 'title' => 'Value Investing: A Estratégia de Warren Buffett', 'desc' => 'O que é o Value Investing? Aprenda a identificar ações subvalorizadas e invista como os grandes bilionários.' ),
				),
			),
			array(
				'slug'    => 'wall-street',
				'topic'   => 'key-terms',
				'title'   => 'Wall Street',
				'excerpt' => 'A street in lower Manhattan that is the original home of the New York Stock Exchange and the historic headquarters of the largest U.S. brokerages.',
				'content' => self::paragraph( 'A street in lower Manhattan that is the original home of the New York Stock Exchange and the historic headquarters of the largest U.S. brokerages.' ),
				'seo'     => array( 'title' => 'Wall Street: The World\'s Financial Hub', 'desc' => 'Learn about the history of Wall Street, the New York Stock Exchange, and its influence on the global economy.' ),
				'pt'      => array(
					'title'   => 'Wall Street',
					'excerpt' => 'Rua localizada em Manhattan, Nova Iorque, que abriga a principal Bolsa de Valores (NYSE).',
					'content' => self::paragraph( 'Rua localizada em Manhattan, Nova Iorque, que abriga a principal Bolsa de Valores (NYSE).' ),
					'seo'     => array( 'title' => 'Wall Street: O Centro Financeiro Mundial', 'desc' => 'Conheça a história de Wall Street, a bolsa de Nova Iorque e a sua influência na economia global.' ),
				),
			),
			array(
				'slug'    => 'yield',
				'topic'   => 'key-terms',
				'title'   => 'Yield',
				'excerpt' => 'The income returned on an investment, such as the interest received from holding a particular security.',
				'content' => self::paragraph( 'The income returned on an investment, such as the interest received from holding a particular security.' ),
				'seo'     => array( 'title' => 'What is Yield in Investing?', 'desc' => 'Discover the meaning of yield and how to calculate the percentage return on your financial assets.' ),
				'pt'      => array(
					'title'   => 'Yield',
					'excerpt' => 'A taxa de rendimento gerada e devolvida por um investimento ao longo do tempo.',
					'content' => self::paragraph( 'A taxa de rendimento gerada e devolvida por um investimento ao longo do tempo.' ),
					'seo'     => array( 'title' => 'O Que é o Yield (Rendimento) nos Investimentos?', 'desc' => 'Descubra o significado de yield e como calcular o retorno percentual dos seus ativos financeiros.' ),
				),
			),
			array(
				'slug'    => 'zero-coupon-bond',
				'topic'   => 'key-terms',
				'title'   => 'Zero-Coupon Bond',
				'excerpt' => 'A debt security that doesn\'t pay interest but is traded at a deep discount, rendering profit at maturity.',
				'content' => self::paragraph( 'A debt security that doesn\'t pay interest but is traded at a deep discount, rendering profit at maturity.' ),
				'seo'     => array( 'title' => 'Zero-Coupon Bonds: What Are They?', 'desc' => 'What are Zero-Coupon Bonds? Learn how they work and why you should consider them for long-term investing.' ),
				'pt'      => array(
					'title'   => 'Zero-Coupon Bond',
					'excerpt' => 'Tipo de obrigação de dívida que não paga juros periodicamente, vendida com desconto face ao valor nominal.',
					'content' => self::paragraph( 'Tipo de obrigação de dívida que não paga juros periodicamente, vendida com desconto face ao valor nominal.' ),
					'seo'     => array( 'title' => 'Obrigações de Cupão Zero: O Que São?', 'desc' => 'O que são Zero-Coupon Bonds? Saiba como funcionam estas obrigações e por que investir para o longo prazo.' ),
				),
			),
		);
	}

	/**
	 * Institutional & legal pages (the 301 targets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pages(): array {
		$legal_notice = self::notice( 'Placeholder content — replace with legally reviewed text before launch.' );

		$pages = array(
			array(
				'slug'    => 'investor-profile-quiz',
				'title'   => 'Discover your investor profile',
				'content' => '<!-- wp:shortcode -->[hti_questionnaire]<!-- /wp:shortcode -->',
				'pt'      => array(
					'title'   => 'Descobre o teu perfil de investidor',
					'content' => '<!-- wp:shortcode -->[hti_questionnaire]<!-- /wp:shortcode -->',
				),
			),
			array(
				'slug'    => 'my-account',
				'title'   => 'My account',
				'content' => '<!-- wp:shortcode -->[hti_account]<!-- /wp:shortcode -->',
				'pt'      => array(
					'title'   => 'A minha conta',
					'content' => '<!-- wp:shortcode -->[hti_account]<!-- /wp:shortcode -->',
				),
			),
			array(
				'slug'    => 'about',
				'title'   => 'About HowToInvest',
				'content' => self::paragraph( 'HowToInvest is an educational platform about investing literacy. We help you understand the building blocks of a portfolio — by asset class, never by specific products — through a short questionnaire and clear, illustrative examples. Nothing here is financial advice.' ),
				'pt'      => array(
					'title'   => 'Sobre a HowToInvest',
					'content' => self::paragraph( 'A HowToInvest é uma plataforma educativa sobre literacia financeira. Ajudamos-te a perceber os blocos de construção de uma carteira — por classe de ativos, nunca por produtos específicos — através de um questionário curto e exemplos claros e ilustrativos. Nada aqui é aconselhamento financeiro.' ),
				),
			),
			array(
				'slug'    => 'contact',
				'title'   => 'Contact',
				'content' => self::paragraph( 'Questions or feedback about the educational content? Send us a message and we’ll get back to you.' )
					. '<!-- wp:shortcode -->[hti_contact]<!-- /wp:shortcode -->',
				'pt'      => array(
					'title'   => 'Contacto',
					'content' => self::paragraph( 'Tens questões ou sugestões sobre o conteúdo educativo? Envia-nos uma mensagem e respondemos assim que possível.' )
						. '<!-- wp:shortcode -->[hti_contact]<!-- /wp:shortcode -->',
				),
			),
			array(
				'slug'    => 'how-to-start-investing',
				'title'   => 'How to start investing',
				'content' => self::heading( 'Start with the basics' )
					. self::paragraph( 'Before thinking about any portfolio, the most useful first step is usually an emergency fund — money kept somewhere safe and easy to reach, so a surprise never forces you to sell investments at a bad time.' )
					. self::heading( 'Understand the building blocks' )
					. self::paragraph( 'Portfolios are built from asset classes — global equities, bonds, cash, real estate and alternatives, and a small optional slice of crypto. Each behaves differently. Our glossary explains them in plain language.' )
					. self::heading( 'Know your time horizon' )
					. self::paragraph( 'Time is an investor\'s biggest ally: the further away your goal, the more ups and downs you can ride out along the way. A profile that fits your horizon and comfort matters more than chasing any single asset.' )
					. '<!-- wp:pattern {"slug":"howtoinvest/cta-questionnaire"} /-->',
				'pt'      => array(
					'title'   => 'Como começar a investir',
					'content' => self::heading( 'Começa pelo básico' )
						. self::paragraph( 'Antes de pensar em qualquer carteira, o primeiro passo mais útil costuma ser um fundo de emergência — dinheiro guardado num sítio seguro e de fácil acesso, para que um imprevisto nunca te obrigue a vender investimentos num mau momento.' )
						. self::heading( 'Percebe os blocos de construção' )
						. self::paragraph( 'As carteiras constroem-se a partir de classes de ativos — ações globais, obrigações, liquidez, imobiliário e alternativos, e uma pequena fatia opcional de cripto. Cada uma comporta-se de forma diferente. O nosso glossário explica-as em linguagem simples.' )
						. self::heading( 'Conhece o teu horizonte temporal' )
						. self::paragraph( 'O tempo é o maior aliado de quem investe: quanto mais longe está o teu objetivo, mais altos e baixos consegues atravessar pelo caminho. Um perfil adequado ao teu horizonte e conforto importa mais do que perseguir um único ativo.' ),
				),
			),
			array(
				'slug'    => 'privacy-policy',
				'title'   => 'Privacy Policy',
				'content' => $legal_notice . self::paragraph( 'This page describes how HowToInvest handles personal data, including anonymous questionnaire sessions, account data, consent for non-essential analytics, and your rights to access, export and delete your data (GDPR).' ),
				'pt'      => array(
					'title'   => 'Política de Privacidade',
					'content' => self::notice( 'Conteúdo provisório — substituir por texto com revisão jurídica antes do lançamento.' ) . self::paragraph( 'Esta página descreve como a HowToInvest trata dados pessoais, incluindo sessões anónimas do questionário, dados de conta, consentimento para analítica não-essencial, e os teus direitos de acesso, exportação e eliminação de dados (RGPD).' ),
				),
			),
			array(
				'slug'    => 'terms-and-conditions',
				'title'   => 'Terms & Conditions',
				'content' => $legal_notice . self::paragraph( 'These terms govern your use of HowToInvest. The platform is educational and does not provide financial, investment, tax or legal advice.' ),
				'pt'      => array(
					'title'   => 'Termos e Condições',
					'content' => self::notice( 'Conteúdo provisório — substituir por texto com revisão jurídica antes do lançamento.' ) . self::paragraph( 'Estes termos regem a utilização da HowToInvest. A plataforma é educativa e não presta aconselhamento financeiro, de investimento, fiscal ou jurídico.' ),
				),
			),
		);

		// Children (archetypes + asset classes + tools) come before the hubs so
		// the hub→child links can be localized once the PT children exist.
		return array_merge( $pages, self::explainer_pages(), self::tool_pages() );
	}

	/**
	 * The Tools hub + the four educational calculators (children first).
	 * Each calculator embeds an [hti_tool] shortcode (see class-tools.php).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function tool_pages(): array {
		$tools = array(
			'compound-interest-calculator' => array(
				'name'     => 'compound',
				'title_en' => 'Compound interest calculator',
				'title_pt' => 'Calculadora de juro composto',
				'intro_en' => 'See how regular contributions can grow over time. Compound growth means your returns can earn returns too — so time in the market often matters more than the amount. Everything below is illustrative, with a hypothetical rate.',
				'intro_pt' => 'Vê como contribuições regulares podem crescer ao longo do tempo. O juro composto significa que os teus retornos também podem gerar retornos — por isso o tempo no mercado costuma importar mais do que o valor. Tudo abaixo é ilustrativo, com uma taxa hipotética.',
			),
			'inflation-calculator'         => array(
				'name'     => 'inflation',
				'title_en' => 'Inflation calculator',
				'title_pt' => 'Calculadora de inflação',
				'intro_en' => 'Inflation slowly reduces what your money can buy. This shows how much purchasing power an amount may lose over time — and how much you would need later to keep the same buying power. Illustrative, with a hypothetical inflation rate.',
				'intro_pt' => 'A inflação reduz lentamente o que o teu dinheiro consegue comprar. Isto mostra quanto poder de compra um valor pode perder ao longo do tempo — e quanto precisarias mais tarde para manter o mesmo poder de compra. Ilustrativo, com uma taxa de inflação hipotética.',
			),
			'savings-goal-calculator'      => array(
				'name'     => 'savings_goal',
				'title_en' => 'Savings goal calculator',
				'title_pt' => 'Calculadora de meta de poupança',
				'intro_en' => 'Have a target in mind? See roughly how much you might set aside each month to get there over a chosen number of years, assuming a hypothetical return. Illustrative only.',
				'intro_pt' => 'Tens um objetivo em mente? Vê aproximadamente quanto poderias pôr de lado por mês para lá chegar num dado número de anos, assumindo um retorno hipotético. Apenas ilustrativo.',
			),
			'cost-of-waiting-calculator'   => array(
				'name'     => 'cost_of_waiting',
				'title_en' => 'The cost of waiting',
				'title_pt' => 'O custo de esperar',
				'intro_en' => 'Starting earlier gives your contributions more time to compound. This compares starting now with waiting a few years — same monthly amount — so you can see what the delay might cost. Illustrative, with a hypothetical rate.',
				'intro_pt' => 'Começar mais cedo dá às tuas contribuições mais tempo para compor. Isto compara começar já com esperar alguns anos — o mesmo valor mensal — para veres o que o atraso pode custar. Ilustrativo, com uma taxa hipotética.',
			),
			'emergency-fund-calculator'    => array(
				'name'     => 'emergency_fund',
				'title_en' => 'Emergency fund calculator',
				'title_pt' => 'Calculadora de fundo de emergência',
				'intro_en' => 'An emergency fund usually comes before any investing — money kept somewhere safe so a surprise never forces you to sell at a bad time. See a target based on your essential expenses, and roughly how long it might take to get there. Illustrative only.',
				'intro_pt' => 'Um fundo de emergência costuma vir antes de qualquer investimento — dinheiro guardado em segurança para que um imprevisto nunca te obrigue a vender num mau momento. Vê um objetivo com base nas tuas despesas essenciais e, aproximadamente, quanto tempo pode demorar a lá chegar. Apenas ilustrativo.',
			),
			'rule-of-72-calculator'        => array(
				'name'     => 'rule_of_72',
				'title_en' => 'Rule of 72 calculator',
				'title_pt' => 'Calculadora da regra dos 72',
				'intro_en' => 'The rule of 72 is a quick mental shortcut: divide 72 by an annual return to estimate how many years money might take to double. See the estimate, how many times it could double over a period, and the resulting multiple. Illustrative, with a hypothetical rate.',
				'intro_pt' => 'A regra dos 72 é um atalho mental rápido: divide 72 por um retorno anual para estimar em quantos anos o dinheiro pode duplicar. Vê a estimativa, quantas vezes pode duplicar num período e o múltiplo resultante. Ilustrativo, com uma taxa hipotética.',
			),
			'fee-impact-calculator'        => array(
				'name'     => 'fee_impact',
				'title_en' => 'Fee impact calculator',
				'title_pt' => 'Calculadora do impacto das comissões',
				'intro_en' => 'Small annual fees can quietly add up over decades. This compares the same illustrative portfolio with and without a yearly fee, so you can see how much the fee might cost over time. Illustrative, with a hypothetical rate — not advice.',
				'intro_pt' => 'Pequenas comissões anuais podem somar silenciosamente ao longo de décadas. Isto compara a mesma carteira ilustrativa com e sem uma comissão anual, para veres quanto a comissão pode custar ao longo do tempo. Ilustrativo, com uma taxa hipotética — não é aconselhamento.',
			),
			'allocation-visualizer'        => array(
				'name'     => 'allocation',
				'title_en' => 'Allocation visualizer',
				'title_pt' => 'Visualizador de alocação',
				'intro_en' => 'Pick one of the five educational investor profiles and see its illustrative allocation by asset class as a donut. The numbers come from our curated profiles — always by asset class, never named instruments, and never advice.',
				'intro_pt' => 'Escolhe um dos cinco perfis educativos de investidor e vê a sua alocação ilustrativa por classes de ativos num gráfico. Os números vêm dos nossos perfis curados — sempre por classes de ativos, nunca instrumentos nomeados, e nunca aconselhamento.',
			),
		);

		$pages = array();
		$tool_terms = array(
			'compound-interest-calculator' => array( 'compound-interest', 'yield' ),
			'inflation-calculator'         => array( 'inflation', 'interest-rate' ),
			'savings-goal-calculator'      => array( 'compound-interest' ),
			'cost-of-waiting-calculator'   => array( 'compound-interest' ),
			'emergency-fund-calculator'    => array( 'inflation', 'diversification' ),
			'rule-of-72-calculator'        => array( 'compound-interest', 'yield' ),
			'fee-impact-calculator'        => array( 'compound-interest', 'investment-fund' ),
			'allocation-visualizer'        => array( 'diversification', 'portfolio' ),
		);
		foreach ( $tools as $slug => $t ) {
			$terms = $tool_terms[ $slug ] ?? array();
			$pages[] = array(
				'slug'    => $slug,
				'title'   => $t['title_en'],
				'excerpt' => $t['intro_en'],
				'content' => self::paragraph( $t['intro_en'] )
					. self::tool_shortcode( $t['name'] )
					. self::glossary_links_block( $terms, 'en' )
					. self::cta(),
				'pt'      => array(
					'title'   => $t['title_pt'],
					'excerpt' => $t['intro_pt'],
					'content' => self::paragraph( $t['intro_pt'] )
						. self::tool_shortcode( $t['name'] )
						. self::glossary_links_block( $terms, 'pt' ),
				),
			);
		}

		// Hub.
		$pages[] = array(
			'slug'    => 'tools',
			'title'   => 'Tools',
			'excerpt' => 'Free, educational calculators about saving and investing — time, inflation, goals and the cost of waiting.',
			'content' => self::paragraph( 'Free, educational calculators to build intuition about saving and investing — time, inflation, goals and the cost of waiting. Each is illustrative, with hypothetical rates, and never advice.' )
				. self::bullets(
					array(
						array( home_url( '/compound-interest-calculator/' ), 'Compound interest', 'see how regular contributions can grow over time.' ),
						array( home_url( '/inflation-calculator/' ), 'Inflation', 'how much buying power your money may lose.' ),
						array( home_url( '/savings-goal-calculator/' ), 'Savings goal', 'how much to set aside monthly to reach a goal.' ),
						array( home_url( '/cost-of-waiting-calculator/' ), 'The cost of waiting', 'what delaying a few years might cost.' ),
						array( home_url( '/emergency-fund-calculator/' ), 'Emergency fund', 'a safety cushion to build before investing.' ),
						array( home_url( '/rule-of-72-calculator/' ), 'Rule of 72', 'a quick estimate of how long money takes to double.' ),
						array( home_url( '/fee-impact-calculator/' ), 'Fee impact', 'how much yearly fees might cost over time.' ),
						array( home_url( '/allocation-visualizer/' ), 'Allocation visualizer', 'see each investor profile by asset class.' ),
					)
				)
				. self::cta(),
			'pt'      => array(
				'title'   => 'Ferramentas',
				'excerpt' => 'Calculadoras gratuitas e educativas sobre poupar e investir — tempo, inflação, objetivos e o custo de esperar.',
				'content' => self::paragraph( 'Calculadoras gratuitas e educativas para ganhares intuição sobre poupar e investir — tempo, inflação, objetivos e o custo de esperar. Cada uma é ilustrativa, com taxas hipotéticas, e nunca aconselhamento.' )
					. self::bullets(
						array(
							array( home_url( '/compound-interest-calculator/' ), 'Juro composto', 'vê como contribuições regulares podem crescer ao longo do tempo.' ),
							array( home_url( '/inflation-calculator/' ), 'Inflação', 'quanto poder de compra o teu dinheiro pode perder.' ),
							array( home_url( '/savings-goal-calculator/' ), 'Meta de poupança', 'quanto pôr de lado por mês para atingir um objetivo.' ),
							array( home_url( '/cost-of-waiting-calculator/' ), 'O custo de esperar', 'o que adiar alguns anos pode custar.' ),
							array( home_url( '/emergency-fund-calculator/' ), 'Fundo de emergência', 'a almofada de segurança a construir antes de investir.' ),
							array( home_url( '/rule-of-72-calculator/' ), 'Regra dos 72', 'uma estimativa rápida do tempo para o dinheiro duplicar.' ),
							array( home_url( '/fee-impact-calculator/' ), 'Impacto das comissões', 'quanto as comissões anuais podem custar ao longo do tempo.' ),
							array( home_url( '/allocation-visualizer/' ), 'Visualizador de alocação', 'vê cada perfil de investidor por classes de ativos.' ),
						)
					),
			),
		);

		return $pages;
	}

	/**
	 * A wp:shortcode block embedding a calculator.
	 *
	 * @param string $name Tool name (compound, inflation, …).
	 */
	private static function tool_shortcode( string $name ): string {
		return '<!-- wp:shortcode -->[hti_tool name="' . $name . '"]<!-- /wp:shortcode -->' . "\n\n";
	}

	/**
	 * Explainer pages: 5 archetype profiles + 5 asset-class deep-dives + 2 hubs.
	 * Allocation tables are built live from Config so the numbers never drift.
	 * Invariant-safe: by asset class, illustrative language, no named instruments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function explainer_pages(): array {
		$pages = array();

		// --- Archetype profile pages (children). ---------------------------
		foreach ( self::archetype_meta() as $id => $m ) {
			$alloc_intro_en = 'Here is the kind of structure a profile like this might explore — by asset class, never specific products. The ranges are illustrative, not a recommendation.';
			$alloc_intro_pt = 'Aqui está o tipo de estrutura que um perfil como este poderia estudar — por classe de ativos, nunca por produtos específicos. Os intervalos são ilustrativos, não uma recomendação.';
			$disc_en        = 'Illustrative example, by asset class only. Not a recommendation — investing involves risk, including loss of capital.';
			$disc_pt        = 'Exemplo ilustrativo, apenas por classe de ativos. Não é uma recomendação — investir envolve risco, incluindo a perda de capital.';

			$pages[] = array(
				'slug'    => $m['slug'],
				'title'   => $m['title_en'],
				'excerpt' => $m['intro_en'],
				'content' => self::paragraph( $m['intro_en'] )
					. self::heading( 'An illustrative allocation' )
					. self::paragraph( $alloc_intro_en )
					. self::alloc_table( $id, 'en' )
					. self::small( $disc_en )
					. self::heading( 'How the pieces fit' )
					. self::paragraph( $m['fit_en'] )
					. self::cta(),
				'pt'      => array(
					'title'   => $m['title_pt'],
					'excerpt' => $m['intro_pt'],
					'content' => self::paragraph( $m['intro_pt'] )
						. self::heading( 'Uma alocação ilustrativa' )
						. self::paragraph( $alloc_intro_pt )
						. self::alloc_table( $id, 'pt' )
						. self::small( $disc_pt )
						. self::heading( 'Como as peças encaixam' )
						. self::paragraph( $m['fit_pt'] ),
				),
			);
		}

		// --- Asset-class deep-dive pages (children). -----------------------
		$asset_terms = array(
			'global-equities-explained'    => array( 'stock', 'dividend', 'volatility' ),
			'bonds-explained'              => array( 'yield', 'fixed-income', 'interest-rate' ),
			'cash-explained'               => array( 'inflation', 'interest-rate' ),
			'reits-alternatives-explained' => array( 'commodities', 'asset' ),
			'crypto-explained'             => array( 'volatility', 'asset' ),
		);
		foreach ( self::asset_meta() as $m ) {
			$terms = $asset_terms[ $m['slug'] ] ?? array();
			$pages[] = array(
				'slug'    => $m['slug'],
				'title'   => $m['title_en'],
				'excerpt' => $m['intro_en'],
				'content' => self::paragraph( $m['intro_en'] )
					. self::heading( 'Its role in a portfolio' )
					. self::paragraph( $m['role_en'] )
					. self::heading( 'Which profiles lean into it' )
					. self::paragraph( $m['profiles_en'] )
					. self::glossary_links_block( $terms, 'en' )
					. self::cta(),
				'pt'      => array(
					'title'   => $m['title_pt'],
					'excerpt' => $m['intro_pt'],
					'content' => self::paragraph( $m['intro_pt'] )
						. self::heading( 'O seu papel numa carteira' )
						. self::paragraph( $m['role_pt'] )
						. self::heading( 'Que perfis se apoiam nela' )
						. self::paragraph( $m['profiles_pt'] )
						. self::glossary_links_block( $terms, 'pt' ),
				),
			);
		}

		// --- Hub: Investor types. ------------------------------------------
		$pages[] = array(
			'slug'    => 'investor-types',
			'title'   => 'Investor types',
			'excerpt' => 'Five educational investor profiles, from cautious to adventurous — each shown as an illustrative allocation by asset class.',
			'content' => self::paragraph( 'Everyone approaches investing a little differently. These five educational profiles describe common starting points — from cautious to adventurous — each shown as an illustrative allocation by asset class. Take the short questionnaire to see which one fits you today.' )
				. self::bullets(
					array(
						array( home_url( '/preservation-investor/' ), 'Preservation', 'protects capital first, with a small growth slice.' ),
						array( home_url( '/balanced-income-investor/' ), 'Balanced income', 'prudent, but open to some growth over a medium horizon.' ),
						array( home_url( '/balanced-investor/' ), 'Balanced', 'an even split between growth and stability.' ),
						array( home_url( '/growth-investor/' ), 'Growth', 'lets global equities do the heavy lifting.' ),
						array( home_url( '/aggressive-growth-investor/' ), 'Aggressive growth', 'almost entirely growth assets, for very long horizons.' ),
					)
				)
				. self::cta(),
			'pt'      => array(
				'title'   => 'Perfis de investidor',
				'excerpt' => 'Cinco perfis de investidor educativos, do mais prudente ao mais arrojado — cada um mostrado como uma alocação ilustrativa por classe de ativos.',
				'content' => self::paragraph( 'Cada pessoa aborda o investimento de forma um pouco diferente. Estes cinco perfis educativos descrevem pontos de partida comuns — do mais prudente ao mais arrojado — cada um mostrado como uma alocação ilustrativa por classe de ativos. Faz o questionário curto para veres qual encaixa em ti hoje.' )
					. self::bullets(
						array(
							array( home_url( '/preservation-investor/' ), 'Preservação', 'protege o capital primeiro, com uma pequena fatia de crescimento.' ),
							array( home_url( '/balanced-income-investor/' ), 'Rendimento equilibrado', 'prudente, mas aberto a algum crescimento num horizonte médio.' ),
							array( home_url( '/balanced-investor/' ), 'Equilibrado', 'uma divisão equilibrada entre crescimento e estabilidade.' ),
							array( home_url( '/growth-investor/' ), 'Crescimento', 'deixa as ações globais fazer o trabalho pesado.' ),
							array( home_url( '/aggressive-growth-investor/' ), 'Crescimento agressivo', 'quase só ativos de crescimento, para horizontes muito longos.' ),
						)
					),
			),
		);

		// --- Hub: Asset classes. -------------------------------------------
		$pages[] = array(
			'slug'    => 'asset-classes',
			'title'   => 'Asset classes',
			'excerpt' => 'The building blocks of any portfolio — global equities, bonds, cash, real estate & alternatives, and crypto — each explained in plain language.',
			'content' => self::paragraph( 'Portfolios are built from a handful of asset classes, each behaving differently. Understanding them is the first step to understanding any portfolio — including the illustrative ones in your result. Here is what each one does.' )
				. self::bullets(
					array(
						array( home_url( '/global-equities-explained/' ), 'Global equities', 'the growth engine — shares in companies worldwide.' ),
						array( home_url( '/bonds-explained/' ), 'Bonds', 'loans that pay interest and add stability.' ),
						array( home_url( '/cash-explained/' ), 'Cash & equivalents', 'money you can reach quickly, for short-term needs.' ),
						array( home_url( '/reits-alternatives-explained/' ), 'Real estate & alternatives', 'variety that does not always move with shares.' ),
						array( home_url( '/crypto-explained/' ), 'Crypto', 'a young, very volatile, strictly optional slice.' ),
					)
				)
				. self::cta(),
			'pt'      => array(
				'title'   => 'Classes de ativos',
				'excerpt' => 'Os blocos de construção de qualquer carteira — ações globais, obrigações, liquidez, imobiliário e alternativos, e cripto — cada um explicado em linguagem simples.',
				'content' => self::paragraph( 'As carteiras constroem-se a partir de um punhado de classes de ativos, cada uma a comportar-se de forma diferente. Compreendê-las é o primeiro passo para compreender qualquer carteira — incluindo as ilustrativas do teu resultado. Aqui está o que cada uma faz.' )
					. self::bullets(
						array(
							array( home_url( '/global-equities-explained/' ), 'Ações globais', 'o motor de crescimento — participações em empresas de todo o mundo.' ),
							array( home_url( '/bonds-explained/' ), 'Obrigações', 'empréstimos que pagam juros e dão estabilidade.' ),
							array( home_url( '/cash-explained/' ), 'Liquidez e equivalentes', 'dinheiro a que chegas depressa, para necessidades de curto prazo.' ),
							array( home_url( '/reits-alternatives-explained/' ), 'Imobiliário e alternativos', 'variedade que nem sempre se move com as ações.' ),
							array( home_url( '/crypto-explained/' ), 'Cripto', 'uma fatia jovem, muito volátil e estritamente opcional.' ),
						)
					),
			),
		);

		return $pages;
	}

	/**
	 * Per-archetype copy (intro from "why this profile" + a short synthesis).
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function archetype_meta(): array {
		return array(
			1 => array(
				'slug'     => 'preservation-investor',
				'title_en' => 'The Preservation investor profile',
				'title_pt' => 'O perfil de investidor de Preservação',
				'intro_en' => 'A profile like this usually puts protecting the money first, because it may be needed soon or because big swings feel uncomfortable. That is why an example for this profile leans heavily on steadier asset classes and keeps growth assets small.',
				'intro_pt' => 'Um perfil como este costuma pôr a proteção do dinheiro em primeiro lugar, porque pode vir a ser preciso em breve ou porque grandes oscilações causam desconforto. Por isso um exemplo para este perfil apoia-se sobretudo em classes mais estáveis e mantém pequena a fatia de crescimento.',
				'fit_en'   => 'Stability leads here: a large share in bonds and cash cushions the ups and downs, while a small slice of global equities leaves room to grow. Crypto is left out for this profile.',
				'fit_pt'   => 'A estabilidade lidera aqui: uma fatia grande em obrigações e liquidez amortece os altos e baixos, enquanto uma pequena fatia de ações globais deixa espaço para crescer. A cripto fica de fora neste perfil.',
			),
			2 => array(
				'slug'     => 'balanced-income-investor',
				'title_en' => 'The Balanced income investor profile',
				'title_pt' => 'O perfil de investidor de Rendimento equilibrado',
				'intro_en' => 'This profile leans toward caution but is open to some growth over a medium horizon. An example here tends to balance steadier classes with a meaningful, but not dominant, slice of shares.',
				'intro_pt' => 'Este perfil inclina-se para a prudência, mas está aberto a algum crescimento num horizonte médio. Um exemplo aqui costuma equilibrar classes mais estáveis com uma fatia de ações relevante, mas não dominante.',
				'fit_en'   => 'Bonds still anchor the example, but global equities take a larger role than in a preservation profile, with real estate and alternatives adding a little variety.',
				'fit_pt'   => 'As obrigações continuam a ancorar o exemplo, mas as ações globais ganham um papel maior do que num perfil de preservação, com imobiliário e alternativos a acrescentar alguma variedade.',
			),
			3 => array(
				'slug'     => 'balanced-investor',
				'title_en' => 'The Balanced investor profile',
				'title_pt' => 'O perfil de investidor Equilibrado',
				'intro_en' => 'This is the middle ground: enough time and comfort to hold a solid share of growth assets, balanced by steadier ones. An example for this profile tends to split fairly evenly between growth and stability.',
				'intro_pt' => 'Este é o meio-termo: tempo e conforto suficientes para manter uma boa fatia de ativos de crescimento, equilibrada por outros mais estáveis. Um exemplo para este perfil costuma dividir-se de forma relativamente equilibrada entre crescimento e estabilidade.',
				'fit_en'   => 'Global equities take the lead, balanced by a meaningful share of bonds. A small, optional slice of crypto may appear for those who want it.',
				'fit_pt'   => 'As ações globais assumem a liderança, equilibradas por uma fatia relevante de obrigações. Uma pequena fatia opcional de cripto pode aparecer para quem a queira.',
			),
			4 => array(
				'slug'     => 'growth-investor',
				'title_en' => 'The Growth investor profile',
				'title_pt' => 'O perfil de investidor de Crescimento',
				'intro_en' => 'With a long horizon and comfort with ups and downs, this profile can let growth assets do the heavy lifting. An example here tends to weight global shares strongly, with a smaller cushion of steadier classes.',
				'intro_pt' => 'Com um horizonte longo e conforto com os altos e baixos, este perfil pode deixar os ativos de crescimento fazer o trabalho pesado. Um exemplo aqui costuma dar bastante peso às ações globais, com uma almofada menor de classes mais estáveis.',
				'fit_en'   => 'Global equities dominate, with bonds providing a smaller cushion. Real estate, alternatives and an optional slice of crypto round out the example.',
				'fit_pt'   => 'As ações globais dominam, com as obrigações a dar uma almofada menor. Imobiliário, alternativos e uma fatia opcional de cripto completam o exemplo.',
			),
			5 => array(
				'slug'     => 'aggressive-growth-investor',
				'title_en' => 'The Aggressive growth investor profile',
				'title_pt' => 'O perfil de investidor de Crescimento agressivo',
				'intro_en' => 'A very long horizon and a high tolerance for volatility let this profile lean almost entirely on growth assets, accepting bigger swings in exchange for more long-term growth potential. An example here keeps steadier classes minimal.',
				'intro_pt' => 'Um horizonte muito longo e uma elevada tolerância à volatilidade permitem que este perfil se apoie quase totalmente em ativos de crescimento, aceitando oscilações maiores em troca de mais potencial de crescimento a longo prazo. Um exemplo aqui mantém as classes mais estáveis ao mínimo.',
				'fit_en'   => 'Almost everything sits in global equities, with only a thin layer of bonds and cash. Real estate and a small optional crypto slice add the rest.',
				'fit_pt'   => 'Quase tudo está em ações globais, com apenas uma camada fina de obrigações e liquidez. Imobiliário e uma pequena fatia opcional de cripto acrescentam o resto.',
			),
		);
	}

	/**
	 * Per-asset-class copy (note from Bloco 2 + role + which profiles).
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function asset_meta(): array {
		return array(
			array(
				'slug'        => 'global-equities-explained',
				'title_en'    => 'Global equities, explained',
				'title_pt'    => 'Ações globais, explicadas',
				'intro_en'    => 'Global equities are the growth engine of a portfolio — shares in companies around the world. Over long periods they have tended to grow the most, but they also swing the most along the way. The longer your horizon, the more time they have to ride out the bumps.',
				'intro_pt'    => 'As ações globais são o motor de crescimento de uma carteira — participações em empresas de todo o mundo. Em períodos longos, tendem a crescer mais, mas também oscilam mais pelo caminho. Quanto maior o teu horizonte, mais tempo têm para absorver os altos e baixos.',
				'role_en'     => 'Because they tend to grow the most over long periods, global equities usually drive a portfolio long-term returns — and its short-term swings. They reward patience more than timing.',
				'role_pt'     => 'Como tendem a crescer mais em períodos longos, as ações globais costumam impulsionar os retornos de longo prazo de uma carteira — e também as suas oscilações de curto prazo. Recompensam mais a paciência do que o timing.',
				'profiles_en' => 'Growth and aggressive-growth profiles lean into global equities the most; preservation profiles keep only a small slice. The right amount depends on your time horizon and comfort with volatility.',
				'profiles_pt' => 'Os perfis de Crescimento e Crescimento agressivo apoiam-se mais nas ações globais; os perfis de Preservação mantêm apenas uma pequena fatia. A quantidade certa depende do teu horizonte temporal e do conforto com a volatilidade.',
			),
			array(
				'slug'        => 'bonds-explained',
				'title_en'    => 'Bonds, explained',
				'title_pt'    => 'Obrigações, explicadas',
				'intro_en'    => 'Bonds are loans to governments or companies that pay you interest. They usually move more gently than shares, which is why they are often used to add stability and soften the ups and downs of a portfolio.',
				'intro_pt'    => 'As obrigações são empréstimos a governos ou empresas que te pagam juros. Costumam mexer-se de forma mais suave do que as ações, por isso são muitas vezes usadas para dar estabilidade e atenuar os altos e baixos de uma carteira.',
				'role_en'     => 'Bonds act as a portfolio shock absorber. They typically grow more slowly than equities, but their steadier behaviour can make the overall ride easier to stick with.',
				'role_pt'     => 'As obrigações funcionam como o amortecedor da carteira. Costumam crescer mais devagar do que as ações, mas o seu comportamento mais estável pode tornar o percurso global mais fácil de manter.',
				'profiles_en' => 'Preservation and balanced-income profiles lean on bonds the most; growth profiles hold a smaller cushion. They rarely disappear entirely except in the most aggressive examples.',
				'profiles_pt' => 'Os perfis de Preservação e Rendimento equilibrado apoiam-se mais nas obrigações; os perfis de Crescimento mantêm uma almofada menor. Raramente desaparecem por completo, exceto nos exemplos mais agressivos.',
			),
			array(
				'slug'        => 'cash-explained',
				'title_en'    => 'Cash & equivalents, explained',
				'title_pt'    => 'Liquidez e equivalentes, explicada',
				'intro_en'    => 'Cash and equivalents are money you can reach quickly without much risk to its value. It grows little, but it is there when you need it — useful for short-term needs and peace of mind.',
				'intro_pt'    => 'A liquidez e equivalentes são dinheiro a que consegues chegar depressa sem grande risco para o seu valor. Cresce pouco, mas está lá quando precisas — útil para necessidades de curto prazo e tranquilidade.',
				'role_en'     => 'Cash is about readiness, not growth. A slice of it means a surprise expense never forces you to sell other assets at a bad moment.',
				'role_pt'     => 'A liquidez é sobre estar preparado, não sobre crescer. Uma fatia dela significa que uma despesa inesperada nunca te obriga a vender outros ativos num mau momento.',
				'profiles_en' => 'Preservation profiles hold the most cash; growth and aggressive profiles keep only a thin layer. An emergency fund usually comes before any portfolio at all.',
				'profiles_pt' => 'Os perfis de Preservação mantêm mais liquidez; os perfis de Crescimento e agressivos mantêm apenas uma camada fina. Um fundo de emergência costuma vir antes de qualquer carteira.',
			),
			array(
				'slug'        => 'reits-alternatives-explained',
				'title_en'    => 'Real estate & alternatives, explained',
				'title_pt'    => 'Imobiliário e alternativos, explicados',
				'intro_en'    => 'This bucket covers things like real estate or other assets that do not always move in step with shares and bonds. A small slice can add variety, which sometimes helps smooth the overall ride.',
				'intro_pt'    => 'Este grupo cobre coisas como imobiliário ou outros ativos que nem sempre se movem ao mesmo ritmo das ações e obrigações. Uma pequena fatia pode acrescentar variedade, o que por vezes ajuda a suavizar o percurso global.',
				'role_en'     => 'Real estate and alternatives are mainly about variety. Because they do not always move with shares and bonds, a small slice can sometimes smooth a portfolio overall path.',
				'role_pt'     => 'O imobiliário e os alternativos são sobretudo variedade. Como nem sempre se movem com as ações e obrigações, uma pequena fatia pode por vezes suavizar o percurso global de uma carteira.',
				'profiles_en' => 'Most profiles include only a small slice of real estate and alternatives — enough to add variety without taking over.',
				'profiles_pt' => 'A maioria dos perfis inclui apenas uma pequena fatia de imobiliário e alternativos — o suficiente para acrescentar variedade sem dominar.',
			),
			array(
				'slug'        => 'crypto-explained',
				'title_en'    => 'Crypto, explained',
				'title_pt'    => 'Cripto, explicada',
				'intro_en'    => 'Crypto is a young, highly volatile category — it can rise and fall sharply in short periods. If it appears here at all, it is only as a very small, optional slice, and only for profiles with a long horizon and a solid financial base.',
				'intro_pt'    => 'A cripto é uma categoria jovem e muito volátil — pode subir e descer bruscamente em curtos períodos. Se aparecer aqui, é apenas como uma fatia muito pequena e opcional, e só para perfis com horizonte longo e uma base financeira sólida.',
				'role_en'     => 'Crypto role is strictly optional and small. Its sharp swings sit better with a long horizon and a solid financial base, which is why it is left out of cautious profiles entirely.',
				'role_pt'     => 'O papel da cripto é estritamente opcional e pequeno. As suas oscilações bruscas encaixam melhor com um horizonte longo e uma base financeira sólida, e por isso fica totalmente de fora dos perfis prudentes.',
				'profiles_en' => 'Only balanced, growth and aggressive-growth profiles may include a small, optional crypto slice; preservation and balanced-income profiles leave it out.',
				'profiles_pt' => 'Apenas os perfis Equilibrado, de Crescimento e de Crescimento agressivo podem incluir uma pequena fatia opcional de cripto; os perfis de Preservação e Rendimento equilibrado deixam-na de fora.',
			),
		);
	}

	/**
	 * Build an illustrative allocation table (core/table block) for an archetype.
	 *
	 * @param int    $id   Archetype id (1–5).
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function alloc_table( int $id, string $lang ): string {
		$archetypes = Config::archetypes();
		$ranges     = $archetypes[ $id ]['ranges'] ?? array();
		if ( ! $ranges ) {
			return '';
		}
		$h1   = 'pt' === $lang ? 'Classe de ativos' : 'Asset class';
		$h2   = 'pt' === $lang ? 'Intervalo ilustrativo' : 'Illustrative range';
		$rows = '';
		foreach ( self::class_order() as $key ) {
			if ( ! isset( $ranges[ $key ] ) ) {
				continue;
			}
			$url   = home_url( '/investing-glossary/' . self::class_gloss_slug( $key ) . '/' );
			$rows .= '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( self::class_label( $key, $lang ) ) . '</a></td><td>' . esc_html( self::fmt_range( (array) $ranges[ $key ] ) ) . '</td></tr>';
		}
		return '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>'
			. esc_html( $h1 ) . '</th><th>' . esc_html( $h2 ) . '</th></tr></thead><tbody>'
			. $rows . '</tbody></table></figure><!-- /wp:table -->' . "\n\n";
	}

	/**
	 * Asset-class display order.
	 *
	 * @return array<int,string>
	 */
	private static function class_order(): array {
		return array( 'global_equity', 'bonds', 'reits_alt', 'cash', 'crypto' );
	}

	/**
	 * Localized asset-class label.
	 *
	 * @param string $key  Class key.
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function class_label( string $key, string $lang ): string {
		$labels = array(
			'global_equity' => array( 'en' => 'Global equities', 'pt' => 'Ações globais' ),
			'bonds'         => array( 'en' => 'Bonds', 'pt' => 'Obrigações' ),
			'reits_alt'     => array( 'en' => 'Real estate & alternatives', 'pt' => 'Imobiliário e alternativos' ),
			'cash'          => array( 'en' => 'Cash & equivalents', 'pt' => 'Liquidez e equivalentes' ),
			'crypto'        => array( 'en' => 'Crypto', 'pt' => 'Cripto' ),
		);
		return $labels[ $key ][ $lang ] ?? ( $labels[ $key ]['en'] ?? $key );
	}

	/**
	 * Glossary slug for an asset-class key (for cross-linking).
	 *
	 * @param string $key Class key.
	 */
	private static function class_gloss_slug( string $key ): string {
		$map = array(
			'global_equity' => 'global-equities',
			'bonds'         => 'bonds',
			'reits_alt'     => 'reits-and-alternatives',
			'cash'          => 'cash',
			'crypto'        => 'crypto',
		);
		return $map[ $key ] ?? $key;
	}

	/**
	 * Format a [min, max] range as an illustrative percentage string.
	 *
	 * @param array<int,int> $range [min, max].
	 */
	private static function fmt_range( array $range ): string {
		$min = (int) ( $range[0] ?? 0 );
		$max = (int) ( $range[1] ?? 0 );
		if ( 0 === $min && 0 === $max ) {
			return '0%';
		}
		if ( 0 === $min ) {
			return '0–' . $max . '%';
		}
		return $min . '–' . $max . '%';
	}

	/**
	 * Small, italic note paragraph block.
	 *
	 * @param string $text Plain text.
	 */
	private static function small( string $text ): string {
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><em>'
			. esc_html( $text ) . '</em></p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * A bulleted list of internal links with one-line descriptions.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $items [url, title, desc].
	 */
	private static function bullets( array $items ): string {
		$lis = '';
		foreach ( $items as $item ) {
			$lis .= '<li><a href="' . esc_url( $item[0] ) . '">' . esc_html( $item[1] ) . '</a> — ' . esc_html( $item[2] ) . '</li>';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $lis . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * All seeded page + article slugs (targets of internal links that must be
	 * localized to PT). Cached for the request.
	 *
	 * @return array<int,string>
	 */
	private static function internal_link_slugs(): array {
		static $slugs = null;
		if ( null === $slugs ) {
			$slugs = array_merge(
				array_column( self::pages(), 'slug' ),
				self::article_slugs()
			);
		}
		return $slugs;
	}

	/**
	 * The seed article slugs (without building their content — avoids recursion
	 * with the cross-link helpers).
	 *
	 * @return array<int,string>
	 */
	private static function article_slugs(): array {
		return array(
			'what-is-an-investor-profile',
			'asset-classes-explained',
			'why-your-time-horizon-matters',
			'staying-calm-when-markets-fall',
			'why-an-emergency-fund-comes-first',
			'what-is-diversification',
			'risk-and-reward-explained',
			'what-is-esg-investing',
		);
	}

	/**
	 * Root URL for an internal target: /learn/{slug}/ for articles, /{slug}/
	 * for pages.
	 *
	 * @param string $slug Slug.
	 */
	private static function internal_url( string $slug ): string {
		static $articles = null;
		if ( null === $articles ) {
			$articles = array_flip( self::article_slugs() );
		}
		return isset( $articles[ $slug ] ) ? home_url( '/learn/' . $slug . '/' ) : home_url( '/' . $slug . '/' );
	}

	/**
	 * Resolve an EN seeded post by slug (page first, then post). Cached.
	 *
	 * @param string $slug Slug.
	 * @return \WP_Post|null
	 */
	private static function resolve_en_post( string $slug ): ?\WP_Post {
		static $cache = array();
		if ( ! array_key_exists( $slug, $cache ) ) {
			$post = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $post instanceof \WP_Post ) {
				$post = get_page_by_path( $slug, OBJECT, 'learn' );
			}
			$cache[ $slug ] = $post instanceof \WP_Post ? $post : null;
		}
		return $cache[ $slug ];
	}

	/**
	 * Educational seed articles (standard posts) — EN body + PT in meta.
	 * Invariant-safe: by asset class, conditional language, no named instruments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function articles(): array {
		$g = static fn( string $slug ): string => home_url( '/investing-glossary/' . $slug . '/' );
		$articles = array(
			array(
				'slug'    => 'what-is-an-investor-profile',
				'title'   => 'What is an investor profile?',
				'excerpt' => 'A simple way to describe how you might approach investing — based on your time, goals and comfort with risk.',
				'content' => self::paragraph( 'An investor profile — sometimes called an investor archetype — is a simple way to describe how you might approach investing, based on your time, your goals and your comfort with risk. It is not a label that boxes you in; it is a starting point for understanding which kinds of portfolios tend to suit different situations.' )
					. self::heading( 'What shapes your profile' )
					. self::paragraph( 'A few things matter most: how long until you need the money (your horizon), what you are investing for, how you would react if markets fell, and how stable your finances are. Together these hint at how much short-term ups and downs you can comfortably ride out.' )
					. self::heading( 'Why it is only a starting point' )
					. self::paragraph( 'Two people with the same profile can still make different choices. A profile is educational — it shows the kind of structure a situation like yours might explore, organised by asset class, never specific products. It is a lens for learning, not a personal recommendation.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'O que é um perfil de investidor?',
					'excerpt' => 'Uma forma simples de descrever como podes abordar o investimento — com base no teu tempo, objetivos e conforto com o risco.',
					'content' => self::paragraph( 'Um perfil de investidor — por vezes chamado arquétipo de investidor — é uma forma simples de descrever como podes abordar o investimento, com base no teu tempo, nos teus objetivos e no teu conforto com o risco. Não é um rótulo que te prende; é um ponto de partida para perceber que tipos de carteira costumam encaixar em diferentes situações.' )
						. self::heading( 'O que molda o teu perfil' )
						. self::paragraph( 'Algumas coisas contam mais: quanto tempo falta até precisares do dinheiro (o teu horizonte), para que estás a investir, como reagirias se os mercados caíssem, e quão estável é a tua situação financeira. Juntas, sugerem quantos altos e baixos de curto prazo consegues atravessar com conforto.' )
						. self::heading( 'Porque é só um ponto de partida' )
						. self::paragraph( 'Duas pessoas com o mesmo perfil podem fazer escolhas diferentes. Um perfil é educativo — mostra o tipo de estrutura que uma situação como a tua poderia estudar, organizada por classe de ativos, nunca por produtos específicos. É uma lente para aprender, não uma recomendação pessoal.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'asset-classes-explained',
				'title'   => 'Asset classes explained',
				'excerpt' => 'Portfolios are built from a handful of broad building blocks. Here is what each one does.',
				'content' => self::paragraph( 'Portfolios are built from a handful of broad building blocks called asset classes. Understanding them is the first step to understanding any example portfolio you will see here.' )
					. self::heading( 'The main classes' )
					. self::paragraph( 'Global equities are the growth engine — shares in companies around the world. Bonds are loans to governments or companies that pay interest and usually move more gently. Cash is money you can reach quickly. Real estate and alternatives add variety. And crypto, if it appears at all, is only ever a very small, optional slice.' )
					. self::related(
						array(
							array( $g( 'global-equities' ), 'Global equities' ),
							array( $g( 'bonds' ), 'Bonds' ),
							array( $g( 'cash' ), 'Cash' ),
							array( $g( 'reits-and-alternatives' ), 'REITs & alternatives' ),
						)
					)
					. self::heading( 'Why mix them' )
					. self::paragraph( 'Each class behaves differently, so combining them can smooth the overall ride. The right mix depends on your situation — mostly your time horizon and how comfortable you are with swings.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'As classes de ativos explicadas',
					'excerpt' => 'As carteiras constroem-se a partir de poucos blocos de construção. Eis o que cada um faz.',
					'content' => self::paragraph( 'As carteiras constroem-se a partir de poucos blocos de construção chamados classes de ativos. Percebê-los é o primeiro passo para entender qualquer exemplo de carteira que vais ver aqui.' )
						. self::heading( 'As principais classes' )
						. self::paragraph( 'As ações globais são o motor de crescimento — participações em empresas de todo o mundo. As obrigações são empréstimos a governos ou empresas que pagam juros e costumam mexer-se de forma mais suave. A liquidez é dinheiro a que chegas depressa. O imobiliário e os alternativos acrescentam variedade. E a cripto, se aparecer, é sempre apenas uma fatia muito pequena e opcional.' )
						. self::heading( 'Porquê misturá-las' )
						. self::paragraph( 'Cada classe comporta-se de forma diferente, por isso combiná-las pode suavizar o percurso global. A mistura certa depende da tua situação — sobretudo do teu horizonte temporal e do conforto com as oscilações.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'why-your-time-horizon-matters',
				'title'   => 'Why your time horizon is your biggest ally',
				'excerpt' => 'The further away your goal, the more ups and downs you can ride out along the way.',
				'content' => self::paragraph( 'Time is an investor\'s biggest ally. The further away your goal, the more ups and downs you can ride out along the way.' )
					. self::heading( 'Short versus long horizons' )
					. self::paragraph( 'Money you may need within a few years usually leans on steadier classes, because there is little time to recover from a fall. Money you will not touch for a decade or more can hold more growth assets, which swing more but have time to recover.' )
					. self::heading( 'Horizon can outweigh appetite' )
					. self::paragraph( 'Even if you are comfortable with risk, a short horizon often calls for caution — time matters as much as comfort.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'Porque o teu horizonte temporal é o maior aliado',
					'excerpt' => 'Quanto mais longe está o teu objetivo, mais altos e baixos consegues atravessar pelo caminho.',
					'content' => self::paragraph( 'O tempo é o maior aliado de quem investe. Quanto mais longe está o teu objetivo, mais altos e baixos consegues atravessar pelo caminho.' )
						. self::heading( 'Horizontes curtos versus longos' )
						. self::paragraph( 'O dinheiro de que podes precisar dentro de poucos anos costuma apoiar-se em classes mais estáveis, porque há pouco tempo para recuperar de uma queda. O dinheiro que não vais tocar durante uma década ou mais pode ter mais ativos de crescimento, que oscilam mais mas têm tempo para recuperar.' )
						. self::heading( 'O horizonte pode pesar mais do que o apetite' )
						. self::paragraph( 'Mesmo que te sintas confortável com o risco, um horizonte curto pede muitas vezes prudência — o tempo conta tanto como o conforto.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'staying-calm-when-markets-fall',
				'title'   => 'How to stay calm when markets fall',
				'excerpt' => 'The best plan is the one you can stick to when markets get scary.',
				'content' => self::paragraph( 'The best plan is the one you can stick to when markets get scary. Selling at the bottom is one of the costliest mistakes an investor can make.' )
					. self::heading( 'Why drops feel worse than they are' )
					. self::paragraph( 'Falls are a normal part of investing in growth assets. Over long periods, markets have tended to recover — but the recovery only helped those who stayed invested.' )
					. self::heading( 'A simple guardrail' )
					. self::paragraph( 'Knowing in advance how you would react to a sharp drop helps you avoid panic decisions. A portfolio that fits your comfort makes staying the course far easier.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'Como manter a calma quando os mercados caem',
					'excerpt' => 'O melhor plano é o que consegues manter quando o mercado assusta.',
					'content' => self::paragraph( 'O melhor plano é o que consegues manter quando o mercado assusta. Vender no fundo é um dos erros mais caros que um investidor pode cometer.' )
						. self::heading( 'Porque as quedas parecem piores do que são' )
						. self::paragraph( 'As quedas são uma parte normal de investir em ativos de crescimento. Em períodos longos, os mercados tendem a recuperar — mas a recuperação só ajudou quem se manteve investido.' )
						. self::heading( 'Uma salvaguarda simples' )
						. self::paragraph( 'Saber de antemão como reagirias a uma queda acentuada ajuda-te a evitar decisões em pânico. Uma carteira adequada ao teu conforto torna muito mais fácil manter o rumo.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'why-an-emergency-fund-comes-first',
				'title'   => 'Why an emergency fund comes before investing',
				'excerpt' => 'The most important first step is usually money kept somewhere safe and easy to reach.',
				'content' => self::paragraph( 'Before talking about portfolios, the most important step is usually building an emergency fund — money kept somewhere safe and easy to reach.' )
					. self::heading( 'Why it comes first' )
					. self::paragraph( 'An emergency fund — often three to six months of expenses — is what stops a surprise from forcing you to sell investments at a bad time.' )
					. self::heading( 'Then you can invest with a clear head' )
					. self::paragraph( 'Once that base is in place, an example portfolio is something to keep in mind. Investing before you have a cushion adds avoidable risk.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'Porque o fundo de emergência vem antes de investir',
					'excerpt' => 'O primeiro passo mais importante é dinheiro guardado num sítio seguro e de fácil acesso.',
					'content' => self::paragraph( 'Antes de falarmos de carteiras, o passo mais importante costuma ser construir um fundo de emergência — dinheiro guardado num sítio seguro e de fácil acesso.' )
						. self::heading( 'Porque vem primeiro' )
						. self::paragraph( 'Um fundo de emergência — muitas vezes três a seis meses de despesas — é o que evita que um imprevisto te obrigue a vender investimentos num mau momento.' )
						. self::heading( 'Depois podes investir com a cabeça tranquila' )
						. self::paragraph( 'Com essa base montada, um exemplo de carteira é algo a ter em mente. Investir antes de teres um colchão acrescenta risco evitável.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'what-is-diversification',
				'title'   => 'What is diversification?',
				'excerpt' => 'The simple idea of not putting all your eggs in one basket.',
				'content' => self::paragraph( 'Diversification is the simple idea of not putting all your eggs in one basket.' )
					. self::heading( 'How it helps' )
					. self::paragraph( 'Different asset classes do not always move together. Holding a mix means a bad patch in one part may be cushioned by another, smoothing the overall ride.' )
					. self::heading( 'It is not a guarantee' )
					. self::paragraph( 'Diversification reduces some risks, not all — markets can fall together at times. Even so, a sensible spread across classes is a cornerstone of most example portfolios.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'O que é diversificação?',
					'excerpt' => 'A ideia simples de não pôr todos os ovos no mesmo cesto.',
					'content' => self::paragraph( 'Diversificação é a ideia simples de não pôr todos os ovos no mesmo cesto.' )
						. self::heading( 'Como ajuda' )
						. self::paragraph( 'Diferentes classes de ativos nem sempre se movem juntas. Ter uma mistura significa que um mau período numa parte pode ser amortecido por outra, suavizando o percurso global.' )
						. self::heading( 'Não é uma garantia' )
						. self::paragraph( 'A diversificação reduz alguns riscos, não todos — por vezes os mercados caem em conjunto. Ainda assim, uma distribuição sensata pelas classes é um pilar da maioria dos exemplos de carteira.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'risk-and-reward-explained',
				'title'   => 'Risk and reward: the trade-off explained',
				'excerpt' => 'Assets that can grow the most usually swing the most along the way.',
				'content' => self::paragraph( 'In investing, risk and potential reward tend to go hand in hand. Assets that can grow the most usually swing the most along the way.' )
					. self::heading( 'The trade-off' )
					. self::paragraph( 'Growth assets like global equities have historically grown more over long periods, but with bigger ups and downs. Steadier classes grow less but move more gently.' )
					. self::heading( 'Finding your balance' )
					. self::paragraph( 'There is no single right answer — only the balance that fits your time horizon and how comfortable you are with swings.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'Risco e retorno: o equilíbrio explicado',
					'excerpt' => 'Os ativos que podem crescer mais costumam oscilar mais pelo caminho.',
					'content' => self::paragraph( 'A investir, o risco e o potencial retorno tendem a andar de mãos dadas. Os ativos que podem crescer mais costumam oscilar mais pelo caminho.' )
						. self::heading( 'O equilíbrio' )
						. self::paragraph( 'Os ativos de crescimento como as ações globais cresceram historicamente mais em períodos longos, mas com altos e baixos maiores. As classes mais estáveis crescem menos mas mexem-se de forma mais suave.' )
						. self::heading( 'Encontrar o teu equilíbrio' )
						. self::paragraph( 'Não há uma resposta única — apenas o equilíbrio que se adequa ao teu horizonte temporal e ao teu conforto com as oscilações.' )
						. self::cta(),
				),
			),
			array(
				'slug'    => 'what-is-esg-investing',
				'title'   => 'What is ESG investing?',
				'excerpt' => 'A lens some people like to apply to where their money goes.',
				'content' => self::paragraph( 'ESG investing means looking at how companies handle environmental, social and governance issues — a lens some people like to apply to where their money goes.' )
					. self::heading( 'What ESG covers' )
					. self::paragraph( 'Environmental (for example, climate impact), social (how a company treats people) and governance (how it is run). It is a way of considering more than just financial returns.' )
					. self::heading( 'A lens, not a strategy' )
					. self::paragraph( 'ESG is a preference about where money goes, not a risk profile on its own. It does not change how much short-term volatility you can comfortably handle.' )
					. self::cta(),
				'pt'      => array(
					'title'   => 'O que é investimento ESG?',
					'excerpt' => 'Uma lente que algumas pessoas gostam de aplicar a onde colocam o dinheiro.',
					'content' => self::paragraph( 'Investimento ESG significa olhar para a forma como as empresas lidam com questões ambientais, sociais e de governo — uma lente que algumas pessoas gostam de aplicar a onde colocam o seu dinheiro.' )
						. self::heading( 'O que o ESG abrange' )
						. self::paragraph( 'Ambiental (por exemplo, impacto climático), social (como a empresa trata as pessoas) e governo (como é gerida). É uma forma de considerar mais do que apenas o retorno financeiro.' )
						. self::heading( 'Uma lente, não uma estratégia' )
						. self::paragraph( 'O ESG é uma preferência sobre onde o dinheiro vai, não um perfil de risco por si só. Não altera quanta volatilidade de curto prazo consegues suportar com conforto.' )
						. self::cta(),
				),
			),
		);

		return self::with_article_key_terms( $articles );
	}

	/**
	 * Append a "Key terms" glossary-link block to each seed article (EN + PT).
	 *
	 * @param array<int,array<string,mixed>> $articles Article entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_article_key_terms( array $articles ): array {
		$map = array(
			'what-is-an-investor-profile'       => array( 'portfolio', 'diversification', 'asset' ),
			'asset-classes-explained'           => array( 'global-equities', 'bonds', 'cash', 'reits-and-alternatives', 'crypto', 'commodities' ),
			'why-your-time-horizon-matters'     => array( 'compound-interest', 'volatility' ),
			'staying-calm-when-markets-fall'    => array( 'volatility', 'bear-market', 'bull-market' ),
			'why-an-emergency-fund-comes-first' => array( 'cash' ),
			'what-is-diversification'           => array( 'diversification', 'portfolio', 'asset' ),
			'risk-and-reward-explained'         => array( 'volatility', 'yield', 'leverage' ),
			'what-is-esg-investing'             => array( 'global-equities', 'asset' ),
		);

		// Titles + category index for the "Related articles" block.
		$titles = array();
		$by_cat = array();
		foreach ( $articles as $e ) {
			$titles[ $e['slug'] ] = array( 'en' => (string) $e['title'], 'pt' => (string) ( $e['pt']['title'] ?? $e['title'] ) );
			$by_cat[ self::learn_category_of( (string) $e['slug'] ) ][] = (string) $e['slug'];
		}

		foreach ( $articles as &$entry ) {
			$slug  = (string) $entry['slug'];
			$terms = $map[ $slug ] ?? array();

			$related = array();
			foreach ( $by_cat[ self::learn_category_of( $slug ) ] as $other ) {
				if ( $other === $slug ) {
					continue;
				}
				$related[] = $other;
				if ( count( $related ) >= 3 ) {
					break;
				}
			}

			$entry['content'] .= self::glossary_links_block( $terms, 'en' )
				. self::article_links_block( $related, $titles, 'en' );
			if ( isset( $entry['pt']['content'] ) ) {
				$entry['pt']['content'] .= self::glossary_links_block( $terms, 'pt' )
					. self::article_links_block( $related, $titles, 'pt' );
			}
		}
		unset( $entry );
		return $articles;
	}

	/**
	 * A "Related articles: a · b" block linking same-category articles.
	 *
	 * @param array<int,string>                        $slugs  Article slugs.
	 * @param array<string,array{en:string,pt:string}> $titles Slug → titles.
	 * @param string                                   $lang   'en' or 'pt'.
	 */
	private static function article_links_block( array $slugs, array $titles, string $lang ): string {
		$items = array();
		foreach ( $slugs as $slug ) {
			$items[] = array( self::internal_url( $slug ), $titles[ $slug ][ $lang ] ?? ( $titles[ $slug ]['en'] ?? $slug ) );
		}
		return self::links_para( 'pt' === $lang ? 'Artigos relacionados' : 'Related articles', $items );
	}

	/**
	 * The questionnaire CTA pattern block.
	 */
	private static function cta(): string {
		return '<!-- wp:pattern {"slug":"howtoinvest/cta-questionnaire"} /-->' . "\n\n";
	}

	/**
	 * A "Learn more" paragraph of internal links.
	 *
	 * @param array<int,array{0:string,1:string}> $links [url, text] pairs.
	 */
	private static function related( array $links ): string {
		$parts = array();
		foreach ( $links as $link ) {
			$parts[] = '<a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>';
		}
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">'
			. esc_html__( 'Learn more:', 'hti-engine' ) . ' ' . implode( ' · ', $parts )
			. '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * Wrap text in a paragraph block.
	 *
	 * @param string $text Plain text.
	 */
	private static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * Wrap text in an h2 heading block.
	 *
	 * @param string $text Plain text.
	 */
	private static function heading( string $text ): string {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * A highlighted notice paragraph block.
	 *
	 * @param string $text Plain text.
	 */
	private static function notice( string $text ): string {
		return '<!-- wp:paragraph {"backgroundColor":"caution-surface","textColor":"caution"} -->'
			. '<p class="has-caution-color has-caution-surface-background-color has-text-color has-background"><strong>' . esc_html( $text ) . '</strong></p>'
			. '<!-- /wp:paragraph -->' . "\n\n";
	}

	/* ---------- wp-admin: Tools → Seed content ---------- */

	/**
	 * Add the Tools submenu page.
	 */
	public static function admin_menu(): void {
		add_management_page(
			__( 'HowToInvest — Seed content', 'hti-engine' ),
			__( 'Seed content', 'hti-engine' ),
			'manage_options',
			'hti-seed',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the seeder form.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'HowToInvest — Seed content', 'hti-engine' ); ?></h1>
			<p><?php echo esc_html__( 'Create the starter glossary terms and institutional pages. Existing entries (matched by slug) are skipped, so your edits are safe.', 'hti-engine' ); ?></p>
			<p><?php echo esc_html__( 'If Polylang is active, the seeder also creates the Portuguese version of each entry and links it as a translation of the English one. Re-running only adds what is missing.', 'hti-engine' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_run_seeder" />
				<?php wp_nonce_field( 'hti_run_seeder' ); ?>
				<?php submit_button( __( 'Run seeder', 'hti-engine' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the seeder form submission.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-engine' ) );
		}
		check_admin_referer( 'hti_run_seeder' );

		$report = self::seed();
		set_transient( 'hti_seed_report', $report, 60 );

		wp_safe_redirect( add_query_arg( 'page', 'hti-seed', admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Show the result notice after seeding.
	 */
	public static function admin_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_hti-seed' !== $screen->id ) {
			return;
		}

		$report = get_transient( 'hti_seed_report' );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( 'hti_seed_report' );

		$message = sprintf(
			/* translators: 1: glossary terms, 2: pages, 3: articles, 4: PT translations, 5: entries skipped. */
			__( 'Seeding complete: %1$d glossary terms, %2$d pages and %3$d articles created, %4$d Portuguese translations linked, %5$d skipped (already existed).', 'hti-engine' ),
			(int) $report['glossary_created'],
			(int) $report['pages_created'],
			(int) $report['articles_created'],
			(int) $report['translations_created'],
			(int) $report['skipped']
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
