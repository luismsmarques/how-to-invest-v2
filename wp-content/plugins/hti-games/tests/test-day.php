<?php
/**
 * The day boundary, checked as arithmetic.
 *
 * A game with one run per day lives or dies on where the day starts. The
 * boundary is 18:30:00 UTC (00:00 IST) and the interesting cases are the
 * second before it, the second of it, and the month and year crossings — the
 * places an off-by-one hands somebody a second run or eats one.
 *
 *   php wp-content/plugins/hti-games/tests/test-day.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-day.php';

use HTI\Games\Day;

/**
 * Shorthand for a UTC timestamp.
 *
 * @param string $iso ISO 8601 in UTC.
 */
function ts( string $iso ): int {
	return (int) strtotime( $iso . ' UTC' );
}

echo "The IST boundary is 18:30 UTC\n";
hti_games_check( '2026-08-30' === Day::key( ts( '2026-08-30 18:29:59' ) ), 'one second before 18:30 UTC is still the 30th' );
hti_games_check( '2026-08-31' === Day::key( ts( '2026-08-30 18:30:00' ) ), '18:30:00 UTC flips to the 31st' );
hti_games_check( '2026-08-30' === Day::key( ts( '2026-08-30 00:00:00' ) ), 'UTC midnight is mid-day in IST, still the 30th' );

echo "\nMonth and year crossings\n";
hti_games_check( '2026-09-01' === Day::key( ts( '2026-08-31 18:30:00' ) ), 'the month rolls over at the IST boundary, not the UTC one' );
hti_games_check( '2027-01-01' === Day::key( ts( '2026-12-31 18:30:00' ) ), 'so does the year' );
hti_games_check( '2028-02-29' === Day::key( ts( '2028-02-28 18:30:00' ) ), 'a leap day is a real day' );

echo "\nThe day index is monotonic and stable\n";
$a = Day::index( '2026-08-30' );
$b = Day::index( '2026-08-31' );
hti_games_check( $b === $a + 1, 'consecutive days are consecutive indexes' );
hti_games_check( Day::index( '2026-08-30' ) === $a, 'the same key always gives the same index' );
hti_games_check( Day::index( '2027-01-01' ) > $b, 'the index never resets at a year boundary' );
hti_games_check( 0 === Day::index( 'not-a-date' ), 'an unparseable key yields 0 rather than a warning' );

echo "\nCountdown to the next reset\n";
hti_games_check( 1 === Day::seconds_until_reset( ts( '2026-08-30 18:29:59' ) ), 'one second before the reset, one second remains' );
hti_games_check( 86400 === Day::seconds_until_reset( ts( '2026-08-30 18:30:00' ) ), 'at the reset a whole day is ahead' );
hti_games_check( Day::seconds_until_reset( ts( '2026-08-30 06:30:00' ) ) === 12 * 3600, 'twelve hours before the reset, twelve hours remain' );

echo "\nDay keys arriving from the open web are validated, not trusted\n";
hti_games_check( Day::valid( '2026-08-30' ), 'a real date is valid' );
hti_games_check( ! Day::valid( '2026-02-30' ), 'the 30th of February is not' );
hti_games_check( ! Day::valid( '2026-8-30' ), 'an unpadded month is rejected' );
hti_games_check( ! Day::valid( "2026-08-30' OR 1=1" ), 'nor is anything with something appended' );
hti_games_check( ! Day::valid( '' ), 'nor is an empty string' );

echo "\nThe offset is filterable\n";
add_filter( 'hti_games_day_offset', fn() => 0 );
hti_games_check( 0 === Day::offset(), 'the filter can move the boundary' );
hti_games_check( '2026-08-30' === Day::key( ts( '2026-08-30 18:30:00' ) ), 'at offset 0 the day key is just the UTC day' );

hti_games_done();
