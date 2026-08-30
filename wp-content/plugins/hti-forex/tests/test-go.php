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

// --- Campaign-id passthrough ------------------------------------------------
// The tool pages used to write the campaign id straight onto the affiliate
// href in the browser. Now it arrives here as `cid` and is re-attached
// server-side, so the affiliate panel sees exactly what it saw before.
check( 'abc-123_XY' === Go::cid( 'abc-123_XY' ), 'a clean campaign id survives' );
check( 'drop' === Go::cid( 'dr op<>' ), 'anything outside [A-Za-z0-9_-] is stripped' );
check( 64 === strlen( Go::cid( str_repeat( 'a', 200 ) ) ), 'a campaign id is capped at 64 characters' );
check( '' === Go::cid( '' ), 'no campaign id stays empty' );

check(
	'https://partner.example.com/visit?clickid=camp42' === Go::destination( $on, 'position-size', 'camp42' ),
	'a campaign id takes the sub-id ahead of the placement'
);
check(
	'https://partner.example.com/visit?clickid=position-size' === Go::destination( $on, 'position-size', '' ),
	'without a campaign id the placement is still attributed'
);
check( 'https://partner.example.com/visit' === Go::destination( $on, '', '' ), 'no slot and no campaign id means no sub-id at all' );
check( '' === Go::destination( array_merge( $on, array( 'cta_enabled' => false ) ), 'position-size', 'camp42' ), 'the kill-switch beats a campaign id too' );

// The whole point of the redirector: a printed link must never carry the
// affiliate URL, so the destination is only ever resolved here, at click time.
check( ! str_contains( (string) file_get_contents( __DIR__ . '/../assets/pdf/src/cheat-sheet.html' ), 'pipaffiliates' ), 'the PDF source carries no affiliate URL' );

// --- Nothing else may reach cta_url -----------------------------------------
// CLAUDE.md invariant 4: outbound affiliate links only via our own redirector.
// cta_url is the destination this class resolves; a renderer that reads it
// puts a raw affiliate URL back into the page source, which is exactly the
// bug this route exists to prevent. So the setting is readable in precisely
// two places — the screen that stores it, and the redirector that follows it.
$readers = array();
foreach ( (array) glob( __DIR__ . '/../includes/*.php' ) as $file ) {
	if ( str_contains( (string) file_get_contents( $file ), "'cta_url'" ) ) {
		$readers[] = basename( $file );
	}
}
sort( $readers );
check(
	array( 'class-go.php', 'class-settings.php' ) === $readers,
	'only the settings screen and the redirector read cta_url (found: ' . implode( ', ', $readers ) . ')'
);

$tools = (string) file_get_contents( __DIR__ . '/../includes/class-tools.php' );
check( str_contains( $tools, 'Go::url( $cta[\'slot\'] )' ), 'the tool-page CTA links to our own /forex/go/ route' );

$js = (string) file_get_contents( __DIR__ . '/../assets/js/forex.js' );
check( ! str_contains( $js, 'cfg.subParam' ), 'the browser is never told the affiliate sub-id parameter' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
