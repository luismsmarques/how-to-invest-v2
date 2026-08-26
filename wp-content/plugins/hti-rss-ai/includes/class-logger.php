<?php
/**
 * Lightweight activity log (capped, no PII).
 *
 * Stored in a single autoload-off option as a ring buffer of the last entries.
 * Records pipeline events (fetch summaries, generation results, errors) — never
 * personal data.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only, size-capped logger.
 */
class Logger {

	private const OPTION = 'rssai_logs';
	private const MAX     = 100;

	/**
	 * Append a log entry.
	 *
	 * @param string $type    Short type tag (fetch, generate, error…).
	 * @param string $message Human-readable message (no PII).
	 */
	public static function log( string $type, string $message ): void {
		$logs   = (array) get_option( self::OPTION, array() );
		$logs[] = array(
			't'    => current_time( 'mysql' ),
			'type' => sanitize_key( $type ),
			'msg'  => sanitize_text_field( $message ),
		);
		if ( count( $logs ) > self::MAX ) {
			$logs = array_slice( $logs, -self::MAX );
		}
		update_option( self::OPTION, $logs, false );
	}

	/**
	 * Entries, most recent first.
	 *
	 * @return array<int,array{t:string,type:string,msg:string}>
	 */
	public static function all(): array {
		return array_reverse( (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Clear the log.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Drop log entries older than N days. Returns how many were removed.
	 *
	 * @param int $days Retention window in days.
	 */
	public static function prune( int $days ): int {
		$logs = (array) get_option( self::OPTION, array() );
		if ( ! $logs ) {
			return 0;
		}
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		$kept   = array();
		foreach ( $logs as $entry ) {
			$ts = isset( $entry['t'] ) ? strtotime( (string) $entry['t'] ) : false;
			if ( false === $ts || $ts >= $cutoff ) {
				$kept[] = $entry;
			}
		}
		$removed = count( $logs ) - count( $kept );
		if ( $removed > 0 ) {
			update_option( self::OPTION, $kept, false );
		}
		return $removed;
	}
}
