<?php
/**
 * Tests for the ads.txt virtual route. Guards that the served body is a valid
 * IAB ads.txt record (domain, publisher id, DIRECT|RESELLER[, cert]) and that
 * the line stays filterable via `hti_ads_txt`.
 *
 *   php wp-content/plugins/hti-engine/tests/test-ads-txt.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-ads-txt.php';

use HTI\Engine\Ads_Txt;

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
 * Whether every non-empty line is a valid ads.txt record.
 *
 * @param string $body ads.txt body.
 */
function ads_txt_valid( string $body ): bool {
	$lines = array_filter( array_map( 'trim', explode( "\n", $body ) ) );
	if ( ! $lines ) {
		return false;
	}
	foreach ( $lines as $line ) {
		if ( str_starts_with( $line, '#' ) ) {
			continue; // Comment line.
		}
		// domain, publisher-id, DIRECT|RESELLER[, cert-authority-id].
		if ( ! preg_match( '/^[^,]+,\s*[^,]+,\s*(DIRECT|RESELLER)(\s*,\s*\S+)?$/i', $line ) ) {
			return false;
		}
	}
	return true;
}

echo "\nAds_Txt — /ads.txt body\n";

$body = Ads_Txt::body();

check( is_string( $body ) && '' !== trim( $body ), 'body() returns a non-empty string' );
check( ads_txt_valid( $body ), 'default body is a valid ads.txt record' );
check( str_contains( $body, 'pub-5553650822418832' ), 'default body carries the AdSense publisher id' );
check( str_contains( strtoupper( $body ), 'DIRECT' ), 'default body declares a DIRECT relationship' );

// The line is filterable.
add_filter(
	'hti_ads_txt',
	static function () {
		return "google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0\ncustom.example, 123, RESELLER";
	}
);
$filtered = Ads_Txt::body();
check( str_contains( $filtered, 'custom.example' ), 'hti_ads_txt filter overrides the body' );
check( ads_txt_valid( $filtered ), 'a filtered multi-line body is still valid' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
