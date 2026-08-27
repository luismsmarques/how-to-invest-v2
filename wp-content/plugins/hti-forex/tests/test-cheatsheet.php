<?php
/**
 * Cheat-sheet source guards.
 *
 * The PDF is generated from assets/pdf/src/cheat-sheet.html and then lives on
 * readers' disks forever, so the things that must never end up in it (a raw
 * affiliate URL) and the things that must always be in it (the ad label, the
 * risk warning, clickable links) are asserted here rather than trusted to a
 * careful edit.
 *
 *   php wp-content/plugins/hti-forex/tests/test-cheatsheet.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';

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

$src_path = __DIR__ . '/../assets/pdf/src/cheat-sheet.html';
$pdf_path = __DIR__ . '/../assets/pdf/hti-forex-lot-size-cheat-sheet.pdf';
$html     = (string) file_get_contents( $src_path );

check( '' !== $html, 'cheat-sheet source is readable' );
check( is_file( $pdf_path ), 'the generated PDF is committed beside its source' );

// --- No affiliate URL ever printed into a file ------------------------------
foreach ( array( 'pipaffiliates', 'clicks.', 'aff_', 'utm_', '?c=' ) as $needle ) {
	check( ! str_contains( $html, $needle ), "no raw affiliate/tracking marker in the source ({$needle})" );
}

// --- The partner block ------------------------------------------------------
check( str_contains( $html, 'class="partner"' ), 'the partner block is present' );
check( str_contains( $html, 'Partner &middot; Ad' ), 'the partner block is labelled as advertising' );
check( str_contains( $html, 'we may be paid' ), 'the partner block discloses the affiliate relationship' );
check( str_contains( $html, 'most retail accounts lose money' ), 'the partner block carries the CFD risk warning' );
check( str_contains( $html, 'https://howtoinvest.pro/forex/go/cheatsheet/' ), 'the partner link points at our own redirector' );

// --- Clickable links --------------------------------------------------------
$anchors = array();
preg_match_all( '/href="([^"]+)"/', $html, $anchors );
$hrefs = $anchors[1];

check( count( $hrefs ) >= 7, 'the sheet carries at least seven links' );
foreach (
	array(
		'https://howtoinvest.pro/forex/position-size-calculator/',
		'https://howtoinvest.pro/forex/pip-value-calculator/',
		'https://howtoinvest.pro/forex/profit-calculator/',
		'https://howtoinvest.pro/forex/xauusd-lot-size-calculator/',
		'https://howtoinvest.pro/forex/market-hours-ist/',
		'https://howtoinvest.pro/forex/',
	) as $url
) {
	check( in_array( $url, $hrefs, true ), "the calculators list links to {$url}" );
}
foreach ( $hrefs as $href ) {
	check( str_starts_with( $href, 'https://' ), "every link is https ({$href})" );
}

// The educational content comes first: the ad sits after the calculators.
check(
	strpos( $html, 'The free calculators' ) < strpos( $html, 'class="partner"' ),
	'the partner block sits after the educational content'
);

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
