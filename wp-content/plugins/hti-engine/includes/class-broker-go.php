<?php
/**
 * /go/{slug} — the affiliate outbound redirector (broker-affiliate skill).
 *
 * Every outbound broker link on the site points here instead of embedding the
 * destination, so:
 * - swapping an affiliate deal never requires touching (or purging) pages;
 * - the click is counted server-side (`broker_click`) — a client beacon would
 *   lose events to the navigation;
 * - the affiliate URL never appears in page HTML.
 *
 * Always a 302 (deals change), never indexed (X-Robots-Tag + robots.txt
 * Disallow), never cached. Destination: the affiliate URL while the deal is
 * active, otherwise the broker's official site. Zero PII, zero cookies — the
 * same discipline as the metrics beacon.
 *
 * Mirrors the Ads_Txt virtual-route pattern.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the /go/{slug} outbound redirect.
 */
class Broker_Go {

	/**
	 * Query var carrying the broker slug.
	 */
	private const QUERY_VAR = 'hti_go';

	/**
	 * Allowed `loc` values (where the click came from), for the breakdown.
	 */
	public const LOCATIONS = array( 'compare', 'review', 'guide', 'result', 'menu' );

	/**
	 * Wire up the rewrite rule, query var, renderer and robots exclusion.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		// Priority 0 so we run before core's redirect_canonical (priority 10).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'skip_canonical' ), 10, 2 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 10, 2 );
	}

	/**
	 * The route's regex (also unit-tested): lowercase slug only.
	 */
	public static function pattern(): string {
		return '^go/([a-z0-9\-]+)/?$';
	}

	/**
	 * Register the rewrite for /go/{slug}.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( self::pattern(), 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Expose the query var.
	 *
	 * @param array<int,string> $vars Registered query vars.
	 * @return array<int,string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Build a /go/ URL for a broker slug (+ optional click location).
	 *
	 * @param string $slug Broker (EN/default-language) slug.
	 * @param string $loc  One of self::LOCATIONS, or ''.
	 */
	public static function url( string $slug, string $loc = '' ): string {
		$url = home_url( '/go/' . sanitize_key( $slug ) . '/' );
		if ( '' !== $loc && in_array( $loc, self::LOCATIONS, true ) ) {
			$url = add_query_arg( 'loc', $loc, $url );
		}
		return $url;
	}

	/**
	 * Pick the destination for a click. Pure (unit-tested): the affiliate URL
	 * only while the deal is active and https; otherwise the official site;
	 * '' when nothing valid remains (the caller 404s).
	 *
	 * @param string $affiliate_url Stored affiliate URL ('' when none).
	 * @param string $official_url  Stored official-site URL.
	 * @param bool   $active        Whether the affiliate deal is active.
	 */
	public static function choose( string $affiliate_url, string $official_url, bool $active ): string {
		if ( $active && str_starts_with( $affiliate_url, 'https://' ) ) {
			return $affiliate_url;
		}
		if ( str_starts_with( $official_url, 'https://' ) ) {
			return $official_url;
		}
		return '';
	}

	/**
	 * Never canonical-redirect a /go/ request.
	 *
	 * @param string $redirect_url  The proposed canonical URL.
	 * @param string $requested_url The originally requested URL.
	 * @return string|false
	 */
	public static function skip_canonical( $redirect_url, $requested_url = '' ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Keep crawlers out of the redirector.
	 *
	 * @param string $output Current robots.txt body.
	 * @param bool   $is_public Whether the site is public.
	 */
	public static function robots( $output, $is_public ) {
		if ( $is_public ) {
			$output .= "\n# Affiliate redirector — not for crawling.\nUser-agent: *\nDisallow: /go/\n";
		}
		return $output;
	}

	/**
	 * Count the click and redirect. The redirect always happens; only the
	 * counting is rate-limited (an abuser can waste a counter, never block a
	 * visitor).
	 */
	public static function maybe_redirect(): void {
		$slug = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );
		if ( '' === $slug ) {
			return;
		}

		$destination = self::destination( $slug );
		if ( '' === $destination ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		if ( ! RateLimit::exceeded( 'event' ) ) {
			$loc = sanitize_key( (string) ( $_GET['loc'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, anonymous counter; no state changes.
			Metrics::bump(
				'broker_click',
				array(
					'broker'   => $slug,
					'location' => in_array( $loc, self::LOCATIONS, true ) ? $loc : '',
				)
			);
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external broker destination by design; validated https from curated meta.
		exit;
	}

	/**
	 * Resolve a broker slug to its outbound destination ('' → 404).
	 *
	 * @param string $slug Broker slug (default-language post name).
	 */
	private static function destination( string $slug ): string {
		$post = get_page_by_path( $slug, OBJECT, 'broker' );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		return self::choose(
			(string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'affiliate_url', true ),
			(string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'official_url', true ),
			'1' === (string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'affiliate_active', true )
		);
	}
}
