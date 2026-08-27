<?php
/**
 * /forex/go/{slot} redirector tests (pure, no WordPress).
 *
 *   php wp-content/plugins/hti-forex/tests/test-go.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-go.php';

use HTI\Forex\Go;
use HTI\Forex\Settings;

$passes   = 0;
$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Label.
 */
function check( bool $cond, string $label ): void {
	global $passes, $failures;
	if ( $cond ) {
		++$passes;
		echo "\033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "\033[31m✗\033[0m {$label}\n";
	}
}

// --- Slot normalization -----------------------------------------------------
check( 'cheatsheet' === Go::slot( 'cheatsheet' ), 'a plain slot survives' );
check( 'cheatsheet' === Go::slot( '  CheatSheet  ' ), 'slot is trimmed and lowercased' );
check( 'pdf-cover' === Go::slot( 'pdf-cover' ), 'hyphens are kept' );
check( 'abc' === Go::slot( 'a b/c' ), 'spaces and slashes are stripped' );
check( '' === Go::slot( '///' ), 'a slot with nothing usable becomes empty' );
check( 32 === strlen( Go::slot( str_repeat( 'a', 80 ) ) ), 'slot is length-capped at 32' );
check( 'script' === Go::slot( '<script>' ), 'markup characters are stripped from a slot' );
check( 0 === preg_match( '/[^a-z0-9\-]/', Go::slot( '"><img src=x onerror=1>' ) ), 'a hostile slot reduces to safe characters only' );

// --- Route pattern ----------------------------------------------------------
$pattern = '#' . str_replace( array( '^', '$' ), '', Go::pattern() ) . '#';
check( 1 === preg_match( $pattern, 'forex/go/cheatsheet/' ), 'route matches the trailing-slash form' );
check( 1 === preg_match( $pattern, 'forex/go/cheatsheet' ), 'route matches without the trailing slash' );
check( 0 === preg_match( $pattern, 'forex/go/Cheat Sheet/' ), 'route rejects spaces and capitals' );
check( 0 === preg_match( $pattern, 'forex/position-size-calculator/' ), 'route does not swallow the tool pages' );

// --- Destination ------------------------------------------------------------
$d  = Settings::defaults();
$on = array_merge(
	$d,
	array(
		'cta_enabled' => true,
		'cta_url'     => 'https://partner.example.com/visit',
		'sub_param'   => 'clickid',
	)
);

check(
	'https://partner.example.com/visit?clickid=cheatsheet' === Go::destination( $on, 'cheatsheet' ),
	'active CTA gets the placement as the affiliate sub-id'
);

$with_query = array_merge( $on, array( 'cta_url' => 'https://partner.example.com/c?c=123&l=en' ) );
check(
	'https://partner.example.com/c?c=123&l=en&clickid=cheatsheet' === Go::destination( $with_query, 'cheatsheet' ),
	'an existing query string is appended to, never broken'
);

$custom = array_merge( $on, array( 'sub_param' => 'sub1' ) );
check( str_contains( (string) Go::destination( $custom, 'pdf' ), 'sub1=pdf' ), 'the configured sub-id parameter is used' );

check( '' === Go::destination( array_merge( $on, array( 'cta_enabled' => false ) ), 'cheatsheet' ), 'kill-switch sends the click to the hub fallback' );
check( '' === Go::destination( array_merge( $on, array( 'cta_url' => '' ) ), 'cheatsheet' ), 'no URL means the hub fallback' );
check( '' === Go::destination( array_merge( $on, array( 'cta_url' => 'http://partner.example.com/x' ) ), 'cheatsheet' ), 'plain-http destination is never followed' );
check( 'https://partner.example.com/visit' === Go::destination( $on, '' ), 'an empty slot still redirects, without a sub-id' );

// The whole point of the redirector: a printed link must never carry the
// affiliate URL, so the destination is only ever resolved here, at click time.
check( ! str_contains( (string) file_get_contents( __DIR__ . '/../assets/pdf/src/cheat-sheet.html' ), 'pipaffiliates' ), 'the PDF source carries no affiliate URL' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
