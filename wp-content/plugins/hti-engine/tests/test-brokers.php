<?php
/**
 * Tests for the broker comparison's pure pieces: the bilingual string tables,
 * the product-label map, the star rendering, the /go/ link rel rules and the
 * canonical affiliate/CFD disclosure texts.
 *
 *   php wp-content/plugins/hti-engine/tests/test-brokers.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// Minimal WP shims for the link builder.
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param string $url   URL.
	 * @return string
	 */
	function add_query_arg( $key, $value, $url ) {
		return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
	}
}

require_once __DIR__ . '/../includes/class-disclaimer.php';
require_once __DIR__ . '/../includes/class-broker-go.php';
require_once __DIR__ . '/../includes/class-brokers.php';

use HTI\Engine\Broker_Go;
use HTI\Engine\Brokers;
use HTI\Engine\Disclaimer;

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

echo "\nBrokers — bilingual interface\n";

$pt = Brokers::strings( 'pt' );
$en = Brokers::strings( 'en' );

$missing_in_en = array_diff( array_keys( $pt ), array_keys( $en ) );
$missing_in_pt = array_diff( array_keys( $en ), array_keys( $pt ) );
check( array() === $missing_in_en, 'every PT key exists in EN (' . implode( ',', $missing_in_en ) . ')' );
check( array() === $missing_in_pt, 'every EN key exists in PT (' . implode( ',', $missing_in_pt ) . ')' );

$empty = array();
foreach ( $en as $k => $v ) {
	if ( '' === (string) $v ) {
		$empty[] = $k;
	}
}
check( array() === $empty, 'no empty EN strings (' . implode( ',', $empty ) . ')' );
check( $pt['label'] !== $en['label'], 'PT and EN diverge (partner label)' );
check( 'Parceria · Publicidade' === $pt['label'], 'PT label is the canonical wording' );

$labels = Brokers::product_labels( $en );
check( isset( $labels['stocks'], $labels['etf'], $labels['crypto'], $labels['interest'] ), 'product map parses' );
check( 'Ações' === Brokers::product_labels( $pt )['stocks'], 'PT product labels localized' );

echo "\nStars (visual only)\n";
check( '★★★★☆ ' === Brokers::stars( 4.0 ) . ' ', 'four stars at 4.0' );
check( '★★★★★' === Brokers::stars( 4.8 ), 'rounds up at 4.8' );
check( '☆☆☆☆☆' === Brokers::stars( 0.0 ), 'zero stars at 0' );

echo "\n/go/ links\n";

$active   = array( 'slug' => 'xtb', 'affiliate' => true );
$inactive = array( 'slug' => 'degiro', 'affiliate' => false );

$a = Brokers::go_link( $active, 'compare' );
$i = Brokers::go_link( $inactive, 'review' );

check( 'sponsored nofollow noopener' === $a['rel'], 'active deal → rel sponsored nofollow' );
check( 'nofollow noopener' === $i['rel'], 'no deal → rel nofollow (no sponsored)' );
check( false !== strpos( $a['href'], '/go/xtb/' ), 'href goes through /go/' );
check( false !== strpos( $a['href'], 'loc=compare' ), 'href carries the loc breakdown' );
check( false === strpos( $a['href'], 'partners' ), 'affiliate URL never in the href' );

echo "\nCanonical disclosure texts\n";

