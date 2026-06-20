<?php
/**
 * SEO structured data for the public content types.
 *
 * Strategy (plugin-agnostic):
 * - Glossary terms always emit `DefinedTerm` JSON-LD — semantic markup that
 *   general SEO plugins (RankMath/Yoast) do not model for a glossary CPT.
 * - `Article` / `NewsArticle` JSON-LD is emitted only as a fallback when no
 *   known SEO plugin is active, so pages still have schema before RankMath is
 *   configured, without duplicating its output afterwards.
 *
 * Sitemaps & meta titles/descriptions are owned by the SEO plugin (RankMath).
 * Our CPTs are public + has_archive, so RankMath lists them automatically.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs JSON-LD structured data for glossary and news content.
 */
class SEO {

	/**
	 * Hook into the document head.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 20 );
	}

	/**
	 * Whether a known SEO plugin is active and will emit Article schema itself.
	 */
	private static function seo_plugin_active(): bool {
		return class_exists( '\\RankMath' ) || defined( 'WPSEO_VERSION' );
	}

	/**
	 * Print the appropriate JSON-LD for the current singular view.
	 */
	public static function output_schema(): void {
		if ( ! is_singular( array( 'glossary', 'news', 'learn' ) ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$graph = array();

		if ( 'glossary' === $post->post_type ) {
			$graph[] = self::defined_term( $post );
		}

		if ( ! self::seo_plugin_active() ) {
			$graph[] = self::article( $post );
		}

		/**
		 * Emit a BreadcrumbList. On by default: FSE block themes rarely call
		 * the SEO plugin's breadcrumb function, so this schema is usually
		 * absent. Filter to false if the active SEO plugin already outputs it.
		 *
		 * @param bool $emit Whether to add BreadcrumbList schema.
		 */
		if ( apply_filters( 'hti_emit_breadcrumbs', true ) ) {
			$graph[] = self::breadcrumbs( $post );
		}

		$graph = array_values( array_filter( $graph ) );
		if ( empty( $graph ) ) {
			return;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Build a BreadcrumbList node: Home → section archive → current page.
	 *
	 * Language-aware (PT under /pt/). The section archive link resolves to the
	 * current language via Polylang when active.
	 *
	 * @param \WP_Post $post Singular post.
	 * @return array<string,mixed>
	 */
	private static function breadcrumbs( \WP_Post $post ): array {
		$pt = str_starts_with( strtolower( (string) get_locale() ), 'pt' );

		$sections = array(
			'learn'    => array( 'en' => 'Learn', 'pt' => 'Aprender' ),
			'news'     => array( 'en' => 'News', 'pt' => 'Notícias' ),
			'glossary' => array( 'en' => 'Glossary', 'pt' => 'Glossário' ),
		);

		$items    = array();
		$position = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'name'     => $pt ? 'Início' : 'Home',
			'item'     => home_url( '/' ),
		);

		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive && isset( $sections[ $post->post_type ] ) ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $sections[ $post->post_type ][ $pt ? 'pt' : 'en' ],
				'item'     => $archive,
			);
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'name'     => wp_strip_all_tags( get_the_title( $post ) ),
			'item'     => get_permalink( $post ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => get_permalink( $post ) . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Build a DefinedTerm node for a glossary entry.
	 *
	 * @param \WP_Post $post Glossary post.
	 * @return array<string,mixed>
	 */
	private static function defined_term( \WP_Post $post ): array {
		$node = array(
			'@type' => 'DefinedTerm',
			'@id'   => get_permalink( $post ) . '#definedterm',
			'name'  => wp_strip_all_tags( get_the_title( $post ) ),
			'url'   => get_permalink( $post ),
		);

		$description = self::description( $post );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$archive = get_post_type_archive_link( 'glossary' );
		if ( $archive ) {
			$node['inDefinedTermSet'] = array(
				'@type' => 'DefinedTermSet',
				'@id'   => $archive . '#glossary',
				'name'  => get_bloginfo( 'name' ) . ' — ' . __( 'Glossary', 'hti-engine' ),
				'url'   => $archive,
			);
		}

		return $node;
	}

	/**
	 * Build an Article / NewsArticle node (fallback only).
	 *
	 * @param \WP_Post $post Glossary or news post.
	 * @return array<string,mixed>
	 */
	private static function article( \WP_Post $post ): array {
		$type = ( 'news' === $post->post_type ) ? 'NewsArticle' : 'Article';

		$node = array(
			'@type'            => $type,
			'@id'              => get_permalink( $post ) . '#article',
			'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
			'url'              => get_permalink( $post ),
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'inLanguage'       => get_bloginfo( 'language' ),
			'mainEntityOfPage' => get_permalink( $post ),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		$description = self::description( $post );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
			);
		}

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( $image ) {
			$node['image'] = $image;
		}

		return $node;
	}

	/**
	 * A clean, plain-text description for schema (excerpt, else trimmed content).
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function description( \WP_Post $post ): string {
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );

		return trim( wp_trim_words( $text, 55, '' ) );
	}
}
