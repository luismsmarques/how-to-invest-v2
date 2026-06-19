<?php
/**
 * HowToInvest child theme functions.
 *
 * Keeps the theme thin: design tokens live in theme.json, the interactive
 * questionnaire/result are provided by the hti-engine plugin. This file only
 * wires up enqueuing, i18n, theme supports and our block-pattern category.
 *
 * @package HowToInvest
 */

namespace HowToInvest\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Theme version, used for cache-busting enqueued assets.
 */
const VERSION = '0.6.1';

/**
 * Load the theme text domain (EN default + PT translations in languages/).
 *
 * Translations ship as a performant PHP file (howtoinvest-pt_PT.l10n.php),
 * loaded automatically by WordPress 6.5+.
 */
function load_textdomain(): void {
	load_theme_textdomain( 'howtoinvest', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Declare theme supports not already inherited from the parent block theme.
 */
function theme_supports(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\theme_supports' );

/**
 * Enqueue the child theme stylesheet after the parent's styles.
 */
function enqueue_styles(): void {
	wp_enqueue_style(
		'howtoinvest-style',
		get_stylesheet_uri(),
		array(),
		VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles' );

/**
 * Enqueue the small mobile-header menu toggle (deferred, footer).
 */
function enqueue_scripts(): void {
	wp_enqueue_script(
		'howtoinvest-header',
		get_stylesheet_directory_uri() . '/assets/js/header.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);

	if ( is_post_type_archive( 'glossary' ) ) {
		wp_enqueue_script(
			'howtoinvest-glossary',
			get_stylesheet_directory_uri() . '/assets/js/glossary.js',
			array(),
			VERSION,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );

/**
 * Current front-end language ('pt' or 'en').
 *
 * Uses Polylang when present, otherwise the WordPress locale. Keeps theme
 * presentation strings language-aware without depending on .mo files (whose
 * locale, e.g. pt_PT_ao90, may not match the shipped translations).
 */
function current_lang(): string {
	if ( function_exists( 'pll_current_language' ) ) {
		$slug = (string) pll_current_language( 'slug' );
		if ( '' !== $slug ) {
			return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
		}
	}
	return str_starts_with( strtolower( (string) determine_locale() ), 'pt' ) ? 'pt' : 'en';
}

/**
 * Language-aware theme presentation strings (EN default + PT).
 *
 * @return array<string,array{en:string,pt:string}>
 */
function strings(): array {
	return array(
		// Header / nav / CTAs.
		'cta_get_started'  => array( 'en' => 'Get started', 'pt' => 'Começar' ),
		'cta_start_quiz'   => array( 'en' => 'Start the questionnaire', 'pt' => 'Começar o questionário' ),
		'nav_learn'        => array( 'en' => 'Learn', 'pt' => 'Aprender' ),
		'nav_glossary'     => array( 'en' => 'Glossary', 'pt' => 'Glossário' ),
		'nav_news'         => array( 'en' => 'News', 'pt' => 'Notícias' ),
		'foot_privacy'     => array( 'en' => 'Privacy', 'pt' => 'Privacidade' ),
		'foot_terms'       => array( 'en' => 'Terms', 'pt' => 'Termos' ),
		'foot_contact'     => array( 'en' => 'Contact', 'pt' => 'Contacto' ),
		// Homepage.
		'hero_badge'       => array( 'en' => 'Educational · free · no sign-up', 'pt' => 'Educativo · gratuito · sem registo' ),
		'hero_title'       => array( 'en' => 'Discover what kind of investor you are.', 'pt' => 'Descobre que tipo de investidor és.' ),
		'hero_lead'        => array( 'en' => 'An educational tool that helps you understand your profile — in minutes, jargon-free, and without asking anything of you.', 'pt' => 'Uma ferramenta educativa que te ajuda a perceber o teu perfil — em poucos minutos, sem jargão e sem te pedir nada.' ),
		'hero_explore'     => array( 'en' => 'Explore articles', 'pt' => 'Explorar artigos' ),
		'hero_fineprint'   => array( 'en' => 'Educational tool · not advice · examples by asset class only', 'pt' => 'Ferramenta educativa · não é aconselhamento · exemplos só por classe de ativos' ),
		'step1_t'          => array( 'en' => 'You answer', 'pt' => 'Respondes' ),
		'step1_d'          => array( 'en' => 'Six short questions about your time frame, goals and how you react to drops.', 'pt' => 'Seis perguntas curtas sobre o teu tempo, objetivos e como reages a quedas.' ),
		'step2_t'          => array( 'en' => 'You see your profile', 'pt' => 'Vês o teu perfil' ),
		'step2_d'          => array( 'en' => 'A clear archetype, with an illustrative example by asset class — never tickers.', 'pt' => 'Um arquétipo claro, com um exemplo ilustrativo por classes — nunca tickers.' ),
		'step3_t'          => array( 'en' => 'You learn', 'pt' => 'Aprendes' ),
		'step3_d'          => array( 'en' => 'We explain why you landed in that profile and what to study next.', 'pt' => 'Explicamos porque caíste nesse perfil e o que estudar a seguir.' ),
		'section_learn'    => array( 'en' => 'Start learning', 'pt' => 'Para começar a aprender' ),
		'see_all'          => array( 'en' => 'See all →', 'pt' => 'Ver tudo →' ),
		// Footer.
		'footer_disclaimer' => array(
			'en' => 'HowToInvest is an educational platform about investing literacy. Nothing here is financial, investment, tax or legal advice, or a recommendation to buy or sell any asset. Investing carries risk, including loss of capital. Examples are illustrative and by asset class only. Always do your own research and consider professional advice.',
			'pt' => 'A HowToInvest é uma plataforma educativa sobre literacia financeira. Nada aqui constitui aconselhamento financeiro, de investimento, fiscal ou jurídico, nem recomendação de compra ou venda de qualquer ativo. Investir envolve risco, incluindo a perda de capital. Os exemplos são ilustrativos e apenas por classe de ativos. Faz sempre a tua própria pesquisa e considera aconselhamento profissional.',
		),
		'footer_copy'      => array( 'en' => '© 2026 HowToInvest · Educational content, not an investment recommendation.', 'pt' => '© 2026 HowToInvest · Conteúdo educativo, não constitui recomendação de investimento.' ),
		// Archives / back links.
		'sub_glossary'     => array( 'en' => 'The essential terms, explained without jargon.', 'pt' => 'Os termos essenciais, explicados sem jargão.' ),
		'sub_news'         => array( 'en' => "Calm reads on what's happening in the markets — and what it means for you.", 'pt' => 'Leituras calmas do que acontece nos mercados — e do que isso significa para ti.' ),
		'back_learn'       => array( 'en' => '← Learn', 'pt' => '← Aprender' ),
		'back_news'        => array( 'en' => '← News', 'pt' => '← Notícias' ),
		// Glossary index.
		'gloss_all'        => array( 'en' => 'All', 'pt' => 'Todos' ),
		'gloss_filter'     => array( 'en' => 'Filter by letter', 'pt' => 'Filtrar por letra' ),
		// Language switcher.
		'lang_switch'      => array( 'en' => 'Language', 'pt' => 'Idioma' ),
	);
}

/**
 * Translate a theme presentation string by key for the current language.
 *
 * @param string $key Key in strings().
 */
function t( string $key ): string {
	$all  = strings();
	$lang = current_lang();
	if ( isset( $all[ $key ] ) ) {
		return $all[ $key ][ $lang ] ?? $all[ $key ]['en'];
	}
	return '';
}

/**
 * Dynamic blocks rendered at request time (so t() sees the current Polylang
 * language — patterns run at init, before the language is known).
 */
function register_dynamic_blocks(): void {
	register_block_type(
		'howtoinvest/homepage-intro',
		array(
			'api_version'     => 3,
			'title'           => __( 'Homepage intro', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_homepage_intro',
		)
	);
	register_block_type(
		'howtoinvest/header-cta',
		array(
			'api_version'     => 3,
			'title'           => __( 'Header CTA', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_header_cta',
		)
	);
	register_block_type(
		'howtoinvest/lang-switcher',
		array(
			'api_version'     => 3,
			'title'           => __( 'Language switcher', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_lang_switcher',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_dynamic_blocks' );

/**
 * Language switcher (Polylang) — links to the current page's translations.
 * Renders nothing when Polylang is inactive or only one language exists.
 */
function render_lang_switcher(): string {
	if ( ! function_exists( 'pll_the_languages' ) ) {
		return '';
	}

	$langs = pll_the_languages(
		array(
			'raw'                    => 1,
			'hide_if_no_translation' => 0,
			'display_names_as'       => 'slug',
		)
	);
	if ( ! is_array( $langs ) || count( $langs ) < 2 ) {
		return '';
	}

	$items = '';
	foreach ( $langs as $lang ) {
		$label   = strtoupper( (string) ( $lang['slug'] ?? '' ) );
		$current = ! empty( $lang['current_lang'] );
		$class   = 'hti-lang__item' . ( $current ? ' is-current' : '' );

		if ( $current ) {
			$items .= '<span class="' . esc_attr( $class ) . '" aria-current="true">' . esc_html( $label ) . '</span>';
		} else {
			$items .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) ( $lang['url'] ?? '#' ) ) . '" hreflang="' . esc_attr( (string) ( $lang['slug'] ?? '' ) ) . '" rel="alternate">' . esc_html( $label ) . '</a>';
		}
	}

	return '<nav class="hti-lang" aria-label="' . esc_attr( t( 'lang_switch' ) ) . '">' . $items . '</nav>';
}

/**
 * Language-aware header CTA button.
 */
function render_header_cta(): string {
	return '<div class="wp-block-buttons hti-cta"><div class="wp-block-button is-style-fill">'
		. '<a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/investor-profile-quiz/' ) ) . '">'
		. esc_html( t( 'cta_get_started' ) ) . '</a></div></div>';
}

/**
 * Language-aware homepage hero + "how it works" steps.
 */
function render_homepage_intro(): string {
	$quiz = esc_url( home_url( '/investor-profile-quiz/' ) );

	$html  = '<div class="wp-block-group alignwide hti-hero">';
	$html .= '<span class="hti-badge"><span class="hti-badge__dot"></span>' . esc_html( t( 'hero_badge' ) ) . '</span>';
	$html .= '<h1 class="wp-block-heading has-text-align-center hti-hero__title has-huge-font-size">' . esc_html( t( 'hero_title' ) ) . '</h1>';
	$html .= '<p class="has-text-align-center hti-hero__lead has-muted-color has-text-color has-large-font-size">' . esc_html( t( 'hero_lead' ) ) . '</p>';
	$html .= '<div class="wp-block-buttons hti-hero__actions">';
	$html .= '<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="' . $quiz . '">' . esc_html( t( 'cta_start_quiz' ) ) . '</a></div>';
	$html .= '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#hti-articles">' . esc_html( t( 'hero_explore' ) ) . '</a></div>';
	$html .= '</div>';
	$html .= '<p class="has-text-align-center hti-hero__fineprint has-small-font-size">' . esc_html( t( 'hero_fineprint' ) ) . '</p>';
	$html .= '</div>';

	$steps = array(
		array( '1', 'step1_t', 'step1_d' ),
		array( '2', 'step2_t', 'step2_d' ),
		array( '3', 'step3_t', 'step3_d' ),
	);
	$html .= '<div class="wp-block-group alignwide hti-steps">';
	foreach ( $steps as $step ) {
		$html .= '<div class="wp-block-group hti-step">'
			. '<span class="hti-step__num">' . esc_html( $step[0] ) . '</span>'
			. '<h3 class="wp-block-heading hti-step__title">' . esc_html( t( $step[1] ) ) . '</h3>'
			. '<p class="hti-step__text has-muted-color has-text-color">' . esc_html( t( $step[2] ) ) . '</p>'
			. '</div>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Reusable inline string block: <tag class href>translated text</tag>.
 * Lets FSE templates output a language-aware string (subtitles, back links…).
 */
function register_t_block(): void {
	register_block_type(
		'howtoinvest/t',
		array(
			'api_version'     => 3,
			'title'           => __( 'HowToInvest text', 'howtoinvest' ),
			'category'        => 'theme',
			'attributes'      => array(
				'k'    => array( 'type' => 'string', 'default' => '' ),
				'tag'  => array( 'type' => 'string', 'default' => 'span' ),
				'cls'  => array( 'type' => 'string', 'default' => '' ),
				'href' => array( 'type' => 'string', 'default' => '' ),
			),
			'render_callback' => __NAMESPACE__ . '\\render_t_block',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_t_block' );

/**
 * Render the howtoinvest/t block.
 *
 * @param array<string,mixed> $a Attributes (k, tag, cls, href).
 * @return string Safe HTML.
 */
function render_t_block( array $a ): string {
	$key = isset( $a['k'] ) ? (string) $a['k'] : '';
	$text = t( $key );
	if ( '' === $text ) {
		return '';
	}
	$allowed = array( 'span', 'p', 'a', 'h2', 'h3', 'div' );
	$tag     = isset( $a['tag'] ) && in_array( $a['tag'], $allowed, true ) ? $a['tag'] : 'span';
	$cls     = isset( $a['cls'] ) ? sanitize_text_field( (string) $a['cls'] ) : '';
	$attr    = '' !== $cls ? ' class="' . esc_attr( $cls ) . '"' : '';

	if ( 'a' === $tag ) {
		$href  = isset( $a['href'] ) ? (string) $a['href'] : '#';
		$href  = str_starts_with( $href, '/' ) ? home_url( $href ) : $href;
		$attr .= ' href="' . esc_url( $href ) . '"';
	}

	return '<' . $tag . $attr . '>' . esc_html( $text ) . '</' . $tag . '>';
}

/**
 * Register a dedicated block-pattern category for our reusable patterns.
 */
function register_pattern_category(): void {
	register_block_pattern_category(
		'howtoinvest',
		array( 'label' => __( 'HowToInvest', 'howtoinvest' ) )
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_pattern_category' );

/**
 * Register classic nav-menu locations.
 *
 * Registering locations also restores the Appearance → Menus screen under a
 * block theme, so the header/footer menus can be edited there (and assigned
 * per language by Polylang). The header and footer template parts render these
 * via the howtoinvest/menu block below.
 */
function register_menus(): void {
	register_nav_menus(
		array(
			'primary' => __( 'Header menu', 'howtoinvest' ),
			'footer'  => __( 'Footer menu', 'howtoinvest' ),
		)
	);
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_menus' );

/**
 * Ensure Appearance → Menus is reachable under a block theme.
 *
 * Block themes hide the classic Menus screen by default; registering menu
 * locations normally restores it, but we add the submenu link defensively
 * (without duplicating it if core already provides it).
 */
function ensure_menus_admin_page(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		return;
	}

	global $submenu;
	if ( isset( $submenu['themes.php'] ) ) {
		foreach ( $submenu['themes.php'] as $item ) {
			if ( isset( $item[2] ) && 'nav-menus.php' === $item[2] ) {
				return;
			}
		}
	}

	add_submenu_page(
		'themes.php',
		__( 'Menus', 'howtoinvest' ),
		__( 'Menus', 'howtoinvest' ),
		'edit_theme_options',
		'nav-menus.php'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ensure_menus_admin_page', 100 );

/**
 * A small server-rendered block that prints a classic nav menu by location,
 * so the FSE header/footer can show menus managed in Appearance → Menus.
 * Falls back to sensible default links until a menu is assigned to the location.
 */
function register_menu_block(): void {
	register_block_type(
		'howtoinvest/menu',
		array(
			'api_version'     => 3,
			'title'           => __( 'HowToInvest menu', 'howtoinvest' ),
			'category'        => 'theme',
			'attributes'      => array(
				'location'  => array(
					'type'    => 'string',
					'default' => 'primary',
				),
				'className' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => __NAMESPACE__ . '\\render_menu_block',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_menu_block' );

/**
 * Render the howtoinvest/menu block: a classic menu for the given location,
 * or default links when none is assigned yet.
 *
 * @param array<string,mixed> $attributes Block attributes (location, className).
 * @return string Safe HTML.
 */
function render_menu_block( array $attributes ): string {
	$location = isset( $attributes['location'] ) ? sanitize_key( (string) $attributes['location'] ) : 'primary';
	$extra    = isset( $attributes['className'] ) ? sanitize_html_class( (string) $attributes['className'] ) : '';

	if ( has_nav_menu( $location ) ) {
		$menu = wp_nav_menu(
			array(
				'theme_location'  => $location,
				'container'       => 'nav',
				'container_class' => trim( 'hti-menu-nav ' . $extra ),
				'menu_class'      => 'hti-menu',
				'depth'           => 1,
				'fallback_cb'     => false,
				'echo'            => false,
			)
		);
		if ( is_string( $menu ) && '' !== $menu ) {
			return $menu;
		}
	}

	return default_menu( $location, $extra );
}

/**
 * Default header/footer links, shown until a menu is assigned to the location.
 *
 * @param string $location Menu location slug.
 * @param string $extra    Extra container class.
 * @return string Safe HTML.
 */
function default_menu( string $location, string $extra ): string {
	if ( 'footer' === $location ) {
		$items = array(
			'/privacy-policy/'       => t( 'foot_privacy' ),
			'/terms-and-conditions/' => t( 'foot_terms' ),
			'/contact/'              => t( 'foot_contact' ),
		);
	} else {
		$items = array(
			'/how-to-start-investing/' => t( 'nav_learn' ),
			'/investing-glossary/'     => t( 'nav_glossary' ),
			'/financial-news/'         => t( 'nav_news' ),
		);
	}

	$list = '';
	foreach ( $items as $path => $label ) {
		$list .= '<li class="menu-item"><a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a></li>';
	}

	return '<nav class="' . esc_attr( trim( 'hti-menu-nav ' . $extra ) ) . '"><ul class="hti-menu">' . $list . '</ul></nav>';
}

/**
 * Server-rendered glossary index: an A–Z filter row (built from the terms that
 * exist) plus the list of terms as rows with a trailing arrow — matching the
 * design's E3 Glossary screen. Language-aware (Polylang filters the query to
 * the current language). Filtering is enhanced by glossary.js; without JS the
 * full list shows.
 */
function register_glossary_block(): void {
	register_block_type(
		'howtoinvest/glossary-index',
		array(
			'api_version'     => 3,
			'title'           => __( 'Glossary index', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_glossary_index',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_glossary_block' );

/**
 * First letter of a title, accent-folded and uppercased (e.g. "Ações" → "A").
 *
 * @param string $title Post title.
 */
function glossary_letter( string $title ): string {
	$folded = remove_accents( $title );
	$first  = strtoupper( substr( ltrim( $folded ), 0, 1 ) );
	return preg_match( '/[A-Z]/', $first ) ? $first : '#';
}

/**
 * Render the glossary index block.
 *
 * @return string Safe HTML (empty when there are no terms).
 */
function render_glossary_index(): string {
	if ( ! post_type_exists( 'glossary' ) ) {
		return '';
	}

	$query = new \WP_Query(
		array(
			'post_type'           => 'glossary',
			'post_status'         => 'publish',
			'posts_per_page'      => 300,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);

	if ( ! $query->have_posts() ) {
		return '';
	}

	$rows    = '';
	$letters = array();
	while ( $query->have_posts() ) {
		$query->the_post();
		$title  = get_the_title();
		$letter = glossary_letter( $title );

		$letters[ $letter ] = true;
		$short              = wp_strip_all_tags( (string) get_the_excerpt() );

		$rows .= '<a class="hti-gloss__row" data-letter="' . esc_attr( $letter ) . '" href="' . esc_url( (string) get_permalink() ) . '">'
			. '<span class="hti-gloss__rowtext"><span class="hti-gloss__term">' . esc_html( $title ) . '</span>'
			. ( '' !== $short ? '<span class="hti-gloss__short">' . esc_html( $short ) . '</span>' : '' )
			. '</span><span class="hti-gloss__arrow" aria-hidden="true">→</span></a>';
	}
	wp_reset_postdata();

	ksort( $letters );
	$chips = '<button type="button" class="hti-gloss__letter is-active" data-letter="all">' . esc_html( t( 'gloss_all' ) ) . '</button>';
	foreach ( array_keys( $letters ) as $letter ) {
		$chips .= '<button type="button" class="hti-gloss__letter" data-letter="' . esc_attr( $letter ) . '">' . esc_html( $letter ) . '</button>';
	}

	return '<div class="hti-gloss">'
		. '<div class="hti-gloss__alpha" role="group" aria-label="' . esc_attr( t( 'gloss_filter' ) ) . '">' . $chips . '</div>'
		. '<div class="hti-gloss__list">' . $rows . '</div>'
		. '</div>';
}
