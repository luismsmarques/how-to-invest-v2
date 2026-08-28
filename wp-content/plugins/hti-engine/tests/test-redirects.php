<?php
/**
 * Tests for the legacy Base44 → WordPress redirect map.
 *
 * Guards the three failures the August 2026 Search Console audit surfaced:
 * legacy paths with no map entry, news articles losing their `?slug=` and
 * collapsing onto the archive, and /how-to-start splitting impressions with
 * the canonical chapter. Every URL asserted below is one that really appeared
 * in the Search Console or GA4 export.
 *
 *   php wp-content/plugins/hti-engine/tests/test-redirects.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-tools-content.php';
require_once __DIR__ . '/../includes/class-redirects.php';

use HTI\Engine\Redirects;
use HTI\Engine\Tools_Content;

$failures = 0;
$passes   = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Description.
 */
function check( bool $cond, string $label ): void {
	global $failures, $passes;
	if ( $cond ) {
		++$passes;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}

/**
 * Assert a request URI resolves to an expected target.
 *
 * @param string      $uri      Request URI.
 * @param string|null $expected Expected relative target, or null for no redirect.
 * @param string      $label    Description.
 */
function resolves( string $uri, ?string $expected, string $label ): void {
	$actual = Redirects::resolve( $uri, 'fake_news_lookup' );
	$shown  = null === $actual ? 'null' : $actual;
	$want   = null === $expected ? 'null' : $expected;
	check( $actual === $expected, "{$label} — {$uri} → {$shown}" . ( $actual === $expected ? '' : " (esperado {$want})" ) );
}

/**
 * Stand-in for the real `news` post lookup.
 *
 * Knows two articles; everything else is unknown, which is how the archive
 * fallback gets exercised.
 *
 * @param string $slug Sanitized slug.
 * @return string|null
 */
function fake_news_lookup( string $slug ): ?string {
	$known = array(
		'spacex-stock-closes-below-debut-price-in-post-inclusion-slide' => '/financial-news/spacex-stock-closes-below-debut-price-in-post-inclusion-slide/',
		'millennium-bcp-lanca-campanha-novos-clientes-oferta-iphone-17'  => '/pt/financial-news/millennium-bcp-lanca-campanha-novos-clientes-oferta-iphone-17/',
	);

	return $known[ $slug ] ?? null;
}

echo "\n=== P0-1: /how-to-start deixa de competir com o capítulo canónico ===\n";
resolves( '/HowToStart', '/how-to-start-investing/', 'CamelCase legado' );
resolves( '/how-to-start', '/how-to-start-investing/', 'slug duplicado' );
resolves( '/how-to-start/', '/how-to-start-investing/', 'com barra final' );
resolves( '/HOWTOSTART', '/how-to-start-investing/', 'maiúsculas' );
resolves( '/how-to-start-investing/', null, 'o canónico não se redireciona a si próprio' );

echo "\n=== P0-2: rotas legadas que estavam fora do mapa ===\n";
resolves( '/EducationalResources', '/learn/', '365 pageviews GA4, pos. 20' );
resolves( '/Home', '/', '264 pageviews GA4' );
resolves( '/Sitemap', '/', '16 impressões, pos. 7' );
resolves( '/ProfileBuilder', '/investor-profile-quiz/', 'construtor de perfil' );
resolves( '/ProfileSettings', '/my-account/', 'definições de perfil' );
resolves( '/EducationModule', '/learn/', 'módulo educativo' );
resolves( '/LocalizedPage', '/', 'página localizada' );
resolves( '/Results', '/investor-profile-quiz/', '414 pageviews GA4' );

echo "\n=== P0-2b: prefixos de idioma mortos (invariante EN+PT) ===\n";
resolves( '/es', '/', 'espanhol nu' );
resolves( '/fr', '/', 'francês nu' );
resolves( '/es/terminos-condiciones', '/', 'ES sem correspondência vai para a home' );
resolves( '/fr/comment-commencer', '/', 'FR sem correspondência vai para a home' );
resolves( '/de/HowToStart', '/how-to-start-investing/', 'idioma morto + rota conhecida' );
resolves( '/ru/About', '/about/', 'idioma morto + página conhecida' );
resolves( '/pt/learn/', null, 'PT é uma língua viva — nunca redirecionada' );
resolves( '/pt/questionario-perfil-investidor/', null, 'PT preservado' );

echo "\n=== P0-3: ?slug= das notícias resolve para o artigo, não para o arquivo ===\n";
resolves(
	'/FinancialNews?slug=spacex-stock-closes-below-debut-price-in-post-inclusion-slide',
	'/financial-news/spacex-stock-closes-below-debut-price-in-post-inclusion-slide/',
	'artigo conhecido (estava em pos. 1)'
);
resolves(
	'/FinancialNewsArticle?slug=spacex-stock-closes-below-debut-price-in-post-inclusion-slide',
	'/financial-news/spacex-stock-closes-below-debut-price-in-post-inclusion-slide/',
	'a variante FinancialNewsArticle'
);
resolves(
	'/FinancialNews?slug=millennium-bcp-lanca-campanha-novos-clientes-oferta-iphone-17',
	'/pt/financial-news/millennium-bcp-lanca-campanha-novos-clientes-oferta-iphone-17/',
	'artigo PT mantém o prefixo de idioma'
);
resolves( '/FinancialNews?slug=artigo-que-ja-nao-existe', '/financial-news/', 'desconhecido → arquivo' );
resolves( '/FinancialNews', '/financial-news/', 'sem slug → arquivo' );
resolves( '/FinancialNews?slug=', '/financial-news/', 'slug vazio → arquivo' );
resolves(
	'/FinancialNews?utm_source=x&slug=spacex-stock-closes-below-debut-price-in-post-inclusion-slide&utm_medium=y',
	'/financial-news/spacex-stock-closes-below-debut-price-in-post-inclusion-slide/',
	'slug encontrado entre outros parâmetros'
);

echo "\n=== Sanitização do slug ===\n";
resolves(
	'/FinancialNews?slug=SpaceX-Stock-Closes-Below-Debut-Price-In-Post-Inclusion-Slide',
	'/financial-news/spacex-stock-closes-below-debut-price-in-post-inclusion-slide/',
	'maiúsculas normalizadas'
);
resolves(
	"/FinancialNews?slug=spacex-stock-closes-below-debut-price-in-post-inclusion-slide'%20OR%201=1",
	'/financial-news/',
	'injeção deturpa o slug → não corresponde, cai no arquivo'
);
resolves( '/FinancialNews?slug=' . str_repeat( 'a', 500 ), '/financial-news/', 'slug gigante não rebenta' );
resolves( '/FinancialNews?slug=../../etc/passwd', '/financial-news/', 'travessia de diretórios neutralizada' );

echo "\n=== Comportamento existente preservado ===\n";
resolves( '/About', '/about/', 'about' );
resolves( '/Contact', '/contact/', 'contact' );
resolves( '/Questionnaire', '/investor-profile-quiz/', 'questionnaire' );
resolves( '/PrivacyPolicy', '/privacy-policy/', 'privacy policy' );
resolves( '/TermsAndConditions', '/terms-and-conditions/', 'terms' );
resolves( '/', null, 'a raiz nunca redireciona' );
resolves( '/learn/why-invest/', null, 'conteúdo real intocado' );
resolves( '/investing-glossary/ipo/', null, 'glossário intocado' );
resolves( '/financial-news/', null, 'o arquivo não se redireciona a si próprio' );

echo "\n=== As calculadoras mudaram para baixo do hub ===\n";
foreach ( Tools_Content::tools() as $tool_slug => $tool_def ) {
	$pt_slug = (string) $tool_def['pt_slug'];

	resolves(
		'/' . $tool_slug . '/',
		'/tools/' . $tool_slug . '/',
		'EN ' . $tool_slug . ' → sob o hub'
	);
	resolves(
		'/pt/' . $pt_slug . '/',
		'/pt/ferramentas/' . $pt_slug . '/',
		'PT ' . $pt_slug . ' → sob o hub'
	);

	// O destino não se pode redirecionar a si próprio, ou é um ciclo.
	resolves( '/tools/' . $tool_slug . '/', null, 'o novo URL EN não redireciona' );
	resolves( '/pt/ferramentas/' . $pt_slug . '/', null, 'o novo URL PT não redireciona' );
}

// Uma nona calculadora sem 301 tem de partir a suite, não o site.
$missing = array();
foreach ( Tools_Content::slugs() as $tool_slug ) {
	if ( null === Redirects::resolve( '/' . $tool_slug . '/' ) ) {
		$missing[] = $tool_slug;
	}
}
check( array() === $missing, 'toda a ferramenta em Tools_Content tem 301 (em falta: ' . ( $missing ? implode( ', ', $missing ) : 'nenhuma' ) . ')' );

// O hub em si nunca se move.
resolves( '/tools/', null, 'o hub EN fica onde está' );
resolves( '/pt/ferramentas/', null, 'o hub PT fica onde está' );

echo "\n=== O mapa continua filtrável ===\n";
add_filter(
	'hti_legacy_redirects',
	static function ( $map ) {
		$map['legacyonly'] = '/tools/';
		return $map;
	}
);
resolves( '/LegacyOnly', '/tools/', 'hti_legacy_redirects acrescenta entradas' );

add_filter(
	'hti_dead_language_prefixes',
	static function () {
		return array( 'xx' );
	}
);
resolves( '/xx/About', '/about/', 'hti_dead_language_prefixes substitui a lista' );
resolves( '/es/About', null, 'es sai da lista e deixa de ser dobrado' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
