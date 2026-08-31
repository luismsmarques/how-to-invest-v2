<?php
/**
 * Which day it is, for a game whose day starts at 00:00 IST.
 *
 * IST is UTC+05:30 with no daylight saving, so the IST calendar day is
 * literally the UTC day shifted by 19 800 seconds. That lets the whole thing
 * be one pure expression over gmdate() — the convention the rest of the
 * codebase already uses for day keys — instead of a timezone object, and it
 * means the rotation needs no cron: which challenge serves today is computed
 * on read from the date. Given WP-Cron is disabled in production, a game that
 * depended on a scheduled job to roll over would be a game that stops.
 *
 * 00:00 IST is 19:30 in Lisbon in summer, 18:30 in winter: an evening reset
 * for the Portuguese audience and a midnight one for the Indian traffic the
 * /forex/ section already brings.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Day keys and the deterministic day index. Pure.
 */
class Day {

	/**
	 * IST is UTC+05:30, fixed, no DST.
	 */
	public const OFFSET = 19800;

	/**
	 * Seconds in a day.
	 */
	private const DAY = 86400;

	/**
	 * The offset actually in use.
	 *
	 * Filterable so a future variant can run on a different clock, but moving
	 * it once shifts a boundary and can hand somebody a second run that day —
	 * which is why the guard against a second run is a UNIQUE index and not
	 * this function.
	 */
	public static function offset(): int {
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the seconds added to UTC before taking the day key.
			 *
			 * @param int $offset Default 19800 (IST, UTC+05:30).
			 */
			return (int) apply_filters( 'hti_games_day_offset', self::OFFSET );
		}
		return self::OFFSET;
	}

	/**
	 * The current game day as 'Y-m-d'.
	 *
	 * @param int|null $now Unix timestamp; defaults to now.
	 */
	public static function key( ?int $now = null ): string {
		return gmdate( 'Y-m-d', ( $now ?? time() ) + self::offset() );
	}

	/**
	 * A monotonically increasing integer for a day key — the rotation cursor.
	 *
	 * Days since the epoch, so it never resets and never collides, and the
	 * same key always yields the same index on any server.
	 *
	 * @param string $key Day key, 'Y-m-d'.
	 */
	public static function index( string $key ): int {
		$ts = strtotime( $key . ' 00:00:00 UTC' );
		if ( false === $ts ) {
			return 0;
		}
		return intdiv( $ts, self::DAY );
	}

	/**
	 * Seconds until the next reset — what the countdown on the result screen
	 * needs, and the TTL a cached challenge must never outlive.
	 *
	 * @param int|null $now Unix timestamp; defaults to now.
	 */
	public static function seconds_until_reset( ?int $now = null ): int {
		$shifted = ( $now ?? time() ) + self::offset();
		return self::DAY - ( $shifted % self::DAY );
	}

	/**
	 * Whether a day key is well-formed and real (rejects '2026-02-30').
	 *
	 * The client sends its day key back with a decision so a stale tab can be
	 * told the day moved; that value arrives from the open web and is only
	 * ever compared, never trusted.
	 *
	 * @param string $key Candidate key.
	 */
	public static function valid( string $key ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $key ) ) {
			return false;
		}
		return gmdate( 'Y-m-d', (int) strtotime( $key . ' 00:00:00 UTC' ) ) === $key;
	}
}
