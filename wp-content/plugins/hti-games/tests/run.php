<?php
/**
 * Repeatable test-suite runner for hti-games.
 *
 * Runs every tests/test-*.php plus the Node parity test, and aggregates the
 * result. Exits non-zero if any file fails — so it can gate CI. Dropping a new
 * test-*.php into this directory is the entire registration step.
 *
 *   php wp-content/plugins/hti-games/tests/run.php
 *
 * @package HTI_Games
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

// The parity test: the JS engines against the same fixture the PHP ones use.
// Skipped cleanly when node is absent, exactly as hti-forex does — CI proves
// it actually ran with a separate explicit step.
$mjs = $dir . '/test-games-core.mjs';
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
	echo 'HTI-GAMES SUITE FAILED: ' . implode( ', ', $failed ) . "\n";
	exit( 1 );
}
echo "HTI-GAMES SUITE PASSED — all test files green.\n";
exit( 0 );
