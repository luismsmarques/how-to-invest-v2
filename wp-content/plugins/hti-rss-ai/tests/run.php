<?php
/**
 * Repeatable test-suite runner for HTI RSS AI Feed.
 *
 * Runs every tests/test-*.php (grouping, fetcher, validator, extract-json,
 * image-client) as its own process and aggregates the result. Each test file
 * exits non-zero on failure (via rssai_done), so the suite exits non-zero if
 * any file fails — it can gate CI.
 *
 *   php wp-content/plugins/hti-rss-ai/tests/run.php
 *
 * @package HTI_RSS_AI
 */

$dir   = __DIR__;
$self  = basename( __FILE__ );
$files = glob( $dir . '/test-*.php' );
sort( $files );

$failed = array();

foreach ( $files as $file ) {
	if ( basename( $file ) === $self ) {
		continue;
	}
	$code = 0;
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ), $code );
	if ( 0 !== $code ) {
		$failed[] = basename( $file );
	}
}

echo "\n==================================================\n";
if ( $failed ) {
	echo 'SUITE FAILED: ' . implode( ', ', $failed ) . "\n";
	exit( 1 );
}
echo "SUITE PASSED — all test files green.\n";
exit( 0 );
