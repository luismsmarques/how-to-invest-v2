<?php
/**
 * Google News XML sitemap.
 *
 * Google News uses a dedicated sitemap (the `news` namespace) that lists only
 * articles published in the last 48 hours. Each <url> declares its own
 * publication language, so a single sitemap covers both EN and PT — Google News
 * filters each edition by the per-article <news:language> tag.
 *
 * RankMath's News Sitemap and Yoast News SEO are paid add-ons, so this is built
 * in-house. Exposed at /news-sitemap.xml and advertised in robots.txt.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and serves the Google News sitemap for the `news` post type.
 */
class News_Sitemap {

	/**
	 * Query var that triggers the sitemap output.
	 */
	private const QUERY_VAR = 'hti_news_sitemap';

	/**
	 * Public path of the sitemap.
	 */
	private const PATH = 'news-sitemap.xml';

	/**
	 * Google News only indexes articles from the last 48 hours.
	 */
	private const WINDOW_HOURS = 48;

	/**
	 * Hard cap on URLs (Google News sitemaps allow up to 1000).
	 */
	private const MAX_URLS = 1000;

	/**
	 * Cache lifetime for the rendered XML (kept short — news is time-sensitive).
	 */
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient key for the cached XML.
	 */
	private const CACHE_KEY = 'hti_news_sitemap_xml';

	/**
	 * Wire up the rewrite rule, query var and renderer.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );

		// A fresh post/edit invalidates the cache so the sitemap reflects it fast.
		add_action( 'save_post_news', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Register the pretty rewrite for /news-sitemap.xml.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
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
	 * Public URL of the sitemap.
	 */
	public static function url(): string {
		return home_url( '/' . self::PATH );
	}

	/**
	 * Render the sitemap when the query var is set, then stop.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$xml = get_transient( self::CACHE_KEY );
		if ( ! is_string( $xml ) || '' === $xml ) {
			$xml = self::build();
			set_transient( self::CACHE_KEY, $xml, self::CACHE_TTL );
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully built/escaped XML.
		exit;
	}

	/**
	 * Build the sitemap XML from recent news posts.
	 */
	private static function build(): string {
		$posts = get_posts(
			array(
				'post_type'              => 'news',
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_URLS,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_HOURS * HOUR_IN_SECONDS ),
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				),
			)
		);

		$pub_name = wp_strip_all_tags( get_bloginfo( 'name' ) );

		$urls = '';
		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( ! $permalink ) {
				continue;
			}

			$urls .= "\t<url>\n";
			$urls .= "\t\t<loc>" . esc_url( $permalink ) . "</loc>\n";
			$urls .= "\t\t<news:news>\n";
			$urls .= "\t\t\t<news:publication>\n";
			$urls .= "\t\t\t\t<news:name>" . self::xml( $pub_name ) . "</news:name>\n";
			$urls .= "\t\t\t\t<news:language>" . self::xml( self::post_lang( $post ) ) . "</news:language>\n";
			$urls .= "\t\t\t</news:publication>\n";
			$urls .= "\t\t\t<news:publication_date>" . self::xml( get_post_time( DATE_W3C, true, $post ) ) . "</news:publication_date>\n";
			$urls .= "\t\t\t<news:title>" . self::xml( wp_strip_all_tags( get_the_title( $post ) ) ) . "</news:title>\n";
			$urls .= "\t\t</news:news>\n";
			$urls .= "\t</url>\n";
		}

		$header  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$header .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
			. 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		return $header . $urls . '</urlset>' . "\n";
	}

	/**
	 * Per-post publication language slug (`pt` / `en`) for <news:language>.
	 *
	 * Uses Polylang when available; otherwise derives from the site locale.
	 *
	 * @param \WP_Post $post News post.
	 */
	private static function post_lang( \WP_Post $post ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$slug = (string) pll_get_post_language( (int) $post->ID, 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Escape a value for safe inclusion in an XML text node.
	 *
	 * @param string $value Raw text.
	 */
	private static function xml( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Advertise the news sitemap in robots.txt.
	 *
	 * @param string $output Existing robots.txt body.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public static function robots_txt( $output, $public ): string {
		if ( $public ) {
			$output .= "\nSitemap: " . self::url() . "\n";
		}
		return (string) $output;
	}

	/**
	 * Drop the cached XML (on publish/edit/delete of a news post).
	 */
	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
