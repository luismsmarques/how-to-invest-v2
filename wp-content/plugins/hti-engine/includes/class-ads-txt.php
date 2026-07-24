<?php
/**
 * ads.txt (Authorized Digital Sellers).
 *
 * Google AdSense requires an `ads.txt` file at the domain root declaring which
 * sellers may sell the site's ad inventory. The deploy only ships the plugin
 * and theme (never the web docroot), so a static file would never reach the
 * live root — instead we serve /ads.txt as a virtual route, mirroring the
 * News_Sitemap pattern.
 *
 * The publisher line is filterable via `hti_ads_txt` so extra sellers can be
 * added in code without editing this class.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the ads.txt file at /ads.txt.
 */
class Ads_Txt {

	/**
	 * Query var that triggers the ads.txt output.
	 */
	private const QUERY_VAR = 'hti_ads_txt';

	/**
	 * Public path of the file.
	 */
	private const PATH = 'ads.txt';

	/**
	 * Default AdSense authorized-sellers line.
	 */
	private const DEFAULT_LINE = 'google.com, pub-5553650822418832, DIRECT, f08c47fec0942fa0';

	/**
	 * Wire up the rewrite rule, query var and renderer.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Register the rewrite for /ads.txt.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( '^' . preg_quote( self::PATH, '/' ) . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
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
	 * Public URL of the file.
	 */
	public static function url(): string {
		return home_url( '/' . self::PATH );
	}

	/**
	 * The ads.txt body — one authorized-seller record per line. Filterable so
	 * additional sellers can be appended without touching this class.
	 */
	public static function body(): string {
		return (string) apply_filters( 'hti_ads_txt', self::DEFAULT_LINE );
	}

	/**
	 * Render ads.txt when the query var is set, then stop.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		echo self::body() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text ads.txt record(s).
		exit;
	}
}
