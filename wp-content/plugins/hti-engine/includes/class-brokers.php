<?php
/**
 * Broker comparison — `[hti_brokers]` (broker-affiliate skill).
 *
 * Server-rendered, indexable comparison of the curated broker records (the
 * `broker` CPT), enhanced by brokers.js (client-side search/sort/CFD filter).
 * Mirrors the Deposits comparator pattern: bilingual local strings, cards with
 * `data-*` attributes, a strong on-page disclosure, and schema emitted here.
 *
 * Compliance (CMVM 2025-03-13 / ESMA), enforced by this renderer:
 * - the affiliate disclosure renders ON the page, above the cards;
 * - cards with an active deal carry the "Partner · Ad" label;
 * - CFD brokers carry the risk warning;
 * - outbound links only via /go/{slug} with the correct rel;
 * - ItemList schema only — never Review/AggregateRating (YMYL).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode + renderer for the broker comparison pages.
 */
class Brokers {

	private const SHORTCODE = 'hti_brokers';
	private const SHORTCODE_CTA = 'hti_broker_cta';

	/**
	 * Hook the shortcodes, assets and schema.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( self::SHORTCODE_CTA, array( __CLASS__, 'render_cta' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_head', array( __CLASS__, 'schema' ) );
	}

	/* ------------------------------------------------------------- language */

	/**
	 * Current render language: 'pt' on the Portuguese pages, 'en' otherwise
	 * (EN is the site default).
	 */
	public static function lang(): string {
		if ( function_exists( 'pll_current_language' ) && 'pt' === pll_current_language( 'slug' ) ) {
			return 'pt';
		}
		return 'en';
	}

