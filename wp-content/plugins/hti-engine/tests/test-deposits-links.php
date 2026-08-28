<?php
/**
 * Tests for Links::page_url() and the term-deposit comparator's URLs.
 *
 * The comparator was linked from three places with three different hand-written
 * URLs, none of which existed: the nav said /pt/comparador-de-depositos/ (no
 * "-a-prazo"), the account hub said /comparador-de-depositos/ (wrong slug and no
 * /pt/), and the comparator's own methodology link dropped the /pt/ prefix. All
 * three 404'd, and nothing in the suite would have noticed — test-deposits.php
 * only covers the string table.
 *
 *   php wp-content/plugins/hti-engine/tests/test-deposits-links.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal stand-in for the WordPress post object.
	 */
	class WP_Post {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $post_name = '';
	}
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

/*
 * The pages the seeder really creates, EN slug => [ id, permalink ], plus the
 * EN id => PT id translation links Polylang would hold.
 */
$GLOBALS['hti_pages'] = array(
	'term-deposit-comparison-portugal' => 1,
	'deposit-comparison-methodology'   => 3,
);
$GLOBALS['hti_permalinks'] = array(
	1 => 'https://howtoinvest.pro/term-deposit-comparison-portugal/',
	2 => 'https://howtoinvest.pro/pt/comparador-de-depositos-a-prazo/',
	3 => 'https://howtoinvest.pro/deposit-comparison-methodology/',
	4 => 'https://howtoinvest.pro/pt/metodologia-do-comparador-de-depositos/',
);
$GLOBALS['hti_translations'] = array( 1 => 2, 3 => 4 );
$GLOBALS['hti_lang']         = 'pt';

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * Shim: resolve a page by path.
	 *
	 * @param string $path Path.
	 * @param mixed  $out  Output type (ignored).
	 * @param mixed  $type Post type (ignored).
	 * @return WP_Post|null
	 */
	function get_page_by_path( $path, $out = null, $type = null ) {
		if ( ! isset( $GLOBALS['hti_pages'][ $path ] ) ) {
			return null;
		}
		$post     = new WP_Post();
		$post->ID = (int) $GLOBALS['hti_pages'][ $path ];
		return $post;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Shim: permalink by id.
	 *
	 * @param int $id Post ID.
	 * @return string|false
	 */
	function get_permalink( $id ) {
		return $GLOBALS['hti_permalinks'][ (int) $id ] ?? false;
	}
}

if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * Shim: Polylang translation lookup.
	 *
	 * @param int    $id   Post ID.
	 * @param string $lang Language slug.
	 * @return int|false
	 */
	function pll_get_post( $id, $lang ) {
		if ( 'pt' !== $lang ) {
			return (int) $id;
		}
		return $GLOBALS['hti_translations'][ (int) $id ] ?? false;
	}
}

