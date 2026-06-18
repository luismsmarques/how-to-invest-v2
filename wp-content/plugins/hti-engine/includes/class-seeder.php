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
 * variant is stored in post meta (`hti_title_pt`, `hti_content_pt`,
 * `hti_excerpt_pt`) so the data stays language-aware regardless of which
 * multilingual approach (Polylang vs native) is finalised later.
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
	 * @return array{glossary_created:int,pages_created:int,skipped:int}
	 */
	public static function seed(): array {
		$report = array(
			'glossary_created' => 0,
			'pages_created'    => 0,
			'skipped'          => 0,
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

		return $report;
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
			/* translators: 1: glossary terms created, 2: pages created, 3: entries skipped. */
			__( 'Seeding complete: %1$d glossary terms and %2$d pages created, %3$d skipped (already existed).', 'hti-engine' ),
			(int) $report['glossary_created'],
			(int) $report['pages_created'],
			(int) $report['skipped']
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