	/**
	 * Localized interface strings.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$en = array(
			'label'          => 'Partner · Ad',
			'how_link'       => 'How we make money',
			'search_ph'      => 'Broker name…',
			'search_aria'    => 'Search brokers',
			'no_cfd'         => 'Hide CFD providers',
			'sort_label'     => 'Sort:',
			'sort_aria'      => 'Sort results',
			'sort_editorial' => 'Editorial order',
			'sort_rating'    => 'Highest rating',
			'sort_name'      => 'Name (A–Z)',
			'count_word'     => 'platforms',
			'clear'          => 'Clear',
			'empty_t'        => 'No platform matches the filters',
			'empty_d'        => 'Try clearing the search or showing CFD providers again.',
			'regulated'      => 'Regulated:',
			'min_label'      => 'Minimum',
			'products_label' => 'Products',
			'interest_label' => 'Interest on cash',
			'fees_label'     => 'Costs',
			'cfd_chip'       => 'Offers CFDs',
			'review_btn'     => 'Read the review',
			'visit_btn'      => 'Visit',
			'verified_label' => 'Data verified:',
			'rating_aria'    => 'Editorial rating:',
			'products_map'   => 'stocks:Stocks|etf:ETFs|funds:Funds|crypto:Crypto|interest:Interest|savings:Savings plans',
			'categories'     => 'Compare by use:',
		);

		$pt = array(
			'label'          => 'Parceria · Publicidade',
			'how_link'       => 'Como ganhamos dinheiro',
			'search_ph'      => 'Nome da corretora…',
			'search_aria'    => 'Pesquisar corretoras',
			'no_cfd'         => 'Esconder fornecedores de CFDs',
			'sort_label'     => 'Ordenar:',
			'sort_aria'      => 'Ordenar resultados',
			'sort_editorial' => 'Ordem editorial',
			'sort_rating'    => 'Maior avaliação',
			'sort_name'      => 'Nome (A–Z)',
			'count_word'     => 'plataformas',
			'clear'          => 'Limpar',
			'empty_t'        => 'Nenhuma plataforma corresponde aos filtros',
			'empty_d'        => 'Experimenta limpar a pesquisa ou voltar a mostrar fornecedores de CFDs.',
			'regulated'      => 'Regulada:',
			'min_label'      => 'Mínimo',
			'products_label' => 'Produtos',
			'interest_label' => 'Juros sobre o saldo',
			'fees_label'     => 'Custos',
			'cfd_chip'       => 'Oferece CFDs',
			'review_btn'     => 'Ler análise',
			'visit_btn'      => 'Visitar',
			'verified_label' => 'Dados verificados:',
			'rating_aria'    => 'Avaliação editorial:',
			'products_map'   => 'stocks:Ações|etf:ETFs|funds:Fundos|crypto:Cripto|interest:Juros|savings:Planos de poupança',
			'categories'     => 'Comparar por uso:',
		);

		return 'pt' === $lang ? $pt : $en;
	}

	/**
	 * Product tag → localized label.
	 *
	 * @param array<string,string> $l Strings table.
	 * @return array<string,string>
	 */
	public static function product_labels( array $l ): array {
		$out = array();
		foreach ( explode( '|', (string) ( $l['products_map'] ?? '' ) ) as $pair ) {
			$bits = explode( ':', $pair, 2 );
			if ( 2 === count( $bits ) ) {
				$out[ $bits[0] ] = $bits[1];
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------- records */

	/**
	 * Normalized broker records, in editorial (menu_order) order. Meta is read
	 * from the default-language post (the single source); title/excerpt/URL are
	 * resolved to the requested language via Polylang when available.
	 *
	 * @param string $lang     'en' or 'pt'.
	 * @param string $use_case Optional use-case slug (EN term) to filter by.
	 * @return list<array<string,mixed>>
	 */
	public static function records( string $lang, string $use_case = '' ): array {
		$args = array(
			'post_type'              => 'broker',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
		);
		if ( function_exists( 'pll_default_language' ) ) {
			// Query the default-language posts only — they carry the meta.
			$args['lang'] = (string) pll_default_language( 'slug' );
		}
		if ( '' !== $use_case ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- small curated set (≤100 posts).
				array(
					'taxonomy' => 'broker_use_case',
					'field'    => 'slug',
					'terms'    => sanitize_key( $use_case ),
				),
			);
		}

		$posts = get_posts( $args );
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = self::record( $post, $lang );
		}
		return $out;
	}

	/**
	 * Normalize one broker post (default-language) into a render-ready record.
	 *
	 * @param \WP_Post $post Default-language broker post.
	 * @param string   $lang Render language.
	 * @return array<string,mixed>
	 */
	public static function record( \WP_Post $post, string $lang ): array {
		$id = (int) $post->ID;

		// Language-specific presentation post (title/excerpt/permalink).
		$view = $post;
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $id, $lang );
			if ( $translated ) {
				$maybe = get_post( (int) $translated );
				if ( $maybe instanceof \WP_Post && 'publish' === $maybe->post_status ) {
					$view = $maybe;
				}
			}
		}

		$m = static function ( string $key ) use ( $id ): string {
			return (string) get_post_meta( $id, Broker_Admin::PREFIX . $key, true );
		};

		$is_pt    = 'pt' === $lang;
		$fees     = $is_pt && '' !== $m( 'fees_note_pt' ) ? $m( 'fees_note_pt' ) : $m( 'fees_note' );
		$interest = $is_pt && '' !== $m( 'interest_rate_note_pt' ) ? $m( 'interest_rate_note_pt' ) : $m( 'interest_rate_note' );

		$guide_url = '';
		$guide_id  = (int) $m( 'guide_page' );
		if ( $guide_id > 0 ) {
			if ( function_exists( 'pll_get_post' ) ) {
				$guide_tr = pll_get_post( $guide_id, $lang );
				$guide_id = $guide_tr ? (int) $guide_tr : $guide_id;
			}
			$guide = get_post( $guide_id );
			if ( $guide instanceof \WP_Post && 'publish' === $guide->post_status ) {
				$guide_url = (string) get_permalink( $guide );
			}
		}

