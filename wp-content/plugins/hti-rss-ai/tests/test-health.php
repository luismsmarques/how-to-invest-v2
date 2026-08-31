<?php
/**
 * Tests for Health's pure parts: the rolling 24-hour window, and the scrubbing
 * that keeps an API key out of a stored error message.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require dirname( __DIR__ ) . '/includes/class-health.php';

use HTI\RssAI\Health;

/* ------------------------------------------------------------------ roll() */

$now = 1_800_000_000;

// An unseen subsystem starts at zero, with every field present.
$fresh = Health::roll( array(), $now );
foreach ( array( 'window', 'ok_24h', 'fail_24h', 'last_ok', 'last_fail', 'last_error' ) as $key ) {
	rssai_ok( array_key_exists( $key, $fresh ), "roll fills in $key: $key" );
}
rssai_ok( 0 === $fresh['ok_24h'] && 0 === $fresh['fail_24h'], 'a fresh entry counts nothing' );
rssai_ok( $now === $fresh['window'], 'a fresh entry opens its window now' );

// Inside the window, counters survive.
$inside = Health::roll(
	array( 'window' => $now - 3600, 'ok_24h' => 5, 'fail_24h' => 2, 'last_error' => 'boom' ),
	$now
);
rssai_ok( 5 === $inside['ok_24h'] && 2 === $inside['fail_24h'], 'counters survive inside the window' );
rssai_ok( $now - 3600 === $inside['window'], 'the window is not moved while it is open' );
rssai_ok( 'boom' === $inside['last_error'], 'the last error survives the roll' );

// Exactly 24 hours old is expired — the window is closed, not half-open.
$edge = Health::roll( array( 'window' => $now - DAY_IN_SECONDS, 'fail_24h' => 9 ), $now );
rssai_ok( 0 === $edge['fail_24h'], 'a 24h-old window is rolled' );
rssai_ok( $now === $edge['window'], 'rolling opens a new window' );

// One second short of 24 hours still counts.
$almost = Health::roll( array( 'window' => $now - DAY_IN_SECONDS + 1, 'fail_24h' => 9 ), $now );
rssai_ok( 9 === $almost['fail_24h'], 'one second short of 24h still counts' );

// Older than the window: rolled, but the last error is kept — knowing what
// broke is still useful after the counter resets.
$old = Health::roll(
	array( 'window' => $now - ( 5 * DAY_IN_SECONDS ), 'ok_24h' => 3, 'fail_24h' => 40, 'last_error' => 'model not found' ),
	$now
);
rssai_ok( 0 === $old['ok_24h'] && 0 === $old['fail_24h'], 'a stale window resets both counters' );
rssai_ok( 'model not found' === $old['last_error'], 'the last error outlives the window' );

// Junk in the stored option must not produce negative or non-integer counters.
$junk = Health::roll( array( 'window' => 'yesterday', 'ok_24h' => -5, 'fail_24h' => '7' ), $now );
rssai_ok( 0 === $junk['ok_24h'], 'a negative counter is clamped to zero' );
rssai_ok( is_int( $junk['fail_24h'] ), 'counters come back as integers' );
rssai_ok( $now === $junk['window'], 'an unparseable window is reopened' );

/* --------------------------------------------------------- trim_message() */

$leaky = 'Request to https://generativelanguage.googleapis.com/v1beta/models/x:predict?key=AIzaSyREALKEY123 failed';
$safe  = Health::trim_message( $leaky );
rssai_ok( false === strpos( $safe, 'AIzaSyREALKEY123' ), 'an API key never reaches the stored message' );
rssai_ok( false !== strpos( $safe, 'key=***' ), 'the key is replaced, not just deleted' );

rssai_ok( 'a b c' === Health::trim_message( "a\n b\t\tc " ), 'whitespace is normalised' );
rssai_ok( strlen( Health::trim_message( str_repeat( 'x', 900 ) ) ) <= 240, 'long messages are capped' );
rssai_ok( '' === Health::trim_message( '   ' ), 'a blank message stays blank' );

/* ------------------------------------------------------------------ labels */

foreach ( Health::SUBSYSTEMS as $subsystem ) {
	rssai_ok( '' !== Health::label( $subsystem ), "subsystem $subsystem has a label: $subsystem" );
}
rssai_ok( in_array( 'image', Health::SUBSYSTEMS, true ), 'image generation is tracked' );
rssai_ok( in_array( 'embed', Health::SUBSYSTEMS, true ), 'embeddings are tracked' );

rssai_done( 'health' );
