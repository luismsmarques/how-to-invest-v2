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
		if ( is_admin() ) {
			return;
		}

		$graph = array();

		/**
		 * Sitewide entity graph on every front-end page: the WebSite (with a
		 * SearchAction) and the publishing Organization. These anchor brand
		 * entity recognition for search + AI engines and the sitelinks search
		 * box, and are referenced by @id from article/course publisher nodes.
		 * Filter to false if the active SEO plugin already emits an identical
		 * WebSite/Organization graph (avoids duplicate entity nodes).
		 *
		 * @param bool $emit Whether to add the WebSite + Organization nodes.
		 */
		if ( apply_filters( 'hti_emit_entity_graph', true ) ) {
			$graph[] = self::website_node();
			$graph[] = self::organization_node();
		}

		$post = get_queried_object();
		if ( is_singular( array( 'glossary', 'news', 'learn' ) ) && $post instanceof \WP_Post ) {
			if ( 'glossary' === $post->post_type ) {
				$graph[] = self::defined_term( $post );
			} elseif ( 'news' === $post->post_type ) {
				// News needs a reliable, correct NewsArticle for Google News —
				// emit it even when an SEO plugin is active (its free tier rarely
				// models the NewsArticle type, per-post language or publisher logo).
				$graph[] = self::article( $post );
			} elseif ( 'learn' === $post->post_type ) {
				// The Learn course is the flagship asset: always express each
				// chapter as a LearningResource (a type RankMath does not emit,
				// so no duplication) plus its end-of-chapter Quiz when present.
				$graph[] = self::learning_resource( $post );
				$quiz = self::quiz_node( $post );
				if ( $quiz ) {
					$graph[] = $quiz;
				}
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
	 * Stable @id for the publishing Organization, referenced across the graph.
	 */
	public static function org_id(): string {
		return home_url( '/' ) . '#organization';
	}

	/**
	 * Current page language as a BCP-47 tag, from the site locale.
	 */
	private static function site_lang(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt-PT' : 'en-US';
	}

	/**
	 * Sitewide WebSite node with a SearchAction (sitelinks search box) and a
	 * publisher reference to the Organization.
	 *
	 * @return array<string,mixed>
	 */
	private static function website_node(): array {
		return array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/' ) . '#website',
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => self::site_lang(),
			'publisher'       => array( '@id' => self::org_id() ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/**
	 * Sitewide Organization node: name, url, logo and social profiles. The
	 * `hti_organization_same_as` filter supplies the `sameAs` social URLs.
	 *
	 * @return array<string,mixed>
	 */
	private static function organization_node(): array {
		$node = array(
			'@type' => 'Organization',
			'@id'   => self::org_id(),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo = self::logo_url();
		if ( '' !== $logo ) {
			$node['logo']  = array(
				'@type' => 'ImageObject',
				'@id'   => home_url( '/' ) . '#logo',
				'url'   => $logo,
			);
			$node['image'] = array( '@id' => home_url( '/' ) . '#logo' );
		}

		/**
		 * Social / authoritative profile URLs for the Organization `sameAs`.
		 *
		 * @param array<int,string> $urls Profile URLs.
		 */
		$same_as = array_values( array_filter( array_map( 'esc_url_raw', (array) apply_filters( 'hti_organization_same_as', array() ) ) ) );
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
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
	 * Build an Article / NewsArticle node.
	 *
	 * For `news` this is the Google News article markup; emitted reliably with a
	 * per-post language, a publisher logo and a headline trimmed to Google's
	 * 110-character limit.
	 *
	 * @param \WP_Post $post Glossary, learn or news post.
	 * @return array<string,mixed>
	 */
	private static function article( \WP_Post $post ): array {
		$type = ( 'news' === $post->post_type ) ? 'NewsArticle' : 'Article';

		$node = array(
			'@type'            => $type,
			'@id'              => get_permalink( $post ) . '#article',
			'headline'         => self::headline( $post ),
			'url'              => get_permalink( $post ),
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'inLanguage'       => self::post_lang( $post ),
			'isAccessibleForFree' => true,
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
			'publisher'        => array( '@id' => self::org_id() ),
		);

		$description = self::description( $post );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$node['author'] = self::author_node( $post );

		// Article/NewsArticle require an image; fall back to the publisher logo
		// so a post without a featured image still emits valid schema.
		$image = self::image_node( $post ) ?: self::logo_image_node();
		if ( $image ) {
			$node['image'] = $image;
		}

		return $node;
	}

	/**
	 * Build a `LearningResource` node for a Learn chapter — the educational
	 * counterpart of article(), tying the chapter into the course.
	 *
	 * @param \WP_Post $post Learn post.
	 * @return array<string,mixed>
	 */
	private static function learning_resource( \WP_Post $post ): array {
		$node = array(
			'@type'               => array( 'LearningResource', 'Article' ),
			'@id'                  => get_permalink( $post ) . '#chapter',
			'headline'            => self::headline( $post ),
			'name'                => wp_strip_all_tags( get_the_title( $post ) ),
			'url'                  => get_permalink( $post ),
			'datePublished'       => get_the_date( DATE_W3C, $post ),
			'dateModified'        => get_the_modified_date( DATE_W3C, $post ),
			'inLanguage'          => self::post_lang( $post ),
			'isAccessibleForFree' => true,
			'learningResourceType' => 'Chapter',
			'educationalLevel'    => 'Beginner',
			'isPartOf'            => array( '@id' => self::course_id() ),
			'mainEntityOfPage'    => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
			'publisher'           => array( '@id' => self::org_id() ),
			'author'              => self::author_node( $post ),
		);

		$description = self::description( $post );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$image = self::image_node( $post ) ?: self::logo_image_node();
		if ( $image ) {
			$node['image'] = $image;
		}

		return $node;
	}

	/**
	 * Stable @id for the Learn course (one entity across languages).
	 */
	public static function course_id(): string {
		return home_url( '/' ) . '#learn-course';
	}

	/**
	 * Express a chapter's end-of-chapter quiz (stored in `hti_quiz`) as a Quiz
	 * node with one Question per item — rich-result and AI-citation eligible.
	 *
	 * @param \WP_Post $post Learn post.
	 * @return array<string,mixed>|null
	 */
	private static function quiz_node( ?\WP_Post $post ): ?array {
		if ( ! $post || ! class_exists( '\\HTI\\Engine\\Content_Import' ) ) {
			return null;
		}
		$quiz = Content_Import::get_quiz( (int) $post->ID );
		if ( empty( $quiz ) ) {
			return null;
		}

		$questions = array();
		foreach ( $quiz as $q ) {
			$text    = wp_strip_all_tags( (string) ( $q['q'] ?? '' ) );
			$options = (array) ( $q['options'] ?? array() );
			if ( '' === $text || count( $options ) < 2 ) {
				continue;
			}
			$correct  = '';
			$wrong    = array();
			foreach ( $options as $o ) {
				$opt = wp_strip_all_tags( (string) ( $o['t'] ?? '' ) );
				if ( '' === $opt ) {
					continue;
				}
				if ( ! empty( $o['c'] ) ) {
					$correct = $opt;
				} else {
					$wrong[] = array( '@type' => 'Answer', 'text' => $opt );
				}
			}
			if ( '' === $correct ) {
				continue;
			}
			$questions[] = array(
				'@type'          => 'Question',
				'eduQuestionType' => 'Multiple choice',
				'name'           => $text,
				'text'           => $text,
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $correct ),
				'suggestedAnswer' => $wrong,
			);
		}

		if ( empty( $questions ) ) {
			return null;
		}

		return array(
			'@type'      => 'Quiz',
			'@id'        => get_permalink( $post ) . '#quiz',
			'about'      => array( '@id' => get_permalink( $post ) . '#chapter' ),
			'inLanguage' => self::post_lang( $post ),
			'isAccessibleForFree' => true,
			'hasPart'    => $questions,
		);
	}

	/**
	 * Author node: the editorial brand as an Organization, referencing the
	 * publisher entity. Keeps a consistent, trustworthy byline (E-E-A-T) rather
	 * than leaking a WordPress display name.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function author_node( \WP_Post $post ): array {
		/**
		 * Filter the schema author node for an article/chapter.
		 *
		 * @param array<string,mixed> $author Author node.
		 * @param \WP_Post            $post   Post.
		 */
		return apply_filters(
			'hti_schema_author',
			array(
				'@type' => 'Organization',
				'@id'   => self::org_id(),
				'name'  => get_bloginfo( 'name' ),
			),
			$post
		);
	}

	/**
	 * The publisher logo as an ImageObject (for use as an image fallback).
	 *
	 * @return array<string,mixed>|string
	 */
	private static function logo_image_node() {
		$logo = self::logo_url();
		return '' === $logo ? '' : array( '@id' => home_url( '/' ) . '#logo', '@type' => 'ImageObject', 'url' => $logo );
	}

	/**
	 * Headline trimmed to Google's 110-character recommendation for News.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function headline( \WP_Post $post ): string {
		$title = wp_strip_all_tags( get_the_title( $post ) );
		if ( mb_strlen( $title ) <= 110 ) {
			return $title;
		}
		return rtrim( mb_substr( $title, 0, 109 ) ) . '…';
	}

	/**
	 * Per-post BCP-47 language tag for `inLanguage`.
	 *
	 * Uses Polylang's per-post language when present, so a PT translation is
	 * marked `pt-PT` even on an EN-default site; falls back to the site locale.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function post_lang( \WP_Post $post ): string {
		$slug = '';
		if ( function_exists( 'pll_get_post_language' ) ) {
			$slug = (string) pll_get_post_language( (int) $post->ID, 'slug' );
		}
		if ( '' === $slug ) {
			$slug = (string) get_locale();
		}
		return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt-PT' : 'en-US';
	}

	/**
	 * Best available publisher logo URL (custom logo → site icon → filter).
	 */
	private static function logo_url(): string {
		$url = '';

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$url = (string) $src[0];
			}
		}

		if ( '' === $url ) {
			$url = (string) get_site_icon_url( 512 );
		}

		/**
		 * Filter the publisher logo URL used in NewsArticle/Organization schema.
		 *
		 * @param string $url Resolved logo URL (may be empty).
		 */
		return (string) apply_filters( 'hti_publisher_logo_url', $url );
	}

	/**
	 * Featured image as an ImageObject (with dimensions when known).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>|string
	 */
	private static function image_node( \WP_Post $post ) {
		$id = (int) get_post_thumbnail_id( $post );
		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return '';
		}

		$node = array(
			'@type' => 'ImageObject',
			'url'   => (string) $src[0],
		);
		if ( ! empty( $src[1] ) ) {
			$node['width'] = (int) $src[1];
		}
		if ( ! empty( $src[2] ) ) {
			$node['height'] = (int) $src[2];
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