		$terms     = get_the_terms( $id, 'broker_use_case' );
		$use_cases = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$use_cases[] = (string) $term->slug;
			}
		}

		return array(
			'id'            => $id,
			'slug'          => (string) $post->post_name,
			'name'          => (string) $post->post_title,
			'tagline'       => (string) $view->post_excerpt,
			'review_url'    => (string) get_permalink( $view ),
			'guide_url'     => $guide_url,
			'regulator'     => $m( 'regulator' ),
			'cfd'           => '1' === $m( 'cfd' ),
			'cfd_pct'       => $m( 'cfd_risk_pct' ),
			'products'      => array_values( array_filter( explode( ',', $m( 'products' ) ) ) ),
			'asset_classes' => array_values( array_filter( explode( ',', $m( 'asset_classes' ) ) ) ),
			'profile_fit'   => array_map( 'intval', array_filter( explode( ',', $m( 'profile_fit' ) ) ) ),
			'min_deposit'   => $m( 'min_deposit' ),
			'fees_note'     => $fees,
			'interest_note' => $interest,
			'rating'        => '' !== $m( 'rating' ) ? (float) $m( 'rating' ) : null,
			'affiliate'     => '1' === $m( 'affiliate_active' ) && '' !== $m( 'affiliate_url' ),
			'verified'      => $m( 'verified' ),
			'use_cases'     => $use_cases,
			'menu_order'    => (int) $post->menu_order,
		);
	}

	/* ----------------------------------------------------------------- links */

	/**
	 * Outbound link attributes for a record: /go/ href + the correct rel.
	 *
	 * @param array<string,mixed> $r   Record (needs slug + affiliate).
	 * @param string              $loc Click location (Broker_Go::LOCATIONS).
	 * @return array{href:string,rel:string}
	 */
	public static function go_link( array $r, string $loc ): array {
		return array(
			'href' => Broker_Go::url( (string) $r['slug'], $loc ),
			'rel'  => ! empty( $r['affiliate'] ) ? 'sponsored nofollow noopener' : 'nofollow noopener',
		);
	}

	/**
	 * Localized URL of the "How we make money" page.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function money_page_url( string $lang ): string {
		$slug = 'pt' === $lang ? 'como-ganhamos-dinheiro' : 'how-we-make-money';
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page instanceof \WP_Post ) {
			return (string) get_permalink( $page );
		}
		return home_url( ( 'pt' === $lang ? '/pt/' : '/' ) . $slug . '/' );
	}

	/* ------------------------------------------------------------ components */

	/**
	 * The on-page affiliate disclosure (CMVM: on every page with broker links),
	 * linking the "How we make money" page.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @return string Safe HTML.
	 */
	public static function disclosure_html( string $lang ): string {
		$l = self::strings( $lang );
		return '<div class="hti-bk__disclosure" role="note">'
			. '<span class="hti-bk__label">' . esc_html( $l['label'] ) . '</span> '
			. '<span>' . esc_html( Disclaimer::affiliate( $lang ) ) . '</span> '
			. '<a href="' . esc_url( self::money_page_url( $lang ) ) . '">' . esc_html( $l['how_link'] ) . ' →</a>'
			. '</div>';
	}

	/**
	 * The ESMA CFD risk warning for one record.
	 *
	 * @param string $lang 'en' or 'pt'.
	 * @param string $pct  Provider loss percentage ('' → generic wording).
	 * @return string Safe HTML.
	 */
	public static function cfd_warning_html( string $lang, string $pct ): string {
		return '<p class="hti-bk__cfd-warning">' . esc_html( Disclaimer::cfd_risk( $lang, $pct ) ) . '</p>';
	}

	/* ---------------------------------------------------------------- render */

	/**
	 * Whether the queried singular page embeds the comparison shortcode.
	 */
	private static function is_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Whether the queried singular page embeds the partner CTA box (guides).
	 */
	private static function is_cta_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE_CTA );
	}

	/**
	 * Enqueue the comparison assets only where the shortcode is present.
	 */
	public static function enqueue(): void {
		if ( ! self::is_page() && ! self::is_cta_page() && ! is_singular( 'broker' ) ) {
			return;
		}
		wp_enqueue_style( 'hti-brokers', HTI_ENGINE_URL . 'assets/css/brokers.css', array(), VERSION );
		wp_enqueue_script(
			'hti-brokers',
			HTI_ENGINE_URL . 'assets/js/brokers.js',
			array( 'hti-track' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Render the comparison (shortcode callback).
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string Safe HTML.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'category' => '' ), (array) $atts, self::SHORTCODE );

		$lang    = self::lang();
		$l       = self::strings( $lang );
		$records = self::records( $lang, (string) $atts['category'] );

		if ( array() === $records ) {
			return '';
		}

		ob_start();
		?>
		<section class="hti-bk" data-lang="<?php echo esc_attr( $lang ); ?>">
			<?php echo self::disclosure_html( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in disclosure_html(). ?>

			<?php echo self::category_chips( $lang, (string) $atts['category'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in category_chips(). ?>

			<div class="hti-bk__toolbar">
				<input type="search" class="hti-bk__q" placeholder="<?php echo esc_attr( $l['search_ph'] ); ?>" autocomplete="off" aria-label="<?php echo esc_attr( $l['search_aria'] ); ?>">
				<label class="hti-bk__switch"><input type="checkbox" class="hti-bk__nocfd"><span><?php echo esc_html( $l['no_cfd'] ); ?></span></label>
				<label class="hti-bk__sortwrap"><?php echo esc_html( $l['sort_label'] ); ?>
					<select class="hti-bk__sort" aria-label="<?php echo esc_attr( $l['sort_aria'] ); ?>">
						<option value="editorial"><?php echo esc_html( $l['sort_editorial'] ); ?></option>
						<option value="rating"><?php echo esc_html( $l['sort_rating'] ); ?></option>
						<option value="name"><?php echo esc_html( $l['sort_name'] ); ?></option>
					</select>
				</label>
				<p class="hti-bk__count" role="status" aria-live="polite"><span class="hti-bk__count-n"><?php echo esc_html( (string) count( $records ) ); ?></span> <?php echo esc_html( $l['count_word'] ); ?></p>
			</div>

			<div class="hti-bk__list">
				<?php foreach ( $records as $r ) : ?>
					<?php echo self::card( $r, $l, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card(). ?>
				<?php endforeach; ?>
			</div>

			<div class="hti-bk__empty" hidden>
				<p class="hti-bk__empty-t"><?php echo esc_html( $l['empty_t'] ); ?></p>
				<p class="hti-bk__empty-d"><?php echo esc_html( $l['empty_d'] ); ?></p>
				<button type="button" class="hti-bk__reset"><?php echo esc_html( $l['clear'] ); ?></button>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The labelled partner CTA box — the ONLY affiliate component inside a
	 * "how to open an account" guide (the rest of the guide is factual
	 * step-by-step content). Carries the on-page disclosure, the label, the
	 * /go/ link and the CFD warning.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string Safe HTML.
	 */
	public static function render_cta( $atts ): string {
		$atts = shortcode_atts(
			array(
				'slug'     => '',
				'location' => 'guide',
			),
			(array) $atts,
			self::SHORTCODE_CTA
		);

		$slug = sanitize_key( (string) $atts['slug'] );
		if ( '' === $slug ) {
			return '';
		}
		$post = get_page_by_path( $slug, OBJECT, 'broker' );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		$lang = self::lang();
		$l    = self::strings( $lang );
		$r    = self::record( $post, $lang );
		$loc  = in_array( (string) $atts['location'], Broker_Go::LOCATIONS, true ) ? (string) $atts['location'] : 'guide';
		$go   = self::go_link( $r, $loc );

		ob_start();
		?>
		<aside class="hti-bk hti-bkr__cta hti-bkr__cta--inline">
			<?php if ( ! empty( $r['affiliate'] ) ) : ?>
				<span class="hti-bk__label"><?php echo esc_html( $l['label'] ); ?></span>
			<?php endif; ?>
			<a class="hti-bk__btn" href="<?php echo esc_url( $go['href'] ); ?>" rel="<?php echo esc_attr( $go['rel'] ); ?>" target="_blank"><?php echo esc_html( $l['visit_btn'] . ' ' . (string) $r['name'] ); ?></a>
			<a class="hti-bk__btn hti-bk__btn--ghost" href="<?php echo esc_url( (string) $r['review_url'] ); ?>"><?php echo esc_html( $l['review_btn'] ); ?> →</a>
			<?php if ( $r['cfd'] ) : ?>
				<?php echo self::cfd_warning_html( $lang, (string) $r['cfd_pct'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cfd_warning_html(). ?>
			<?php endif; ?>
			<?php echo self::disclosure_html( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in disclosure_html(). ?>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Links to the pillar + category pages ("compare by use"). Internal links,
	 * server-rendered for SEO — the categories are real pages, not JS filters.
	 *
	 * @param string $lang    'en' or 'pt'.
	 * @param string $current Current category ('' on the pillar).
	 * @return string Safe HTML.
	 */
	private static function category_chips( string $lang, string $current ): string {
		$l     = self::strings( $lang );
		$pt    = 'pt' === $lang;
		$pages = array(
			''                 => $pt ? array( 'melhores-corretoras-em-portugal', 'Todas' ) : array( 'best-brokers-in-portugal', 'All' ),
			'beginners'        => $pt ? array( 'melhores-corretoras-para-iniciantes', 'Iniciantes' ) : array( 'best-brokers-for-beginners-portugal', 'Beginners' ),
			'etfs'             => $pt ? array( 'melhores-corretoras-para-etfs', 'ETFs' ) : array( 'best-etf-brokers-portugal', 'ETFs' ),
			'stocks'           => $pt ? array( 'melhores-corretoras-para-acoes', 'Ações' ) : array( 'best-stock-brokers-portugal', 'Stocks' ),
			'interest-on-cash' => $pt ? array( 'melhores-corretoras-com-juros-sobre-o-saldo', 'Juros' ) : array( 'best-interest-on-cash-accounts-portugal', 'Interest' ),
			'crypto'           => $pt ? array( 'melhores-corretoras-para-cripto', 'Cripto' ) : array( 'best-crypto-brokers-portugal', 'Crypto' ),
		);

		$html = '<nav class="hti-bk__cats" aria-label="' . esc_attr( $l['categories'] ) . '"><span class="hti-bk__cats-label">' . esc_html( $l['categories'] ) . '</span>';
		foreach ( $pages as $cat => $pair ) {
			list( $slug, $label ) = $pair;
			$page                 = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			$class = 'hti-bk__cat' . ( $cat === $current ? ' is-active' : '' );
			$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) get_permalink( $page ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/**
	 * One broker card with filter data attributes.
	 *
	 * @param array<string,mixed>  $r    Record.
	 * @param array<string,string> $l    Localized strings.
	 * @param string               $lang Render language.
	 * @return string Safe HTML.
	 */
	private static function card( array $r, array $l, string $lang ): string {
		$products = self::product_labels( $l );
		$go       = self::go_link( $r, 'compare' );

		ob_start();
		?>
		<article class="hti-bk__card"
			data-name="<?php echo esc_attr( strtolower( (string) $r['name'] ) ); ?>"
			data-rating="<?php echo esc_attr( null === $r['rating'] ? '0' : (string) $r['rating'] ); ?>"
			data-order="<?php echo esc_attr( (string) $r['menu_order'] ); ?>"
			data-cfd="<?php echo $r['cfd'] ? '1' : '0'; ?>"
			data-text="<?php echo esc_attr( strtolower( $r['name'] . ' ' . $r['regulator'] . ' ' . implode( ' ', (array) $r['products'] ) ) ); ?>">
			<div class="hti-bk__card-head">
				<div class="hti-bk__avatar" aria-hidden="true"><?php echo esc_html( self::initials( (string) $r['name'] ) ); ?></div>
				<div class="hti-bk__id">
					<h3 class="hti-bk__name"><?php echo esc_html( (string) $r['name'] ); ?></h3>
					<p class="hti-bk__reg"><?php echo esc_html( $l['regulated'] . ' ' . (string) $r['regulator'] ); ?></p>
				</div>
				<?php if ( ! empty( $r['affiliate'] ) ) : ?>
					<span class="hti-bk__label"><?php echo esc_html( $l['label'] ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== (string) $r['tagline'] ) : ?>
				<p class="hti-bk__tagline"><?php echo esc_html( (string) $r['tagline'] ); ?></p>
			<?php endif; ?>

			<div class="hti-bk__facts">
				<?php if ( null !== $r['rating'] ) : ?>
					<span class="hti-bk__stars" role="img" aria-label="<?php echo esc_attr( $l['rating_aria'] . ' ' . number_format_i18n( (float) $r['rating'], 1 ) . '/5' ); ?>"><?php echo esc_html( self::stars( (float) $r['rating'] ) . ' ' . number_format_i18n( (float) $r['rating'], 1 ) ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $r['min_deposit'] ) : ?>
					<span class="hti-bk__fact"><?php echo esc_html( $l['min_label'] . ': ' . (string) $r['min_deposit'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $r['fees_note'] ) : ?>
					<span class="hti-bk__fact"><?php echo esc_html( $l['fees_label'] . ': ' . (string) $r['fees_note'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $r['interest_note'] ) : ?>
					<span class="hti-bk__fact"><?php echo esc_html( $l['interest_label'] . ': ' . (string) $r['interest_note'] ); ?></span>
				<?php endif; ?>
			</div>

			<div class="hti-bk__chips">
				<?php foreach ( (array) $r['products'] as $p ) : ?>
					<?php if ( isset( $products[ $p ] ) ) : ?>
						<span class="hti-bk__chip"><?php echo esc_html( $products[ $p ] ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( $r['cfd'] ) : ?>
					<span class="hti-bk__chip hti-bk__chip--cfd"><?php echo esc_html( $l['cfd_chip'] ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $r['cfd'] ) : ?>
				<?php echo self::cfd_warning_html( $lang, (string) $r['cfd_pct'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cfd_warning_html(). ?>
			<?php endif; ?>

			<div class="hti-bk__actions">
				<a class="hti-bk__btn hti-bk__btn--ghost" href="<?php echo esc_url( (string) $r['review_url'] ); ?>"><?php echo esc_html( $l['review_btn'] ); ?> →</a>
				<a class="hti-bk__btn" href="<?php echo esc_url( $go['href'] ); ?>" rel="<?php echo esc_attr( $go['rel'] ); ?>" target="_blank"><?php echo esc_html( $l['visit_btn'] . ' ' . (string) $r['name'] ); ?></a>
			</div>

			<?php if ( '' !== (string) $r['verified'] ) : ?>
				<p class="hti-bk__verified"><?php echo esc_html( $l['verified_label'] . ' ' . (string) $r['verified'] ); ?></p>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Star string for the visual editorial rating (never schema).
	 *
	 * @param float $rating 0–5.
	 */
	public static function stars( float $rating ): string {
		$full = (int) floor( $rating + 0.25 );
		$full = max( 0, min( 5, $full ) );
		return str_repeat( '★', $full ) . str_repeat( '☆', 5 - $full );
	}

	/**
	 * Brand initials for the avatar (mirrors Deposits::initials).
	 *
	 * @param string $name Broker name.
	 */
	private static function initials( string $name ): string {
		$words = preg_split( '/\s+/', trim( $name ) );
		$out   = '';
		foreach ( (array) $words as $w ) {
			if ( mb_strlen( $w ) > 2 && mb_strlen( $out ) < 2 ) {
				$out .= mb_strtoupper( mb_substr( $w, 0, 1 ) );
			}
		}
		if ( '' === $out && '' !== $name ) {
			$out = mb_strtoupper( mb_substr( $name, 0, 2 ) );
		}
		return $out;
	}

	/* -------------------------------------------------------------- partner */

	/**
	 * Build the post-result partner module ("Passar à prática"), fully
	 * localized and ready to render — result.js only paints it. Deterministic
	 * (Broker_Match), never persisted with the profile, never part of the
	 * Explainer/Validator pipeline, and excluded from the PDF and emails.
	 *
	 * @param int                                     $archetype_id Archetype 1–5.
	 * @param list<array{class:string,pct:int|float}> $allocation   Fixed allocation.
	 * @param string                                  $locale       Request locale.
	 * @return array<string,mixed>|null Null when there is nothing to show.
	 */
	public static function partner_module( int $archetype_id, array $allocation, string $locale ): ?array {
		$lang = str_starts_with( strtolower( $locale ), 'pt' ) ? 'pt' : 'en';

		$records = self::records( $lang );
		if ( array() === $records ) {
			return null;
		}

		$picked = Broker_Match::pick( $records, $archetype_id, $allocation );
		if ( array() === $picked ) {
			return null;
		}

		$l  = self::strings( $lang );
		$pt = 'pt' === $lang;

		$items = array();
		foreach ( $picked as $r ) {
			$go      = self::go_link( $r, 'result' );
			$items[] = array(
				'name'        => (string) $r['name'],
				'tagline'     => (string) $r['tagline'],
				'regulator'   => (string) $r['regulator'],
				'url'         => $go['href'],
				'rel'         => $go['rel'],
				'review_url'  => (string) $r['review_url'],
				'affiliate'   => (bool) $r['affiliate'],
				'cfd'         => (bool) $r['cfd'],
				'cfd_warning' => $r['cfd'] ? Disclaimer::cfd_risk( $lang, (string) $r['cfd_pct'] ) : null,
				'visit_label' => $l['visit_btn'] . ' ' . (string) $r['name'],
			);
		}

		// Canonical module copy (Textos §6.4).
		return array(
			'eyebrow'      => $l['label'],
			'heading'      => $pt ? 'Passar à prática' : 'Putting it into practice',
			'intro'        => $pt
				? 'Plataformas que perfis como este costumam usar para deter estas classes de ativos. Informação editorial com metodologia pública — não é uma recomendação pessoal.'
				: 'Platforms that profiles like this often use to hold these asset classes. Editorial information with a public methodology — not a personal recommendation.',
			'disclosure'   => Disclaimer::affiliate( $lang ),
			'how_link'     => array(
				'url'   => self::money_page_url( $lang ),
				'label' => $l['how_link'],
			),
			'review_label' => $l['review_btn'],
			'items'        => $items,
		);
	}

	/* ---------------------------------------------------------------- schema */

	/**
	 * ItemList JSON-LD on comparison pages: position + the review URLs.
	 * Deliberately NOT Review/AggregateRating (YMYL — see broker-affiliate).
	 */
	public static function schema(): void {
		if ( ! self::is_page() ) {
			return;
		}
		$lang    = self::lang();
		$records = self::records( $lang );
		if ( array() === $records ) {
			return;
		}

		$items = array();
		foreach ( $records as $i => $r ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => (string) $r['name'],
				'url'      => (string) $r['review_url'],
			);
		}

		$post = get_queried_object();
		$node = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $post instanceof \WP_Post ? (string) $post->post_title : 'Brokers',
			'inLanguage'      => 'pt' === $lang ? 'pt-PT' : 'en',
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}
}
