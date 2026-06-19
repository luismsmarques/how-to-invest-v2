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

		foreach ( self::articles() as $entry ) {
			$id = self::insert( 'post', $entry );
			if ( $id > 0 ) {
				++$report['articles_created'];
			} else {
				++$report['skipped'];
			}
		}

		// If Polylang is active, mirror every seeded entry into a linked
		// Portuguese translation (built from the hti_*_pt variants).
		$report['translations_created'] = self::seed_translations();

		return $report;
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

		$created = 0;
		$groups  = array(
			'glossary' => self::glossary_terms(),
			'page'     => self::pages(),
			'post'     => self::articles(),
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

		// Mirror the privacy-page option for the PT page (GDPR alignment).
		if ( 'page' === $type && 'privacy-policy' === $entry['slug'] && function_exists( 'pll_set_post_language' ) ) {
			update_option( 'wp_page_for_privacy_policy_' . $pt, $pt_id );
		}

		// File PT glossary posts under the PT "Asset classes" topic.
		if ( 'glossary' === $type ) {
			$pt_term = get_term_by( 'slug', 'asset-classes-' . $pt, 'glossary_topic' );
			if ( $pt_term instanceof \WP_Term ) {
				wp_set_object_terms( $pt_id, array( (int) $pt_term->term_id ), 'glossary_topic', true );
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

		return (string) preg_replace_callback(
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

		$en_term = get_term_by( 'slug', 'asset-classes', 'glossary_topic' );
		if ( ! $en_term instanceof \WP_Term ) {
			return;
		}

		if ( ! pll_get_term_language( $en_term->term_id ) ) {
			pll_set_term_language( (int) $en_term->term_id, $en );
		}

		if ( pll_get_term( (int) $en_term->term_id, $pt ) ) {
			return;
		}

		$res = wp_insert_term(
			'Classes de ativos',
			'glossary_topic',
			array( 'slug' => 'asset-classes-' . $pt )
		);
		if ( is_wp_error( $res ) ) {
			return;
		}

		$pt_term_id = (int) $res['term_id'];
		pll_set_term_language( $pt_term_id, $pt );
		pll_save_term_translations( array( $en => (int) $en_term->term_id, $pt => $pt_term_id ) );
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
	 * Ensure the "Asset classes" glossary topic exists and assign every
	 * seeded glossary term to it. Idempotent (append, never replaces).
	 */
	private static function assign_glossary_topic(): void {
		if ( ! taxonomy_exists( 'glossary_topic' ) ) {
			return;
		}

		$existing = term_exists( 'asset-classes', 'glossary_topic' );
		if ( ! $existing ) {
			$existing = wp_insert_term(
				'Asset classes',
				'glossary_topic',
				array( 'slug' => 'asset-classes' )
			);
		}
		if ( is_wp_error( $existing ) ) {
			return;
		}

		$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		update_term_meta( $term_id, 'hti_name_pt', 'Classes de ativos' );

		foreach ( self::glossary_terms() as $entry ) {
			$post = get_page_by_path( $entry['slug'], OBJECT, 'glossary' );
			if ( $post instanceof \WP_Post ) {
				wp_set_object_terms( $post->ID, array( $term_id ), 'glossary_topic', true );
			}
		}
	}

	/**
	 * Glossary seed data — the curated asset-class notes (Textos Finais §2).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function glossary_terms(): array {
		return array(
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
	}

	/**
	 * Institutional & legal pages (the 301 targets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pages(): array {
		$legal_notice = self::notice( 'Placeholder content — replace with legally reviewed text before launch.' );

		return array(
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
				'content' => self::paragraph( 'Questions or feedback about the educational content? Reach us at hello@howtoinvest.pro.' ),
				'pt'      => array(
					'title'   => 'Contacto',
					'content' => self::paragraph( 'Tens questões ou sugestões sobre o conteúdo educativo? Fala connosco em hello@howtoinvest.pro.' ),
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
	}

	/**
	 * Educational seed articles (standard posts) — EN body + PT in meta.
	 * Invariant-safe: by asset class, conditional language, no named instruments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function articles(): array {
		$g = static fn( string $slug ): string => home_url( '/investing-glossary/' . $slug . '/' );

		return array(
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
