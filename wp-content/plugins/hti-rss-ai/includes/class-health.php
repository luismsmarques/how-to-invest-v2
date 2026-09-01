<?php
/**
 * Which parts of the pipeline are currently failing, and since when.
 *
 * Image generation and embeddings are both best-effort by design: when they
 * fail the article is still written, so nothing breaks loudly. That is the
 * right behaviour and it is also how this plugin ran for weeks with its image
 * model retired and its embeddings dead, degrading quietly the whole time.
 * Graceful degradation without a way to see it is just a silent outage.
 *
 * One autoload-off option, one rolling 24-hour counter per subsystem. The
 * activity log is a 100-entry ring buffer and a busy day rolls it, so the
 * counters live here instead of being grepped back out of log text.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Per-subsystem failure counters.
 */
class Health {

	private const OPTION = 'rssai_health';
	private const WINDOW = DAY_IN_SECONDS;

	/**
	 * Subsystems tracked, in display order.
	 */
	public const SUBSYSTEMS = array( 'image', 'brief', 'embed' );

	/**
	 * Record one outcome.
	 *
	 * @param string $subsystem One of SUBSYSTEMS.
	 * @param bool   $ok        Whether the call succeeded.
	 * @param string $message   Error message when it did not.
	 */
	public static function record( string $subsystem, bool $ok, string $message = '' ): void {
		if ( ! in_array( $subsystem, self::SUBSYSTEMS, true ) ) {
			return;
		}
		$state = self::all();
		$now   = time();
		$entry = self::roll( $state[ $subsystem ] ?? array(), $now );

		if ( $ok ) {
			$entry['ok_24h']  = (int) $entry['ok_24h'] + 1;
			$entry['last_ok'] = $now;
		} else {
			$entry['fail_24h']   = (int) $entry['fail_24h'] + 1;
			$entry['last_fail']  = $now;
			$entry['last_error'] = self::trim_message( $message );
		}

		$state[ $subsystem ] = $entry;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The stored state, with every subsystem present and windows rolled.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$stored = (array) get_option( self::OPTION, array() );
		$now    = time();
		$out    = array();
		foreach ( self::SUBSYSTEMS as $subsystem ) {
			$out[ $subsystem ] = self::roll( (array) ( $stored[ $subsystem ] ?? array() ), $now );
		}
		return $out;
	}

	/**
	 * Reset the counters (the "I've fixed it, clear the warning" button).
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether anything is currently failing.
	 */
	public static function has_failures(): bool {
		foreach ( self::all() as $entry ) {
			if ( (int) $entry['fail_24h'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human label for a subsystem.
	 *
	 * @param string $subsystem Subsystem key.
	 */
	public static function label( string $subsystem ): string {
		switch ( $subsystem ) {
			case 'image':
				return __( 'Image generation', 'hti-rss-ai' );
			case 'brief':
				return __( 'Image brief (vision / text)', 'hti-rss-ai' );
			default:
				return __( 'Embeddings', 'hti-rss-ai' );
		}
	}

	/**
	 * Start a fresh 24-hour window when the stored one has expired.
	 *
	 * Pure: given an entry and a clock, returns the entry that should be used.
	 *
	 * @param array<string,mixed> $entry Stored entry (possibly empty).
	 * @param int                 $now   Current unix time.
	 * @return array<string,mixed>
	 */
	public static function roll( array $entry, int $now ): array {
		$entry = array_merge(
			array(
				'window'     => $now,
				'ok_24h'     => 0,
				'fail_24h'   => 0,
				'last_ok'    => 0,
				'last_fail'  => 0,
				'last_error' => '',
			),
			$entry
		);

		$window = (int) $entry['window'];
		if ( $window <= 0 || ( $now - $window ) >= self::WINDOW ) {
			$entry['window']   = $now;
			$entry['ok_24h']   = 0;
			$entry['fail_24h'] = 0;
		}

		$entry['ok_24h']   = max( 0, (int) $entry['ok_24h'] );
		$entry['fail_24h'] = max( 0, (int) $entry['fail_24h'] );
		return $entry;
	}

	/**
	 * Keep the stored error short and free of anything key-shaped.
	 *
	 * @param string $message Raw error message.
	 */
	public static function trim_message( string $message ): string {
		$message = (string) preg_replace( '/key=[A-Za-z0-9_\-]+/', 'key=***', $message );
		$message = (string) preg_replace( '/\s+/u', ' ', $message );
		$message = trim( $message );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, 240 );
		}
		return substr( $message, 0, 240 );
	}
}