if ( ! function_exists( 'pll_current_language' ) ) {
	/**
	 * Shim: current language.
	 *
	 * @param string $field Field (ignored).
	 * @return string
	 */
	function pll_current_language( $field = 'slug' ) {
		return $GLOBALS['hti_lang'];
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Shim: home URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '/' ) {
		return 'https://howtoinvest.pro' . $path;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Shim: locale.
	 *
	 * @return string
	 */
	function get_locale() {
		return 'pt' === $GLOBALS['hti_lang'] ? 'pt_PT' : 'en_US';
	}
}

require_once __DIR__ . '/../includes/class-links.php';
require_once __DIR__ . '/../includes/class-tools-content.php';
require_once __DIR__ . '/../includes/class-redirects.php';

use HTI\Engine\Links;
use HTI\Engine\Redirects;

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

echo "\n=== Links::page_url() resolve a tradução ===\n";
$GLOBALS['hti_lang'] = 'pt';
check(
	'https://howtoinvest.pro/pt/comparador-de-depositos-a-prazo/' === Links::page_url( 'term-deposit-comparison-portugal' ),
	'comparador em PT'
);
check(
	'https://howtoinvest.pro/pt/metodologia-do-comparador-de-depositos/' === Links::page_url( 'deposit-comparison-methodology' ),
	'metodologia em PT'
);

$GLOBALS['hti_lang'] = 'en';
check(
	'https://howtoinvest.pro/term-deposit-comparison-portugal/' === Links::page_url( 'term-deposit-comparison-portugal' ),
	'comparador em EN'
);
check(
	'https://howtoinvest.pro/deposit-comparison-methodology/' === Links::page_url( 'deposit-comparison-methodology' ),
	'metodologia em EN'
);

echo "\n=== Nenhum dos URLs antigos é gerado ===\n";
foreach ( array( 'en', 'pt' ) as $lang ) {
	$GLOBALS['hti_lang'] = $lang;
	foreach ( array( 'term-deposit-comparison-portugal', 'deposit-comparison-methodology' ) as $slug ) {
		$url = Links::page_url( $slug );
		check(
			'https://howtoinvest.pro/pt/comparador-de-depositos/' !== $url
				&& 'https://howtoinvest.pro/comparador-de-depositos/' !== $url
				&& 'https://howtoinvest.pro/metodologia-do-comparador-de-depositos/' !== $url,
			"{$lang}/{$slug} não devolve nenhum dos URLs partidos"
		);
	}
}

echo "\n=== Degradação ===\n";
$GLOBALS['hti_lang'] = 'pt';
check(
	'https://howtoinvest.pro/nao-existe/' === Links::page_url( 'nao-existe' ),
	'página inexistente cai no slug EN'
);
check(
	'https://howtoinvest.pro/algures/' === Links::page_url( 'nao-existe', '/algures/' ),
	'o fallback explícito é respeitado'
);
check( false === Links::page_exists( 'nao-existe' ), 'page_exists() é falso para uma página que não existe' );
check( true === Links::page_exists( 'term-deposit-comparison-portugal' ), 'page_exists() é verdadeiro para o comparador' );

// Sem tradução PT, a EN serve — melhor um link certo noutra língua que um 404.
$GLOBALS['hti_translations'] = array();
check(
	'https://howtoinvest.pro/term-deposit-comparison-portugal/' === Links::page_url( 'term-deposit-comparison-portugal' ),
	'sem tradução PT, devolve a página EN em vez de um URL inventado'
);
$GLOBALS['hti_translations'] = array( 1 => 2, 3 => 4 );

echo "\n=== Os 404 antigos passam a 301 ===\n";
$moves = array(
	'/comparador-de-depositos/'                 => '/pt/comparador-de-depositos-a-prazo/',
	'/pt/comparador-de-depositos/'              => '/pt/comparador-de-depositos-a-prazo/',
	'/metodologia-do-comparador-de-depositos/'  => '/pt/metodologia-do-comparador-de-depositos/',
);
foreach ( $moves as $from => $to ) {
	check( $to === Redirects::resolve( $from ), "{$from} → {$to}" );
}

echo "\n=== Os destinos não se redirecionam a si próprios ===\n";
foreach ( array_unique( array_values( $moves ) ) as $target ) {
	check( null === Redirects::resolve( $target ), "{$target} fica onde está" );
}
check( null === Redirects::resolve( '/term-deposit-comparison-portugal/' ), 'o comparador EN fica onde está' );
check( null === Redirects::resolve( '/deposit-comparison-methodology/' ), 'a metodologia EN fica onde está' );

echo "\n=== Links::translation_exists() ===\n";
check( true === Links::translation_exists( 'term-deposit-comparison-portugal', 'en' ), 'a página EN existe' );
check( true === Links::translation_exists( 'term-deposit-comparison-portugal', 'pt' ), 'a tradução PT existe' );
check( false === Links::translation_exists( 'nao-existe', 'en' ), 'página inexistente, em EN' );
check( false === Links::translation_exists( 'nao-existe', 'pt' ), 'página inexistente, em PT' );

$GLOBALS['hti_translations'] = array();
check( true === Links::translation_exists( 'term-deposit-comparison-portugal', 'en' ), 'sem tradução, a EN continua a existir' );
check( false === Links::translation_exists( 'term-deposit-comparison-portugal', 'pt' ), 'sem tradução, a PT não existe' );
$GLOBALS['hti_translations'] = array( 1 => 2, 3 => 4 );

echo "\n=== Os 301 dos depósitos também esperam pelo destino ===\n";
$absent = static fn( string $path, string $lang ): bool => false;
foreach ( array_keys( $moves ) as $from ) {
	check( null === Redirects::resolve( $from, null, $absent ), "{$from} não redireciona sem destino" );
}
foreach ( $moves as $from => $to ) {
	check(
		$to === Redirects::resolve( $from, null, array( Links::class, 'translation_exists' ) ),
		"{$from} redireciona com o verificador real"
	);
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
