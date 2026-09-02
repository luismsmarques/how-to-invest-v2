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
	 * Option holding the last uncatchable fatal recorded by the watch.
	 */
	private const FATAL_OPTION = 'rssai_last_fatal';

	/**
	 * Context label while a fatal watch is armed ('' = not watching).
	 */
	private static string $watching = '';

	/**
	 * Arm a shutdown watch: if PHP dies fatally while $context is running —
	 * out of memory and the execution limit are the usual killers, and neither
	 * is catchable — the error is recorded where the admin can see it. Without
	 * this an uncatchable death leaves only WordPress's anonymous
	 * critical-error page and an empty plugin log.
	 *
	 * @param string $context Short label for what is running, e.g. 'Group now'.
	 */
	public static function watch_fatals( string $context ): void {
		if ( '' === self::$watching ) {
			register_shutdown_function( array( __CLASS__, 'record_fatal' ) );
		}
		self::$watching = $context;
	}

	/**
	 * Disarm the watch after the guarded code finished (or failed catchably).
	 */
	public static function unwatch_fatals(): void {
		self::$watching = '';
	}

	/**
	 * Shutdown callback: record the fatal that ended this request, if any.
	 * Runs after the fatal unwound the stack, so even an out-of-memory death
	 * usually leaves enough room to write two options.
	 */
	public static function record_fatal(): void {
		if ( '' === self::$watching ) {
			return;
		}
		$msg = self::format_fatal( error_get_last(), self::$watching );
		if ( null === $msg ) {
			return;
		}
		// The tiny dedicated option first — appending to the ring buffer costs
		// more memory than a request that just died of OOM may have left.
		update_option(
			self::FATAL_OPTION,
			array(
				't'   => current_time( 'mysql' ),
				'msg' => $msg,
			),
			false
		);
		self::log( 'fatal', $msg );
	}

	/**
	 * One human-readable line for a fatal error, or null when the last error
	 * is absent or not fatal. Pure; testable.
	 *
	 * @param array<string,mixed>|null $error   error_get_last() result.
	 * @param string                   $context Watch label.
	 */
	public static function format_fatal( ?array $error, string $context ): ?string {
		if ( ! is_array( $error ) ) {
			return null;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal, true ) ) {
			return null;
		}
		return sprintf(
			'%s died: %s in %s:%d',
			$context,
			(string) ( $error['message'] ?? '' ),
			basename( (string) ( $error['file'] ?? '' ) ),
			(int) ( $error['line'] ?? 0 )
		);
	}

	/**
	 * The last recorded uncatchable fatal ({t, msg}), or null when none.
	 *
	 * @return array{t:string,msg:string}|null
	 */
	public static function last_fatal(): ?array {
		$data = get_option( self::FATAL_OPTION, null );
		return is_array( $data ) && isset( $data['msg'] ) ? $data : null;
	}

	/**
	 * Forget the recorded fatal (a later run completed cleanly).
	 */
	public static function clear_last_fatal(): void {
		delete_option( self::FATAL_OPTION );
	}

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
