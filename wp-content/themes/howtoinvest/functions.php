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
const VERSION = '0.8.47';

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
 * Favicon / app-icon link tags. Files live at the site root
 * (apple-touch-icon.png, favicon-32x32.png, favicon-16x16.png, site.webmanifest).
 */
function favicon_tags(): void {
	$icons = array(
		'<link rel="apple-touch-icon" sizes="180x180" href="%s">' => '/apple-touch-icon.png',
		'<link rel="icon" type="image/png" sizes="32x32" href="%s">' => '/favicon-32x32.png',
		'<link rel="icon" type="image/png" sizes="16x16" href="%s">' => '/favicon-16x16.png',
		'<link rel="manifest" href="%s">' => '/site.webmanifest',
	);
	foreach ( $icons as $tag => $path ) {
		printf( $tag . "\n", esc_url( home_url( $path ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\favicon_tags', 2 );

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

	// Single-news article: reading-progress bar + copy-link.
	wp_register_style( 'howtoinvest-news-article', get_stylesheet_directory_uri() . '/assets/css/news-article.css', array(), VERSION );
	wp_register_script(
		'howtoinvest-news-article',
		get_stylesheet_directory_uri() . '/assets/js/news-article.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);

	// Learn hub: path progress (localStorage) + ebook lead-magnet. Registered
	// here; the learn-hub block enqueues it on render (HTI_LEARN localized there).
	wp_register_script(
		'howtoinvest-learn',
		get_stylesheet_directory_uri() . '/assets/js/learn.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);

	// On a single Learn guide, record the visit so the path marks it complete.
	// The path keys on the canonical (EN) slug, so resolve PT posts back to it.
	if ( is_singular( 'learn' ) ) {
		$post = get_queried_object();
		if ( $post instanceof \WP_Post ) {
			$slug = $post->post_name;
			if ( class_exists( '\\HTI\\Engine\\Content_Import' ) && function_exists( 'pll_get_post_language' ) ) {
				$L = \HTI\Engine\Content_Import::lang_slugs();
				if ( $L['pt'] === pll_get_post_language( (int) $post->ID, 'slug' ) ) {
					$en = (int) pll_get_post( (int) $post->ID, $L['default'] );
					if ( $en ) {
						$slug = get_post_field( 'post_name', $en );
					}
				}
			}
			wp_enqueue_script( 'howtoinvest-learn' );
			wp_localize_script( 'howtoinvest-learn', 'HTI_LEARN_REC', array( 'slug' => $slug ) );
			wp_localize_script( 'howtoinvest-learn', 'HTI_LEARN_CFG', learn_progress_cfg() );
		}
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );

/**
 * Strip legacy in-content blocks from a Learn chapter at render time.
 *
 * Earlier imports appended a trailing "Learn more: <glossary>" paragraph and
 * the questionnaire CTA pattern to each chapter's stored content. These broke
 * the reading flow before the quiz / course nav, so the importer no longer
 * generates them — but the blocks remain in already-published posts until a
 * re-sync. This filter removes them on output so they can never appear, even
 * without a re-sync and regardless of post status.
 *
 * Hooked before core/blocks rendering (priority 8 < the 9 of do_blocks) so it
 * operates on the raw block markup.
 *
 * @param string $content Post content (raw block markup at this priority).
 * @return string
 */
function strip_legacy_learn_blocks( string $content ): string {
	if ( ! is_singular( 'learn' ) || false === strpos( $content, '<!-- wp:' ) ) {
		return $content;
	}
	$blocks  = parse_blocks( $content );
	$changed = false;
	$kept    = array();
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? '';
		$html = (string) ( $b['innerHTML'] ?? '' );

		// The questionnaire CTA, stored as a pattern reference.
		if ( 'core/pattern' === $name && 'howtoinvest/cta-questionnaire' === ( $b['attrs']['slug'] ?? '' ) ) {
			$changed = true;
			continue;
		}
		// The questionnaire CTA, in case it was stored already expanded.
		if ( 'core/group' === $name && false !== strpos( $html, 'has-primary-soft-background-color' )
			&& ( false !== strpos( $html, '/investor-profile-quiz/' ) || false !== strpos( $html, 'cta-questionnaire' ) ) ) {
			$changed = true;
			continue;
		}
		// The trailing "Learn more: <glossary>" paragraph.
		if ( 'core/paragraph' === $name && false !== strpos( $html, 'has-small-font-size' )
			&& ( false !== strpos( $html, 'Learn more:' ) || false !== strpos( $html, 'Sabe mais:' ) ) ) {
			$changed = true;
			continue;
		}
		$kept[] = $b;
	}
	return $changed ? serialize_blocks( $kept ) : $content;
}
add_filter( 'the_content', __NAMESPACE__ . '\\strip_legacy_learn_blocks', 8 );

/**
 * Remove the inline "Related terms:" paragraph baked into glossary content by
 * the seeder. The same related terms are shown by the howtoinvest/related block
 * (pills) on the single-glossary template, so the in-content line was a
 * duplicate. The curated "Learn more:" line is kept. Runs at render so existing
 * seeded terms are cleaned without a re-seed.
 *
 * @param string $content Post content (raw block markup at priority 8).
 * @return string
 */
function strip_glossary_inline_related( string $content ): string {
	if ( ! is_singular( 'glossary' ) || false === strpos( $content, '<!-- wp:' ) ) {
		return $content;
	}
	$blocks  = parse_blocks( $content );
	$changed = false;
	$kept    = array();
	foreach ( $blocks as $b ) {
		if ( 'core/paragraph' === ( $b['blockName'] ?? '' ) ) {
			$html = (string) ( $b['innerHTML'] ?? '' );
			if ( false !== strpos( $html, 'Related terms:</strong>' ) || false !== strpos( $html, 'Termos relacionados:</strong>' ) ) {
				$changed = true;
				continue;
			}
		}
		$kept[] = $b;
	}
	return $changed ? serialize_blocks( $kept ) : $content;
}
add_filter( 'the_content', __NAMESPACE__ . '\\strip_glossary_inline_related', 8 );

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
		'nav_feedback'     => array( 'en' => 'Feedback', 'pt' => 'Feedback' ),
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
		// Single news article.
		'art_byline'       => array( 'en' => 'HowToInvest Editorial', 'pt' => 'Redação HowToInvest' ),
		'art_min_read'     => array( 'en' => 'min read', 'pt' => 'min de leitura' ),
		'art_illustration' => array( 'en' => 'Editorial illustration', 'pt' => 'Ilustração editorial' ),
		'art_video'        => array( 'en' => 'Video', 'pt' => 'Vídeo' ),
		'art_watch'        => array( 'en' => 'Watch on YouTube', 'pt' => 'Ver no YouTube' ),
		'art_video_intro'  => array( 'en' => 'Watch the video, then read the summary below.', 'pt' => 'Vê o vídeo e lê o resumo em baixo.' ),
		'art_share'        => array( 'en' => 'Share', 'pt' => 'Partilhar' ),
		'art_copy'         => array( 'en' => 'Copy link', 'pt' => 'Copiar link' ),
		'art_copied'       => array( 'en' => 'Copied!', 'pt' => 'Copiado!' ),
		'art_author_bio'   => array( 'en' => 'We write educational content about personal finance — jargon-free, with nothing to sell, reviewed by the team. Everything here is illustrative and never advice.', 'pt' => 'Escrevemos conteúdo educativo sobre finanças pessoais — sem jargão, sem vender nada e revisto pela equipa. Tudo o que lês é ilustrativo e nunca aconselhamento.' ),
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
		'nav_login'            => array( 'en' => 'Sign in', 'pt' => 'Entrar' ),
		'nav_my_account'       => array( 'en' => 'My account', 'pt' => 'A minha conta' ),
		'menu_explore'         => array( 'en' => 'Explore', 'pt' => 'Explorar' ),
		'menu_more'            => array( 'en' => 'More', 'pt' => 'Mais' ),
		'menu_privacy_terms'   => array( 'en' => 'Privacy & terms', 'pt' => 'Privacidade e termos' ),
		// Glossary index.
		'gloss_all'        => array( 'en' => 'All', 'pt' => 'Todos' ),
		'gloss_filter'     => array( 'en' => 'Filter by letter', 'pt' => 'Filtrar por letra' ),
		'gloss_filter_topic' => array( 'en' => 'Filter by topic', 'pt' => 'Filtrar por tema' ),
		'gloss_count_pill' => array( 'en' => '%s terms · each in one line', 'pt' => '%s termos · cada um numa frase' ),
		'gloss_search_label' => array( 'en' => 'Search the glossary', 'pt' => 'Pesquisar no glossário' ),
		'gloss_search_ph'  => array( 'en' => 'E.g. diversification, ETF, risk…', 'pt' => 'Ex.: diversificação, ETF, risco…' ),
		'gloss_clear'      => array( 'en' => 'Clear', 'pt' => 'Limpar' ),
		'gloss_clear_filters' => array( 'en' => 'Clear filters', 'pt' => 'Limpar filtros' ),
		'gloss_results'    => array( 'en' => '%s terms', 'pt' => '%s termos' ),
		'gloss_result_one' => array( 'en' => '1 term', 'pt' => '1 termo' ),
		'gloss_topic_lbl'  => array( 'en' => 'Topic', 'pt' => 'Tema' ),
		'gloss_letter_lbl' => array( 'en' => 'Letter', 'pt' => 'Letra' ),
		'gloss_empty_t'    => array( 'en' => 'No results for this search', 'pt' => 'Sem resultados para esta pesquisa' ),
		'gloss_empty_b'    => array( 'en' => 'Try another word, or clear the filters to see every term in the glossary.', 'pt' => 'Experimenta outra palavra, ou limpa os filtros para ver todos os termos do glossário.' ),
		'gloss_course_eyebrow' => array( 'en' => 'Guided path', 'pt' => 'Percurso guiado' ),
		'gloss_course_t'   => array( 'en' => 'Prefer to learn in order?', 'pt' => 'Preferes aprender por uma ordem?' ),
		'gloss_course_b'   => array( 'en' => 'The glossary terms come to life in our course “From zero to your first portfolio”.', 'pt' => 'Os termos do glossário ganham vida no nosso curso «Do zero à tua primeira carteira».' ),
		'gloss_course_btn' => array( 'en' => 'Start learning →', 'pt' => 'Começar a aprender →' ),
		// Glossary single (term) footer.
		'term_learn_eyebrow' => array( 'en' => 'Learn more', 'pt' => 'Aprender mais' ),
		'term_course_name' => array( 'en' => 'From zero to your first portfolio', 'pt' => 'Do zero à tua primeira carteira' ),
		'term_course_sub'  => array( 'en' => 'Continue from the terms to the full course.', 'pt' => 'Continua dos termos para o curso completo.' ),
		'term_disclaimer'  => array( 'en' => 'Educational content, not financial advice. Examples by asset class only.', 'pt' => 'Conteúdo educativo, não constitui aconselhamento financeiro. Exemplos só por classe de ativos.' ),
		'term_back'        => array( 'en' => 'Back to the glossary', 'pt' => 'Voltar ao glossário' ),
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
		'howtoinvest/learn-nav',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn course nav', 'howtoinvest' ),
			'category'        => 'theme',
			'attributes'      => array( 'pos' => array( 'type' => 'string', 'default' => 'bottom' ) ),
			'render_callback' => __NAMESPACE__ . '\\render_learn_nav',
		)
	);
	register_block_type(
		'howtoinvest/learn-quiz',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn quiz', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_learn_quiz',
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
		'howtoinvest/learn-meta',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn byline', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_learn_meta',
		)
	);
	register_block_type(
		'howtoinvest/learn-related',
		array(
			'api_version'     => 3,
			'title'           => __( 'Learn related terms', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_learn_related',
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
		'howtoinvest/term-eyebrow',
		array(
			'api_version'     => 3,
			'title'           => __( 'Glossary term topic eyebrow', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_term_eyebrow',
		)
	);
	register_block_type(
		'howtoinvest/term-footer',
		array(
			'api_version'     => 3,
			'title'           => __( 'Glossary term footer', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_term_footer',
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
	register_block_type(
		'howtoinvest/news-article',
		array(
			'api_version'     => 3,
			'title'           => __( 'News article', 'howtoinvest' ),
			'category'        => 'theme',
			'render_callback' => __NAMESPACE__ . '\\render_news_article',
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
		'feedbk'  => '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/>',
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
	);
	// Feedback survey — only when the plugin's feedback page exists.
	$feedback_url = class_exists( '\\HTI\\Engine\\Feedback' ) ? \HTI\Engine\Feedback::page_url() : '';
	if ( '' !== $feedback_url ) {
		$more[] = array( $feedback_url, t( 'nav_feedback' ), 'feedbk' );
	}
	$more[] = array( account_url(), t( 'nav_account' ), 'account' );
	$more[] = array( page_url( 'privacy-policy' ), t( 'menu_privacy_terms' ), 'privacy' );

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
 * Topic eyebrow pill for a glossary term (E3 Termo design): the term's first
 * glossary_topic, shown above the title. Empty off a glossary single or when
 * the term has no topic.
 *
 * @return string Safe HTML.
 */
function render_term_eyebrow(): string {
	if ( ! is_singular( 'glossary' ) ) {
		return '';
	}
	$terms = get_the_terms( get_queried_object_id(), 'glossary_topic' );
	if ( ! is_array( $terms ) || empty( $terms ) ) {
		return '';
	}
	return '<span class="hti-term__eyebrow">' . esc_html( $terms[0]->name ) . '</span>';
}

/**
 * Glossary term footer (E3 Termo design): a "learn more" cross-link to the
 * course, the contextual disclaimer, and a back-to-glossary link — replacing
 * the execution-style CTA. Empty off a glossary single.
 *
 * @return string Safe HTML.
 */
function render_term_footer(): string {
	if ( ! is_singular( 'glossary' ) ) {
		return '';
	}

	$learn_url = archive_url( 'learn', 'learn' );

	$card = '<a class="hti-term__chapter" href="' . esc_url( $learn_url ) . '">'
		. '<span class="hti-term__chapter-l">'
		. '<span class="hti-term__chapter-ic" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 5.5V20.5"/></svg></span>'
		. '<span class="hti-term__chapter-txt">'
		. '<span class="hti-term__chapter-eyebrow">' . esc_html( t( 'term_learn_eyebrow' ) ) . '</span>'
		. '<span class="hti-term__chapter-t">' . esc_html( t( 'term_course_name' ) ) . '</span>'
		. '<span class="hti-term__chapter-sub">' . esc_html( t( 'term_course_sub' ) ) . '</span>'
		. '</span></span>'
		. '<span class="hti-term__chapter-arrow" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6"/></svg></span>'
		. '</a>';

	$disclaimer = '<p class="hti-term__disclaimer">'
		. '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'
		. esc_html( t( 'term_disclaimer' ) ) . '</p>';

	$back = '<div class="hti-term__back-wrap">'
		. '<a class="hti-term__back" href="' . esc_url( get_post_type_archive_link( 'glossary' ) ) . '">'
		. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H6M11 18l-6-6 6-6"/></svg>'
		. esc_html( t( 'term_back' ) ) . '</a></div>';

	return $card . $disclaimer . $back;
}

/**
 * Strings for the Learn hub (EN default + PT), kept local to the renderer.
 *
 * @param bool $pt Whether Portuguese.
 * @return array<string,string>
 */
function learn_hub_strings( bool $pt ): array {
	if ( $pt ) {
		return array(
			'eyebrow'    => 'O teu caminho · começa aqui',
			'h1'         => 'Do zero à tua primeira carteira',
			'lead'       => 'Um percurso guiado em 7 módulos, uma ideia de cada vez. Sem jargão e sem pressa — saberás sempre onde estás e qual é o próximo passo.',
			'progress'   => 'O teu progresso',
			'chapters'   => 'capítulos',
			'continue'   => 'Continuar o caminho →',
			'open_gloss' => 'Abrir o glossário',
			'path_h'     => 'O percurso',
			'path_p'     => '7 módulos, do essencial ao teu plano. Avança quando quiseres — está tudo aberto.',
			'mod'        => 'Módulo',
			'done'       => 'Concluído',
			'current'    => 'A decorrer',
			'open'       => 'Por começar',
			'cont_pill'  => 'Continuar',
			'soon'       => 'Em breve',
			'featured'   => 'Guia em destaque',
			'feat_title' => 'Como montar a tua primeira carteira, passo a passo',
			'feat_desc'  => 'Um guia prático que junta tudo — sempre por classes de ativos, nunca por produtos.',
			'feat_tag'   => 'Na prática',
			'read_guide' => 'Ler o guia →',
			'quiz_h'     => 'Descobre o teu perfil de investidor',
			'quiz_p'     => '6 perguntas curtas. Ajuda-te a saber por onde começar.',
			'quiz_btn'   => 'Começar o questionário',
			'gloss_h'    => 'Glossário',
			'gloss_p'    => 'Termos essenciais, sem jargão',
			'topics_h'   => 'Explorar por tema',
			'topics_p'   => 'Preferes saltar para um assunto específico? Começa por aqui.',
			'guides'     => 'guias',
			'ebook_tag'  => 'Gratuito · PT / EN',
			'ebook_h'    => 'Recebe o ebook “Como começar a investir”',
			'ebook_p'    => 'Um PDF que reúne as bases num só sítio — pensado para quem está mesmo a começar. Sem produtos, sem promessas.',
			'ebook_ph'   => 'o-teu-email@exemplo.pt',
			'ebook_btn'  => 'Quero o ebook',
			'ebook_ok'   => 'Enviámos-te o ebook. Verifica a tua caixa de entrada.',
			'ebook_cons' => 'Aceito receber a newsletter educativa por email. Sem spam — podes cancelar quando quiseres.',
			'ebook_cov1' => 'Ebook gratuito',
			'ebook_cov2' => 'Como começar a investir',
			'ebook_cov3' => 'PT · EN · PDF',
			'disc'       => 'Conteúdo meramente educativo. Não constitui aconselhamento financeiro, fiscal ou de investimento. Todos os exemplos são ilustrativos e referem-se apenas a classes de ativos — nunca a produtos, fundos ou empresas específicas.',
		);
	}
	return array(
		'eyebrow'    => 'Your path · start here',
		'h1'         => 'From zero to your first portfolio',
		'lead'       => 'A guided path in 7 modules, one idea at a time. No jargon and no rush — you’ll always know where you are and what’s next.',
		'progress'   => 'Your progress',
		'chapters'   => 'chapters',
		'continue'   => 'Continue the path →',
		'open_gloss' => 'Open the glossary',
		'path_h'     => 'The path',
		'path_p'     => '7 modules, from the essentials to your own plan. Move whenever you like — everything is open.',
		'mod'        => 'Module',
		'done'       => 'Done',
		'current'    => 'In progress',
		'open'       => 'Not started',
		'cont_pill'  => 'Continue',
		'soon'       => 'Soon',
		'featured'   => 'Featured guide',
		'feat_title' => 'How to build your first portfolio, step by step',
		'feat_desc'  => 'A practical guide that brings it together — always by asset class, never by product.',
		'feat_tag'   => 'In practice',
		'read_guide' => 'Read the guide →',
		'quiz_h'     => 'Discover your investor profile',
		'quiz_p'     => '6 short questions. It helps you know where to begin.',
		'quiz_btn'   => 'Start the questionnaire',
		'gloss_h'    => 'Glossary',
		'gloss_p'    => 'Essential terms, no jargon',
		'topics_h'   => 'Browse by topic',
		'topics_p'   => 'Prefer to jump to a specific subject? Start here.',
		'guides'     => 'guides',
		'ebook_tag'  => 'Free · PT / EN',
		'ebook_h'    => 'Get the “How to start investing” ebook',
		'ebook_p'    => 'A PDF that gathers the basics in one place — made for people who are truly starting out. No products, no promises.',
		'ebook_ph'   => 'your-email@example.com',
		'ebook_btn'  => 'Send me the ebook',
		'ebook_ok'   => 'We’ve sent you the ebook. Please check your inbox.',
		'ebook_cons' => 'I agree to receive the educational newsletter by email. No spam — unsubscribe anytime.',
		'ebook_cov1' => 'Free ebook',
		'ebook_cov2' => 'How to start investing',
		'ebook_cov3' => 'PT · EN · PDF',
		'disc'       => 'Purely educational content. It is not financial, tax or investment advice. All examples are illustrative and refer only to asset classes — never to specific products, funds or companies.',
	);
}

/**
 * Topic cards (4 fixed categories) with live guide counts + tints.
 *
 * @param bool $pt Whether Portuguese.
 * @return string
 */
function learn_topic_cards( bool $pt ): string {
	$cats = array(
		'getting-started' => array(
			'en' => array( 'Getting started', 'The first steps, no rush.' ),
			'pt' => array( 'Começar', 'Os primeiros passos, sem pressa.' ),
			'tint' => '#FFEDE9', 'ink' => '#FF6B5E', 'count' => '#C9362C',
			'icon' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
		),
		'concepts' => array(
			'en' => array( 'Concepts', 'The key ideas, explained calmly.' ),
			'pt' => array( 'Conceitos', 'As ideias-chave, explicadas com calma.' ),
			'tint' => '#EFE9FE', 'ink' => '#7C5CFC', 'count' => '#7C5CFC',
			'icon' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
		),
		'mindset' => array(
			'en' => array( 'Behaviour & mindset', 'The right head for investing.' ),
			'pt' => array( 'Comportamento & mentalidade', 'A cabeça certa para investir.' ),
			'tint' => '#E2F7F2', 'ink' => '#14A88F', 'count' => '#14A88F',
			'icon' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
		),
		'planning' => array(
			'en' => array( 'Planning', 'From goal to plan, with clarity.' ),
			'pt' => array( 'Planeamento', 'Do objetivo ao plano, com clareza.' ),
			'tint' => '#F8EFD9', 'ink' => '#B8801A', 'count' => '#B8801A',
			'icon' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
		),
	);

	$out = '';
	foreach ( $cats as $slug => $c ) {
		$term = get_term_by( 'slug', $pt ? $slug . '-pt' : $slug, 'learn_topic' );
		$url  = $term instanceof \WP_Term ? get_term_link( $term ) : archive_url( 'learn', 'learn' );
		$n    = $term instanceof \WP_Term ? (int) $term->count : 0;
		list( $name, $desc ) = $pt ? $c['pt'] : $c['en'];
		$out .= '<a class="hti-lh-topic" href="' . esc_url( is_wp_error( $url ) ? '#' : (string) $url ) . '">'
			. '<span class="hti-lh-topic__ic" style="background:' . esc_attr( $c['tint'] ) . '"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $c['ink'] ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $c['icon'] . '</svg></span>'
			. '<span class="hti-lh-topic__h">' . esc_html( $name ) . '</span>'
			. '<span class="hti-lh-topic__p">' . esc_html( $desc ) . '</span>'
			/* translators: %d: number of guides. */
			. '<span class="hti-lh-topic__n" style="color:' . esc_attr( $c['count'] ) . '">' . esc_html( sprintf( '%d %s', $n, $pt ? 'guias' : 'guides' ) ) . '</span>'
			. '</a>';
	}
	return $out;
}

/**
 * Config for learn.js to sync course progress to a signed-in account (so the
 * browser set merges with the server set across devices). Localized wherever a
 * learn progress UI renders. Guests get isLoggedIn=false and stay localStorage.
 *
 * @return array<string,mixed>
 */
function learn_progress_cfg(): array {
	return array(
		'isLoggedIn'  => is_user_logged_in(),
		'progressUrl' => esc_url_raw( rest_url( 'htinvest/v1/learn-progress' ) ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
	);
}

/**
 * Render the Learn hub — the "From zero to your first portfolio" path, topic
 * cards and the ebook lead-magnet. Server-rendered; learn.js hydrates per-user
 * progress (anonymous localStorage, or the account when signed in). Matches the
 * Claude Design redesign.
 */
function render_learn_hub(): string {
	if ( ! post_type_exists( 'learn' ) ) {
		return '';
	}
	$pt   = 'pt' === current_lang();
	$lang = $pt ? 'pt' : 'en';
	$s    = learn_hub_strings( $pt );

	$curriculum = class_exists( '\\HTI\\Engine\\Content_Import' )
		? \HTI\Engine\Content_Import::curriculum( $lang )
		: array();

	$quiz_url  = page_url( 'investor-profile-quiz' );
	$gloss_url = archive_url( 'glossary', 'investing-glossary' );

	// Featured guide → the flagship "build a portfolio" chapter, else the quiz.
	$feat_post = get_page_by_path( 'how-a-portfolio-is-built', OBJECT, 'learn' );
	$feat_url  = ( $feat_post instanceof \WP_Post && 'publish' === $feat_post->post_status )
		? (string) get_permalink( $feat_post )
		: $quiz_url;

	// Total chapters + the first published chapter (the path entry point).
	$total = 0;
	$first_url = '';
	foreach ( $curriculum as $m ) {
		foreach ( $m['chapters'] as $c ) {
			++$total;
			if ( '' === $first_url && $c['published'] ) {
				$first_url = $c['url'];
			}
		}
	}
	if ( '' === $first_url ) {
		$first_url = $quiz_url;
	}

	// Assets.
	wp_enqueue_style( 'howtoinvest-learn', get_stylesheet_directory_uri() . '/assets/css/learn.css', array(), VERSION );
	wp_enqueue_script( 'howtoinvest-learn' );
	wp_localize_script(
		'howtoinvest-learn',
		'HTI_LEARN',
		array(
			'subscribeUrl' => esc_url_raw( rest_url( 'htinvest/v1/subscribe' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => $lang,
			'strings'      => array(
				'done'    => $s['done'],
				'current' => $s['current'],
				'open'    => $s['open'],
				'cont'    => $s['cont_pill'],
				'ok'      => $s['ebook_ok'],
				'err'     => $pt ? 'Não foi possível. Tenta novamente.' : 'Something went wrong. Please try again.',
				'consent' => $pt ? 'Aceita receber os emails para continuar.' : 'Please accept the emails to continue.',
			),
		)
	);
	wp_localize_script( 'howtoinvest-learn', 'HTI_LEARN_CFG', learn_progress_cfg() );

	ob_start();
	?>
	<div class="hti-lh">

		<!-- Hero -->
		<div class="hti-lh-hero">
			<div class="hti-lh-hero__glow" aria-hidden="true"></div>
			<div class="hti-lh-hero__in">
				<span class="hti-lh-hero__badge"><span class="hti-lh-dot"></span><?php echo esc_html( $s['eyebrow'] ); ?></span>
				<h1 class="hti-lh-hero__h1"><?php echo esc_html( $s['h1'] ); ?></h1>
				<p class="hti-lh-hero__lead"><?php echo esc_html( $s['lead'] ); ?></p>
				<div class="hti-lh-prog">
					<div class="hti-lh-prog__row">
						<span class="hti-lh-prog__lbl"><?php echo esc_html( $s['progress'] ); ?></span>
						<span class="hti-lh-prog__num"><span class="hti-lh-prog-done">0</span> / <span class="hti-lh-prog-total"><?php echo (int) $total; ?></span> <?php echo esc_html( $s['chapters'] ); ?></span>
					</div>
					<div class="hti-lh-prog__bar"><div class="hti-lh-prog__fill" style="width:0%"></div></div>
				</div>
				<div class="hti-lh-hero__cta">
					<a class="hti-lh-btn hti-lh-continue" href="<?php echo esc_url( $first_url ); ?>" data-fallback="<?php echo esc_url( $first_url ); ?>"><?php echo esc_html( $s['continue'] ); ?></a>
					<a class="hti-lh-btn hti-lh-btn--ghost" href="<?php echo esc_url( $gloss_url ); ?>"><?php echo esc_html( $s['open_gloss'] ); ?></a>
				</div>
			</div>
		</div>

		<?php
		// Achievements: a badge per module mastered, plus the full-course badge.
		// JS computes each state from the visitor's progress (quizzes passed, or
		// visits for un-quizzed chapters). Guests with progress see an account
		// nudge — only when self-registration is actually open.
		$ach = $pt
			? array(
				'title'    => 'As tuas conquistas',
				'sub'      => 'Ganha um crachá por cada módulo que dominas e, no fim, o curso completo. O teu progresso fica guardado neste dispositivo.',
				'course'   => 'Curso completo',
				'modules'  => 'módulos',
				'mod'      => 'Módulo',
				'earned'   => 'Conquistado',
				'progress' => 'Em progresso',
				'locked'   => 'Por desbloquear',
				'nudge'    => 'Cria uma conta gratuita para guardares os teus crachás em todos os dispositivos.',
				'nudge_c'  => 'Guardar o meu progresso',
				'reset'    => 'Repor o meu progresso',
				'reset_c'  => 'Isto apaga o teu progresso de aprendizagem e os crachás. Continuar?',
			)
			: array(
				'title'    => 'Your achievements',
				'sub'      => 'Earn a badge for each module you master, then the full course. Your progress is saved on this device.',
				'course'   => 'Full course',
				'modules'  => 'modules',
				'mod'      => 'Module',
				'earned'   => 'Earned',
				'progress' => 'In progress',
				'locked'   => 'Locked',
				'nudge'    => 'Create a free account to keep your badges across every device.',
				'nudge_c'  => 'Save my progress',
				'reset'    => 'Reset my progress',
				'reset_c'  => 'This will erase your learning progress and badges. Continue?',
			);
		$reg_url = ( ! is_user_logged_in() && get_option( 'users_can_register' ) ) ? wp_registration_url() : '';
		$mod_count = count( $curriculum );
		?>
		<section class="hti-lh-ach" data-total="<?php echo (int) $mod_count; ?>"
			data-l-earned="<?php echo esc_attr( $ach['earned'] ); ?>"
			data-l-progress="<?php echo esc_attr( $ach['progress'] ); ?>"
			data-l-locked="<?php echo esc_attr( $ach['locked'] ); ?>"
			aria-label="<?php echo esc_attr( $ach['title'] ); ?>">
			<div class="hti-lh-ach__head">
				<h2 class="hti-lh-ach__h"><?php echo esc_html( $ach['title'] ); ?></h2>
				<p class="hti-lh-ach__sub"><?php echo esc_html( $ach['sub'] ); ?></p>
			</div>
			<div class="hti-lh-ach__row">
				<div class="hti-lh-ach__course" data-state="locked">
					<span class="hti-lh-ach__cmedal" aria-hidden="true">
						<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0zM7 6H4v1a3 3 0 0 0 3 3M17 6h3v1a3 3 0 0 1-3 3"/></svg>
					</span>
					<span class="hti-lh-ach__ctext">
						<span class="hti-lh-ach__clabel"><?php echo esc_html( $ach['course'] ); ?></span>
						<span class="hti-lh-ach__cprog"><span class="hti-lh-ach-earned">0</span> / <?php echo (int) $mod_count; ?> <?php echo esc_html( $ach['modules'] ); ?></span>
					</span>
				</div>
				<div class="hti-lh-ach__mods">
					<?php foreach ( $curriculum as $m ) : ?>
						<div class="hti-lh-ach__mod" data-mod="<?php echo esc_attr( (string) $m['num'] ); ?>" data-state="locked" title="<?php echo esc_attr( (string) $m['title'] ); ?>">
							<span class="hti-lh-ach__medal" aria-hidden="true">
								<span class="hti-lh-ach__ribbon"><?php echo esc_html( (string) $m['num'] ); ?></span>
								<span class="hti-lh-ach__lock"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
								<span class="hti-lh-ach__check"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
							</span>
							<span class="hti-lh-ach__mlabel"><?php echo esc_html( $ach['mod'] . ' ' . $m['num'] ); ?></span>
							<span class="hti-lh-ach__mstate"></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php if ( '' !== $reg_url ) : ?>
				<div class="hti-lh-ach__nudge" hidden>
					<span class="hti-lh-ach__nudge-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
					<span class="hti-lh-ach__nudge-tx"><?php echo esc_html( $ach['nudge'] ); ?></span>
					<a class="hti-lh-ach__nudge-cta" href="<?php echo esc_url( $reg_url ); ?>"><?php echo esc_html( $ach['nudge_c'] ); ?></a>
				</div>
			<?php endif; ?>
			<button type="button" class="hti-lh-ach__reset" data-confirm="<?php echo esc_attr( $ach['reset_c'] ); ?>" hidden>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
				<?php echo esc_html( $ach['reset'] ); ?>
			</button>
		</section>

		<!-- Path (stepper) + rail -->
		<div class="hti-lh-grid">
			<div class="hti-lh-stepper">
				<h2 class="hti-lh-h2"><?php echo esc_html( $s['path_h'] ); ?></h2>
				<p class="hti-lh-sub"><?php echo esc_html( $s['path_p'] ); ?></p>
				<div class="hti-lh-track">
					<?php
					foreach ( $curriculum as $i => $m ) :
						$state = 0 === $i ? 'current' : 'open';
						?>
						<div class="hti-lh-mod" data-state="<?php echo esc_attr( $state ); ?>" data-mod="<?php echo esc_attr( (string) $m['num'] ); ?>">
							<div class="hti-lh-mod__node">
								<span class="hti-lh-node hti-lh-node--done"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
								<span class="hti-lh-node hti-lh-node--current"><?php echo esc_html( (string) $m['num'] ); ?></span>
								<span class="hti-lh-node hti-lh-node--open"><?php echo esc_html( (string) $m['num'] ); ?></span>
							</div>
							<div class="hti-lh-mod__card">
								<div class="hti-lh-mod__top">
									<div>
										<div class="hti-lh-mod__eyebrow"><?php echo esc_html( $s['mod'] . ' ' . $m['num'] ); ?></div>
										<h3 class="hti-lh-mod__title"><?php echo esc_html( (string) $m['title'] ); ?></h3>
									</div>
									<span class="hti-lh-badge hti-lh-badge--done"><?php echo esc_html( $s['done'] ); ?></span>
									<span class="hti-lh-badge hti-lh-badge--current"><?php echo esc_html( $s['current'] ); ?></span>
									<span class="hti-lh-badge hti-lh-badge--open"><?php echo esc_html( $s['open'] ); ?></span>
								</div>
								<p class="hti-lh-mod__desc"><?php echo esc_html( (string) $m['desc'] ); ?></p>
								<div class="hti-lh-chaps">
									<?php foreach ( $m['chapters'] as $c ) : ?>
										<?php $tag = $c['published'] ? 'a' : 'span'; ?>
										<<?php echo esc_html( $tag ); ?> class="hti-lh-chap" data-state="open" data-slug="<?php echo esc_attr( (string) $c['slug'] ); ?>" data-quiz="<?php echo ! empty( $c['has_quiz'] ) ? '1' : '0'; ?>" data-url="<?php echo esc_url( (string) $c['url'] ); ?>"<?php echo $c['published'] ? ' href="' . esc_url( (string) $c['url'] ) . '"' : ''; ?>>
											<span class="hti-lh-chap__dot">
												<span class="hti-lh-dot--done"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
												<span class="hti-lh-dot--current"></span>
												<span class="hti-lh-dot--open"></span>
											</span>
											<span class="hti-lh-chap__t"><?php echo esc_html( (string) $c['title'] ); ?></span>
											<span class="hti-lh-chap__cont"><?php echo esc_html( $s['cont_pill'] ); ?></span>
											<span class="hti-lh-chap__meta"><?php echo $c['published'] ? esc_html( $c['mins'] . ' min' ) : esc_html( $s['soon'] ); ?></span>
										</<?php echo esc_html( $tag ); ?>>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<aside class="hti-lh-rail">
				<div class="hti-lh-feat">
					<div class="hti-lh-feat__ribbon"><?php echo esc_html( $s['featured'] ); ?></div>
					<div class="hti-lh-feat__body">
						<h3 class="hti-lh-feat__title"><?php echo esc_html( $s['feat_title'] ); ?></h3>
						<p class="hti-lh-feat__desc"><?php echo esc_html( $s['feat_desc'] ); ?></p>
						<div class="hti-lh-feat__meta"><span class="hti-lh-pill hti-lh-pill--purple"><?php echo esc_html( $s['feat_tag'] ); ?></span></div>
						<a class="hti-lh-btn hti-lh-btn--dark" href="<?php echo esc_url( $feat_url ); ?>"><?php echo esc_html( $s['read_guide'] ); ?></a>
					</div>
				</div>

				<div class="hti-lh-quiz">
					<span class="hti-lh-quiz__ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.1 9a3 3 0 0 1 5.82 1c0 2-3 3-3 3"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg></span>
					<h3 class="hti-lh-quiz__h"><?php echo esc_html( $s['quiz_h'] ); ?></h3>
					<p class="hti-lh-quiz__p"><?php echo esc_html( $s['quiz_p'] ); ?></p>
					<a class="hti-lh-btn hti-lh-btn--purple" href="<?php echo esc_url( $quiz_url ); ?>"><?php echo esc_html( $s['quiz_btn'] ); ?></a>
				</div>

				<a class="hti-lh-glosscard" href="<?php echo esc_url( $gloss_url ); ?>">
					<span class="hti-lh-glosscard__ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
					<span><span class="hti-lh-glosscard__h"><?php echo esc_html( $s['gloss_h'] ); ?></span><span class="hti-lh-glosscard__p"><?php echo esc_html( $s['gloss_p'] ); ?></span></span>
				</a>
			</aside>
		</div>

		<!-- Browse by topic -->
		<div class="hti-lh-topics">
			<h2 class="hti-lh-h2"><?php echo esc_html( $s['topics_h'] ); ?></h2>
			<p class="hti-lh-sub"><?php echo esc_html( $s['topics_p'] ); ?></p>
			<div class="hti-lh-topics__grid"><?php echo learn_topic_cards( $pt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>

		<!-- Ebook lead-magnet -->
		<div class="hti-lh-ebook">
			<div class="hti-lh-ebook__main">
				<span class="hti-lh-ebook__tag"><?php echo esc_html( $s['ebook_tag'] ); ?></span>
				<h2 class="hti-lh-ebook__h"><?php echo esc_html( $s['ebook_h'] ); ?></h2>
				<p class="hti-lh-ebook__p"><?php echo esc_html( $s['ebook_p'] ); ?></p>
				<form class="hti-lh-ebook__form" novalidate>
					<div class="hti-lh-ebook__row">
						<input class="hti-lh-ebook__email" type="email" autocomplete="email" placeholder="<?php echo esc_attr( $s['ebook_ph'] ); ?>" aria-label="Email" required>
						<button class="hti-lh-btn" type="submit"><?php echo esc_html( $s['ebook_btn'] ); ?></button>
					</div>
					<label class="hti-lh-ebook__consent"><input type="checkbox" class="hti-lh-ebook__cons" required> <span><?php echo esc_html( $s['ebook_cons'] ); ?></span></label>
					<input type="text" class="hti-lh-ebook__hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
					<p class="hti-lh-ebook__status" role="status" aria-live="polite"></p>
				</form>
			</div>
			<div class="hti-lh-ebook__cover" aria-hidden="true">
				<div class="hti-lh-book">
					<span class="hti-lh-book__k"><?php echo esc_html( $s['ebook_cov1'] ); ?></span>
					<span class="hti-lh-book__t"><?php echo esc_html( $s['ebook_cov2'] ); ?></span>
					<span class="hti-lh-book__f"><?php echo esc_html( $s['ebook_cov3'] ); ?></span>
				</div>
			</div>
		</div>

		<p class="hti-lh-disc"><?php echo esc_html( $s['disc'] ); ?></p>
	</div>
	<?php
	return (string) ob_get_clean() . learn_course_schema( $curriculum, $lang );
}

/**
 * Course structured data (JSON-LD) for the Learn path, built from the live
 * curriculum. Anchors the learning path as a single Course entity (referenced
 * by each chapter's LearningResource isPartOf) and is eligible for Google's
 * course rich results. Provider/Organization is the sitewide node emitted by
 * HTI\Engine\SEO on the same page.
 *
 * @param array<int,array<string,mixed>> $curriculum Modules + chapters.
 * @param string                         $lang       'en'|'pt'.
 * @return string A <script type="application/ld+json"> block, or '' if empty.
 */
function learn_course_schema( array $curriculum, string $lang ): string {
	if ( empty( $curriculum ) || ! class_exists( '\\HTI\\Engine\\SEO' ) ) {
		return '';
	}
	$pt      = 'pt' === $lang;
	$hub_url = get_post_type_archive_link( 'learn' );
	if ( ! $hub_url ) {
		$hub_url = home_url( $pt ? '/pt/learn/' : '/learn/' );
	}

	$parts      = array();
	$total_mins = 0;
	foreach ( $curriculum as $m ) {
		foreach ( $m['chapters'] as $c ) {
			if ( empty( $c['published'] ) || empty( $c['url'] ) ) {
				continue;
			}
			$total_mins += (int) ( $c['mins'] ?? 0 );
			$parts[]     = array(
				'@type'                => 'LearningResource',
				'name'                 => (string) $c['title'],
				'url'                  => (string) $c['url'],
				'learningResourceType' => 'Chapter',
			);
		}
	}
	if ( empty( $parts ) ) {
		return '';
	}

	$lang_tag = $pt ? 'pt-PT' : 'en-US';
	$course   = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'Course',
		'@id'                 => \HTI\Engine\SEO::course_id(),
		'name'                => $pt ? 'Do zero à tua primeira carteira' : 'From zero to your first portfolio',
		'description'         => $pt
			? 'Um percurso gratuito e passo a passo de literacia financeira: dos conceitos básicos à construção de uma carteira ilustrativa por classes de ativos. Educativo, não é aconselhamento.'
			: 'A free, step-by-step financial-literacy path: from the basics to building an illustrative portfolio by asset class. Educational, not advice.',
		'url'                 => $hub_url,
		'inLanguage'          => $lang_tag,
		'provider'            => array( '@id' => \HTI\Engine\SEO::org_id() ),
		'isAccessibleForFree' => true,
		'educationalLevel'    => 'Beginner',
		'offers'              => array(
			'@type'         => 'Offer',
			'price'         => '0',
			'priceCurrency' => 'EUR',
			'category'      => 'Free',
		),
		'hasCourseInstance'   => array(
			'@type'          => 'CourseInstance',
			'courseMode'     => 'online',
			'courseWorkload' => 'PT' . max( 1, (int) ceil( $total_mins / 60 ) ) . 'H',
			'inLanguage'     => $lang_tag,
		),
		'hasPart'             => $parts,
	);

	return '<script type="application/ld+json">' . wp_json_encode( $course, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/**
 * Byline + dates for a Learn chapter (E-E-A-T + freshness). Shows the editorial
 * brand (linked to the About page when present), the published date, and an
 * "Updated" date when the chapter has been revised. Rendered as a block on the
 * single-learn template, below the title.
 *
 * @return string
 */
function render_learn_meta(): string {
	if ( ! is_singular( 'learn' ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	$pt      = 'pt' === current_lang();
	$by      = $pt ? 'Redação HowToInvest' : 'HowToInvest Editorial';
	$about   = page_url( 'about' );
	$pub_iso = get_the_date( DATE_W3C, $post );
	$pub_h   = get_the_date( '', $post );
	$updated = get_the_modified_date( 'Ymd', $post ) !== get_the_date( 'Ymd', $post );

	$byline = '' !== $about
		? '<a class="hti-learn-meta__by" href="' . esc_url( $about ) . '">' . esc_html( $by ) . '</a>'
		: '<span class="hti-learn-meta__by">' . esc_html( $by ) . '</span>';

	$out  = '<div class="hti-learn-meta">';
	$out .= $byline;
	$out .= '<span class="hti-learn-meta__sep" aria-hidden="true">·</span>';
	$out .= '<time datetime="' . esc_attr( $pub_iso ) . '">' . esc_html( ( $pt ? 'Publicado a ' : 'Published ' ) . $pub_h ) . '</time>';
	if ( $updated ) {
		$out .= '<span class="hti-learn-meta__sep" aria-hidden="true">·</span>';
		$out .= '<time datetime="' . esc_attr( get_the_modified_date( DATE_W3C, $post ) ) . '">' . esc_html( ( $pt ? 'Atualizado a ' : 'Updated ' ) . get_the_modified_date( '', $post ) ) . '</time>';
	}
	$out .= '</div>';

	return $out;
}

/**
 * "Related terms" rail for a Learn chapter: chapter→glossary internal links
 * built from the chapter's frontmatter glossary list (stored as post meta by
 * the importer, resolved to the localized term). Restores the topical-cluster
 * crawl path without the old flow-breaking footer. Empty when the chapter has
 * no related terms (e.g. before a content re-sync populates the meta).
 *
 * @return string
 */
function render_learn_related(): string {
	if ( ! is_singular( 'learn' ) || ! class_exists( '\\HTI\\Engine\\Content_Import' ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	$pt    = 'pt' === current_lang();
	$links = \HTI\Engine\Content_Import::related_terms( (int) $post->ID, $pt ? 'pt' : 'en' );
	if ( empty( $links ) ) {
		return '';
	}

	$pills = '';
	foreach ( $links as $l ) {
		$pills .= '<a class="hti-related__pill" href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a>';
	}

	return '<aside class="hti-related hti-related--pills hti-learn-related"><h2 class="hti-related__title">'
		. esc_html( $pt ? 'Termos relacionados' : 'Related terms' ) . '</h2>'
		. '<div class="hti-related__pills">' . $pills . '</div></aside>';
}

/**
 * Homepage Learn block: the "From zero to your first portfolio" path as a
 * compact card — eyebrow, heading, a progress bar and a horizontal 7-module
 * stepper, plus a "continue" button. Progress is hydrated client-side by
 * learn.js (anonymous, localStorage), shared with the Learn hub. Self-contained
 * (its own heading), so home.html no longer needs a separate section header.
 */
function render_learn_feed(): string {
	if ( ! post_type_exists( 'learn' ) || ! class_exists( '\\HTI\\Engine\\Content_Import' ) ) {
		return '';
	}
	$pt   = 'pt' === current_lang();
	$lang = $pt ? 'pt' : 'en';
	$mods = \HTI\Engine\Content_Import::curriculum( $lang );
	if ( empty( $mods ) ) {
		return '';
	}

	$s = $pt
		? array(
			'eyebrow'  => 'O teu percurso · 7 módulos',
			'h2'       => 'Do zero à tua primeira carteira',
			'sub'      => 'Um caminho guiado, uma ideia de cada vez. Continua de onde paraste.',
			'seeall'   => 'Ver o percurso →',
			'chapters' => 'capítulos',
			'continue' => 'Continuar o caminho →',
			'inprog'   => 'A decorrer',
		)
		: array(
			'eyebrow'  => 'Your path · 7 modules',
			'h2'       => 'From zero to your first portfolio',
			'sub'      => 'A guided path, one idea at a time. Pick up where you left off.',
			'seeall'   => 'See the path →',
			'chapters' => 'chapters',
			'continue' => 'Continue the path →',
			'inprog'   => 'In progress',
		);

	$learn_url = archive_url( 'learn', 'learn' );

	// Ordered chapters (for client-side progress) + total + first published URL.
	$ordered   = array();
	$total     = 0;
	$first_url = '';
	foreach ( $mods as $m ) {
		foreach ( $m['chapters'] as $c ) {
			++$total;
			$ordered[] = array( 's' => $c['slug'], 'u' => $c['url'], 'p' => $c['published'] ? 1 : 0, 't' => $c['title'] );
			if ( '' === $first_url && $c['published'] ) {
				$first_url = $c['url'];
			}
		}
	}
	if ( '' === $first_url ) {
		$first_url = $learn_url;
	}

	// Assets (shared with the hub).
	wp_enqueue_style( 'howtoinvest-learn', get_stylesheet_directory_uri() . '/assets/css/learn.css', array(), VERSION );
	wp_enqueue_script( 'howtoinvest-learn' );
	wp_localize_script( 'howtoinvest-learn', 'HTI_LEARN_CFG', learn_progress_cfg() );

	$steps = '';
	foreach ( $mods as $i => $m ) {
		$slugs = array();
		foreach ( $m['chapters'] as $c ) {
			$slugs[] = $c['slug'];
		}
		$state  = 0 === $i ? 'current' : 'open';
		$steps .= '<a class="hti-hp-mod" data-state="' . esc_attr( $state ) . '" data-slugs="' . esc_attr( implode( ',', $slugs ) ) . '" href="' . esc_url( $learn_url ) . '">'
			. '<span class="hti-hp-node hti-hp-node--done"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>'
			. '<span class="hti-hp-node hti-hp-node--current">' . esc_html( (string) $m['num'] ) . '</span>'
			. '<span class="hti-hp-node hti-hp-node--open">' . esc_html( (string) $m['num'] ) . '</span>'
			. '<span class="hti-hp-mod__t">' . esc_html( (string) $m['title'] ) . '</span>'
			. '</a>';
	}

	ob_start();
	?>
	<div class="wp-block-group alignwide hti-hp-path"
		data-first="<?php echo esc_url( $first_url ); ?>">
		<script type="application/json" class="hti-hp-data"><?php echo wp_json_encode( $ordered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<div class="hti-hp-path__head">
			<div>
				<span class="hti-hp-path__eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></span>
				<h2 class="hti-hp-path__h2"><?php echo esc_html( $s['h2'] ); ?></h2>
				<p class="hti-hp-path__sub"><?php echo esc_html( $s['sub'] ); ?></p>
			</div>
			<a class="hti-hp-path__seeall" href="<?php echo esc_url( $learn_url ); ?>"><?php echo esc_html( $s['seeall'] ); ?></a>
		</div>
		<div class="hti-hp-path__prog">
			<div class="hti-hp-path__bar"><div class="hti-hp-path__fill" style="width:0%"></div></div>
			<span class="hti-hp-path__count"><span class="hti-hp-prog-done">0</span> <?php echo esc_html( ( $pt ? 'de' : 'of' ) ); ?> <?php echo (int) $total; ?> <?php echo esc_html( $s['chapters'] ); ?></span>
		</div>
		<div class="hti-hp-steps"><?php echo $steps; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<div class="hti-hp-current"><span class="hti-hp-current__badge"><?php echo esc_html( $s['inprog'] ); ?></span><span class="hti-hp-current__t"></span></div>
		<a class="hti-lh-btn hti-hp-continue" href="<?php echo esc_url( $first_url ); ?>" data-fallback="<?php echo esc_url( $first_url ); ?>"><?php echo esc_html( $s['continue'] ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Course-style navigation for a single Learn guide: a context bar (module +
 * "chapter Y of Z" + reading time + module progress) when pos="top", and
 * prominent Previous / Next chapter cards + "back to the path" when pos="bottom".
 * Driven by the curriculum so the whole path chains together like an LMS.
 *
 * @param array<string,mixed> $attrs Block attributes ('pos' => top|bottom).
 * @return string
 */
function render_learn_nav( array $attrs = array() ): string {
	if ( ! is_singular( 'learn' ) || ! class_exists( '\\HTI\\Engine\\Content_Import' ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}
	$pos  = ( isset( $attrs['pos'] ) && 'top' === $attrs['pos'] ) ? 'top' : 'bottom';
	$pt   = 'pt' === current_lang();
	$lang = $pt ? 'pt' : 'en';

	// Canonical (EN) slug — the curriculum keys on it.
	$slug = $post->post_name;
	if ( class_exists( '\\HTI\\Engine\\Content_Import' ) && function_exists( 'pll_get_post_language' ) ) {
		$L = \HTI\Engine\Content_Import::lang_slugs();
		if ( $L['pt'] === pll_get_post_language( (int) $post->ID, 'slug' ) ) {
			$en = (int) pll_get_post( (int) $post->ID, $L['default'] );
			if ( $en ) {
				$slug = get_post_field( 'post_name', $en );
			}
		}
	}

	$mods = \HTI\Engine\Content_Import::curriculum( $lang );
	$flat = array();
	foreach ( $mods as $mi => $m ) {
		foreach ( $m['chapters'] as $ci => $c ) {
			$flat[] = array(
				'mod'      => $m['num'],
				'modTitle' => $m['title'],
				'modIdx'   => $mi,
				'ci'       => $ci,
				'modCount' => count( $m['chapters'] ),
				'slug'     => $c['slug'],
				'title'    => $c['title'],
				'url'      => $c['url'],
			);
		}
	}
	$idx = -1;
	foreach ( $flat as $k => $f ) {
		if ( $f['slug'] === $slug ) {
			$idx = $k;
			break;
		}
	}
	if ( $idx < 0 ) {
		return ''; // Not part of the curated path.
	}
	$cur  = $flat[ $idx ];
	$prev = $idx > 0 ? $flat[ $idx - 1 ] : null;
	$next = $idx < count( $flat ) - 1 ? $flat[ $idx + 1 ] : null;

	wp_enqueue_style( 'howtoinvest-learn', get_stylesheet_directory_uri() . '/assets/css/learn.css', array(), VERSION );

	$learn_url = archive_url( 'learn', 'learn' );
	$s = $pt
		? array(
			'learn' => 'Aprender', 'mod' => 'Módulo', 'prev' => 'Anterior', 'next' => 'Seguinte',
			'back' => '← Voltar ao percurso', 'min' => 'min de leitura',
			'finish' => 'Concluíste o percurso', 'finish_sub' => 'Descobre o teu perfil de investidor',
			'newmod' => 'Começa o Módulo',
		)
		: array(
			'learn' => 'Learn', 'mod' => 'Module', 'prev' => 'Previous', 'next' => 'Next',
			'back' => '← Back to the path', 'min' => 'min read',
			'finish' => 'You’ve finished the path', 'finish_sub' => 'Discover your investor profile',
			'newmod' => 'Starts Module',
		);

	/* ---------- TOP: context bar ---------- */
	if ( 'top' === $pos ) {
		$mins = max( 2, (int) ceil( str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) / 200 ) );
		$mod_slugs = array();
		foreach ( $mods[ $cur['modIdx'] ]['chapters'] as $c ) {
			$mod_slugs[] = $c['slug'];
		}
		/* translators: 1: chapter number, 2: chapters in module. */
		$chap_of = sprintf( $pt ? 'Capítulo %1$d de %2$d' : 'Chapter %1$d of %2$d', (int) $cur['ci'] + 1, (int) $cur['modCount'] );

		ob_start();
		?>
		<nav class="hti-ln-top" aria-label="<?php echo esc_attr( $s['learn'] ); ?>">
			<div class="hti-ln-crumb">
				<a href="<?php echo esc_url( $learn_url ); ?>"><?php echo esc_html( $s['learn'] ); ?></a>
				<span aria-hidden="true">›</span>
				<span><?php echo esc_html( $s['mod'] . ' ' . $cur['mod'] . ' · ' . $cur['modTitle'] ); ?></span>
			</div>
			<div class="hti-ln-meta">
				<span class="hti-ln-chip"><?php echo esc_html( $chap_of ); ?></span>
				<span class="hti-ln-mins"><?php echo esc_html( $mins . ' ' . $s['min'] ); ?></span>
			</div>
			<div class="hti-ln-modprog" data-slugs="<?php echo esc_attr( implode( ',', $mod_slugs ) ); ?>">
				<div class="hti-ln-modprog__bar"><div class="hti-ln-modprog__fill" style="width:0%"></div></div>
				<span class="hti-ln-modprog__txt"><span class="hti-ln-modprog-done">0</span>/<?php echo (int) $cur['modCount']; ?></span>
			</div>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- BOTTOM: prev / next cards ---------- */
	$quiz_url = page_url( 'investor-profile-quiz' );
	$card = static function ( array $c, string $dir, string $dirlabel, array $s, bool $newmod ): string {
		if ( empty( $c['url'] ) ) {
			return '';
		}
		$tag = $newmod ? '<span class="hti-ln-card__tag">' . esc_html( $s['newmod'] . ' ' . $c['mod'] ) . '</span>' : '';
		return '<a class="hti-ln-card hti-ln-card--' . esc_attr( $dir ) . '" href="' . esc_url( (string) $c['url'] ) . '">'
			. '<span class="hti-ln-card__dir">' . esc_html( $dirlabel ) . '</span>'
			. '<span class="hti-ln-card__t">' . esc_html( (string) $c['title'] ) . '</span>'
			. '<span class="hti-ln-card__mod">' . esc_html( $s['mod'] . ' ' . $c['mod'] ) . $tag . '</span>'
			. '</a>';
	};

	$prev_html = $prev ? $card( $prev, 'prev', '← ' . $s['prev'], $s, false ) : '<span class="hti-ln-card hti-ln-card--empty" aria-hidden="true"></span>';

	if ( $next ) {
		$next_html = $card( $next, 'next', $s['next'] . ' →', $s, (int) $next['mod'] !== (int) $cur['mod'] );
	} else {
		$next_html = '<a class="hti-ln-card hti-ln-card--finish" href="' . esc_url( $quiz_url ) . '">'
			. '<span class="hti-ln-card__dir">🎉 ' . esc_html( $s['finish'] ) . '</span>'
			. '<span class="hti-ln-card__t">' . esc_html( $s['finish_sub'] ) . ' →</span>'
			. '</a>';
	}

	ob_start();
	?>
	<nav class="hti-ln-bottom" aria-label="<?php echo esc_attr( $s['learn'] ); ?>">
		<a class="hti-ln-back" href="<?php echo esc_url( $learn_url ); ?>"><?php echo esc_html( $s['back'] ); ?></a>
		<div class="hti-ln-cards">
			<?php echo $prev_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $next_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</nav>
	<?php
	return (string) ob_get_clean();
}

/**
 * Canonical (English) slug for a learn post — the curriculum/progress key.
 *
 * @param \WP_Post $post Learn post.
 * @return string
 */
function learn_canonical_slug( \WP_Post $post ): string {
	$slug = $post->post_name;
	if ( class_exists( '\\HTI\\Engine\\Content_Import' ) && function_exists( 'pll_get_post_language' ) ) {
		$L = \HTI\Engine\Content_Import::lang_slugs();
		if ( $L['pt'] === pll_get_post_language( (int) $post->ID, 'slug' ) ) {
			$en = (int) pll_get_post( (int) $post->ID, $L['default'] );
			if ( $en ) {
				$slug = (string) get_post_field( 'post_name', $en );
			}
		}
	}
	return (string) $slug;
}

/**
 * End-of-chapter quiz block: renders the questions stored on the post and lets
 * the reader self-check. Passing (all correct) marks the chapter "completed"
 * (learn.js records it, locally + to the account). Nothing renders when the
 * chapter has no quiz.
 *
 * @return string
 */
function render_learn_quiz(): string {
	if ( ! is_singular( 'learn' ) || ! class_exists( '\\HTI\\Engine\\Content_Import' ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}
	$quiz = \HTI\Engine\Content_Import::get_quiz( (int) $post->ID );
	if ( empty( $quiz ) ) {
		return '';
	}

	$pt    = 'pt' === current_lang();
	$count = count( $quiz );
	$s     = $pt
		? array(
			'eyebrow' => 'Fim do capítulo',
			'badge'   => 1 === $count ? 'Quiz · 1 pergunta' : sprintf( 'Quiz · %d perguntas', $count ),
			'h' => 'Testa o que aprendeste',
			'intro' => 'Uma verificação rápida para fixar o capítulo — sem nota, só para ti.',
			'check' => 'Verificar respostas', 'retry' => 'Tentar de novo',
			'empty' => 'Escolhe uma resposta para cada pergunta.',
			/* translators: 1: correct, 2: total. */
			'partial' => '%1$d de %2$d certas — revê e tenta de novo.',
			'tag_correct' => 'Resposta certa', 'tag_your' => 'A tua resposta',
			'complete_h' => 'Capítulo concluído',
			'passed_sub' => 'Boa — fixaste este capítulo.',
			'returning_sub' => 'Já concluíste este capítulo.',
			'badge_kicker' => 'Progresso do crachá', 'badge_cue' => '+1 para o crachá do módulo',
			'review' => 'Rever respostas', 'return_review' => 'Rever o quiz',
		)
		: array(
			'eyebrow' => 'End of chapter',
			'badge'   => 1 === $count ? 'Quiz · 1 question' : sprintf( 'Quiz · %d questions', $count ),
			'h' => 'Test what you learned',
			'intro' => 'A quick check to lock in this chapter — no grades, just for you.',
			'check' => 'Check answers', 'retry' => 'Try again',
			'empty' => 'Choose an answer for each question.',
			/* translators: 1: correct, 2: total. */
			'partial' => '%1$d of %2$d correct — review and try again.',
			'tag_correct' => 'Correct answer', 'tag_your' => 'Your answer',
			'complete_h' => 'Chapter complete',
			'passed_sub' => 'Nice work — you’ve locked this chapter in.',
			'returning_sub' => 'You’ve already completed this chapter.',
			'badge_kicker' => 'Badge progress', 'badge_cue' => '+1 toward your module badge',
			'review' => 'Review answers', 'return_review' => 'Review the quiz',
		);

	// Dynamic copy the JS state machine needs.
	wp_localize_script(
		'howtoinvest-learn',
		'HTI_LEARN_QUIZ',
		array(
			'check' => $s['check'], 'retry' => $s['retry'], 'empty' => $s['empty'],
			'partial' => $s['partial'], 'tagCorrect' => $s['tag_correct'], 'tagYour' => $s['tag_your'],
			'passedSub' => $s['passed_sub'], 'returningSub' => $s['returning_sub'],
			'review' => $s['review'], 'returnReview' => $s['return_review'],
		)
	);

	$slug = learn_canonical_slug( $post );

	$mk_check = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
	$mk_cross = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

	ob_start();
	?>
	<section class="hti-quiz"
			data-slug="<?php echo esc_attr( $slug ); ?>"
			data-l-check="<?php echo esc_attr( $s['check'] ); ?>"
			data-l-retry="<?php echo esc_attr( $s['retry'] ); ?>"
			data-l-partial="<?php echo esc_attr( $s['partial'] ); ?>"
			data-l-tagcorrect="<?php echo esc_attr( $s['tag_correct'] ); ?>"
			data-l-tagyour="<?php echo esc_attr( $s['tag_your'] ); ?>"
			data-l-passedsub="<?php echo esc_attr( $s['passed_sub'] ); ?>"
			data-l-returningsub="<?php echo esc_attr( $s['returning_sub'] ); ?>"
			data-l-review="<?php echo esc_attr( $s['review'] ); ?>"
			data-l-returnreview="<?php echo esc_attr( $s['return_review'] ); ?>"
			aria-label="<?php echo esc_attr( $s['h'] ); ?>">
		<div class="hti-quiz__boundary" aria-hidden="true">
			<span class="hti-quiz__dash"></span>
			<span class="hti-quiz__seal"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4l9 2.5L5 9M5 9l11 2.5"/></svg></span>
			<span class="hti-quiz__dash"></span>
		</div>

		<div class="hti-quiz__card">
			<div class="hti-quiz__accent"></div>
			<div class="hti-quiz__pad">

				<!-- Quiz view -->
				<div class="hti-quiz__quizview">
					<div class="hti-quiz__top">
						<span class="hti-quiz__eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></span>
						<span class="hti-quiz__badge"><span class="hti-quiz__badge-ic" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2"/><path d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12l2 2 3.5-3.5"/></svg></span><?php echo esc_html( $s['badge'] ); ?></span>
					</div>
					<h2 class="hti-quiz__h"><?php echo esc_html( $s['h'] ); ?></h2>
					<p class="hti-quiz__intro"><?php echo esc_html( $s['intro'] ); ?></p>

					<div class="hti-quiz__qs">
						<?php foreach ( $quiz as $qi => $q ) : ?>
							<div class="hti-quiz__q" data-q="<?php echo (int) $qi; ?>">
								<div class="hti-quiz__qhead">
									<span class="hti-quiz__qnum"><?php echo (int) $qi + 1; ?></span>
									<span class="hti-quiz__qtext"><?php echo esc_html( (string) ( $q['q'] ?? '' ) ); ?></span>
								</div>
								<div class="hti-quiz__opts" role="radiogroup" aria-label="<?php echo esc_attr( (string) ( $q['q'] ?? '' ) ); ?>">
									<?php foreach ( (array) ( $q['options'] ?? array() ) as $oi => $o ) : ?>
										<div class="hti-quiz__opt" role="radio" aria-checked="false" tabindex="0" data-q="<?php echo (int) $qi; ?>" data-o="<?php echo (int) $oi; ?>" data-correct="<?php echo ! empty( $o['c'] ) ? '1' : '0'; ?>">
											<span class="hti-quiz__marker" aria-hidden="true">
												<span class="hti-quiz__m hti-quiz__m--empty"></span>
												<span class="hti-quiz__m hti-quiz__m--filled"><span></span></span>
												<span class="hti-quiz__m hti-quiz__m--check"><?php echo $mk_check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<span class="hti-quiz__m hti-quiz__m--cross"><?php echo $mk_cross; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
											</span>
											<span class="hti-quiz__opt-t"><?php echo esc_html( (string) ( $o['t'] ?? '' ) ); ?></span>
											<span class="hti-quiz__tag"></span>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="hti-quiz__alert" role="alert" hidden>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
						<span><?php echo esc_html( $s['empty'] ); ?></span>
					</div>

					<div class="hti-quiz__result" aria-live="polite" hidden>
						<span class="hti-quiz__result-ic" aria-hidden="true"></span>
						<span class="hti-quiz__result-txt"></span>
					</div>

					<button type="button" class="hti-quiz__primary"><?php echo esc_html( $s['check'] ); ?></button>
				</div>

				<!-- Complete view -->
				<div class="hti-quiz__complete" hidden>
					<span class="hti-quiz__medal hti-quiz__medal--celebrate" aria-hidden="true">
						<span class="hti-quiz__confetti c1"></span><span class="hti-quiz__confetti c2"></span><span class="hti-quiz__confetti c3"></span><span class="hti-quiz__confetti c4"></span>
						<span class="hti-quiz__medal-disc"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
					</span>
					<span class="hti-quiz__medal hti-quiz__medal--returning" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#0E9C84" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
					<h2 class="hti-quiz__ch"><?php echo esc_html( $s['complete_h'] ); ?></h2>
					<p class="hti-quiz__csub"></p>
					<div class="hti-quiz__badgecue">
						<span class="hti-quiz__badgecue-ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 7.7l5.4-.8z"/></svg></span>
						<span class="hti-quiz__badgecue-tx"><span class="hti-quiz__badgecue-k"><?php echo esc_html( $s['badge_kicker'] ); ?></span><span class="hti-quiz__badgecue-c"><?php echo esc_html( $s['badge_cue'] ); ?></span></span>
					</div>
					<button type="button" class="hti-quiz__review"></button>
				</div>

			</div>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
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
		array( '#6B46E0', 'linear-gradient(135deg,#EFE9FE,#DFD4FB)' ),
		array( '#1E7A5C', 'linear-gradient(135deg,#E6F7EF,#D2E8DC)' ),
		array( '#C9362C', 'linear-gradient(135deg,#FFEDE9,#FFD9D2)' ),
		array( '#8A6310', 'linear-gradient(135deg,#F6EEDD,#EFE2C5)' ),
		array( '#2D6CB3', 'linear-gradient(135deg,#E4F0FB,#D2E4F4)' ),
		array( '#8A45B5', 'linear-gradient(135deg,#F3E8FB,#E7D4F4)' ),
		array( '#B85A33', 'linear-gradient(135deg,#FBEBE2,#F4DBC9)' ),
		array( '#4F7A48', 'linear-gradient(135deg,#EAF3E6,#D9E8D2)' ),
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
	$thumb = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'medium_large' ) : '';

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
	if ( $post instanceof \WP_Post && is_tool_page( (string) $post->post_content ) ) {
		return '';
	}
	return $block_content;
}
add_filter( 'render_block', __NAMESPACE__ . '\\hide_duplicate_page_title', 10, 2 );

/**
 * Whether a page body embeds one of our full-bleed shortcode "tools" that
 * renders its own heading and controls its own width (questionnaire, account
 * dashboard, deposit comparator). These need the template's page title hidden
 * and the constrained content width lifted.
 *
 * @param string $content Page content.
 * @return bool
 */
function is_tool_page( string $content ): bool {
	return has_shortcode( $content, 'hti_questionnaire' )
		|| has_shortcode( $content, 'hti_account' )
		|| has_shortcode( $content, 'hti_depositos' );
}

/**
 * Tag shortcode-tool pages with a body class so their content can break out of
 * the theme's narrow constrained width and match the per-tool design width
 * (the account hub, the questionnaire and the comparator each set their own).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function tool_page_body_class( array $classes ): array {
	if ( ! is_singular() ) {
		return $classes;
	}
	$post = get_post();
	if ( ! $post instanceof \WP_Post ) {
		return $classes;
	}
	$content = (string) $post->post_content;
	if ( has_shortcode( $content, 'hti_account' ) ) {
		$classes[] = 'hti-page-account';
	}
	if ( has_shortcode( $content, 'hti_questionnaire' ) ) {
		$classes[] = 'hti-page-quiz';
	}
	if ( has_shortcode( $content, 'hti_depositos' ) ) {
		$classes[] = 'hti-page-tool';
	}
	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\\tool_page_body_class' );

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
	if ( '' !== trim( $desc ) || is_admin() ) {
		return $desc;
	}
	$pt = 'pt' === current_lang();

	// Singular pages whose body is a server-rendered shortcode (empty excerpt).
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $desc;
		}
		$content = (string) $post->post_content;
		if ( has_shortcode( $content, 'hti_depositos' ) ) {
			return $pt
				? 'Compara a TANB, prazos e condições dos depósitos a prazo em Portugal. Define o teu montante e vê o juro líquido estimado, lado a lado. Ferramenta educativa — não é aconselhamento.'
				: 'Compare term-deposit rates (TANB), terms and conditions in Portugal. Set your amount and see the estimated net interest side by side. An educational tool — not advice.';
		}
		if ( has_shortcode( $content, 'hti_questionnaire' ) ) {
			return $pt
				? 'Responde a um questionário curto e descobre o teu arquétipo de investidor, com um exemplo ilustrativo de carteira por classes de ativos. Gratuito, sem registo — educativo, não é aconselhamento.'
				: 'Answer a short questionnaire and discover your investor archetype, with an illustrative example portfolio by asset class. Free, no sign-up — educational, not advice.';
		}
		return $desc;
	}

	// Taxonomy archives (glossary topics, learn topics, news categories) have no
	// auto description — synthesise one from the term (its own description wins).
	if ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		if ( $term instanceof \WP_Term ) {
			if ( '' !== trim( (string) $term->description ) ) {
				return wp_strip_all_tags( $term->description );
			}
			$name = $term->name;
			if ( $pt ) {
				$meta = get_term_meta( $term->term_id, 'hti_name_pt', true );
				if ( is_string( $meta ) && '' !== $meta ) {
					$name = $meta;
				}
			}
			switch ( $term->taxonomy ) {
				case 'glossary_topic':
					return $pt
						? sprintf( 'Termos de investimento sobre %s, explicados sem jargão. Glossário educativo da HowToInvest.', $name )
						: sprintf( 'Investing terms about %s, explained without jargon. The HowToInvest educational glossary.', $name );
				case 'learn_topic':
					return $pt
						? sprintf( 'Artigos para aprender sobre %s — leituras curtas e claras para investires com mais confiança.', $name )
						: sprintf( 'Articles to learn about %s — short, clear reads to invest with more confidence.', $name );
				case 'news_category':
					return $pt
						? sprintf( 'Notícias e análises sobre %s, explicadas com calma e sem jargão.', $name )
						: sprintf( 'News and analysis about %s, explained calmly and jargon-free.', $name );
			}
		}
	}
	return $desc;
}
add_filter( 'rank_math/frontend/description', __NAMESPACE__ . '\\dynamic_meta_description' );
add_filter( 'wpseo_metadesc', __NAMESPACE__ . '\\dynamic_meta_description' );

/**
 * Account pages (the [hti_account] dashboard) are private utility views: keep
 * them out of the index and the XML sitemap. RankMath/Yoast both drop noindex
 * URLs from their sitemaps, so this also removes them from the sitemap.
 *
 * @param array<string,string>|string $robots Robots directives.
 * @return array<string,string>|string
 */
function noindex_account_pages( $robots ) {
	if ( is_admin() || ! is_singular() ) {
		return $robots;
	}
	$post = get_queried_object();
	if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'hti_account' ) ) {
		if ( is_array( $robots ) ) {
			$robots['index'] = 'noindex';
			return $robots;
		}
		return 'noindex, follow';
	}
	return $robots;
}
add_filter( 'rank_math/frontend/robots', __NAMESPACE__ . '\\noindex_account_pages' );
add_filter( 'wpseo_robots', __NAMESPACE__ . '\\noindex_account_pages' );

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
	$out .= '<div class="hti-newshub__tabs" role="group" aria-label="' . esc_attr( $pt ? 'Filtrar por categoria' : 'Filter by category' ) . '">';
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
	// The hero image is the LCP element: render a real <img> with high fetch
	// priority so it's discovered and loaded early (a CSS background loads late).
	if ( '' !== $it['thumb'] ) {
		$media = '<span class="hti-newshub__hero-media"><img class="hti-newshub__img" src="' . esc_url( (string) $it['thumb'] ) . '" alt="" width="768" height="376" fetchpriority="high" decoding="async"><span class="hti-newshub__hero-badge">' . esc_html( $badge ) . '</span></span>';
	} else {
		$media = '<span class="hti-newshub__hero-media" style="background:' . esc_attr( (string) $it['grad'] ) . ';"><span class="hti-newshub__hero-badge">' . esc_html( $badge ) . '</span></span>';
	}
	$out  = '<a class="hti-newshub__hero" href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= $media;
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
	$media = '' !== $it['thumb']
		? '<span class="hti-newshub__side-media"><img class="hti-newshub__img" src="' . esc_url( (string) $it['thumb'] ) . '" alt="" width="380" height="148" loading="lazy" decoding="async"></span>'
		: '<span class="hti-newshub__side-media" style="background:' . esc_attr( (string) $it['grad'] ) . ';"></span>';
	$out  = '<a class="hti-newshub__side" href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= $media;
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
	$media = '' !== $it['thumb']
		? '<span class="hti-newshub__row-media"><img class="hti-newshub__img" src="' . esc_url( (string) $it['thumb'] ) . '" alt="" width="168" height="168" loading="lazy" decoding="async"></span>'
		: '<span class="hti-newshub__row-media" style="background:' . esc_attr( (string) $it['grad'] ) . ';"></span>';
	$cls = 'hti-newshub__row' . ( $dup ? ' is-dup' : '' );
	$out  = '<a class="' . $cls . '"' . ( $dup ? ' hidden' : '' ) . ' href="' . esc_url( (string) $it['url'] ) . '" data-cat="' . esc_attr( (string) $it['slug'] ) . '">';
	$out .= $media;
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
 * The HowToInvest editorial mark (used in the byline + author box avatars).
 */
function news_brand_mark(): string {
	return '<svg viewBox="0 0 64 64" width="26" height="26" fill="none" aria-hidden="true"><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/><g fill="#7C5CFC"><rect x="22" y="24" width="3.4" height="6" rx="1"/><rect x="28" y="21" width="3.4" height="9" rx="1"/><rect x="34" y="18" width="3.4" height="12" rx="1"/><rect x="22" y="38" width="3.4" height="6" rx=".8"/><rect x="28" y="35" width="3.4" height="9" rx=".8"/><rect x="34" y="32" width="3.4" height="12" rx=".8"/></g></svg>';
}

/**
 * Social-share row for a news article (share-intent links + copy button).
 *
 * @param string $url   Article permalink.
 * @param string $title Article title.
 * @param bool   $pt    Current language is PT.
 */
function news_article_share( string $url, string $title, bool $pt ): string {
	$u = rawurlencode( $url );
	$t = rawurlencode( $title );
	$ic = array(
		'wa'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 0 0 1.51 5.26l-.999 3.648 3.978-.207zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>',
		'li'  => '<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.13 1.45-2.13 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12zM7.12 20.45H3.55V9h3.57v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg>',
		'x'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
		'fb'  => '<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.41 0 12.07c0 6.02 4.39 11.01 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.08 24 18.09 24 12.07z"/></svg>',
		'em'  => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>',
	);
	$share_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>';
	$copy_icon  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg>';

	$btn = static function ( string $href, string $net, string $label, string $svg ): string {
		return '<a class="hti-art__sh hti-art__sh--' . $net . '" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer nofollow" aria-label="' . esc_attr( $label ) . '">' . $svg . '</a>';
	};

	$out  = '<div class="hti-art__share">';
	$out .= '<span class="hti-art__share-label">' . $share_icon . esc_html( t( 'art_share' ) ) . '</span>';
	$out .= '<div class="hti-art__share-row">';
	$out .= $btn( 'https://wa.me/?text=' . $t . '%20' . $u, 'wa', 'WhatsApp', $ic['wa'] );
	$out .= $btn( 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u, 'li', 'LinkedIn', $ic['li'] );
	$out .= $btn( 'https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u, 'x', 'X', $ic['x'] );
	$out .= $btn( 'https://www.facebook.com/sharer/sharer.php?u=' . $u, 'fb', 'Facebook', $ic['fb'] );
	$out .= $btn( 'mailto:?subject=' . $t . '&body=' . $u, 'em', 'Email', $ic['em'] );
	$out .= '<span class="hti-art__share-div"></span>';
	$out .= '<button type="button" class="hti-art__copy" data-url="' . esc_url( $url ) . '" data-copied="' . esc_attr( t( 'art_copied' ) ) . '">' . $copy_icon . '<span class="hti-art__copy-label">' . esc_html( t( 'art_copy' ) ) . '</span></button>';
	$out .= '</div></div>';
	return $out;
}

/**
 * "Keep reading" related-news cards (same category, latest), padded with the
 * latest news when a category is thin.
 *
 * @param \WP_Post $post Current article.
 * @param bool     $pt   Current language is PT.
 */
function news_article_related( \WP_Post $post, bool $pt ): string {
	$cats = wp_get_post_terms( $post->ID, 'news_category', array( 'fields' => 'ids' ) );
	$args = array(
		'post_type'           => 'news',
		'post_status'         => 'publish',
		'posts_per_page'      => 3,
		'post__not_in'        => array( $post->ID ),
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'news_category', 'terms' => $cats ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}
	$q     = new \WP_Query( $args );
	$posts = $q->posts;
	wp_reset_postdata();

	if ( count( $posts ) < 3 ) {
		$have = array( $post->ID => true );
		foreach ( $posts as $p ) {
			$have[ $p->ID ] = true;
		}
		foreach ( news_hub_posts( 6 ) as $p ) {
			if ( count( $posts ) >= 3 ) {
				break;
			}
			if ( empty( $have[ $p->ID ] ) ) {
				$posts[]        = $p;
				$have[ $p->ID ] = true;
			}
		}
	}
	if ( empty( $posts ) ) {
		return '';
	}

	$out = '<section class="hti-art__related"><h2 class="hti-art__related-h"><span class="hti-art__related-bar"></span>' . esc_html( t( 'related_read' ) ) . '</h2><div class="hti-art__related-grid">';
	foreach ( $posts as $p ) {
		$d     = news_item_data( $p, $pt );
		$media = '' !== $d['thumb']
			? '<span class="hti-art__rcard-media"><img class="hti-art__rcard-img" src="' . esc_url( (string) $d['thumb'] ) . '" alt="" loading="lazy" decoding="async"></span>'
			: '<span class="hti-art__rcard-media" style="background:' . esc_attr( (string) $d['grad'] ) . ';"></span>';
		$out  .= '<a class="hti-art__rcard" href="' . esc_url( (string) $d['url'] ) . '">' . $media . '<span class="hti-art__rcard-body"><span class="hti-art__rcard-cat" style="color:' . esc_attr( (string) $d['color'] ) . '">' . esc_html( (string) $d['cat'] ) . '</span><span class="hti-art__rcard-title">' . esc_html( (string) $d['title'] ) . '</span></span></a>';
	}
	$out .= '</div></section>';
	return $out;
}

/**
 * Single-news article (designed): reading-progress bar, breadcrumb, category
 * pill, lead, editorial byline, featured image (or gradient illustration),
 * prose body, tags, social share, author box, CTA and "keep reading" cards.
 *
 * @return string Safe HTML.
 */
function render_news_article(): string {
	if ( ! is_singular( 'news' ) ) {
		return '';
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}
	wp_enqueue_style( 'howtoinvest-news-article' );
	wp_enqueue_script( 'howtoinvest-news-article' );

	$pt    = 'pt' === current_lang();
	$it    = news_item_data( $post, $pt );
	$url   = (string) get_permalink( $post );
	$title = (string) get_the_title( $post );
	$logo  = news_brand_mark();

	$views = (int) $it['views'];
	$vf    = $views >= 1000 ? number_format_i18n( $views / 1000, 1 ) . 'k' : (string) $views;
	$meta  = $it['date'] . ' · ' . $it['read'] . ' ' . t( 'art_min_read' );
	if ( $views > 0 ) {
		$meta .= ' · ' . $vf . ' ' . t( 'news_reads' );
	}

	// Video format: YouTube-sourced articles (set by hti-rss-ai) lead with the
	// embedded video and read like a watch-and-read summary.
	$video_id = (string) get_post_meta( $post->ID, 'rssai_youtube_video_id', true );
	$is_video = '' !== $video_id && preg_match( '~^[A-Za-z0-9_-]{6,20}$~', $video_id );

	$out  = '<article class="hti-art' . ( $is_video ? ' hti-art--video' : '' ) . '">';
	$out .= '<div class="hti-art__progress hti-noprint" aria-hidden="true"><span class="hti-art__bar"></span></div>';

	// Breadcrumb.
	$out .= '<nav class="hti-art__crumb" aria-label="breadcrumb"><a href="' . esc_url( archive_url( 'news', 'financial-news' ) ) . '">' . esc_html( t( 'nav_news' ) ) . '</a>';
	if ( '' !== $it['cat'] ) {
		$out .= '<span class="hti-art__crumb-sep" aria-hidden="true">›</span><span class="hti-art__crumb-cat" style="color:' . esc_attr( (string) $it['color'] ) . '">' . esc_html( (string) $it['cat'] ) . '</span>';
	}
	$out .= '</nav>';

	// Category pill (+ a video badge for video-format articles).
	if ( $is_video ) {
		$out .= '<span class="hti-art__pill hti-art__pill--video"><span class="hti-art__playdot" aria-hidden="true">▶</span>' . esc_html( t( 'art_video' ) ) . '</span>';
	} elseif ( '' !== $it['cat'] ) {
		$out .= '<span class="hti-art__pill" style="color:' . esc_attr( (string) $it['color'] ) . '">' . esc_html( (string) $it['cat'] ) . '</span>';
	}

	// Title + lead.
	$out .= '<h1 class="hti-art__title">' . esc_html( $title ) . '</h1>';
	$lead = has_excerpt( $post ) ? trim( (string) get_the_excerpt( $post ) ) : '';
	if ( '' !== $lead ) {
		$out .= '<p class="hti-art__lead">' . esc_html( $lead ) . '</p>';
	}

	// Byline.
	$out .= '<div class="hti-art__byline"><span class="hti-art__avatar">' . $logo . '</span><div><div class="hti-art__by">' . esc_html( t( 'art_byline' ) ) . '</div><div class="hti-art__meta">' . esc_html( $meta ) . '</div></div></div>';

	// Hero: the embedded video (video format), else featured image, else gradient.
	if ( $is_video ) {
		$embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video_id );
		$watch = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
		$out  .= '<figure class="hti-art__video">'
			. '<div class="hti-art__video-frame"><iframe src="' . esc_url( $embed ) . '" title="' . esc_attr( $title ) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>'
			. '<figcaption class="hti-art__video-cap"><span>' . esc_html( t( 'art_video_intro' ) ) . '</span>'
			. '<a class="hti-art__video-link" href="' . esc_url( $watch ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( t( 'art_watch' ) ) . ' ↗</a>'
			. '</figcaption></figure>';
	} elseif ( has_post_thumbnail( $post ) ) {
		$out .= '<figure class="hti-art__figure">' . get_the_post_thumbnail( $post, 'large', array( 'class' => 'hti-art__img', 'alt' => $title, 'fetchpriority' => 'high', 'decoding' => 'async' ) ) . '</figure>';
	} else {
		$out .= '<div class="hti-art__illus" style="background:' . esc_attr( (string) $it['grad'] ) . ';"><span class="hti-art__illus-bubble" aria-hidden="true"></span><span class="hti-art__illus-label">' . esc_html( t( 'art_illustration' ) ) . '</span></div>';
	}

	// Body (prose).
	$out .= '<div class="hti-art__prose">' . apply_filters( 'the_content', get_the_content( null, false, $post ) ) . '</div>';

	// Tags (news categories).
	$terms = get_the_terms( $post, 'news_category' );
	if ( is_array( $terms ) && ! empty( $terms ) ) {
		$out .= '<div class="hti-art__tags">';
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$out .= '<a class="hti-art__tag" href="' . esc_url( (string) $link ) . '">#' . esc_html( news_cat_name( $term, $pt ) ) . '</a>';
			}
		}
		$out .= '</div>';
	}

	// Social share.
	$out .= news_article_share( $url, $title, $pt );

	// Author box.
	$out .= '<div class="hti-art__author"><span class="hti-art__avatar hti-art__avatar--lg">' . $logo . '</span><div><div class="hti-art__by">' . esc_html( t( 'art_byline' ) ) . '</div><p class="hti-art__author-bio">' . esc_html( t( 'art_author_bio' ) ) . '</p></div></div>';

	// CTA.
	$out .= '<div class="hti-art__cta"><span class="hti-art__cta-q">' . esc_html( t( 'news_cta_q' ) ) . '</span><a class="hti-art__cta-btn" href="' . esc_url( page_url( 'investor-profile-quiz' ) ) . '" data-hti-track="cta_click" data-htip-location="news_article">' . esc_html( t( 'news_cta_btn' ) ) . '</a></div>';

	// Related.
	$out .= news_article_related( $post, $pt );

	$out .= '</article>';
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
	$out = '<div class="hti-header__actions">';

	if ( is_user_logged_in() ) {
		// Logged-in: an account avatar showing the user's initial → My account.
		$user    = wp_get_current_user();
		$name    = $user->display_name ? $user->display_name : $user->user_login;
		$initial = mb_strtoupper( mb_substr( wp_strip_all_tags( (string) $name ), 0, 1 ) );
		$out    .= '<a class="hti-header__account" href="' . esc_url( account_url() ) . '" aria-label="' . esc_attr( t( 'nav_my_account' ) ) . '" title="' . esc_attr( t( 'nav_my_account' ) ) . '">'
			. esc_html( '' !== $initial ? $initial : '·' ) . '</a>';
	} else {
		// Guest: a "Sign in" link (the account page handles authentication).
		$out .= '<a class="hti-header__login" href="' . esc_url( account_url() ) . '">' . esc_html( t( 'nav_login' ) ) . '</a>';
	}

	// "Get started" CTA (always present).
	$out .= '<div class="wp-block-buttons hti-cta"><div class="wp-block-button is-style-fill">'
		. '<a class="wp-block-button__link wp-element-button" href="' . esc_url( page_url( 'investor-profile-quiz' ) ) . '" data-hti-track="cta_click" data-htip-location="header">'
		. esc_html( t( 'cta_get_started' ) ) . '</a></div>'
		. '</div></div>';

	return $out;
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

	// Logged-in users get an initial avatar; guests get the generic user icon.
	if ( is_user_logged_in() ) {
		$u       = wp_get_current_user();
		$name    = $u->display_name ? $u->display_name : $u->user_login;
		$initial = mb_strtoupper( mb_substr( wp_strip_all_tags( (string) $name ), 0, 1 ) );
		$account = '<span class="hti-mbar__avatar">' . esc_html( '' !== $initial ? $initial : '·' ) . '</span>';
		$label   = t( 'nav_my_account' );
	} else {
		$account = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"></path></svg>';
		$label   = t( 'nav_account' );
	}

	return '<div class="hti-mbar">'
		. '<a class="hti-mbar__btn" href="' . esc_url( search_url() ) . '" aria-label="' . esc_attr( t( 'search_label' ) ) . '">' . $search . '</a>'
		. '<a class="hti-mbar__btn hti-mbar__btn--account" href="' . esc_url( account_url() ) . '" aria-label="' . esc_attr( $label ) . '">' . $account . '</a>'
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

	// Load the search/filter behaviour wherever this block renders.
	wp_enqueue_script( 'howtoinvest-glossary' );

	// Collect terms grouped by initial letter, plus the set of topics used.
	$groups = array();   // letter => array of row HTML.
	$letters = array();  // letter => true (has terms).
	$topics  = array();  // topic slug => label.
	$total   = 0;

	while ( $query->have_posts() ) {
		$query->the_post();
		$post_id = get_the_ID();
		$title   = get_the_title();
		$letter  = glossary_letter( $title );
		$short   = wp_strip_all_tags( (string) get_the_excerpt() );

		// Topics this term belongs to (for the topic filter + data-topic).
		$slugs      = array();
		$post_terms = get_the_terms( $post_id, 'glossary_topic' );
		if ( is_array( $post_terms ) ) {
			foreach ( $post_terms as $tp ) {
				$slugs[]             = $tp->slug;
				$topics[ $tp->slug ] = $tp->name;
			}
		}

		// Accent-folded haystack so search is diacritic-insensitive.
		$search = remove_accents( strtolower( trim( $title . ' ' . $short ) ) );

		$letters[ $letter ] = true;
		++$total;

		$groups[ $letter ][] = '<li class="hti-gloss__row" data-letter="' . esc_attr( $letter ) . '"'
			. ' data-topic="' . esc_attr( implode( ' ', $slugs ) ) . '"'
			. ' data-search="' . esc_attr( $search ) . '">'
			. '<a class="hti-gloss__link" href="' . esc_url( (string) get_permalink() ) . '">'
			. '<span class="hti-gloss__rowtext"><span class="hti-gloss__term">' . esc_html( $title ) . '</span>'
			. ( '' !== $short ? '<span class="hti-gloss__short">' . esc_html( $short ) . '</span>' : '' )
			. '</span><span class="hti-gloss__arrow" aria-hidden="true">'
			. '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6"/></svg>'
			. '</span></a></li>';
	}
	wp_reset_postdata();

	ksort( $groups );
	ksort( $letters );

	// --- Count pill (decorative, static total). ---
	$count_pill = '<span class="hti-gloss__total"><span class="hti-gloss__dot" aria-hidden="true"></span>'
		. esc_html( sprintf( t( 'gloss_count_pill' ), number_format_i18n( $total ) ) ) . '</span>';

	// --- Search box + live count. ---
	$search_box = '<div class="hti-gloss__search">'
		. '<label class="hti-gloss__slabel" for="hti-gloss-q">' . esc_html( t( 'gloss_search_label' ) ) . '</label>'
		. '<div class="hti-gloss__sbox">'
		. '<span class="hti-gloss__sicon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></span>'
		. '<input id="hti-gloss-q" class="hti-gloss__input" type="text" autocomplete="off" placeholder="' . esc_attr( t( 'gloss_search_ph' ) ) . '" aria-describedby="hti-gloss-count" />'
		. '<button type="button" class="hti-gloss__clear" hidden>' . esc_html( t( 'gloss_clear' ) ) . '</button>'
		. '</div>'
		. '<p id="hti-gloss-count" class="hti-gloss__count" role="status" aria-live="polite" data-template="' . esc_attr( t( 'gloss_results' ) ) . '" data-one="' . esc_attr( t( 'gloss_result_one' ) ) . '">'
		. esc_html( sprintf( t( 'gloss_results' ), number_format_i18n( $total ) ) ) . '</p>'
		. '</div>';

	// --- Topic filter (only when there is more than one topic). ---
	$topic_filter = '';
	if ( count( $topics ) > 1 ) {
		asort( $topics );
		$chips = '<button type="button" class="hti-gloss__topic is-active" data-topic="all" aria-pressed="true">' . esc_html( t( 'gloss_all' ) ) . '</button>';
		foreach ( $topics as $slug => $label ) {
			$chips .= '<button type="button" class="hti-gloss__topic" data-topic="' . esc_attr( $slug ) . '" aria-pressed="false">' . esc_html( $label ) . '</button>';
		}
		$topic_filter = '<div class="hti-gloss__filter">'
			. '<span class="hti-gloss__flabel">' . esc_html( t( 'gloss_topic_lbl' ) ) . '</span>'
			. '<div class="hti-gloss__topics" role="group" aria-label="' . esc_attr( t( 'gloss_filter_topic' ) ) . '">' . $chips . '</div>'
			. '</div>';
	}

	// --- A–Z filter (all 26 letters; letters with no term are inert). ---
	$alpha = '<button type="button" class="hti-gloss__letter is-active" data-letter="all" aria-pressed="true">' . esc_html( t( 'gloss_all' ) ) . '</button>';
	foreach ( range( 'A', 'Z' ) as $L ) {
		if ( isset( $letters[ $L ] ) ) {
			$alpha .= '<button type="button" class="hti-gloss__letter" data-letter="' . esc_attr( $L ) . '" aria-pressed="false">' . esc_html( $L ) . '</button>';
		} else {
			$alpha .= '<span class="hti-gloss__letter is-disabled" aria-hidden="true">' . esc_html( $L ) . '</span>';
		}
	}
	if ( isset( $letters['#'] ) ) {
		$alpha .= '<button type="button" class="hti-gloss__letter" data-letter="#" aria-pressed="false">#</button>';
	}
	$letter_filter = '<div class="hti-gloss__filter">'
		. '<span class="hti-gloss__flabel">' . esc_html( t( 'gloss_letter_lbl' ) ) . '</span>'
		. '<div class="hti-gloss__alpha" role="group" aria-label="' . esc_attr( t( 'gloss_filter' ) ) . '">' . $alpha . '</div>'
		. '</div>';

	// --- Grouped list, one card per letter. ---
	$list = '<div class="hti-gloss__groups">';
	foreach ( $groups as $letter => $rows ) {
		$list .= '<div class="hti-gloss__group" data-letter="' . esc_attr( $letter ) . '">'
			. '<div class="hti-gloss__gletter" aria-hidden="true">' . esc_html( $letter ) . '</div>'
			. '<ul class="hti-gloss__glist">' . implode( '', $rows ) . '</ul>'
			. '</div>';
	}
	$list .= '</div>';

	// --- Empty state (hidden until search/filter clears the list). ---
	$empty = '<div class="hti-gloss__empty" hidden>'
		. '<div class="hti-gloss__empty-ic" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></div>'
		. '<h2 class="hti-gloss__empty-t">' . esc_html( t( 'gloss_empty_t' ) ) . '</h2>'
		. '<p class="hti-gloss__empty-b">' . esc_html( t( 'gloss_empty_b' ) ) . '</p>'
		. '<button type="button" class="hti-gloss__empty-btn">' . esc_html( t( 'gloss_clear_filters' ) ) . '</button>'
		. '</div>';

	// --- Cross-link to the course. ---
	$course = '<div class="hti-gloss__course">'
		. '<div class="hti-gloss__course-txt">'
		. '<span class="hti-gloss__course-eyebrow">' . esc_html( t( 'gloss_course_eyebrow' ) ) . '</span>'
		. '<h2 class="hti-gloss__course-t">' . esc_html( t( 'gloss_course_t' ) ) . '</h2>'
		. '<p class="hti-gloss__course-b">' . esc_html( t( 'gloss_course_b' ) ) . '</p>'
		. '</div>'
		. '<a class="hti-gloss__course-btn" href="' . esc_url( archive_url( 'learn', 'learn' ) ) . '">' . esc_html( t( 'gloss_course_btn' ) ) . '</a>'
		. '</div>';

	return '<div class="hti-gloss">'
		. $count_pill
		. $search_box
		. $topic_filter
		. $letter_filter
		. $list
		. $empty
		. $course
		. '</div>';
}
