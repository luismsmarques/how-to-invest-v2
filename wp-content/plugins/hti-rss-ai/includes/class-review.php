<?php
/**
 * Review aids on the news edit screen: AI provenance + sources, sitelinking
 * suggestions, and bridging the generated meta description into the active SEO
 * plugin (RankMath / Yoast).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial review helpers for generated news.
 */
class Review {

	/**
	 * Hook the meta box + SEO sync.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_news', array( __CLASS__, 'sync_seo' ), 10, 1 );
	}

	/**
	 * Register the side meta box on news posts.
	 */
	public static function add_box(): void {
		$post_type = Settings::post_type();
		if ( ! post_type_exists( $post_type ) ) {
			return;
		}
		add_meta_box(
			'rssai_review',
			__( 'RSS AI — review', 'hti-rss-ai' ),
			array( __CLASS__, 'render' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post News post.
	 */
	public static function render( \WP_Post $post ): void {
		$group_id  = (int) get_post_meta( $post->ID, 'rssai_group_id', true );
		$sources   = (array) get_post_meta( $post->ID, 'rssai_sources', true );
		$model     = (string) get_post_meta( $post->ID, 'rssai_model', true );
		$generated = (string) get_post_meta( $post->ID, 'rssai_generated_at', true );
		$meta_desc = (string) get_post_meta( $post->ID, '_rssai_meta_description', true );

		if ( $group_id ) {
			echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'AI-generated draft', 'hti-rss-ai' ) . '</strong></p>';
			echo '<p style="margin:0 0 10px;color:#646970;font-size:12px">';
			if ( $model ) {
				echo esc_html( $model );
			}
			if ( $generated ) {
				echo ' · ' . esc_html( $generated );
			}
			echo '</p>';
		} else {
			echo '<p style="color:#646970">' . esc_html__( 'Tips for linking and SEO.', 'hti-rss-ai' ) . '</p>';
		}

		if ( '' !== $meta_desc ) {
			echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Meta description', 'hti-rss-ai' ) . '</strong></p>';
			echo '<p style="margin:0 0 12px;font-size:12px;color:#3c434a">' . esc_html( $meta_desc ) . '</p>';
		}

		if ( $sources ) {
			echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Sources', 'hti-rss-ai' ) . '</strong></p><ul style="margin:0 0 12px 16px">';
			foreach ( array_slice( $sources, 0, 12 ) as $source ) {
				if ( empty( $source['url'] ) ) {
					continue;
				}
				$title = (string) ( $source['title'] ?? $source['url'] );
				echo '<li style="font-size:12px"><a href="' . esc_url( (string) $source['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : (string) $source['url'] ) . '</a></li>';
			}
			echo '</ul>';
		}

		self::render_suggestions( $post );
	}

	/**
	 * Sitelinking suggestions: matching glossary terms + related news.
	 *
	 * @param \WP_Post $post News post.
	 */
	private static function render_suggestions( \WP_Post $post ): void {
		$glossary = self::suggest_glossary( $post );
		$news     = self::suggest_news( $post );

		echo '<hr />';
		echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Suggested internal links', 'hti-rss-ai' ) . '</strong></p>';

		if ( $glossary ) {
			echo '<p style="margin:8px 0 2px;font-size:12px;color:#646970">' . esc_html__( 'Glossary terms mentioned:', 'hti-rss-ai' ) . '</p><ul style="margin:0 0 8px 16px">';
			foreach ( $glossary as $term ) {
				echo '<li style="font-size:12px"><a href="' . esc_url( (string) get_permalink( $term ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $term ) ) . '</a></li>';
			}
			echo '</ul>';
		}

		if ( $news ) {
			echo '<p style="margin:8px 0 2px;font-size:12px;color:#646970">' . esc_html__( 'Related news:', 'hti-rss-ai' ) . '</p><ul style="margin:0 0 8px 16px">';
			foreach ( $news as $item ) {
				echo '<li style="font-size:12px"><a href="' . esc_url( (string) get_permalink( $item ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $item ) ) . '</a></li>';
			}
			echo '</ul>';
		}

		if ( ! $glossary && ! $news ) {
			echo '<p style="font-size:12px;color:#646970">' . esc_html__( 'No suggestions yet.', 'hti-rss-ai' ) . '</p>';
		}
	}

	/**
	 * Glossary terms whose title appears in the article.
	 *
	 * @param \WP_Post $post News post.
	 * @return array<int,\WP_Post>
	 */
	private static function suggest_glossary( \WP_Post $post ): array {
		if ( ! post_type_exists( 'glossary' ) ) {
			return array();
		}
		$haystack = strtolower( wp_strip_all_tags( $post->post_title . ' ' . $post->post_content ) );
		if ( '' === trim( $haystack ) ) {
			return array();
		}
		$terms = get_posts(
			array(
				'post_type'        => 'glossary',
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'suppress_filters' => true,
			)
		);
		$hits = array();
		foreach ( $terms as $term ) {
			$title = trim( (string) $term->post_title );
			if ( '' !== $title && mb_strlen( $title ) > 2 && false !== stripos( $haystack, strtolower( $title ) ) ) {
				$hits[] = $term;
			}
		}
		return array_slice( $hits, 0, 8 );
	}

	/**
	 * Recent news sharing a category with this post.
	 *
	 * @param \WP_Post $post News post.
	 * @return array<int,\WP_Post>
	 */
	private static function suggest_news( \WP_Post $post ): array {
		$args = array(
			'post_type'        => Settings::post_type(),
			'post_status'      => 'publish',
			'numberposts'      => 5,
			'post__not_in'     => array( $post->ID ),
			'suppress_filters' => true,
		);
		$taxonomy = Settings::taxonomy();
		$cats     = '' !== $taxonomy ? wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) ) : array();
		if ( $cats && ! is_wp_error( $cats ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $cats,
				),
			);
		}
		return get_posts( $args );
	}

	/**
	 * Copy the generated meta description into the active SEO plugin's field
	 * (only when that field is still empty).
	 *
	 * @param int $post_id News post id.
	 */
	public static function sync_seo( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$desc = (string) get_post_meta( $post_id, '_rssai_meta_description', true );
		if ( '' === $desc ) {
			return;
		}
		if ( ( class_exists( '\\RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) && '' === (string) get_post_meta( $post_id, 'rank_math_description', true ) ) {
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}
		if ( defined( 'WPSEO_VERSION' ) && '' === (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
		}
	}
}
