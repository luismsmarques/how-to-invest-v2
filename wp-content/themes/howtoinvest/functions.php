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
const VERSION = '0.7.3';

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

	// Registered (not enqueued) so the glossary-index block can enqueue it on
	// render — works wherever the block appears, not only on the CPT archive.
	wp_register_script(
		'howtoinvest-glossary',
		get_stylesheet_directory_uri() . '/assets/js/glossary.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
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
	// The URL language prefix is the ground truth for what the visitor sees
	// (Polylang serves PT under /pt/). Trust it first: on the front page,
	// pll_current_language() can report the default language even under /pt/.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( '' !== $uri && preg_match( '#^/pt(/|$|\?)#', $uri ) ) {
		return 'pt';
	}
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
		'learn_intro'      => array( 'en' => 'Clear, jargon-free articles to build your investing confidence — organised by topic.', 'pt' => 'Artigos claros e sem jargão para ganhares confiança a investir — organizados por tema.' ),
		'nav_types'        => array( 'en' => 'Investor types', 'pt' => 'Perfis' ),
		'nav_classes'      => array( 'en' => 'Asset classes', 'pt' => 'Classes de ativos' ),
		'nav_tools'        => array( 'en' => 'Tools', 'pt' => 'Ferramentas' ),
		'nav_glossary'     => array( 'en' => 'Glossary', 'pt' => 'Glossário' ),
		'nav_news'         => array( 'en' => 'News', 'pt' => 'Notícias' ),
		'foot_about'       => array( 'en' => 'About', 'pt' => 'Sobre' ),
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
		'arch_learn'       => array( 'en' => 'Learn to invest', 'pt' => 'Aprender a investir' ),
		'arch_glossary'    => array( 'en' => 'Investing glossary', 'pt' => 'Glossário de investimento' ),
		'arch_news'        => array( 'en' => 'Financial news', 'pt' => 'Notícias financeiras' ),
		'sub_glossary'     => array( 'en' => 'The essential terms, explained without jargon.', 'pt' => 'Os termos essenciais, explicados sem jargão.' ),
		'sub_news'         => array( 'en' => "Calm reads on what's happening in the markets — and what it means for you.", 'pt' => 'Leituras calmas do que acontece nos mercados — e do que isso significa para ti.' ),
		'back_learn'       => array( 'en' => '← Learn', 'pt' => '← Aprender' ),
		'back_news'        => array( 'en' => '← News', 'pt' => '← Notícias' ),
		// Glossary index.
		'gloss_all'        => array( 'en' => 'All', 'pt' => 'Todos' ),
		'gloss_filter'     => array( 'en' => 'Filter by letter', 'pt' => 'Filtrar por letra' ),
		// Language switcher.
		'lang_switch'      => array( 'en' => 'Language', 'pt' => 'Idioma' ),
		// About page.
		'about_eyebrow'    => array( 'en' => 'About', 'pt' => 'Sobre' ),
		'about_title'      => array( 'en' => 'About this project', 'pt' => 'Sobre este projeto' ),
		'about_lead'       => array( 'en' => 'A personal journey to explore AI, help the community, and show the power of modern programming.', 'pt' => 'Uma jornada pessoal para explorar IA, ajudar a comunidade e mostrar o poder da programação moderna.' ),
		'about_why_h'      => array( 'en' => 'Why does this project exist?', 'pt' => 'Porque é que este projeto existe?' ),
		'about_why_p1'     => array( 'en' => 'This project was born from a personal need: to sharpen my artificial-intelligence skills while solving a real problem I keep running into.', 'pt' => 'Este projeto nasceu de uma necessidade pessoal: aprimorar as minhas competências em inteligência artificial enquanto resolvo um problema real com que me cruzo constantemente.' ),
		'about_why_p2'     => array( 'en' => 'Friends constantly ask me: "Where should I invest?", "How do I start?", "What\'s my risk profile?". On Reddit and other communities, I see the same questions over and over. It was the perfect problem to solve while exploring AI technologies.', 'pt' => 'Os amigos perguntam-me constantemente: "Onde devo investir?", "Como começo?", "Qual é o meu perfil de risco?". No Reddit e noutras comunidades, vejo as mesmas perguntas repetidamente. Era o problema perfeito para resolver enquanto explorava tecnologias de IA.' ),
		'about_why_p3'     => array( 'en' => 'The result? A fully functional application that genuinely helps people understand the financial world better and make more informed decisions about their investments.', 'pt' => 'O resultado? Uma aplicação totalmente funcional que ajuda mesmo as pessoas a compreender melhor o mundo financeiro e a tomar decisões mais informadas sobre os seus investimentos.' ),
		'about_founder_name' => array( 'en' => 'Luis Marques', 'pt' => 'Luis Marques' ),
		'about_founder_role' => array( 'en' => 'Founder & developer', 'pt' => 'Fundador & programador' ),
		'about_founder_bio'  => array( 'en' => '12+ years in iGaming, specializing in programming and automation. Now focused on helping others solve problems with code.', 'pt' => 'Mais de 12 anos em iGaming, especializado em programação e automação. Agora focado em ajudar os outros a resolver problemas com código.' ),
		'about_linkedin'   => array( 'en' => 'LinkedIn', 'pt' => 'LinkedIn' ),
		'about_coffee'     => array( 'en' => 'Buy me a coffee', 'pt' => 'Compra-me um café' ),
		'about_goals_h'    => array( 'en' => 'Project goals', 'pt' => 'Objetivos do projeto' ),
		'about_goal1_t'    => array( 'en' => 'Sharpen my AI skills', 'pt' => 'Aprimorar as minhas competências em IA' ),
		'about_goal1_d'    => array( 'en' => 'Build something real with modern AI — not just demos.', 'pt' => 'Construir algo real com IA moderna — não apenas demonstrações.' ),
		'about_goal2_t'    => array( 'en' => 'Stay on top of financial news', 'pt' => 'Acompanhar as notícias financeiras' ),
		'about_goal2_d'    => array( 'en' => 'Follow the markets and turn noise into clarity.', 'pt' => 'Seguir os mercados e transformar ruído em clareza.' ),
		'about_goal3_t'    => array( 'en' => 'Help the community', 'pt' => 'Ajudar a comunidade' ),
		'about_goal3_d'    => array( 'en' => 'Answer the questions friends and forums keep asking.', 'pt' => 'Responder às perguntas que amigos e fóruns repetem.' ),
		'about_journey_h'  => array( 'en' => 'The project\'s journey', 'pt' => 'A vida do projeto' ),
		'about_journey_p'  => array( 'en' => 'It started as a vibe-coded project on the Base44 platform, then moved to a WordPress solution under my own control — for SEO and full ownership.', 'pt' => 'Começou como um projeto "vibe coded" na plataforma Base44 e migrou para uma solução em WordPress sob o meu controlo — para efeitos de SEO e total autonomia.' ),
		'about_journey_1'  => array( 'en' => 'Prototype on Base44', 'pt' => 'Protótipo no Base44' ),
		'about_journey_2'  => array( 'en' => 'Self-hosted WordPress (SEO)', 'pt' => 'WordPress próprio (SEO)' ),
		'about_cta'        => array( 'en' => 'Discover your investor profile', 'pt' => 'Descobre o teu perfil de investidor' ),
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
	register_block_type(
		'howtoinvest/about',
		array(
			'api_version'     => 3,
			'title'           => __( 'About page', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_about',
		)
	);
	register_block_type(
		'howtoinvest/learn-hub',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn hub', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_learn_hub',
		)
	);
	register_block_type(
		'howtoinvest/learn-feed',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn feed', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_learn_feed',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_dynamic_blocks' );

/**
 * Render the Learn hub: the educational articles grouped by category, in the
 * current language. Polylang filters the query/terms by language when active.
 */
function render_learn_hub(): string {
	if ( ! taxonomy_exists( 'learn_topic' ) || ! post_type_exists( 'learn' ) ) {
		return '';
	}
	$pt    = 'pt' === current_lang();
	$terms = get_terms(
		array(
			'taxonomy'   => 'learn_topic',
			'hide_empty' => true,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	$out = '<div class="hti-learn">';
	$out .= '<p class="hti-learn__intro">' . esc_html( t( 'learn_intro' ) ) . '</p>';

	foreach ( $terms as $term ) {
		$name = $term->name;
		if ( $pt ) {
			$meta = get_term_meta( $term->term_id, 'hti_name_pt', true );
			if ( is_string( $meta ) && '' !== $meta ) {
				$name = $meta;
			}
		}

		$q = new \WP_Query(
			array(
				'post_type'           => 'learn',
				'posts_per_page'      => 20,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'learn_topic',
						'terms'    => (int) $term->term_id,
					),
				),
			)
		);
		if ( ! $q->have_posts() ) {
			continue;
		}

		$out .= '<section class="hti-learn__cat"><h2 class="hti-learn__cat-title">' . esc_html( $name ) . '</h2><ul class="hti-learn__list">';
		while ( $q->have_posts() ) {
			$q->the_post();
			$excerpt = get_the_excerpt();
			$out    .= '<li class="hti-learn__item"><a href="' . esc_url( (string) get_permalink() ) . '">'
				. esc_html( (string) get_the_title() ) . '</a>'
				. ( '' !== $excerpt ? ' <span class="hti-learn__excerpt">— ' . esc_html( $excerpt ) . '</span>' : '' )
				. '</li>';
		}
		$out .= '</ul></section>';
		wp_reset_postdata();
	}

	$out .= '</div>';
	return $out;
}

/**
 * Query a few published Learn articles in a given language (Polylang-aware).
 *
 * @param string $lang Language slug ('' = no language constraint).
 * @param int    $n    How many.
 * @return array<int,\WP_Post>
 */
function learn_feed_posts( string $lang, int $n ): array {
	$args = array(
		'post_type'           => 'learn',
		'post_status'         => 'publish',
		'posts_per_page'      => $n,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	if ( '' !== $lang && function_exists( 'pll_default_language' ) ) {
		$args['lang'] = $lang;
	}
	$query = new \WP_Query( $args );
	$posts = $query->posts;
	wp_reset_postdata();
	return $posts;
}

/**
 * Homepage "Start learning" feed: the latest Learn articles as cards, in the
 * current language. Falls back to the default language so the section is never
 * empty (e.g. before PT translations are seeded). Replaces a core Query Loop
 * that returned nothing once Polylang filtered it by language.
 */
function render_learn_feed(): string {
	if ( ! post_type_exists( 'learn' ) ) {
		return '';
	}
	$lang  = current_lang();
	$posts = learn_feed_posts( $lang, 3 );
	if ( empty( $posts ) && function_exists( 'pll_default_language' ) ) {
		$def = (string) pll_default_language( 'slug' );
		if ( '' !== $def && $def !== $lang ) {
			$posts = learn_feed_posts( $def, 3 );
		}
	}
	if ( empty( $posts ) ) {
		return '';
	}

	$pt  = 'pt' === $lang;
	$out = '<div class="wp-block-group alignwide hti-card-grid"><div class="hti-card-grid__inner" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px">';
	foreach ( $posts as $post ) {
		$permalink = (string) get_permalink( $post );
		$title     = (string) get_the_title( $post );

		$eyebrow = '';
		$terms   = get_the_terms( $post, 'learn_topic' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$term = $terms[0];
			$name = $term->name;
			if ( $pt ) {
				$meta = get_term_meta( $term->term_id, 'hti_name_pt', true );
				if ( is_string( $meta ) && '' !== $meta ) {
					$name = $meta;
				}
			}
			$eyebrow = '<div class="hti-article-card__eyebrow">' . esc_html( $name ) . '</div>';
		}

		$media = '';
		if ( has_post_thumbnail( $post ) ) {
			$media = '<a href="' . esc_url( $permalink ) . '">'
				. get_the_post_thumbnail( $post, 'medium', array( 'style' => 'width:100%;height:120px;object-fit:cover;display:block' ) )
				. '</a>';
		}

		$out .= '<div class="wp-block-group hti-article-card">'
			. '<div class="wp-block-group hti-article-card__media">' . $media . '</div>'
			. '<div class="wp-block-group hti-article-card__body">'
			. $eyebrow
			. '<h3 class="wp-block-heading hti-article-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>'
			. '</div></div>';
	}
	$out .= '</div></div>';
	return $out;
}

/**
 * Language-aware, designed About page (hero, why, founder card, goals,
 * journey). The founder avatar uses the page's featured image when set,
 * otherwise initials.
 */
function render_about(): string {
	$linkedin = 'https://www.linkedin.com/in/luismsmarques/';
	$coffee   = 'https://buymeacoffee.com/luismarques';
	$quiz     = esc_url( page_url( 'investor-profile-quiz' ) );

	// Founder avatar: featured image of the About page, else initials.
	$avatar = '<span class="hti-about__initials" aria-hidden="true">LM</span>';
	$thumb  = get_post_thumbnail_id();
	if ( $thumb ) {
		$img = wp_get_attachment_image( $thumb, 'medium', false, array( 'class' => 'hti-about__photo', 'alt' => t( 'about_founder_name' ) ) );
		if ( $img ) {
			$avatar = $img;
		}
	}

	$goals = array(
		array( 'about_goal1_t', 'about_goal1_d', '<path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6 6l1.5 1.5M16.5 16.5 18 18M18 6l-1.5 1.5M7.5 16.5 6 18"/><circle cx="12" cy="12" r="3.5"/>' ),
		array( 'about_goal2_t', 'about_goal2_d', '<path d="M4 5h13v14H5a1 1 0 0 1-1-1z"/><path d="M17 8h3v9a2 2 0 0 1-2 2"/><path d="M7 9h7M7 13h7M7 17h4"/>' ),
		array( 'about_goal3_t', 'about_goal3_d', '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 3.5a3 3 0 0 1 0 5.8M21 20c0-2.6-1.7-4.9-4-5.7"/>' ),
	);

	$h  = '<div class="alignwide hti-about">';

	// Hero.
	$h .= '<header class="hti-about__hero">';
	$h .= '<span class="hti-badge"><span class="hti-badge__dot"></span>' . esc_html( t( 'about_eyebrow' ) ) . '</span>';
	$h .= '<h1 class="hti-about__title">' . esc_html( t( 'about_title' ) ) . '</h1>';
	$h .= '<p class="hti-about__lead">' . esc_html( t( 'about_lead' ) ) . '</p>';
	$h .= '</header>';

	// Why.
	$h .= '<section class="hti-about__why">';
	$h .= '<h2 class="hti-about__h2">' . esc_html( t( 'about_why_h' ) ) . '</h2>';
	$h .= '<p>' . esc_html( t( 'about_why_p1' ) ) . '</p>';
	$h .= '<p>' . esc_html( t( 'about_why_p2' ) ) . '</p>';
	$h .= '<p class="hti-about__result">' . esc_html( t( 'about_why_p3' ) ) . '</p>';
	$h .= '</section>';

	// Founder card.
	$h .= '<section class="hti-about__founder">';
	$h .= '<div class="hti-about__avatar">' . $avatar . '</div>';
	$h .= '<div class="hti-about__founderbody">';
	$h .= '<span class="hti-about__role">' . esc_html( t( 'about_founder_role' ) ) . '</span>';
	$h .= '<h2 class="hti-about__name">' . esc_html( t( 'about_founder_name' ) ) . '</h2>';
	$h .= '<p class="hti-about__bio">' . esc_html( t( 'about_founder_bio' ) ) . '</p>';
	$h .= '<div class="hti-about__social">';
	$h .= '<a class="hti-btn-about hti-btn-about--ghost" href="' . esc_url( $linkedin ) . '" target="_blank" rel="noopener noreferrer">';
	$h .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.94 7.5a1.94 1.94 0 1 1 0-3.88 1.94 1.94 0 0 1 0 3.88zM5.2 20.4V9h3.48v11.4H5.2zM10.6 9h3.34v1.56h.05c.46-.88 1.6-1.8 3.3-1.8 3.53 0 4.18 2.32 4.18 5.34v6.3h-3.48v-5.6c0-1.34-.02-3.06-1.86-3.06-1.87 0-2.15 1.46-2.15 2.96v5.7H10.6z"/></svg>'
		. esc_html( t( 'about_linkedin' ) ) . '</a>';
	$h .= '<a class="hti-btn-about hti-btn-about--coffee" href="' . esc_url( $coffee ) . '" target="_blank" rel="noopener noreferrer">☕ ' . esc_html( t( 'about_coffee' ) ) . '</a>';
	$h .= '</div></div></section>';

	// Goals.
	$h .= '<section class="hti-about__goals-wrap">';
	$h .= '<h2 class="hti-about__h2 hti-about__h2--center">' . esc_html( t( 'about_goals_h' ) ) . '</h2>';
	$h .= '<div class="hti-about__goals">';
	foreach ( $goals as $g ) {
		$h .= '<div class="hti-about__goal">'
			. '<span class="hti-about__goalicon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $g[2] . '</svg></span>'
			. '<h3 class="hti-about__goalt">' . esc_html( t( $g[0] ) ) . '</h3>'
			. '<p class="hti-about__goald">' . esc_html( t( $g[1] ) ) . '</p>'
			. '</div>';
	}
	$h .= '</div></section>';

	// Journey.
	$h .= '<section class="hti-about__journey">';
	$h .= '<h2 class="hti-about__h2">' . esc_html( t( 'about_journey_h' ) ) . '</h2>';
	$h .= '<p>' . esc_html( t( 'about_journey_p' ) ) . '</p>';
	$h .= '<div class="hti-about__timeline">'
		. '<span class="hti-about__step">' . esc_html( t( 'about_journey_1' ) ) . '</span>'
		. '<span class="hti-about__arrow" aria-hidden="true">→</span>'
		. '<span class="hti-about__step is-now">' . esc_html( t( 'about_journey_2' ) ) . '</span>'
		. '</div>';
	$h .= '</section>';

	// Closing CTA.
	$h .= '<div class="hti-about__cta"><a class="wp-block-button__link wp-element-button" href="' . $quiz . '">' . esc_html( t( 'about_cta' ) ) . '</a></div>';

	$h .= '</div>';
	return $h;
}

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
		. '<a class="wp-block-button__link wp-element-button" href="' . esc_url( page_url( 'investor-profile-quiz' ) ) . '">'
		. esc_html( t( 'cta_get_started' ) ) . '</a></div></div>';
}

/**
 * Language-aware homepage hero + "how it works" steps.
 */
function render_homepage_intro(): string {
	$quiz = esc_url( page_url( 'investor-profile-quiz' ) );

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
			array( page_url( 'about' ), t( 'foot_about' ) ),
			array( page_url( 'privacy-policy' ), t( 'foot_privacy' ) ),
			array( page_url( 'terms-and-conditions' ), t( 'foot_terms' ) ),
			array( page_url( 'contact' ), t( 'foot_contact' ) ),
		);
	} else {
		$items = array(
			array( archive_url( 'learn', '/learn/' ), t( 'nav_learn' ) ),
			array( page_url( 'investor-types' ), t( 'nav_types' ) ),
			array( page_url( 'asset-classes' ), t( 'nav_classes' ) ),
			array( page_url( 'tools' ), t( 'nav_tools' ) ),
			array( archive_url( 'glossary', '/investing-glossary/' ), t( 'nav_glossary' ) ),
			array( archive_url( 'news', '/financial-news/' ), t( 'nav_news' ) ),
		);
	}

	$list = '';
	foreach ( $items as $item ) {
		$list .= '<li class="menu-item"><a href="' . esc_url( $item[0] ) . '">' . esc_html( $item[1] ) . '</a></li>';
	}

	return '<nav class="' . esc_attr( trim( 'hti-menu-nav ' . $extra ) ) . '"><ul class="hti-menu">' . $list . '</ul></nav>';
}

