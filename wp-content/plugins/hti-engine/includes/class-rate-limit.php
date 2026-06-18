<?php
/**
 * Simple per-IP rate limiting for the public endpoints (security hardening).
 *
 * Transient-backed sliding window. Used to blunt abuse of the public routes:
 * mass profile generation (which costs Gemini calls), account-spam and
 * login brute-force. Limits are filterable via `hti_rate_limits`; the client
 * IP source via `hti_client_ip` (e.g. to use a trusted proxy header).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Per-IP, per-action request throttle.
 */
class RateLimit {

	/**
	 * Default limits: action => [ max_requests, window_seconds ].
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function limits(): array {
		/**
		 * Filter the rate-limit table.
		 *
		 * @param array<string,array{0:int,1:int}> $limits action => [max, window_seconds].
		 */
		return (array) apply_filters(
			'hti_rate_limits',
			array(
				'recommend' => array( 15, 600 ),
				'register'  => array( 5, 3600 ),
				'login'     => array( 10, 900 ),
			)
		);
	}

	/**
	 * Whether the current client has exceeded the limit for an action.
	 * Counts the attempt when allowed; does not extend the window once blocked.
	 *
	 * @param string $action Action key (must exist in the limits table).
	 */
	public static function exceeded( string $action ): bool {
		$limits = self::limits();
		if ( ! isset( $limits[ $action ] ) ) {
			return false;
		}
		list( $max, $window ) = $limits[ $action ];

		$key   = self::key( $action );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return true;
		}

		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Transient key for an action + the current client IP.
	 *
	 * @param string $action Action key.
	 */
	private static function key( string $action ): string {
		return 'hti_rl_' . md5( $action . '|' . self::client_ip() );
	}

	/**
	 * Best-effort client IP (REMOTE_ADDR by default; filterable for proxies).
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the resolved client IP (e.g. to read CF-Connecting-IP behind Cloudflare).
		 *
		 * @param string $ip Resolved IP.
		 */
		return (string) apply_filters( 'hti_client_ip', $ip );
	}
}
