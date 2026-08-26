<?php
/**
 * Per-post meta box on News/Glossary editors: mounts the same JS editor, but
 * filtered to the relevant template categories and pre-filled with the post's
 * own content (title, dek/definition, featured image).
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Social meta box.
 */
class Metabox {

	/**
	 * Hook the meta box.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register on the supported post types.
	 */
	public static function register(): void {
		foreach ( Templates::metabox_post_types() as $pt ) {
			add_meta_box(
				'hti-social-card',
				__( 'Social cards', 'hti-social' ),
				array( __CLASS__, 'render' ),
				$pt,
				'normal',
				'low'
			);
		}
	}

	/**
	 * Render the mount + the post prefill payload.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		$cats = Templates::categories_for_post_type( $post->post_type );
		if ( empty( $cats ) ) {
			echo '<p>' . esc_html__( 'No templates available for this content type yet.', 'hti-social' ) . '</p>';
			return;
		}

		$prefill = self::prefill( $post );

		echo '<div id="hti-social-app" data-mode="post" data-categories="' . esc_attr( implode( ',', $cats ) ) . '"></div>';

		// Prefill payload — read before the footer editor script runs. HEX flags
		// stop a title containing "</script>" or quotes from breaking out.
		$json = wp_json_encode( $prefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		echo '<script>window.HTI_SOCIAL_POST = ' . $json . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the prefill fields from the post.
	 *
	 * @param \WP_Post $post Current post.
	 * @return array<string,mixed>
	 */
	private static function prefill( \WP_Post $post ): array {
		$title = wp_strip_all_tags( get_the_title( $post ) );
		$dek   = self::dek( $post );
		$img   = '';
		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$url = wp_get_attachment_image_url( (int) $thumb, 'large' );
			$img = $url ? (string) $url : '';
		}

		$date = '';
		$ts   = get_post_time( 'U', false, $post );
		if ( $ts ) {
			$date = (string) wp_date( 'pt' === Plugin::locale() ? 'j M Y' : 'j M Y', (int) $ts );
		}

		return array(
			'postType' => $post->post_type,
			'fields'   => array(
				'headline'   => $title,
				'dek'        => $dek,
				'term'       => $title,
				'definition' => $dek,
				'date'       => $date,
			),
			'image'    => $img,
		);
	}

	/**
	 * A short dek/definition: the excerpt, else the first paragraph of content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private static function dek( \WP_Post $post ): string {
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		if ( '' === trim( (string) $excerpt ) ) {
			$plain   = wp_strip_all_tags( (string) $post->post_content );
			$excerpt = wp_trim_words( $plain, 26, '…' );
		}
		return trim( (string) $excerpt );
	}
}