/**
 * Permalink of a custom post type archive (Learn, Glossary, News), localized
 * to the current language. Polylang filters get_post_type_archive_link() to
 * prepend the active language's slug, so the PT nav points at /pt/learn/ etc.
 *
 * @param string $post_type Post type with an archive.
 * @param string $fallback  Path to use if the archive link can't be built.
 */
function archive_url( string $post_type, string $fallback ): string {
	$url = get_post_type_archive_link( $post_type );
	return $url ? $url : home_url( $fallback );
}

/**
 * Clean, localized H1 for our CPT archives. WordPress's
 * get_the_archive_title() prepends "Archives:" / "Arquivo:" and uses the
 * post-type label, which Polylang doesn't translate — so PT showed
 * "Arquivo: Learn". Replace it with our bilingual title (no prefix).
 *
 * @param string $title Default archive title.
 */
function archive_title( string $title ): string {
	if ( is_post_type_archive( 'learn' ) ) {
		return t( 'arch_learn' );
	}
	if ( is_post_type_archive( 'glossary' ) ) {
		return t( 'arch_glossary' );
	}
	if ( is_post_type_archive( 'news' ) ) {
		return t( 'arch_news' );
	}
	return $title;
}
add_filter( 'get_the_archive_title', __NAMESPACE__ . '\\archive_title' );

/**
 * Permalink of a page identified by its English slug, localized to the
 * current language via Polylang (falls back to a plain path).
 *
 * @param string $en_slug English page slug.
 */
function page_url( string $en_slug ): string {
	$page = get_page_by_path( $en_slug, OBJECT, 'page' );
	if ( $page instanceof \WP_Post ) {
		$id = (int) $page->ID;
		// Use our current_lang() (URL-aware) rather than pll_current_language(),
		// which can report the default language on the front page.
		if ( function_exists( 'pll_get_post' ) ) {
			$tr = pll_get_post( $id, current_lang() );
			if ( $tr ) {
				$id = (int) $tr;
			}
		}
		$url = get_permalink( $id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/' . $en_slug . '/' );
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

	// Load the A–Z filter behaviour wherever this block renders.
	wp_enqueue_script( 'howtoinvest-glossary' );

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
