<?php
/**
 * Repeatable test-suite runner for hti-forex.
 *
 * Runs every tests/test-*.php plus the Node math test, and aggregates the
 * result. Exits non-zero if any file fails — so it can gate CI.
 *
 *   php wp-content/plugins/hti-forex/tests/run.php
 *
 * @package HTI_Forex
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

// Optional Node test (skipped cleanly if node is unavailable).
$mjs = $dir . '/test-forex-core.mjs';
if ( is_file( $mjs ) ) {
	$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
	if ( '' !== $node ) {
		$code = 0;
		passthru( 'node ' . escapeshellarg( $mjs ), $code );
		if ( 0 !== $code ) {
			$failed[] = basename( $mjs );
		}
	} else {
		echo "\n(skipping " . basename( $mjs ) . " — node not found)\n";
	}
}

echo "\n==================================================\n";
if ( $failed ) {
	echo 'HTI-FOREX SUITE FAILED: ' . implode( ', ', $failed ) . "\n";
	exit( 1 );
}
echo "HTI-FOREX SUITE PASSED — all test files green.\n";
exit( 0 );