check( str_contains( Disclaimer::affiliate( 'pt' ), 'links de afiliado' ), 'PT affiliate disclosure present' );
check( str_contains( Disclaimer::affiliate( 'en' ), 'affiliate links' ), 'EN affiliate disclosure present' );
check( Disclaimer::affiliate( 'pt' ) !== Disclaimer::affiliate( 'en' ), 'disclosures diverge by language' );
check( str_contains( Disclaimer::cfd_risk( 'pt', '76' ), '76%' ), 'PT CFD warning carries the provider %' );
check( str_contains( Disclaimer::cfd_risk( 'en', '74' ), '74%' ), 'EN CFD warning carries the provider %' );
check( ! str_contains( Disclaimer::cfd_risk( 'en', '' ), '%' ) && str_contains( Disclaimer::cfd_risk( 'en', '' ), 'majority' ), 'empty % → generic ESMA wording, no broken number' );
check( ! str_contains( Disclaimer::cfd_risk( 'pt', '' ), '%' ) && str_contains( Disclaimer::cfd_risk( 'pt', '' ), 'maioria' ), 'empty % → generic PT wording' );
check( 1 === preg_match( '/^\d+\.\d+\.\d+$/', Disclaimer::AFFILIATE_VERSION ), 'AFFILIATE_VERSION is semver (audit trail)' );

echo "\nCompact disclosure (single-review page)\n";

// Extra shims for the disclosure renderers (page lookup falls back to home_url).
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * @param string $path      Path.
	 * @param mixed  $output    Output type.
	 * @param string $post_type Post type.
	 * @return null
	 */
	function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
		return null;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
}

$compact_en = Brokers::disclosure_compact_html( 'en' );
$compact_pt = Brokers::disclosure_compact_html( 'pt' );

check( str_contains( $compact_en, 'hti-bk__disclosure--compact' ), 'compact variant carries its modifier class' );
check( str_contains( $compact_en, 'hti-bk__disclosure' ), 'compact variant keeps the base disclosure class' );
check( str_contains( $compact_en, 'Partner · Ad' ), 'compact EN carries the label' );
check( str_contains( $compact_pt, 'Parceria · Publicidade' ), 'compact PT carries the label' );
check( str_contains( $compact_en, 'affiliate links' ), 'compact EN carries the CANONICAL affiliate disclosure (never bespoke)' );
check( str_contains( $compact_pt, 'links de afiliado' ), 'compact PT carries the canonical affiliate disclosure' );
check( str_contains( $compact_en, 'How we make money' ), 'compact EN links the methodology page' );
check( str_contains( $compact_pt, 'Como ganhamos dinheiro' ), 'compact PT links the methodology page' );

/* ---------------------------------------------------------------------------
 * The text that makes this section legal has to be readable.
 *
 * CLAUDE.md invariant 4 lets the broker section exist on the condition that it
 * is labelled "Parceria · Publicidade" with the affiliate disclosure on the
 * page, and ESMA requires the CFD risk warning. All five of those strings were
 * set between 0.68rem and 0.78rem — 10.9px to 12.5px — which made the label
 * the smallest text on a page whose entire compliance argument is that a
 * reader can see it.
 *
 * A floor, checked in the source rather than in a browser: this file needs no
 * rendering, so it cannot rot when the render path changes.
 * ------------------------------------------------------------------------ */

$bk_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/brokers.css' );
$bk_css = (string) preg_replace( '#/\*.*?\*/#s', '', $bk_css );

$too_small = array();
if ( preg_match_all( '/(\.[A-Za-z0-9_-]+)[^{}]*\{([^}]*)\}/', $bk_css, $rules, PREG_SET_ORDER ) ) {
	foreach ( $rules as $rule ) {
		if ( preg_match( '/font-size:\s*([0-9.]+)rem/', $rule[2], $fs ) && (float) $fs[1] < 0.8 ) {
			$too_small[] = $rule[1] . ' at ' . $fs[1] . 'rem (' . round( (float) $fs[1] * 16, 1 ) . 'px)';
		}
	}
}
check(
	array() === $too_small,
	'no broker rule sets text below 0.8rem/12.8px (' . ( $too_small ? implode( '; ', $too_small ) : 'none' ) . ')'
);

// The disclosure link must be able to wrap. A nowrap link inside the box that
// carries the affiliate disclosure is a link that pushes it off a narrow screen.
check(
	! preg_match( '/\.hti-bk__disclosure a\s*\{[^}]*white-space:\s*nowrap/', $bk_css ),
	'the disclosure link is allowed to wrap'
);

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
