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
	 * Our own query parameter carrying a campaign id through the redirect.
	 *
	 * The affiliate URL is never printed, so the only way a campaign can reach
	 * the network is if the click hands it to us here and we re-attach it on
	 * the way out. Without this the panel reports conversions with nothing to
	 * attribute them to — which is the same as not measuring at all.
	 */
	private const CID_PARAM = 'cid';

	/**
	 * Max length of a campaign id.
	 */
	private const CID_MAX = 64;

	/**
	 * Allowed `loc` values (where the click came from), for the breakdown.
	 * On-site surfaces first, then the off-site channels used by the managed
	 * links (Go_Links). Deliberately an allowlist: `loc` reaches us from the
	 * open web, and the per-location counter map is keyed by it.
	 */
	public const LOCATIONS = array(
		'compare',
		'review',
		'guide',
		'result',
		'menu',
		'telegram',
		'telegram_bot_demo',
		'telegram_bot_real',
		'newsletter',
		'youtube',
		'instagram',
		'facebook',
		'x',
		'tiktok',
		'whatsapp',
		'bio',
		'ads',
	);

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
	 * @param string $cid  Campaign id to carry to the network, or ''.
	 */
	public static function url( string $slug, string $loc = '', string $cid = '' ): string {
		$url = home_url( '/go/' . sanitize_key( $slug ) . '/' );
		if ( '' !== $loc && in_array( $loc, self::LOCATIONS, true ) ) {
			$url = add_query_arg( 'loc', $loc, $url );
		}
		$cid = self::cid( $cid );
		if ( '' !== $cid ) {
			$url = add_query_arg( self::CID_PARAM, $cid, $url );
		}
		return $url;
	}

	/**
	 * Normalize a campaign id. Pure (unit-tested).
	 *
	 * Deliberately narrow: this value is written into an outbound URL, so it
	 * is reduced to the characters every affiliate panel handles rather than
	 * trusted and escaped.
	 *
	 * @param string $raw Raw value from a caller or the query string.
	 */
	public static function cid( string $raw ): string {
		$cid = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
		return substr( $cid, 0, self::CID_MAX );
	}

	/**
	 * Attach the campaign id to an outbound URL. Pure (unit-tested).
	 *
	 * Nothing happens without both halves: a network that never told us what
	 * its tracking field is called gets the link exactly as before, which is
	 * the safe outcome — a wrong parameter name is silently ignored by the
	 * network and looks identical to a right one.
	 *
	 * @param string $url   Destination URL.
	 * @param string $param Network's sub-id parameter name ('' → unchanged).
	 * @param string $cid   Normalized campaign id ('' → unchanged).
	 */
	public static function with_sub_id( string $url, string $param, string $cid ): string {
		if ( '' === $url || '' === $param || '' === $cid ) {
			return $url;
		}
		$glue = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $glue . rawurlencode( $param ) . '=' . rawurlencode( $cid );
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

		$target      = self::destination( $slug );
		$destination = (string) $target['url'];
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, anonymous outbound link; no state changes.
		$cid         = self::cid( (string) ( $_GET[ self::CID_PARAM ] ?? '' ) );
		$destination = self::with_sub_id( $destination, (string) $target['sub_param'], $cid );

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external broker destination by design; validated https from curated meta.
		exit;
	}

	/**
	 * Resolve a slug to its outbound destination ('' → 404).
	 *
	 * Brokers win: a published broker post owns its slug, so a managed link
	 * can never shadow the editorial section (whose disclosure and CFD rules
	 * are what make those links compliant). Anything else falls through to the
	 * owner-managed links (Tools → Outbound links).
	 *
	 * @param string $slug Requested slug.
	 * @return array{url:string,sub_param:string}
	 */
	private static function destination( string $slug ): array {
		$post = get_page_by_path( $slug, OBJECT, 'broker' );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$affiliate = (string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'affiliate_url', true );
			$active    = '1' === (string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'affiliate_active', true );
			$url       = self::choose(
				$affiliate,
				(string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'official_url', true ),
				$active
			);

			// The sub-id belongs to the affiliate deal, not to the broker: on
			// the official-site fallback there is no panel to report into, and
			// tagging a plain outbound link would leak the campaign for
			// nothing.
			$param = ( $active && $url === $affiliate )
				? (string) get_post_meta( $post->ID, Broker_Admin::PREFIX . 'affiliate_sub_param', true )
				: '';

			return array(
				'url'       => $url,
				'sub_param' => $param,
			);
		}

		return array(
			'url'       => Go_Links::destination( $slug ),
			'sub_param' => '',
		);
	}
}
