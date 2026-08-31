<?php
/**
 * Featured image for a generated `news` post.
 *
 * The route is: read the scene into an image brief (Image_Brief), then draw the
 * illustration from that brief. When the feed item carries a photograph the
 * brief is written by a vision call that looks at it; when it does not, the text
 * model invents a scene from the headline. Both roads end in the same JSON, so
 * "no photo in the feed" is an ordinary path rather than a special case.
 *
 * The feed photograph is read and never published. Republishing an agency image
 * because our own generation failed is the one outcome worth ruling out, so the
 * last resort is Fallback_Card — a brand card we draw ourselves. That looks like
 * a regression at a glance and is the opposite: there is now always an image,
 * and it is always ours.
 *
 * The image is reused — without re-calling the AI — by the hti-social card
 * generator. Best-effort throughout: a failure here never blocks the article.
 * It is, however, recorded in Health, because a mechanism that degrades in
 * silence is a mechanism nobody knows is broken.
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
	 * @param int                 $post_id    Post id.
	 * @param array<string,mixed> $data       Validated article (for the image prompt).
	 * @param object|null         $source_row Group row OR item row — whichever the
	 *                                        article was written from. Passing the
	 *                                        item is what lets a single-item
	 *                                        article reach its own feed photo.
	 * @param string              $lang       Language slug (unused; kept for symmetry).
	 * @return bool True when a featured image was set.
	 */
	public static function maybe_generate( int $post_id, array $data, ?object $source_row, string $lang = 'en' ): bool {
		if ( empty( Settings::get( 'image_generate', 1 ) ) ) {
			return false;
		}

		try {
			[ $photo, $source, $mime ] = self::acquire_photo( $data, $source_row, $post_id );
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
	 *   1. The illustration generated from the image brief.
	 *   2. Image-to-image on the feed photo (rescue, when 1 fails).
	 *   3. Our own brand card, drawn locally.
	 *
	 * The raw feed photograph is deliberately absent from that list: it is read
	 * to write the brief, and it is never what gets published.
	 *
	 * @param array<string,mixed> $data       Article data.
	 * @param object|null         $source_row Group row or item row.
	 * @param int                 $post_id    Post id (0 when not yet saved).
	 * @return array{0:?string,1:string,2:string} [bytes|null, source, mime]
	 */
	private static function acquire_photo( array $data, ?object $source_row, int $post_id = 0 ): array {
		// Fetched once: the vision call's input, and the rescue route's base.
		// Never the answer.
		[ $feed_bytes, $feed_mime ] = self::feed_image_bytes( $source_row );

		[ $brief, $brief_source ] = self::acquire_brief( $data, $feed_bytes, $feed_mime );
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, Image_Brief::META_KEY, $brief );
			update_post_meta( $post_id, Image_Brief::META_KEY . '_source', $brief_source );
		}

		if ( Image_Client::available() ) {
			// 1. Draw the brief.
			$bytes = Image_Client::generate( Prompt::image_prompt( $data, $brief ), '16:9' );
			if ( ! is_wp_error( $bytes ) && '' !== $bytes ) {
				Health::record( 'image', true );
				return array( $bytes, 'ai-from-brief', 'image/png' );
			}
			if ( is_wp_error( $bytes ) ) {
				Health::record( 'image', false, $bytes->get_error_message() );
				Logger::log( 'image', 'AI image failed: ' . $bytes->get_error_message() );
			}

			// 2. Rescue: restyle the feed photo. Closer to a derivative of
			// someone else's file than route 1, which is why it is second and
			// not first — but a generated image all the same.
			if ( null !== $feed_bytes && Image_Client::base_available() ) {
				$bytes = Image_Client::generate_from_image( Prompt::image_edit_prompt( $data, $brief ), $feed_bytes, $feed_mime );
				if ( ! is_wp_error( $bytes ) && '' !== $bytes ) {
					Health::record( 'image', true );
					return array( $bytes, 'ai-from-feed', 'image/png' );
				}
				if ( is_wp_error( $bytes ) ) {
					Health::record( 'image', false, $bytes->get_error_message() );
					Logger::log( 'image', 'Image-to-image rescue failed: ' . $bytes->get_error_message() );
				}
			}
		}

		// 3. Ours, drawn here, no network involved.
		$card = Fallback_Card::render(
			(string) ( $data['headline'] ?? '' ),
			(string) ( $data['suggested_category'] ?? '' )
		);
		if ( null !== $card ) {
			return array( $card, 'brand-card', 'image/png' );
		}

		return array( null, 'none', '' );
	}

	/**
	 * Get the image brief: read the feed photo, else draft one from the
	 * article, else fall back to the deterministic brief that needs no API.
	 *
	 * @param array<string,mixed> $data       Article data.
	 * @param string|null         $feed_bytes Feed image bytes, when there is one.
	 * @param string              $feed_mime  Feed image MIME.
	 * @return array{0:array<string,mixed>,1:string} [brief, how it was obtained]
	 */
	private static function acquire_brief( array $data, ?string $feed_bytes, string $feed_mime ): array {
		if ( Gemini_Client::available() ) {
			if ( null !== $feed_bytes ) {
				$brief = Image_Brief::describe_image( $feed_bytes, $feed_mime );
				if ( ! is_wp_error( $brief ) ) {
					Health::record( 'brief', true );
					return array( $brief, 'vision' );
				}
				Health::record( 'brief', false, $brief->get_error_message() );
				Logger::log( 'image', 'Vision brief failed, drafting from the article: ' . $brief->get_error_message() );
			}

			$brief = Image_Brief::draft_from_article( $data );
			if ( ! is_wp_error( $brief ) ) {
				Health::record( 'brief', true );
				return array( $brief, 'text' );
			}
			Health::record( 'brief', false, $brief->get_error_message() );
			Logger::log( 'image', 'Text brief failed, using the headline as-is: ' . $brief->get_error_message() );
		}

		return array( Image_Brief::from_article( $data ), 'headline' );
	}

	/**
	 * Fetch the feed image's bytes + MIME for a group or an item (or null).
	 *
	 * @param object|null $source_row Group row or item row.
	 * @return array{0:?string,1:string} [bytes|null, mime]
	 */
	private static function feed_image_bytes( ?object $source_row ): array {
		$url = self::feed_image_url( $source_row );
		if ( '' === $url ) {
			return array( null, '' );
		}
		// SSRF guard: the URL comes from third-party feed HTML, so use the safe
		// variant (reject_unsafe_urls → wp_http_validate_url blocks private/
		// reserved IPs, non-http(s) schemes and odd ports) and cap the response
		// size, so a hostile feed can't point us at internal services
		// (169.254.169.254, localhost, LAN) or exhaust memory.
		$resp = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'limit_response_size' => 12 * MB_IN_BYTES,
			)
		);
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
	 * The feed image URL behind an article.
	 *
	 * Accepts either shape the pipeline produces: an item row, which carries
	 * image_url itself, or a group row, whose items are looked up. The item case
	 * is the one that used to be missing — a single-item article was handed
	 * null, so it never saw its own photo, generated nothing from it, and could
	 * not even fall back to it.
	 *
	 * @param object|null $source_row Group row or item row.
	 */
	public static function feed_image_url( ?object $source_row ): string {
		if ( ! $source_row ) {
			return '';
		}

		// An item row: the URL is right there.
		if ( property_exists( $source_row, 'image_url' ) ) {
			return trim( (string) $source_row->image_url );
		}

		// A group row: the first of its items that has one.
		if ( ! isset( $source_row->id ) || ! class_exists( __NAMESPACE__ . '\\Groups' ) ) {
			return '';
		}
		foreach ( Groups::items( (int) $source_row->id ) as $item ) {
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

	/**
	 * The article's category name, for the image prompt and the brand card.
	 *
	 * @param int $post_id Post id.
	 */
	private static function post_category_name( int $post_id ): string {
		$taxonomy = Settings::taxonomy();
		if ( '' === $taxonomy ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
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
							/* translators: %s: how the image was produced. */
							__( 'Source: %s', 'hti-rss-ai' ),
							self::source_label( $source )
						)
					)
				);
			}
			self::render_brief( $post->ID );
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
		echo '<p class="description">' . esc_html__( 'Re-reads the source image into a fresh brief, draws a new illustration from it, and sets it as the featured image. Costs two API calls.', 'hti-rss-ai' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Plain-English label for a stored photo source.
	 *
	 * @param string $source Stored source key.
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case 'ai-from-brief':
				return __( 'AI illustration, drawn from the image brief', 'hti-rss-ai' );
			case 'ai-from-feed':
				return __( 'AI illustration, restyled from the feed photo', 'hti-rss-ai' );
			case 'brand-card':
				return __( 'Brand card (AI generation was unavailable)', 'hti-rss-ai' );
			case 'ai':
				return __( 'AI illustration', 'hti-rss-ai' );
			case 'feed':
				return __( 'Feed photo (legacy — no longer produced)', 'hti-rss-ai' );
			default:
				return $source;
		}
	}

	/**
	 * Show the stored brief, so an editor can see what the model understood
	 * before deciding whether a regeneration is worth the call.
	 *
	 * @param int $post_id Post id.
	 */
	private static function render_brief( int $post_id ): void {
		$brief = get_post_meta( $post_id, Image_Brief::META_KEY, true );
		if ( ! is_array( $brief ) || ! Image_Brief::is_valid( $brief ) ) {
			return;
		}
		$how = (string) get_post_meta( $post_id, Image_Brief::META_KEY . '_source', true );
		$map = array(
			'vision'   => __( 'read from the feed photo', 'hti-rss-ai' ),
			'text'     => __( 'drafted from the article', 'hti-rss-ai' ),
			'headline' => __( 'headline only (no AI available)', 'hti-rss-ai' ),
		);

		echo '<details style="text-align:left;margin-top:8px;">';
		printf(
			'<summary style="cursor:pointer;">%s</summary>',
			esc_html(
				isset( $map[ $how ] )
					? sprintf(
						/* translators: %s: how the brief was obtained. */
						__( 'Image brief (%s)', 'hti-rss-ai' ),
						$map[ $how ]
					)
					: __( 'Image brief', 'hti-rss-ai' )
			)
		);
		printf(
			'<p class="description" style="margin-top:6px;">%s</p>',
			esc_html( Image_Brief::to_text( $brief ) )
		);
		echo '</details>';
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

		// Whichever row the article came from — group or item — so a
		// regeneration sees the same feed photo the first run did.
		$source_row = null;
		$gid        = (int) get_post_meta( $post_id, 'rssai_group_id', true );
		$iid        = (int) get_post_meta( $post_id, 'rssai_item_id', true );
		if ( $gid > 0 ) {
			$source_row = Groups::get( $gid );
		} elseif ( $iid > 0 ) {
			$source_row = Items::get( $iid );
		}

		$data = array(
			'headline'           => get_the_title( $post_id ),
			'dek'                => get_the_excerpt( $post_id ),
			'suggested_category' => self::post_category_name( $post_id ),
		);

		$ok = self::maybe_generate( $post_id, $data, $source_row );

		wp_safe_redirect(
			add_query_arg(
				array( 'rssai_card' => $ok ? 'ok' : 'fail' ),
				get_edit_post_link( $post_id, 'url' )
			)
		);
		exit;
	}
}
