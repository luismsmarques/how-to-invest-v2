<?php
/**
 * Per-IP rate limiting for the public endpoints (security hardening).
 *
 * Blunts abuse of the public routes: mass profile generation (which costs
 * Gemini calls), account spam and login brute-force. Two properties matter and
 * both are enforced here:
 *
 * 1. Atomic counting — the increment must not lose writes under concurrency,
 *    or a burst of parallel requests slips past the cap. Uses an atomic
 *    `wp_cache_incr` when a persistent object cache is present, and a MySQL
 *    named lock (`GET_LOCK`) to serialise the transient read-modify-write
 *    otherwise.
 * 2. A trustworthy client IP behind a CDN/proxy — `REMOTE_ADDR` is the proxy's
 *    edge IP when the site is fronted by Cloudflare, which would collapse every
 *    visitor into one shared bucket (self-DoS) and make per-user limits
 *    meaningless. We read the forwarded client IP ONLY when the request
 *    actually arrived from a trusted proxy (validated against Cloudflare's
 *    published ranges, extendable via `hti_trusted_proxies`), so the header
 *    cannot be spoofed by a direct attacker.
 *
 * Limits are filterable via `hti_rate_limits`. Only `md5(action|IP)` is stored,
 * never the raw IP (GDPR).
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
	 * Object-cache group for the atomic counters.
	 */
	private const GROUP = 'hti_rl';

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
				'recommend'    => array( 15, 600 ),
				'register'     => array( 5, 3600 ),
				'login'        => array( 10, 900 ),
				'contact'      => array( 5, 3600 ),
				'feedback'     => array( 5, 3600 ),
				'email_result' => array( 10, 3600 ),
				'subscribe'    => array( 5, 3600 ),
				'change_email' => array( 5, 3600 ),
				'recover'      => array( 5, 3600 ),
				'event'        => array( 300, 600 ),
			)
		);
	}

	/**
	 * Whether the current client has exceeded the limit for an action. Counts
	 * this attempt. Atomic under concurrency.
	 *
	 * @param string $action Action key (must exist in the limits table).
	 */
	public static function exceeded( string $action ): bool {
		$limits = self::limits();
		if ( ! isset( $limits[ $action ] ) ) {
			return false;
		}
		list( $max, $window ) = $limits[ $action ];
		$slug = md5( $action . '|' . self::client_ip() );

		// Fast path: a persistent object cache gives us an atomic increment.
		if ( wp_using_ext_object_cache() ) {
			$key   = 'rl_' . $slug;
			$count = wp_cache_incr( $key, 1, self::GROUP );
			if ( false === $count ) {
				// Key absent/expired: (re)create it for this window.
				wp_cache_add( $key, 1, self::GROUP, $window );
				$count = 1;
			}
			return (int) $count > $max;
		}

		// Fallback: serialise the transient read-modify-write with a DB lock so
		// concurrent requests for the same key can't both read the stale count.
		return self::exceeded_locked( $slug, $max, $window );
	}

	/**
	 * Transient-backed counter guarded by a MySQL named lock. Fails open on the
	 * lock (degrades to the old racy behaviour) rather than blocking traffic.
	 *
	 * @param string $slug   md5(action|ip).
	 * @param int    $max    Max requests in the window.
	 * @param int    $window Window length in seconds.
	 */
	private static function exceeded_locked( string $slug, int $max, int $window ): bool {
		global $wpdb;
		$tkey = 'hti_rl_' . $slug;
		$lock = 'htirl_' . $slug; // <= 64 chars (GET_LOCK limit).

		$have_lock = false;
		if ( $wpdb instanceof \wpdb ) {
			$got       = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) );
			$have_lock = ( '1' === (string) $got );
		}

		$count = (int) get_transient( $tkey );
		$over  = $count >= $max;
		if ( ! $over ) {
			set_transient( $tkey, $count + 1, $window );
		}

		if ( $have_lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $over;
	}

	/**
	 * The resolved client IP. Public so other components (e.g. security-alert
	 * emails) share one trustworthy source instead of re-reading REMOTE_ADDR.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		$ip     = $remote;

		// Only trust a forwarded header when the request came from a proxy we
		// trust — otherwise the header is attacker-controlled and spoofable.
		if ( '' !== $remote && self::from_trusted_proxy( $remote ) ) {
			$forwarded = self::forwarded_client_ip();
			if ( '' !== $forwarded ) {
				$ip = $forwarded;
			}
		}

		/**
		 * Final override of the resolved client IP.
		 *
		 * @param string $ip     Resolved IP.
		 * @param string $remote Raw REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'hti_client_ip', $ip, $remote );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : ( '' !== $remote ? $remote : '0.0.0.0' );
	}

	/**
	 * The client IP as reported by a trusted proxy: Cloudflare's dedicated
	 * header first, then the right-most non-proxy hop of X-Forwarded-For.
	 */
	private static function forwarded_client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			// Walk from the right (closest proxy) and return the first hop that
			// is a valid IP and is NOT itself a trusted proxy — that's the real
			// client. Anything a direct attacker prepends stays to the left of a
			// trusted hop and is ignored.
			foreach ( array_reverse( $parts ) as $hop ) {
				if ( filter_var( $hop, FILTER_VALIDATE_IP ) && ! self::from_trusted_proxy( $hop ) ) {
					return $hop;
				}
			}
		}
		return '';
	}

	/**
	 * Whether an IP belongs to a trusted proxy (Cloudflare ranges by default,
	 * extendable via the `hti_trusted_proxies` filter with extra CIDRs).
	 *
	 * @param string $ip Candidate IP.
	 */
	private static function from_trusted_proxy( string $ip ): bool {
		$ranges = array_merge( self::cloudflare_ranges(), (array) apply_filters( 'hti_trusted_proxies', array() ) );
		foreach ( $ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cloudflare's published edge ranges (IPv4 + IPv6). Filterable so the list
	 * can be refreshed without a code change.
	 *
	 * @return array<int,string>
	 */
	private static function cloudflare_ranges(): array {
		return (array) apply_filters(
			'hti_cloudflare_ranges',
			array(
				'173.245.48.0/20',
				'103.21.244.0/22',
				'103.22.200.0/22',
				'103.31.4.0/22',
				'141.101.64.0/18',
				'108.162.192.0/18',
				'190.93.240.0/20',
				'188.114.96.0/20',
				'197.234.240.0/22',
				'198.41.128.0/17',
				'162.158.0.0/15',
				'104.16.0.0/13',
				'104.24.0.0/14',
				'172.64.0.0/13',
				'131.0.72.0/22',
				'2400:cb00::/32',
				'2606:4700::/32',
				'2803:f800::/32',
				'2405:b500::/32',
				'2405:8100::/32',
				'2a06:98c0::/29',
				'2c0f:f248::/32',
			)
		);
	}

	/**
	 * Whether an IP (v4 or v6) falls inside a CIDR range.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR (e.g. 104.16.0.0/13).
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits    = (int) $bits;
		$ip_bin  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input → false, handled.
		$sub_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false; // Family mismatch (v4 vs v6) or malformed.
		}
		$whole = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $whole > 0 && 0 !== strncmp( $ip_bin, $sub_bin, $whole ) ) {
			return false;
		}
		if ( $rem > 0 ) {
			$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
			if ( ( ord( $ip_bin[ $whole ] ) & ord( $mask ) ) !== ( ord( $sub_bin[ $whole ] ) & ord( $mask ) ) ) {
				return false;
			}
		}
		return true;
	}
}
