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
const VERSION = '0.8.18';

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
 * Guarantee a responsive viewport meta tag in <head>.
 *
 * Without it, phones render the page at a default ~980px desktop width
 * (zoomed out, horizontally scrollable) and the responsive @media
 * breakpoints never fire. WordPress normally emits this for block themes,
 * but an SEO/head override on this install was suppressing it — so we emit
 * it ourselves. Core's own callback is removed first to avoid a duplicate.
 */
function viewport_meta(): void {
	remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
}
add_action( 'wp_head', __NAMESPACE__ . '\\viewport_meta', 0 );

/**
 * Emit the x-default hreflang alternate.
 *
 * Polylang already outputs <link rel="alternate" hreflang="en|pt"> for the
 * current page but not an x-default, which Google recommends as the fallback
 * for users who match no specific language. We add only that one tag, pointing
 * at the default-language (EN) URL of the *current* page — taken from the same
 * per-page translation data Polylang uses — so it can never duplicate the
 * existing alternates. No-op without Polylang.
 */
function hreflang_x_default(): void {
	if ( is_admin() || ! function_exists( 'pll_the_languages' ) || ! function_exists( 'pll_default_language' ) ) {
		return;
	}
	$default = (string) pll_default_language();
	if ( '' === $default ) {
		return;
	}
	$langs = pll_the_languages( array( 'raw' => 1, 'hide_if_no_translation' => 0 ) );
	if ( ! is_array( $langs ) ) {
		return;
	}
	foreach ( $langs as $lang ) {
		if ( (string) ( $lang['slug'] ?? '' ) === $default && ! empty( $lang['url'] ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( (string) $lang['url'] ) . '" />' . "\n";
			return;
		}
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\hreflang_x_default', 2 );

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

	// News hub category filter — enqueued on render of the news-hub block.
	wp_register_script(
		'howtoinvest-news-hub',
		get_stylesheet_directory_uri() . '/assets/js/news-hub.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );

/**
 * One-time: remove a stale Site-Editor customization of the "Home" template.
 *
 * A saved DB version of the Home template was overriding the theme's home.html,
 * pinning the homepage to the old English-only hero + an empty core Query Loop
 * (so the dynamic, language-aware blocks and the PT homepage never showed).
 * Deleting that wp_template post reverts the homepage to the theme file. Scoped
 * to this theme's "home" template only; runs once (guarded by an option). The
 * homepage can still be re-customized in the Site Editor afterwards.
 */
function maybe_reset_home_template(): void {
	if ( get_option( 'hti_home_template_reset_v1' ) ) {
		return;
	}
	update_option( 'hti_home_template_reset_v1', 1 ); // Set first: never loop, even on error.

	if ( ! function_exists( 'get_posts' ) ) {
		return;
	}
	$templates = get_posts(
		array(
			'post_type'     => 'wp_template',
			'name'          => 'home',
			'post_status'   => 'any',
			'numberposts'   => 10,
			'no_found_rows' => true,
			'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		)
	);
	foreach ( $templates as $template ) {
		wp_delete_post( (int) $template->ID, true );
	}
}
add_action( 'init', __NAMESPACE__ . '\\maybe_reset_home_template', 20 );

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
		'cta_curious_h'    => array( 'en' => 'Curious where you fit?', 'pt' => 'Curioso sobre onde te encaixas?' ),
		'cta_curious_p'    => array( 'en' => 'Answer a few questions and discover your investor archetype — with an illustrative example portfolio by asset class. Educational, not advice.', 'pt' => 'Responde a algumas perguntas e descobre o teu arquétipo de investidor — com um exemplo ilustrativo de carteira por classe de ativos. Educativo, não é aconselhamento.' ),
		'cta_curious_btn'  => array( 'en' => 'Discover your profile', 'pt' => 'Descobre o teu perfil' ),
		'nav_learn'        => array( 'en' => 'Learn', 'pt' => 'Aprender' ),
		'learn_intro'      => array( 'en' => 'Clear, jargon-free articles to build your investing confidence — organised by topic.', 'pt' => 'Artigos claros e sem jargão para ganhares confiança a investir — organizados por tema.' ),
		'nav_types'        => array( 'en' => 'Investor types', 'pt' => 'Perfis' ),
		'nav_classes'      => array( 'en' => 'Asset classes', 'pt' => 'Classes de ativos' ),
		'nav_tools'        => array( 'en' => 'Tools', 'pt' => 'Ferramentas' ),
		'nav_deposits'     => array( 'en' => 'Term deposits', 'pt' => 'Depósitos a prazo' ),
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
		// News hub.
		'news_hub_title'   => array( 'en' => 'News Hub', 'pt' => 'Hub de Notícias' ),
		'news_hub_sub'     => array( 'en' => 'Finance from every angle — markets, economy, savings and more — explained calmly and jargon-free.', 'pt' => 'As finanças de todos os ângulos — mercados, economia, poupança e mais — explicadas com calma e sem jargão.' ),
		'news_updated'     => array( 'en' => 'Updated today', 'pt' => 'Atualizado hoje' ),
		'news_featured'    => array( 'en' => 'Featured', 'pt' => 'Em destaque' ),
		'news_latest'      => array( 'en' => 'Latest news', 'pt' => 'Últimas notícias' ),
		'news_mostread'    => array( 'en' => 'Most read', 'pt' => 'Mais lidas' ),
		'news_reads'       => array( 'en' => 'reads', 'pt' => 'leituras' ),
		'news_termday'     => array( 'en' => 'Term of the day', 'pt' => 'Termo do dia' ),
		'news_termday_cta' => array( 'en' => 'See in glossary →', 'pt' => 'Ver no glossário →' ),
		'news_week'        => array( 'en' => 'This week', 'pt' => 'Esta semana' ),
		'news_all'         => array( 'en' => 'All', 'pt' => 'Todas' ),
		'news_min'         => array( 'en' => 'min', 'pt' => 'min' ),
		'news_empty'       => array( 'en' => 'No news in this category yet.', 'pt' => 'Ainda não há notícias nesta categoria.' ),
		'sub_glossary'     => array( 'en' => 'The essential terms, explained without jargon.', 'pt' => 'Os termos essenciais, explicados sem jargão.' ),
		'sub_news'         => array( 'en' => "Calm reads on what's happening in the markets — and what it means for you.", 'pt' => 'Leituras calmas do que acontece nos mercados — e do que isso significa para ti.' ),
		'back_learn'       => array( 'en' => '← Learn', 'pt' => '← Aprender' ),
		'back_news'        => array( 'en' => '← News', 'pt' => '← Notícias' ),
		'back_glossary'    => array( 'en' => '← Glossary', 'pt' => '← Glossário' ),
		'news_cta_q'       => array( 'en' => 'Where do you fit in all this?', 'pt' => 'Onde te encaixas nisto tudo?' ),
		'news_cta_btn'     => array( 'en' => 'Discover my profile →', 'pt' => 'Descobrir o meu perfil →' ),
		'related_read'     => array( 'en' => 'Keep reading', 'pt' => 'Continua a ler' ),
		'related_terms'    => array( 'en' => 'Related terms', 'pt' => 'Termos relacionados' ),
		// Search.
		'search_label'         => array( 'en' => 'Search', 'pt' => 'Pesquisar' ),
		'search_title'         => array( 'en' => 'Search', 'pt' => 'Pesquisar' ),
		'search_placeholder'   => array( 'en' => 'Try “diversification”, “emergency fund”…', 'pt' => 'Procura por «diversificação», «fundo de emergência»…' ),
		'search_popular'       => array( 'en' => 'Popular searches', 'pt' => 'Pesquisas populares' ),
		'search_count'         => array( 'en' => '%1$s results for “%2$s”', 'pt' => '%1$s resultados para «%2$s»' ),
		'search_none'          => array( 'en' => 'No results for “%s”', 'pt' => 'Sem resultados para «%s»' ),
		'search_try'           => array( 'en' => 'Try other words, or start here:', 'pt' => 'Experimenta outras palavras, ou começa por aqui:' ),
		'search_all_articles'  => array( 'en' => 'See all articles', 'pt' => 'Ver todos os artigos' ),
		'search_open_glossary' => array( 'en' => 'Open the glossary', 'pt' => 'Abrir o glossário' ),
		// 404.
		'nf_title'             => array( 'en' => 'This page doesn’t exist.', 'pt' => 'Esta página não existe.' ),
		'nf_body'              => array( 'en' => 'The link may be wrong or the page was moved. No worries — there’s always somewhere to keep going.', 'pt' => 'O link pode estar errado ou a página foi movida. Mas não há crise — há sempre por onde continuar.' ),
		'nf_home'              => array( 'en' => 'Back to home', 'pt' => 'Voltar ao início' ),
		'nf_search'            => array( 'en' => 'Search', 'pt' => 'Pesquisar' ),
		// Mobile app-shell.
		'tab_home'             => array( 'en' => 'Home', 'pt' => 'Início' ),
		'tab_nav'              => array( 'en' => 'Sections', 'pt' => 'Secções' ),
		'nav_account'          => array( 'en' => 'Account', 'pt' => 'Conta' ),
		'menu_explore'         => array( 'en' => 'Explore', 'pt' => 'Explorar' ),
		'menu_more'            => array( 'en' => 'More', 'pt' => 'Mais' ),
		'menu_privacy_terms'   => array( 'en' => 'Privacy & terms', 'pt' => 'Privacidade e termos' ),
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
		'howtoinvest/header-search',
		array(
			'api_version'     => 3,
			'title'           => __( 'Header search icon', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_header_search',
		)
	);
	register_block_type(
		'howtoinvest/mobile-bar',
		array(
			'api_version'     => 3,
			'title'           => __( 'Mobile top-bar actions', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_mobile_bar',
		)
	);
	register_block_type(
		'howtoinvest/search',
		array(
			'api_version'     => 3,
			'title'           => __( 'Search results', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_search',
		)
	);
	register_block_type(
		'howtoinvest/notfound',
		array(
			'api_version'     => 3,
			'title'           => __( 'Not found (404)', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_notfound',
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
	register_block_type(
		'howtoinvest/related',
		array(
			'api_version'     => 3,
			'title'           => __( 'Related content', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_related',
		)
	);
	register_block_type(
		'howtoinvest/drawer',
		array(
			'api_version'     => 3,
			'title'           => __( 'Mobile drawer menu', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_drawer',
		)
	);
	register_block_type(
		'howtoinvest/news-hub',
		array(
			'api_version'     => 3,
			'title'           => __( 'News hub', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_news_hub',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_dynamic_blocks' );

/**
 * The rich mobile hamburger drawer (matches the Claude Design handoff): a
 * left slide-in panel with an "Explore" section of icon-tile links, a "More"
 * section and a questionnaire CTA. Rendered inside the header as a sibling of
 * the #hti-nav-check checkbox, so the CSS-only open/close still works without
 * JS. Hidden on desktop (the inline nav rail handles that breakpoint).
 *
 * @return string Safe HTML.
 */
function render_drawer(): string {
	$pt   = 'pt' === current_lang();
	$home = ( $pt && function_exists( 'pll_home_url' ) ) ? pll_home_url( 'pt' ) : home_url( '/' );

	// Icon paths (stroke). Keyed for reuse.
	$ic = array(
		'home'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
		'learn'   => '<path d="M4 4h9a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H4z"/><path d="M20 4h-4a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H20z"/>',
		'gloss'   => '<path d="M4 6h16M4 12h16M4 18h10"/>',
		'news'    => '<path d="M4 5h13v14H5a1 1 0 0 1-1-1z"/><path d="M17 8h3v9a2 2 0 0 1-2 2"/><path d="M7 9h7M7 13h7M7 17h4"/>',
		'compare' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
		'about'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
		'account' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/>',
		'privacy' => '<path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/>',
	);

	// Primary "Explore" tiles: [url, label, icon key, tile-tint class].
	$explore = array(
		array( $home, t( 'tab_home' ), 'home', 'coral' ),
		array( archive_url( 'learn', 'learn' ), t( 'nav_learn' ), 'learn', 'purple' ),
		array( archive_url( 'glossary', 'investing-glossary' ), t( 'nav_glossary' ), 'gloss', 'amber' ),
		array( archive_url( 'news', 'financial-news' ), t( 'nav_news' ), 'news', 'green' ),
	);
	$cmp = deposits_comparator_url();
	if ( '' !== $cmp ) {
		$explore[] = array( $cmp, t( 'nav_deposits' ), 'compare', 'coral' );
	}

	// Secondary "More" links: [url, label, icon key].
	$more = array(
		array( search_url(), t( 'search_label' ), 'search' ),
		array( page_url( 'about' ), t( 'foot_about' ), 'about' ),
		array( account_url(), t( 'nav_account' ), 'account' ),
		array( page_url( 'privacy-policy' ), t( 'menu_privacy_terms' ), 'privacy' ),
	);

	$svg = static function ( string $path ): string {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	};

	$out  = '<div class="hti-drawer hti-noprint">';
	$out .= '<nav class="hti-drawer__panel" aria-label="' . esc_attr( t( 'tab_nav' ) ) . '">';

	// Header: logo + close.
	$out .= '<div class="hti-drawer__head">';
	$out .= '<span class="hti-drawer__brand"><span class="hti-drawer__mark"><svg viewBox="0 0 64 64" width="28" height="28" fill="none" aria-hidden="true"><circle cx="32" cy="32" r="32" fill="#1E2147"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/><g fill="#7C5CFC"><rect x="20.4" y="40" width="3.6" height="6" rx=".8"/><rect x="25.9" y="37.5" width="3.6" height="8.5" rx=".8"/><rect x="31.4" y="35" width="3.6" height="11" rx=".8"/><rect x="36.9" y="32.5" width="3.6" height="13.5" rx=".8"/></g></svg></span><span>HowToInvest</span></span>';
	$out .= '<label class="hti-drawer__close" for="hti-nav-check" aria-label="' . esc_attr( $pt ? 'Fechar' : 'Close' ) . '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></label>';
	$out .= '</div>';

	// Body.
	$out .= '<div class="hti-drawer__body">';
	$out .= '<span class="hti-drawer__eyebrow">' . esc_html( t( 'menu_explore' ) ) . '</span>';
	$out .= '<div class="hti-drawer__group">';
	foreach ( $explore as $it ) {
		$out .= '<a class="hti-drawer__item" href="' . esc_url( (string) $it[0] ) . '">'
			. '<span class="hti-drawer__tile hti-drawer__tile--' . esc_attr( $it[3] ) . '">' . $svg( $ic[ $it[2] ] ) . '</span>'
			. '<span class="hti-drawer__label">' . esc_html( (string) $it[1] ) . '</span></a>';
	}
	$out .= '</div>';
	$out .= '<div class="hti-drawer__divider"></div>';
	$out .= '<span class="hti-drawer__eyebrow">' . esc_html( t( 'menu_more' ) ) . '</span>';
	$out .= '<div class="hti-drawer__group">';
	foreach ( $more as $it ) {
		$out .= '<a class="hti-drawer__item hti-drawer__item--plain" href="' . esc_url( (string) $it[0] ) . '">'
			. '<span class="hti-drawer__plainicon">' . $svg( $ic[ $it[2] ] ) . '</span>'
			. '<span class="hti-drawer__label hti-drawer__label--muted">' . esc_html( (string) $it[1] ) . '</span></a>';
	}
	$out .= '</div></div>';

	// Footer CTA.
	$out .= '<div class="hti-drawer__foot">';
	$out .= '<a class="hti-drawer__cta" href="' . esc_url( page_url( 'investor-profile-quiz' ) ) . '">' . esc_html( t( 'cta_start_quiz' ) ) . '</a>';
	$out .= '</div>';

	$out .= '</nav></div>';
	return $out;
}

/**
 * Related posts sharing a taxonomy term with the given post, in the current
 * language (Polylang filters the query by language when active).
 *
 * @param \WP_Post $post     The singular post.
 * @param string   $taxonomy Taxonomy to match on.
 * @param int      $limit    Max related posts.
 * @return array<int,\WP_Post>
 */
function related_posts( \WP_Post $post, string $taxonomy, int $limit ): array {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}
	$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}
	$query = new \WP_Query(
		array(
			'post_type'           => $post->post_type,
			'post__not_in'        => array( $post->ID ),
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'post_status'         => 'publish',
			'orderby'             => 'date',
			'order'               => 'DESC',
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
				),
			),
		)
	);
	return $query->posts;
}

/**
 * Render the "related content" block for the current singular view:
 * - learn / news → "Keep reading" cards (shared topic/category)
 * - glossary     → "Related terms" pills (shared glossary topic)
 *
 * Internal-linking SEO + the design's related blocks. Empty when off a
 * single or when there are no siblings.
 */
function render_related(): string {
	if ( ! is_singular( array( 'learn', 'news', 'glossary' ) ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	$pt = 'pt' === current_lang();

	// Glossary → related terms as pills.
	if ( 'glossary' === $post->post_type ) {
		$terms = related_posts( $post, 'glossary_topic', 6 );
		if ( empty( $terms ) ) {
			return '';
		}
		$pills = '';
		foreach ( $terms as $t ) {
			$pills .= '<a class="hti-related__pill" href="' . esc_url( (string) get_permalink( $t ) ) . '">'
				. esc_html( (string) get_the_title( $t ) ) . '</a>';
		}
		return '<aside class="hti-related hti-related--pills"><h2 class="hti-related__title">'
			. esc_html( t( 'related_terms' ) ) . '</h2><div class="hti-related__pills">' . $pills . '</div></aside>';
	}

	// learn / news → cards.
	$taxonomy = ( 'news' === $post->post_type ) ? 'news_category' : 'learn_topic';
	$posts    = related_posts( $post, $taxonomy, 3 );
	if ( empty( $posts ) ) {
		return '';
	}

	$cards = '';
	foreach ( $posts as $p ) {
		$permalink = (string) get_permalink( $p );
		$eyebrow   = '';
		$pterms    = get_the_terms( $p, $taxonomy );
		if ( is_array( $pterms ) && ! empty( $pterms ) ) {
			$name = $pterms[0]->name;
			if ( $pt ) {
				$meta = get_term_meta( $pterms[0]->term_id, 'hti_name_pt', true );
				if ( is_string( $meta ) && '' !== $meta ) {
					$name = $meta;
				}
			}
			$eyebrow = '<div class="hti-article-card__eyebrow">' . esc_html( $name ) . '</div>';
		}
		$media = '';
		if ( has_post_thumbnail( $p ) ) {
			$media = '<a href="' . esc_url( $permalink ) . '" tabindex="-1" aria-hidden="true">'
				. get_the_post_thumbnail( $p, 'medium', array( 'style' => 'width:100%;height:120px;object-fit:cover;display:block', 'loading' => 'lazy' ) )
				. '</a>';
		}
		$cards .= '<div class="hti-article-card">'
			. '<div class="hti-article-card__media">' . $media . '</div>'
			. '<div class="hti-article-card__body">' . $eyebrow
			. '<h3 class="wp-block-heading hti-article-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( (string) get_the_title( $p ) ) . '</a></h3>'
			. '</div></div>';
	}

	return '<aside class="hti-related"><h2 class="hti-related__title">'
		. esc_html( t( 'related_read' ) ) . '</h2>'
		. '<div class="hti-card-grid__inner" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px">'
		. $cards . '</div></aside>';
}

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

/* ============================ News hub ============================ */

/**
 * Stable accent colour + gradient for a news category, so each tag and
 * thumbnail placeholder has a consistent look without per-term configuration.
 *
 * @param string $key Category slug (or '' for a neutral default).
 * @return array{0:string,1:string} [color, gradient]
 */
function news_palette( string $key ): array {
	$palette = array(
		array( '#7C5CFC', 'linear-gradient(135deg,#EFE9FE,#DFD4FB)' ),
		array( '#2D9C7A', 'linear-gradient(135deg,#E6F7EF,#D2E8DC)' ),
		array( '#FF6B5E', 'linear-gradient(135deg,#FFEDE9,#FFD9D2)' ),
		array( '#D69A1E', 'linear-gradient(135deg,#F6EEDD,#EFE2C5)' ),
		array( '#3A8DDE', 'linear-gradient(135deg,#E4F0FB,#D2E4F4)' ),
		array( '#A55FD0', 'linear-gradient(135deg,#F3E8FB,#E7D4F4)' ),
		array( '#E07B54', 'linear-gradient(135deg,#FBEBE2,#F4DBC9)' ),
		array( '#5A8C5A', 'linear-gradient(135deg,#EAF3E6,#D9E8D2)' ),
	);
	$i = '' === $key ? 0 : (int) ( crc32( $key ) % count( $palette ) );
	return $palette[ $i ];
}

/**
 * Localized name of a news category term (PT name from term meta when set).
 *
 * @param \WP_Term $term Term.
 * @param bool     $pt   Whether the current language is PT.
 */
function news_cat_name( \WP_Term $term, bool $pt ): string {
	if ( $pt ) {
		$meta = get_term_meta( $term->term_id, 'hti_name_pt', true );
		if ( is_string( $meta ) && '' !== $meta ) {
			return $meta;
		}
	}
	return $term->name;
}

/**
 * Rough reading time in minutes for a post body.
 *
 * @param \WP_Post $post Post.
 */
function news_read_minutes( \WP_Post $post ): int {
	$words = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	return max( 1, (int) round( $words / 200 ) );
}

/**
 * Decorate a news post for the hub cards (cat, colour, gradient, date, etc.).
 *
 * @param \WP_Post $post Post.
 * @param bool     $pt   Current language is PT.
 * @return array<string,mixed>
 */
function news_item_data( \WP_Post $post, bool $pt ): array {
	$terms    = get_the_terms( $post, 'news_category' );
	$cat_name = '';
	$cat_slug = '';
	if ( is_array( $terms ) && ! empty( $terms ) ) {
		$cat_name = news_cat_name( $terms[0], $pt );
		$cat_slug = $terms[0]->slug;
	}
	list( $color, $grad ) = news_palette( $cat_slug );

	$sum = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 26, '…' );
	$thumb = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'large' ) : '';

	return array(
		'url'   => (string) get_permalink( $post ),
		'title' => (string) get_the_title( $post ),
		'sum'   => (string) $sum,
		'cat'   => $cat_name,
		'slug'  => $cat_slug,
		'color' => $color,
		'grad'  => $grad,
		'thumb' => $thumb,
		'date'  => date_i18n( 'j M', (int) get_post_time( 'U', false, $post ) ),
		'read'  => news_read_minutes( $post ),
		'views' => (int) get_post_meta( $post->ID, 'hti_views', true ),
	);
}

/**
 * Latest published news posts (Polylang filters by current language).
 *
 * @param int $n How many.
 * @return array<int,\WP_Post>
 */
function news_hub_posts( int $n ): array {
	$q = new \WP_Query(
		array(
			'post_type'           => 'news',
			'post_status'         => 'publish',
			'posts_per_page'      => $n,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	$posts = $q->posts;
	wp_reset_postdata();
	return $posts;
}

/**
 * Most-read news (by the hti_views counter), padded with the latest posts when
 * there isn't enough view data yet so the widget is never short.
 *
 * @param int $n How many.
 * @return array<int,\WP_Post>
 */
function news_most_read( int $n ): array {
	$q = new \WP_Query(
		array(
			'post_type'           => 'news',
			'post_status'         => 'publish',
			'posts_per_page'      => $n,
			'meta_key'            => 'hti_views', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	$posts = $q->posts;
	wp_reset_postdata();

	if ( count( $posts ) < $n ) {
		$have = array();
		foreach ( $posts as $p ) {
			$have[ $p->ID ] = true;
		}
		foreach ( news_hub_posts( $n * 2 ) as $p ) {
			if ( count( $posts ) >= $n ) {
				break;
			}
			if ( empty( $have[ $p->ID ] ) ) {
				$posts[] = $p;
			}
		}
	}
	return $posts;
}

/**
 * Increment a lightweight per-post view counter on single news views. Soft
 * signal that powers the "Most read" widget; no personal data is stored.
 */
function track_news_view(): void {
	if ( is_admin() || ! is_singular( 'news' ) ) {
		return;
	}
	$id = get_queried_object_id();
	if ( $id ) {
		update_post_meta( $id, 'hti_views', (int) get_post_meta( $id, 'hti_views', true ) + 1 );
	}
}
add_action( 'template_redirect', __NAMESPACE__ . '\\track_news_view' );

/**
 * Hide the template's page title on pages that embed a full-bleed tool which
 * already renders its own H1 (e.g. the deposit comparator), so the heading
 * isn't shown twice. Scoped to those shortcodes only.
 *
 * @param string              $block_content Rendered block HTML.
 * @param array<string,mixed> $block         Parsed block.
 * @return string
 */
function hide_duplicate_page_title( string $block_content, array $block ): string {
	if ( ( $block['blockName'] ?? '' ) !== 'core/post-title' || ! is_singular() ) {
		return $block_content;
	}
	$post = get_post();
	if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'hti_depositos' ) ) {
		return '';
	}
	return $block_content;
}
add_filter( 'render_block', __NAMESPACE__ . '\\hide_duplicate_page_title', 10, 2 );

/**
 * Supply a meta description for our dynamic/shortcode pages when the SEO plugin
 * can't derive one (their content is rendered server-side, so the auto-excerpt
 * is empty — Lighthouse then flags "no meta description"). Only fills when the
 * incoming value is blank, so any description set manually in the SEO plugin
 * always wins. Bilingual. No-op when no SEO plugin is active (filters never fire).
 *
 * @param string $desc Description provided by the SEO plugin (may be empty).
 * @return string
 */
function dynamic_meta_description( $desc = '' ): string {
	$desc = is_string( $desc ) ? $desc : '';
	if ( '' !== trim( $desc ) || is_admin() || ! is_singular() ) {
		return $desc;
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return $desc;
	}
	$pt = 'pt' === current_lang();
	if ( has_shortcode( (string) $post->post_content, 'hti_depositos' ) ) {
		return $pt
			? 'Compara a TANB, prazos e condições dos depósitos a prazo em Portugal. Define o teu montante e vê o juro líquido estimado, lado a lado. Ferramenta educativa — não é aconselhamento.'
			: 'Compare term-deposit rates (TANB), terms and conditions in Portugal. Set your amount and see the estimated net interest side by side. An educational tool — not advice.';
	}
	return $desc;
}
add_filter( 'rank_math/frontend/description', __NAMESPACE__ . '\\dynamic_meta_description' );
add_filter( 'wpseo_metadesc', __NAMESPACE__ . '\\dynamic_meta_description' );

/**
 * The glossary "term of the day": a stable daily pick from the glossary,
 * localized by Polylang. Null when no glossary exists.
 *
 * @return array{title:string,short:string,url:string}|null
 */
function news_term_of_day(): ?array {
	if ( ! post_type_exists( 'glossary' ) ) {
		return null;
	}
	$q = new \WP_Query(
		array(
			'post_type'           => 'glossary',
			'post_status'         => 'publish',
			'posts_per_page'      => 60,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	$ids = $q->posts;
	wp_reset_postdata();
	if ( empty( $ids ) ) {
		return null;
	}
	$id    = (int) $ids[ (int) gmdate( 'z' ) % count( $ids ) ];
	$short = get_the_excerpt( $id );
	if ( '' === $short ) {
		$short = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id ) ) ), 18, '…' );
	}
	return array(
		'title' => (string) get_the_title( $id ),
		'short' => (string) $short,
		'url'   => (string) get_permalink( $id ),
	);
}

/**
 * Parse the editor-managed weekly agenda (one event per line:
 * "Day | Date | Label | Tag"). Empty/short lines are skipped.
 *
 * @return array<int,array{day:string,date:string,label:string,tag:string,color:string}>
 */
function news_events(): array {
	$raw = (string) get_option( 'hti_news_events', '' );
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) < 3 ) {
			continue;
		}
		$tag = $parts[3] ?? '';
		list( $color ) = news_palette( strtolower( $tag ) );
		$out[] = array(
			'day'   => $parts[0],
			'date'  => $parts[1],
			'label' => $parts[2],
			'tag'   => $tag,
			'color' => $color,
		);
	}
	return $out;
}

/**
 * Register the weekly-agenda option and its editor screen (Settings → News
 * agenda), so the "This week" sidebar can be maintained without code.
 */
function news_events_settings(): void {
	register_setting(
		'hti_news_group',
		'hti_news_events',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		)
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\\news_events_settings' );

/**
 * Add the News agenda editor under Settings.
 */
function news_events_menu(): void {
	add_options_page(
		__( 'Agenda de notícias', 'howtoinvest' ),
		__( 'Notícias (agenda)', 'howtoinvest' ),
		'manage_options',
		'hti-news-events',
		__NAMESPACE__ . '\\render_news_events_admin'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\news_events_menu' );

/**
 * Render the News agenda editor page.
 */
function render_news_events_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Agenda “Esta semana” (Hub de Notícias)', 'howtoinvest' ); ?></h1>
		<p><?php esc_html_e( 'Um evento por linha, campos separados por “|”:', 'howtoinvest' ); ?>
			<code><?php esc_html_e( 'Dia | Data | Evento | Categoria', 'howtoinvest' ); ?></code>.
			<?php esc_html_e( 'Exemplo:', 'howtoinvest' ); ?> <code>Ter | 15 Jul | Decisão de taxas do BCE | Mercados</code>.
			<?php esc_html_e( 'Deixa vazio para esconder o widget.', 'howtoinvest' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'hti_news_group' ); ?>
			<textarea name="hti_news_events" rows="10" class="large-text code" placeholder="Ter | 15 Jul | Decisão de taxas do BCE | Mercados"><?php echo esc_textarea( (string) get_option( 'hti_news_events', '' ) ); ?></textarea>
			<?php submit_button( __( 'Guardar agenda', 'howtoinvest' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * News hub: a featured story + secondary picks, category tabs, a latest-news
 * list and a sidebar (most read, term of the day, this week's agenda,
 * newsletter). Replaces the plain Query Loop on the news archive.
 *
 * @return string Safe HTML.
 */
function render_news_hub(): string {
	if ( ! post_type_exists( 'news' ) ) {
		return '';
	}
	$pt    = 'pt' === current_lang();
	$posts = news_hub_posts( 30 );
	if ( empty( $posts ) ) {
		return '';
	}
	wp_enqueue_script( 'howtoinvest-news-hub' );

	$items = array();
	foreach ( $posts as $p ) {
		$items[] = news_item_data( $p, $pt );
	}

	// Category tabs from the news_category taxonomy (PT names when set).
	$tabs  = array( array( '', t( 'news_all' ) ) );
	$terms = get_terms( array( 'taxonomy' => 'news_category', 'hide_empty' => true ) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$tabs[] = array( $term->slug, news_cat_name( $term, $pt ) );
		}
	}

	$out  = '<section class="hti-newshub" data-cat="">';

	// Header.
	$out .= '<div class="hti-newshub__head">';
	$out .= '<span class="hti-newshub__eyebrow"><span class="hti-newshub__eyebrow-dot"></span>' . esc_html( t( 'news_updated' ) ) . '</span>';
	$out .= '<h1 class="hti-newshub__title">' . esc_html( t( 'news_hub_title' ) ) . '</h1>';
	$out .= '<p class="hti-newshub__sub">' . esc_html( t( 'news_hub_sub' ) ) . '</p>';
	$out .= '</div>';

	// Category tabs.
	$out .= '<div class="hti-newshub__tabs" role="tablist">';
	foreach ( $tabs as $i => $tab ) {
		$out .= '<button type="button" class="hti-newshub__tab' . ( 0 === $i ? ' is-active' : '' ) . '" data-cat="' . esc_attr( (string) $tab[0] ) . '">' . esc_html( (string) $tab[1] ) . '</button>';
	}
	$out .= '</div>';

	$out .= '<div class="hti-newshub__layout">';
	$out .= '<div class="hti-newshub__main">';

	// Featured block (latest + two secondary). Hidden by JS when a category tab
	// other than "All" is active.
	$hero = $items[0];
	$out .= '<div class="hti-newshub__feature">';
	$out .= news_hub_hero_html( $hero, t( 'news_featured' ), t( 'news_min' ) );
	if ( isset( $items[1] ) || isset( $items[2] ) ) {
		$out .= '<div class="hti-newshub__feature-side">';
		foreach ( array_slice( $items, 1, 2 ) as $it ) {
			$out .= news_hub_side_html( $it );
		}
		$out .= '</div>';
	}
	$out .= '</div>';

	// Latest list. Items 0..2 are duplicated here (hidden until a category
	// filter reveals them) so filtering never loses the featured stories.
	$out .= '<h2 class="hti-newshub__listhead"><span class="hti-newshub__bar"></span>' . esc_html( t( 'news_latest' ) ) . '</h2>';
	$out .= '<div class="hti-newshub__list">';
	foreach ( $items as $i => $it ) {
		$out .= news_hub_list_html( $it, $i < 3, t( 'news_min' ) );
	}
	$out .= '</div>';
	$out .= '<p class="hti-newshub__empty" hidden>' . esc_html( t( 'news_empty' ) ) . '</p>';

	$out .= '</div>'; // main

	// Sidebar.
	$out .= '<aside class="hti-newshub__aside">';
	$out .= news_hub_mostread_html( $pt );
	$out .= news_hub_termday_html();
	$out .= news_hub_week_html();
	$out .= '<div class="hti-newshub__nl">' . do_shortcode( '[hti_subscribe variant="digest"]' ) . '</div>';
	$out .= '</aside>';

	$out .= '</div></section>';
	return $out;
}

/**
 * Featured (hero) card markup.
 *
 * @param array<string,mixed> $it      Item data.
 * @param string              $badge   "Featured" label.
 * @param string              $min     "min" label.
 */
function news_hub_hero_html( array $it, string $badge, string $min ): string {
	$bg = '' !== $it['thumb']
		? 'background-image:url(' . esc_url( (string) $it['thumb'] ) . ');background-size:cover;background-position:center;'
		: 'background:' . esc_attr( (string) $it['grad'] ) . ';';
	$out  = '<a class="hti-newshub__hero" href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= '<span class="hti-newshub__hero-media" style="' . $bg . '"><span class="hti-newshub__hero-badge">' . esc_html( $badge ) . '</span></span>';
	$out .= '<span class="hti-newshub__hero-body">';
	$out .= '<span class="hti-newshub__meta"><span class="hti-newshub__cat" style="color:' . esc_attr( (string) $it['color'] ) . '">' . esc_html( (string) $it['cat'] ) . '</span><span class="hti-newshub__dot">' . esc_html( (string) $it['date'] ) . ' · ' . esc_html( (string) $it['read'] ) . ' ' . esc_html( $min ) . '</span></span>';
	$out .= '<span class="hti-newshub__hero-title">' . esc_html( (string) $it['title'] ) . '</span>';
	$out .= '<span class="hti-newshub__hero-sum">' . esc_html( (string) $it['sum'] ) . '</span>';
	$out .= '</span></a>';
	return $out;
}

/**
 * Small secondary featured card markup.
 *
 * @param array<string,mixed> $it Item data.
 */
function news_hub_side_html( array $it ): string {
	$bg = '' !== $it['thumb']
		? 'background-image:url(' . esc_url( (string) $it['thumb'] ) . ');background-size:cover;background-position:center;'
		: 'background:' . esc_attr( (string) $it['grad'] ) . ';';
	$out  = '<a class="hti-newshub__side" href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= '<span class="hti-newshub__side-media" style="' . $bg . '"></span>';
	$out .= '<span class="hti-newshub__side-body"><span class="hti-newshub__cat" style="color:' . esc_attr( (string) $it['color'] ) . '">' . esc_html( (string) $it['cat'] ) . '</span><span class="hti-newshub__side-title">' . esc_html( (string) $it['title'] ) . '</span></span>';
	$out .= '</a>';
	return $out;
}

/**
 * Latest-list row markup.
 *
 * @param array<string,mixed> $it  Item data.
 * @param bool                $dup Whether this row duplicates a featured story.
 * @param string              $min "min" label.
 */
function news_hub_list_html( array $it, bool $dup, string $min ): string {
	$bg = '' !== $it['thumb']
		? 'background-image:url(' . esc_url( (string) $it['thumb'] ) . ');background-size:cover;background-position:center;'
		: 'background:' . esc_attr( (string) $it['grad'] ) . ';';
	$cls = 'hti-newshub__row' . ( $dup ? ' is-dup' : '' );
	$out  = '<a class="' . $cls . '"' . ( $dup ? ' hidden' : '' ) . ' href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= '<span class="hti-newshub__row-media" style="' . $bg . '"></span>';
	$out .= '<span class="hti-newshub__row-body">';
	$out .= '<span class="hti-newshub__meta"><span class="hti-newshub__cat" style="color:' . esc_attr( (string) $it['color'] ) . '">' . esc_html( (string) $it['cat'] ) . '</span><span class="hti-newshub__dot">' . esc_html( (string) $it['date'] ) . ' · ' . esc_html( (string) $it['read'] ) . ' ' . esc_html( $min ) . '</span></span>';
	$out .= '<span class="hti-newshub__row-title">' . esc_html( (string) $it['title'] ) . '</span>';
	$out .= '<span class="hti-newshub__row-sum">' . esc_html( (string) $it['sum'] ) . '</span>';
	$out .= '</span></a>';
	return $out;
}

/**
 * "Most read" sidebar widget.
 *
 * @param bool $pt Current language is PT.
 */
function news_hub_mostread_html( bool $pt ): string {
	$posts = news_most_read( 5 );
	if ( empty( $posts ) ) {
		return '';
	}
	$out  = '<div class="hti-newshub__card"><h3 class="hti-newshub__card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M21 7v5h-5"/></svg>' . esc_html( t( 'news_mostread' ) ) . '</h3>';
	$rank = 0;
	foreach ( $posts as $p ) {
		++$rank;
		$it    = news_item_data( $p, $pt );
		$views = (int) $it['views'];
		$vf    = $views >= 1000 ? number_format_i18n( $views / 1000, 1 ) . 'k' : (string) $views;
		$meta  = $views > 0 ? $vf . ' ' . t( 'news_reads' ) . ' · ' . $it['cat'] : (string) $it['cat'];
		$out  .= '<a class="hti-newshub__rank" href="' . esc_url( (string) $it['url'] ) . '"><span class="hti-newshub__rank-n">' . esc_html( (string) $rank ) . '</span><span class="hti-newshub__rank-body"><span class="hti-newshub__rank-t">' . esc_html( (string) $it['title'] ) . '</span><span class="hti-newshub__rank-m">' . esc_html( $meta ) . '</span></span></a>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * "Term of the day" sidebar widget.
 */
function news_hub_termday_html(): string {
	$term = news_term_of_day();
	if ( null === $term ) {
		return '';
	}
	$out  = '<div class="hti-newshub__termday">';
	$out .= '<span class="hti-newshub__termday-eyebrow">' . esc_html( t( 'news_termday' ) ) . '</span>';
	$out .= '<h3 class="hti-newshub__termday-t">' . esc_html( $term['title'] ) . '</h3>';
	$out .= '<p class="hti-newshub__termday-d">' . esc_html( $term['short'] ) . '</p>';
	$out .= '<a class="hti-newshub__termday-cta" href="' . esc_url( $term['url'] ) . '">' . esc_html( t( 'news_termday_cta' ) ) . '</a>';
	$out .= '</div>';
	return $out;
}

/**
 * "This week" agenda sidebar widget (editor-managed). Empty string if no events.
 */
function news_hub_week_html(): string {
	$events = news_events();
	if ( empty( $events ) ) {
		return '';
	}
	$out  = '<div class="hti-newshub__card"><h3 class="hti-newshub__card-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7C5CFC" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' . esc_html( t( 'news_week' ) ) . '</h3>';
	foreach ( $events as $e ) {
		$out .= '<div class="hti-newshub__event"><div class="hti-newshub__event-when"><div class="hti-newshub__event-day">' . esc_html( $e['day'] ) . '</div><div class="hti-newshub__event-date">' . esc_html( $e['date'] ) . '</div></div><div class="hti-newshub__event-body"><div class="hti-newshub__event-label">' . esc_html( $e['label'] ) . '</div>' . ( '' !== $e['tag'] ? '<span class="hti-newshub__event-tag" style="color:' . esc_attr( $e['color'] ) . '">' . esc_html( $e['tag'] ) . '</span>' : '' ) . '</div></div>';
	}
	$out .= '</div>';
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
		. '<a class="wp-block-button__link wp-element-button" href="' . esc_url( page_url( 'investor-profile-quiz' ) ) . '" data-hti-track="cta_click" data-htip-location="header">'
		. esc_html( t( 'cta_get_started' ) ) . '</a></div></div>';
}

/**
 * Account page URL for the current language.
 */
function account_url(): string {
	return page_url( 'my-account' );
}

/**
 * Mobile top-bar actions (search + account) — shown only on phones via CSS.
 */
function render_mobile_bar(): string {
	$search = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>';
	$user   = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"></path></svg>';
	return '<div class="hti-mbar">'
		. '<a class="hti-mbar__btn" href="' . esc_url( search_url() ) . '" aria-label="' . esc_attr( t( 'search_label' ) ) . '">' . $search . '</a>'
		. '<a class="hti-mbar__btn hti-mbar__btn--account" href="' . esc_url( account_url() ) . '" aria-label="' . esc_attr( t( 'nav_account' ) ) . '">' . $user . '</a>'
		. '</div>';
}

/**
 * Bottom tab bar (mobile app-shell). Rendered in the footer; CSS shows it only
 * on phones and pads the page so content clears it. Highlights the section in
 * view.
 */
function render_tab_bar(): void {
	if ( is_admin() ) {
		return;
	}
	$pt   = 'pt' === current_lang();
	$home = ( $pt && function_exists( 'pll_home_url' ) ) ? pll_home_url( 'pt' ) : home_url( '/' );

	$icons = array(
		'home'     => '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path>',
		'learn'    => '<path d="M4 4h9a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H4z"></path><path d="M20 4h-4a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H20z"></path>',
		'glossary' => '<path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h10"></path>',
		'news'     => '<path d="M4 5h13v14H5a1 1 0 0 1-1-1z"></path><path d="M17 8h3v9a2 2 0 0 1-2 2"></path><path d="M7 9h7M7 13h7M7 17h4"></path>',
	);
	$tabs = array(
		array( $home, t( 'tab_home' ), is_front_page(), $icons['home'] ),
		array( archive_url( 'learn', 'learn' ), t( 'nav_learn' ), ( is_post_type_archive( 'learn' ) || is_singular( 'learn' ) ), $icons['learn'] ),
		array( archive_url( 'glossary', 'investing-glossary' ), t( 'nav_glossary' ), ( is_post_type_archive( 'glossary' ) || is_singular( 'glossary' ) ), $icons['glossary'] ),
		array( archive_url( 'news', 'financial-news' ), t( 'nav_news' ), ( is_post_type_archive( 'news' ) || is_singular( 'news' ) ), $icons['news'] ),
	);

	echo '<nav class="hti-tabbar hti-noprint" aria-label="' . esc_attr( t( 'tab_nav' ) ) . '">';
	foreach ( $tabs as $tb ) {
		$active = (bool) $tb[2];
		printf(
			'<a class="hti-tabbar__item%1$s"%2$s href="%3$s"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%4$s</svg><span class="hti-tabbar__label">%5$s</span></a>',
			$active ? ' is-active' : '',
			$active ? ' aria-current="page"' : '',
			esc_url( (string) $tb[0] ),
			$tb[3], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG paths.
			esc_html( (string) $tb[1] )
		);
	}
	echo '</nav>';
}
add_action( 'wp_footer', __NAMESPACE__ . '\\render_tab_bar' );

/**
 * Search URL for the current language ( /?s= or /pt/?s= ).
 */
function search_url(): string {
	$base = ( 'pt' === current_lang() && function_exists( 'pll_home_url' ) ) ? pll_home_url( 'pt' ) : home_url( '/' );
	return add_query_arg( 's', '', $base );
}

/**
 * Header search icon (links to the language-aware search page).
 */
function render_header_search(): string {
	$svg = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>';
	return '<a class="hti-header__search" href="' . esc_url( search_url() ) . '" aria-label="' . esc_attr( t( 'search_label' ) ) . '">' . $svg . '</a>';
}

/**
 * Search results page: a search box plus results across our content types
 * (learn, news, glossary, pages), language-aware. Falls back to popular
 * searches when empty and a friendly empty-state when nothing matches.
 */
function render_search(): string {
	$pt    = 'pt' === current_lang();
	$query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search.
	$query = trim( $query );

	$icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>';

	$out  = '<section class="hti-search">';
	$out .= '<h1 class="hti-search__title">' . esc_html( t( 'search_title' ) ) . '</h1>';
	$out .= '<form class="hti-search__form" role="search" method="get" action="' . esc_url( $pt && function_exists( 'pll_home_url' ) ? pll_home_url( 'pt' ) : home_url( '/' ) ) . '">';
	$out .= '<span class="hti-search__icon">' . $icon . '</span>';
	$out .= '<input type="search" name="s" class="hti-search__input" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr( t( 'search_placeholder' ) ) . '" aria-label="' . esc_attr( t( 'search_title' ) ) . '" autofocus>';
	$out .= '</form>';

	if ( '' === $query ) {
		// Empty state: popular searches.
		$popular = $pt
			? array( 'diversificação', 'fundo de emergência', 'ações', 'obrigações' )
			: array( 'diversification', 'emergency fund', 'stocks', 'bonds' );
		$out    .= '<div class="hti-search__popular"><span class="hti-search__eyebrow">' . esc_html( t( 'search_popular' ) ) . '</span><div class="hti-search__pills">';
		foreach ( $popular as $term ) {
			$out .= '<a class="hti-search__pill" href="' . esc_url( add_query_arg( 's', rawurlencode( $term ), $pt && function_exists( 'pll_home_url' ) ? pll_home_url( 'pt' ) : home_url( '/' ) ) ) . '">' . esc_html( $term ) . '</a>';
		}
		$out .= '</div></div></section>';
		return $out;
	}

	$results = new \WP_Query(
		array(
			's'                   => $query,
			'post_type'           => array( 'learn', 'news', 'glossary', 'page' ),
			'post_status'         => 'publish',
			'posts_per_page'      => 20,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);

	$kinds = array(
		'learn'    => array( 'en' => 'Article', 'pt' => 'Artigo' ),
		'news'     => array( 'en' => 'News', 'pt' => 'Notícia' ),
		'glossary' => array( 'en' => 'Term', 'pt' => 'Term' ),
		'page'     => array( 'en' => 'Page', 'pt' => 'Página' ),
	);
	if ( $pt ) {
		$kinds['glossary']['pt'] = 'Termo';
	}

	if ( ! $results->have_posts() ) {
		$out .= '<div class="hti-search__empty">';
		$out .= '<div class="hti-search__empty-icon">' . $icon . '</div>';
		$out .= '<h2 class="hti-search__empty-title">' . esc_html( sprintf( t( 'search_none' ), $query ) ) . '</h2>';
		$out .= '<p class="hti-search__empty-text">' . esc_html( t( 'search_try' ) ) . '</p>';
		$out .= '<div class="hti-search__pills hti-search__pills--center">';
		$out .= '<a class="hti-search__pill hti-search__pill--accent" href="' . esc_url( archive_url( 'learn', 'learn' ) ) . '">' . esc_html( t( 'search_all_articles' ) ) . '</a>';
		$out .= '<a class="hti-search__pill hti-search__pill--accent" href="' . esc_url( archive_url( 'glossary', 'investing-glossary' ) ) . '">' . esc_html( t( 'search_open_glossary' ) ) . '</a>';
		$out .= '</div></div></section>';
		return $out;
	}

	$count = $results->post_count;
	$out  .= '<p class="hti-search__count">' . esc_html( sprintf( t( 'search_count' ), number_format_i18n( $count ), $query ) ) . '</p>';
	$out  .= '<div class="hti-search__results">';
	foreach ( $results->posts as $p ) {
		$kind = $kinds[ $p->post_type ][ $pt ? 'pt' : 'en' ] ?? '';
		$sum  = has_excerpt( $p ) ? get_the_excerpt( $p ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 24, '…' );
		$out .= '<a class="hti-search__result" href="' . esc_url( (string) get_permalink( $p ) ) . '">';
		$out .= '<span class="hti-search__kind">' . esc_html( $kind ) . '</span>';
		$out .= '<h3 class="hti-search__result-title">' . esc_html( (string) get_the_title( $p ) ) . '</h3>';
		$out .= '<p class="hti-search__result-sum">' . esc_html( (string) $sum ) . '</p>';
		$out .= '</a>';
	}
	$out .= '</div></section>';
	wp_reset_postdata();
	return $out;
}

/**
 * 404 page content (language-aware): gradient "404", message and quick links.
 */
function render_notfound(): string {
	$home   = ( 'pt' === current_lang() && function_exists( 'pll_home_url' ) ) ? pll_home_url( 'pt' ) : home_url( '/' );
	$links  = array(
		array( t( 'nav_learn' ), archive_url( 'learn', 'learn' ) ),
		array( t( 'nav_glossary' ), archive_url( 'glossary', 'investing-glossary' ) ),
		array( t( 'nav_news' ), archive_url( 'news', 'financial-news' ) ),
	);
	$quick = '';
	foreach ( $links as $l ) {
		$quick .= '<a class="hti-404__quick" href="' . esc_url( (string) $l[1] ) . '">' . esc_html( (string) $l[0] ) . '</a>';
	}

	return '<section class="hti-404">'
		. '<div class="hti-404__num" aria-hidden="true">404</div>'
		. '<h1 class="hti-404__title">' . esc_html( t( 'nf_title' ) ) . '</h1>'
		. '<p class="hti-404__body">' . esc_html( t( 'nf_body' ) ) . '</p>'
		. '<div class="hti-404__actions">'
		. '<a class="hti-404__btn hti-404__btn--primary" href="' . esc_url( $home ) . '">' . esc_html( t( 'nf_home' ) ) . '</a>'
		. '<a class="hti-404__btn hti-404__btn--secondary" href="' . esc_url( search_url() ) . '">' . esc_html( t( 'nf_search' ) ) . '</a>'
		. '</div>'
		. '<div class="hti-404__quicklinks">' . $quick . '</div>'
		. '</section>';
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
	$html .= '<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="' . $quiz . '" data-hti-track="cta_click" data-htip-location="hero_primary">' . esc_html( t( 'cta_start_quiz' ) ) . '</a></div>';
	$html .= '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#hti-articles" data-hti-track="cta_click" data-htip-location="hero_explore">' . esc_html( t( 'hero_explore' ) ) . '</a></div>';
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
			. '<h2 class="wp-block-heading hti-step__title">' . esc_html( t( $step[1] ) ) . '</h2>'
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
				'trk'  => array( 'type' => 'string', 'default' => '' ),
				'loc'  => array( 'type' => 'string', 'default' => '' ),
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
		$href  = str_starts_with( $href, '/' ) ? localize_internal_href( $href ) : $href;
		$attr .= ' href="' . esc_url( $href ) . '"';
	}

	// Optional analytics tagging: trk = event name, loc = location param.
	$trk = isset( $a['trk'] ) ? sanitize_key( (string) $a['trk'] ) : '';
	if ( '' !== $trk ) {
		$attr .= ' data-hti-track="' . esc_attr( $trk ) . '"';
		$loc   = isset( $a['loc'] ) ? sanitize_key( (string) $a['loc'] ) : '';
		if ( '' !== $loc ) {
			$attr .= ' data-htip-location="' . esc_attr( $loc ) . '"';
		}
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
		// The term-deposit comparator is a PT-only tool: surface it in the PT nav.
		if ( '' !== deposits_comparator_url() ) {
			array_splice( $items, 4, 0, array( array( deposits_comparator_url(), t( 'nav_deposits' ) ) ) );
		}
	}

	$list = '';
	foreach ( $items as $item ) {
		$list .= '<li class="menu-item"><a href="' . esc_url( $item[0] ) . '">' . esc_html( $item[1] ) . '</a></li>';
	}

	return '<nav class="' . esc_attr( trim( 'hti-menu-nav ' . $extra ) ) . '"><ul class="hti-menu">' . $list . '</ul></nav>';
}

/**
 * URL of the term-deposit comparator, but only in Portuguese — it's a PT-only
 * tool, so it has no place in the English nav. Returns '' when not applicable.
 *
 * @return string Localized URL, or empty string.
 */
function deposits_comparator_url(): string {
	if ( 'pt' !== current_lang() ) {
		return '';
	}
	return home_url( '/pt/comparador-de-depositos/' );
}

/**
 * When a WP-managed menu is assigned to the primary location, append the
 * term-deposit comparator to it (PT only), so it shows in the desktop nav and
 * the mobile drawer even though it isn't a managed menu item. The default_menu()
 * fallback handles the case where no menu is assigned.
 *
 * @param string   $items HTML list of <li> menu items.
 * @param \stdClass $args  wp_nav_menu() arguments.
 * @return string Augmented menu HTML.
 */
function append_deposits_menu_item( string $items, $args ): string {
	$location = isset( $args->theme_location ) ? (string) $args->theme_location : '';
	if ( 'primary' !== $location ) {
		return $items;
	}
	$url = deposits_comparator_url();
	if ( '' === $url ) {
		return $items;
	}
	// Don't duplicate it if the editor already added the page to the menu.
	if ( false !== strpos( $items, 'comparador-de-depositos' ) ) {
		return $items;
	}
	return $items . '<li class="menu-item hti-menu-deposits"><a href="' . esc_url( $url ) . '">' . esc_html( t( 'nav_deposits' ) ) . '</a></li>';
}
add_filter( 'wp_nav_menu_items', __NAMESPACE__ . '\\append_deposits_menu_item', 10, 2 );

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
 * Localize an internal root-relative href ("/learn/", "/about/") to the current
 * language. CPT archives keep their (EN) base under /pt/; page paths resolve to
 * their translated permalink. Non-internal hrefs are returned unchanged.
 *
 * @param string $href Href starting with '/'.
 */
function localize_internal_href( string $href ): string {
	if ( '' === $href || ! str_starts_with( $href, '/' ) ) {
		return $href;
	}
	$path = trim( $href, '/' );

	// CPT archives: the rewrite base stays English; Polylang serves them under
	// /pt/<base>/. Use the URL-aware current_lang() (Polylang can misreport it
	// on the front page).
	if ( in_array( $path, array( 'learn', 'financial-news', 'investing-glossary' ), true ) ) {
		$prefix = 'pt' === current_lang() ? '/pt' : '';
		return home_url( $prefix . '/' . $path . '/' );
	}

	// Otherwise treat it as a page slug → its localized permalink.
	return page_url( $path );
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
