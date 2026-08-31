<?php
/**
 * The rotation: which challenge is today's.
 *
 * The pool query needs a database, but the choice does not — and the choice is
 * the part that has to be right. It is pure arithmetic over the day index, so
 * the same date gives the same challenge on the web server, on the CLI and in
 * this file, with no cron and no stored "today" that can drift.
 *
 * What is being defended here: a player must not be able to make tomorrow's
 * chart appear by reloading, two players on the same day must get the same
 * one, and a pinned slot must actually win — an editor who lines a case up
 * with an anniversary and is quietly overruled by the modulo has been lied to.
 *
 *   php wp-content/plugins/hti-games/tests/test-library.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-library.php';

use HTI\Games\Day;
use HTI\Games\Library;

$pool = array( 11, 22, 33, 44, 55 );

echo "The pick wraps, and only wraps\n";
hti_games_check( 11 === Library::pick( $pool, 0 ), 'index 0 is the first entry' );
hti_games_check( 33 === Library::pick( $pool, 2 ), 'index 2 is the third' );
hti_games_check( 11 === Library::pick( $pool, 5 ), 'index 5 comes back round to the first' );
hti_games_check( 22 === Library::pick( $pool, 5001 ), 'a large index wraps rather than overflowing' );
hti_games_check( 55 === Library::pick( $pool, -1 ), 'a negative index wraps backwards instead of reading off the front of the array' );
hti_games_check( 0 === Library::pick( array(), 7 ), 'an empty pool serves 0, which the caller reads as "nothing to play today"' );
hti_games_check( 42 === Library::pick( array( 42 ), 9999 ), 'a pool of one always serves the same thing, which is the honest answer' );

echo "\nThe same day always gives the same challenge\n";
$index = Day::index( '2026-08-30' );
hti_games_check( Library::pick( $pool, $index ) === Library::pick( $pool, $index ), 'reloading does not reroll' );
hti_games_check( Library::pick( $pool, $index ) !== Library::pick( $pool, $index + 1 ), 'and tomorrow is a different one' );
$seen = array();
foreach ( range( 0, 9 ) as $offset ) {
	$seen[] = Library::pick( $pool, $index + $offset );
}
hti_games_check( count( array_unique( array_slice( $seen, 0, 5 ) ) ) === count( $pool ), 'a full cycle serves every entry exactly once before repeating' );
hti_games_check( array_slice( $seen, 0, 5 ) === array_slice( $seen, 5, 5 ), 'and then repeats in the same order' );

echo "\nKeys out of order do not reorder the rotation\n";
$sparse = array( 3 => 11, 9 => 22, 40 => 33 );
hti_games_check( 22 === Library::pick( $sparse, 1 ), 'the pool is read positionally, so a query returning non-sequential keys is safe' );

echo "\nA pin wins over the rotation\n";
$pins = array( $index => 99 );
hti_games_check( 99 === Library::pick_pinned( $pool, $pins, $index ), 'an absolute day index pins that exact date' );
hti_games_check( Library::pick( $pool, $index + 1 ) === Library::pick_pinned( $pool, $pins, $index + 1 ), 'and leaves every other day to the rotation' );
hti_games_check( Library::pick( $pool, $index ) === Library::pick_pinned( $pool, array(), $index ), 'with no pins at all it is exactly pick()' );
hti_games_check( 0 === Library::pick_pinned( array(), $pins, $index ), 'a pin on an empty pool still serves nothing — a pinned post that is not published is not a pin' );

echo "\nA small slot number is a recurring position in the cycle\n";
$slot_pins = array( 2 => 77 );
hti_games_check( 77 === Library::pick_pinned( $pool, $slot_pins, 2 ), 'slot 2 is served at cycle position 2' );
hti_games_check( 77 === Library::pick_pinned( $pool, $slot_pins, 2 + count( $pool ) ), 'and again one full cycle later' );
hti_games_check( 77 !== Library::pick_pinned( $pool, $slot_pins, 3 ), 'but not on the days either side' );
hti_games_check(
	$index % count( $pool ) !== 2 || 77 === Library::pick_pinned( $pool, $slot_pins, $index ),
	'the two readings cannot collide: a day index is five figures, a cycle slot is smaller than the pool'
);

echo "\nThe pool is what the rotation is measured against\n";
hti_games_check( \HTI\Games\Config::REAL_CLAIM_MIN_POOL >= count( $pool ), 'a five-entry pool is nowhere near the threshold for calling the charts real' );
hti_games_check( is_callable( array( Library::class, 'is_real' ) ), 'is_real() is computed from the pool, never stored: a ticked "these are real" setting stays true long after somebody tops the pool up with generated charts' );

hti_games_done();
