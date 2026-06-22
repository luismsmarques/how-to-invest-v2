<?php
/**
 * Featured image for a generated `news` post: a plain AI photo about the
 * article's topic (Imagen), saved as the post thumbnail. The photo can then be
 * reused — without re-calling the AI — by the hti-social card generator.
 * Best-effort: a failure here never blocks the article.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * AI featured-image orchestration + the editor "Regenerate" control.
 */
class Featured_Image {

	private const ACTION = 'rssai_regen_image';

	/**
	 * Hook the meta box and the regenerate handler.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_news', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_regenerate' ) );
	}

	/**
	 * Best-effort generation called from the pipeline (and the button).
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $data    Validated article (for the image prompt).
	 * @param object|null         $group   Group row (for the feed-image fallback).
	 * @param string              $lang    Language slug (unused; kept for symmetry).
	 * @return bool True when a featured image was set.
	 */
	public static function maybe_generate( int $post_id, array $data, ?object $group, string $lang = 'en' ): bool {
		if ( empty( Settings::get( 'image_generate', 1 ) ) ) {
			return false;
		}

		try {
			[ $photo, $source, $mime ] = self::acquire_photo( $data, $group );
			if ( null === $photo ) {
				Logger::log( 'image', sprintf( 'No featured image for #%d (no AI/feed image).', $post_id ) );
				return false;
			}

			$attach_id = self::store( $post_id, $photo, $mime );
			if ( is_wp_error( $attach_id ) ) {
				Logger::log( 'image', 'Attachment failed: ' . $attach_id->get_error_message() );
				return false;
			}

			self::cleanup_previous( $post_id, $attach_id );
			set_post_thumbnail( $post_id, $attach_id );
			update_post_meta( $post_id, 'rssai_card_attachment', $attach_id );
			update_post_meta( $post_id, 'rssai_card_photo_source', $source );
			Logger::log( 'image', sprintf( 'Featured image set for #%d (source: %s).', $post_id, $source ) );
			return true;
		} catch ( \Throwable $e ) {
			Logger::log( 'image', 'Exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Acquire a photo. Preference order:
	 *   1. Image-to-image: reimagine the feed image into the branded illustration.
	 *   2. Text-to-image (no feed image, or image-to-image unavailable/failed).
	 *   3. The raw feed image.
	 *   4. None.
	 *
	 * @param array<string,mixed> $data  Article data.
	 * @param object|null         $group Group row.
	 * @return array{0:?string,1:string,2:string} [bytes|null, source, mime]
	 */
	private static function acquire_photo( array $data, ?object $group ): array {
		// Grab a feed image up front — used as the base, or as the fallback.
		[ $feed_bytes, $feed_mime ] = self::feed_image_bytes( $group );

		if ( Image_Client::available() ) {
			// 1. Use the feed image as a base, reimagined by a Gemini image model.
			if ( null !== $feed_bytes && Image_Client::base_available() ) {
				$bytes = Image_Client::generate_from_image( Prompt::image_edit_prompt( $data ), $feed_bytes, $feed_mime );
				if ( ! is_wp_error( $bytes ) && '' !== $bytes ) {
					return array( $bytes, 'ai-from-feed', 'image/png' );
				}
				if ( is_wp_error( $bytes ) ) {
					Logger::log( 'image', 'Image-to-image failed, trying text-to-image: ' . $bytes->get_error_message() );
				}
			}

			// 2. Plain text-to-image.
			$bytes = Image_Client::generate( Prompt::image_prompt( $data ), '16:9' );
			if ( ! is_wp_error( $bytes ) && '' !== $bytes ) {
				return array( $bytes, 'ai', 'image/png' );
			}
			if ( is_wp_error( $bytes ) ) {
				Logger::log( 'image', 'AI image failed, falling back: ' . $bytes->get_error_message() );
			}
		}

		// 3. The raw feed image.
		if ( null !== $feed_bytes ) {
			return array( $feed_bytes, 'feed', '' !== $feed_mime ? $feed_mime : 'image/jpeg' );
		}

		return array( null, 'none', '' );
	}

	/**
	 * Fetch the first feed image's bytes + MIME for the group (or null).
	 *
	 * @param object|null $group Group row.
	 * @return array{0:?string,1:string} [bytes|null, mime]
	 */
	private static function feed_image_bytes( ?object $group ): array {
		$url = self::feed_image_url( $group );
		if ( '' === $url ) {
			return array( null, '' );
		}
		$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array( null, '' );
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return array( null, '' );
		}
		return array( $body, (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
	}

	/**
	 * First non-empty feed image among the group's items.
	 *
	 * @param object|null $group Group row.
	 */
	private static function feed_image_url( ?object $group ): string {
		if ( ! $group || ! isset( $group->id ) ) {
			return '';
		}
		foreach ( Groups::items( (int) $group->id ) as $item ) {
			$url = isset( $item->image_url ) ? trim( (string) $item->image_url ) : '';
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Save image bytes as an attachment parented to the post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $bytes   Image bytes.
	 * @param string $mime    MIME type hint.
	 * @return int|\WP_Error Attachment id.
	 */
	private static function store( int $post_id, string $bytes, string $mime ) {
		$ext    = false !== strpos( $mime, 'jpeg' ) || false !== strpos( $mime, 'jpg' ) ? 'jpg' : ( false !== strpos( $mime, 'webp' ) ? 'webp' : 'png' );
		$upload = wp_upload_bits( 'rssai-news-' . $post_id . '-' . time() . '.' . $ext, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'rssai_upload', (string) $upload['error'] );
		}

		$filetype  = wp_check_filetype( $upload['file'] );
		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/png',
				'post_title'     => get_the_title( $post_id ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id,
			true
		);
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}
		$attach_id = (int) $attach_id;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		return $attach_id;
	}

	/**
	 * Delete the previous AI image so we don't orphan uploads.
	 *
	 * @param int $post_id     Post id.
	 * @param int $keep_attach New attachment id to keep.
	 */
	private static function cleanup_previous( int $post_id, int $keep_attach ): void {
		$prev = (int) get_post_meta( $post_id, 'rssai_card_attachment', true );
		if ( $prev > 0 && $prev !== $keep_attach ) {
			wp_delete_attachment( $prev, true );
		}
	}

	/* -------------------------------------------------------------------------
	 * Editor meta box
	 * ---------------------------------------------------------------------- */

	/**
	 * Register the meta box on the news editor.
	 */
	public static function meta_box(): void {
		add_meta_box(
			'rssai_featured_image',
			__( 'AI featured image', 'hti-rss-ai' ),
			array( __CLASS__, 'render_meta_box' ),
			Settings::post_type(),
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box: preview + regenerate button.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		$thumb  = get_the_post_thumbnail( $post->ID, array( 280, 280 ) );
		$source = (string) get_post_meta( $post->ID, 'rssai_card_photo_source', true );
		echo '<div style="text-align:center;">';
		if ( $thumb ) {
			echo wp_kses_post( $thumb );
			if ( '' !== $source ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: photo source (ai, feed or none). */
							__( 'Source: %s', 'hti-rss-ai' ),
							$source
						)
					)
				);
			}
		} else {
			echo '<p class="description">' . esc_html__( 'No featured image yet.', 'hti-rss-ai' ) . '</p>';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . $post->ID ),
			self::ACTION . '_' . $post->ID
		);
		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Regenerate AI image', 'hti-rss-ai' )
		);
		echo '<p class="description">' . esc_html__( 'Generates a fresh AI photo about the article topic and sets it as the featured image.', 'hti-rss-ai' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Handle the regenerate button.
	 */
	public static function handle_regenerate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( self::ACTION . '_' . $post_id );

		$group = null;
		$gid   = (int) get_post_meta( $post_id, 'rssai_group_id', true );
		if ( $gid > 0 ) {
			$group = Groups::get( $gid );
		}
		$data = array(
			'headline'           => get_the_title( $post_id ),
			'suggested_category' => '',
		);

		$ok = self::maybe_generate( $post_id, $data, $group );

		wp_safe_redirect(
			add_query_arg(
				array( 'rssai_card' => $ok ? 'ok' : 'fail' ),
				get_edit_post_link( $post_id, 'url' )
			)
		);
		exit;
	}
}
