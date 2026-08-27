<?php
/**
 * /forex/go/{slot} — the outbound partner redirector for the forex section.
 *
 * Offline material (the cheat sheet PDF, and anything printed or emailed
 * later) cannot carry the affiliate URL itself: a PDF lives forever on the
 * reader's disk, so a deal change would leave every downloaded copy pointing
 * somewhere wrong, with no way to fix it. Those links point here instead, and
 * the destination is resolved at click time from the settings.
 *
 * It also solves what a PDF cannot do: forex.js appends the affiliate sub-id
 * in the browser, which is impossible in a document with no JavaScript. Here
 * the sub-id is appended server-side, so each placement is attributable in the
 * affiliate panel.
 *
 * Mirrors hti-engine's Broker_Go (rewrite → query var → template_redirect),
 * with one deliberate difference: this route NEVER 404s. With the CTA switched
 * off it falls back to the /forex/ hub, because a printed link that dead-ends
 * is worse than one that lands on the tools.
 *
 * Always 302 (deals change), never indexed, never cached. Zero PII, zero
 * cookies — the same discipline as the metrics beacon.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the /forex/go/{slot} outbound redirect.
 */
class Go {

	/**
	 * Query var carrying the placement slot.
	 */
	private const QUERY_VAR = 'hti_fx_go';

	/**
	 * Option holding the VERSION the rewrite rules were last flushed for.
	 */
	private const OPTION_REWRITES = 'hti_forex_rewrites';

	/**
	 * Max length of a placement slot (kept short: it travels as the affiliate
	 * sub-id and some networks truncate long values).
	 */
	private const SLOT_MAX = 32;

	/**
	 * Wire up the rewrite rule, query var, redirect and robots exclusion.
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
	 * The route's regex (also unit-tested). The captured segment is the
	 * placement the click came from — the partner itself is whatever the
	 * settings currently point at.
	 */
	public static function pattern(): string {
		return '^forex/go/([a-z0-9\-]{1,' . self::SLOT_MAX . '})/?$';
	}

	/**
	 * Register the rewrite for /forex/go/{slot}. The deploy never reactivates
	 * plugins, so the rules are flushed once per plugin version — without it
	 * the route would 404 on a live site until someone re-saved permalinks.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( self::pattern(), 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );

		if ( VERSION !== (string) get_option( self::OPTION_REWRITES, '' ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITES, VERSION, false );
		}
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
	 * Build the public URL for one placement — what offline material prints.
	 *
	 * @param string $slot Placement id (e.g. 'cheatsheet').
	 */
	public static function url( string $slot ): string {
		return home_url( '/forex/go/' . self::slot( $slot ) . '/' );
	}

	/**
	 * Normalize a placement slot. Pure (unit-tested): lowercase, [a-z0-9-],
	 * length-capped; '' when nothing usable remains.
	 *
	 * @param string $raw Raw slot from the URL or a caller.
	 */
	public static function slot( string $raw ): string {
		$slot = strtolower( trim( $raw ) );
		$slot = (string) preg_replace( '/[^a-z0-9\-]/', '', $slot );
		return substr( $slot, 0, self::SLOT_MAX );
	}

	/**
	 * Pick the destination for a click. Pure (unit-tested).
	 *
	 * The partner URL only while the CTA is enabled and https (Settings has
	 * already cleared anything else); the sub-id parameter carries the
	 * placement so the affiliate panel can tell the PDF from the tool pages.
	 * With no usable partner URL the caller sends the visitor to the hub.
	 *
	 * @param array<string,mixed> $settings Settings::settings() array.
	 * @param string              $slot     Normalized placement slot.
	 * @return string Partner URL with the sub-id, or '' for the hub fallback.
	 */
	public static function destination( array $settings, string $slot ): string {
		if ( empty( $settings['cta_enabled'] ) ) {
			return '';
		}

		$url = (string) ( $settings['cta_url'] ?? '' );
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return '';
		}
		if ( '' === $slot ) {
			return $url;
		}

		$param = (string) ( $settings['sub_param'] ?? '' );
		if ( '' === $param ) {
			return $url;
		}

		$glue = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $glue . rawurlencode( $param ) . '=' . rawurlencode( $slot );
	}

	/**
	 * Never canonical-redirect a /forex/go/ request.
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
	 * @param string $output    Current robots.txt body.
	 * @param bool   $is_public Whether the site is public.
	 */
	public static function robots( $output, $is_public ) {
		if ( $is_public ) {
			$output .= "\n# Forex partner redirector — not for crawling.\nUser-agent: *\nDisallow: /forex/go/\n";
		}
		return $output;
	}

	/**
	 * Count the click and redirect. The redirect always happens; only the
	 * counting is rate-limited (an abuser can waste a counter, never block a
	 * visitor).
	 */
	public static function maybe_redirect(): void {
		$slot = self::slot( (string) get_query_var( self::QUERY_VAR ) );
		if ( '' === $slot ) {
			return;
		}

		$destination = self::destination( Settings::settings(), $slot );
		$fallback    = '' === $destination;
		if ( $fallback ) {
			$destination = home_url( '/forex/' );
		}

		if ( ! $fallback && class_exists( '\\HTI\\Engine\\Metrics' ) ) {
			$limited = class_exists( '\\HTI\\Engine\\RateLimit' ) && \HTI\Engine\RateLimit::exceeded( 'event' );
			if ( ! $limited ) {
				\HTI\Engine\Metrics::bump( 'cta_click', array( 'location' => 'forex_go_' . $slot ) );
			}
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external partner destination by design; validated https by Settings.
		exit;
	}
}
