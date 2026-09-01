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

// The Node suites. The parity test puts the JS engines against the same fixture
// the PHP ones use; the responsive test puts the rendered pages in a real
// browser, which is the only way to ask whether anything overflows or whether a
// control is 44px once the box model has spoken. Both skip cleanly when their
// tool is absent, exactly as hti-forex does — CI proves the parity one actually
// ran with a separate explicit step.
foreach ( array( 'test-games-core.mjs', 'test-responsive.mjs' ) as $name ) {
	$mjs = $dir . '/' . $name;
	if ( ! is_file( $mjs ) ) {
		continue;
	}
	$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
	if ( '' === $node ) {
		echo "\n(skipping " . $name . " — node not found)\n";
		continue;
	}
	$code = 0;
	passthru( 'node ' . escapeshellarg( $mjs ), $code );
	if ( 0 !== $code ) {
		$failed[] = $name;
	}
}

echo "\n==================================================\n";
if ( $failed ) {
	echo 'HTI-GAMES SUITE FAILED: ' . implode( ', ', $failed ) . "\n";
	exit( 1 );
}
echo "HTI-GAMES SUITE PASSED — all test files green.\n";
exit( 0 );
