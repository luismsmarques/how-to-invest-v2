<?php
/**
 * Builds and attaches the branded featured image for a generated `news` post.
 *
 * Flow: acquire a photo (AI first, feed image fallback, then a branded
 * gradient), render the square card with Social_Card, save it as an attachment
 * and set it as the post thumbnail. Best-effort everywhere — a failure here
 * must never block the article, which is already saved as pending review.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Featured-image orchestration + the editor "Regenerate" control.
 */
class Featured_Image {

	private const ACTION = 'rssai_regen_card';

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
	 * @param string              $lang    Language slug.
	 * @return bool True when a featured image was set.
	 */
	public static function maybe_generate( int $post_id, array $data, ?object $group, string $lang ): bool {
		if ( empty( Settings::get( 'image_generate', 1 ) ) ) {
			return false;
		}
		if ( ! Social_Card::available() ) {
			Logger::log( 'image', 'GD unavailable; skipped featured image.' );
			return false;
		}

		try {
			[ $photo, $source ] = self::acquire_photo( $data, $group );

			$png = Social_Card::render(
				array(
					'headline'   => (string) ( $data['headline'] ?? get_the_title( $post_id ) ),
					'kicker'     => self::kicker( $post_id, $lang ),
					'badge'      => 'pt' === $lang ? 'Notícias' : 'News',
					'handle'     => 'howtoinvest',
					'domain'     => 'howtoinvest.pt',
					'disclaimer' => self::disclaimer( $lang ),
					'photo'      => $photo,
				)
			);

			if ( is_wp_error( $png ) ) {
				Logger::log( 'image', 'Render failed: ' . $png->get_error_message() );
				return false;
			}

			$attach_id = self::store( $post_id, $png );
			if ( is_wp_error( $attach_id ) ) {
				Logger::log( 'image', 'Attachment failed: ' . $attach_id->get_error_message() );
				return false;
			}

			self::cleanup_previous( $post_id, $attach_id );
			set_post_thumbnail( $post_id, $attach_id );
			update_post_meta( $post_id, 'rssai_card_attachment', $attach_id );
			update_post_meta( $post_id, 'rssai_card_photo_source', $source );
			Logger::log( 'image', sprintf( 'Featured image set for #%d (photo: %s).', $post_id, $source ) );
			return true;
		} catch ( \Throwable $e ) {
			Logger::log( 'image', 'Exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Acquire a photo: AI first, then the group's feed image, else none.
	 *
	 * @param array<string,mixed> $data  Article data.
	 * @param object|null         $group Group row.
	 * @return array{0:?string,1:string} [bytes|null, source]
	 */
	private static function acquire_photo( array $data, ?object $group ): array {
		if ( Image_Client::available() ) {
			$bytes = Image_Client::generate( Prompt::image_prompt( $data ), '16:9' );
			if ( ! is_wp_error( $bytes ) && '' !== $bytes ) {
				return array( $bytes, 'ai' );
			}
			if ( is_wp_error( $bytes ) ) {
				Logger::log( 'image', 'AI image failed, falling back: ' . $bytes->get_error_message() );
			}
		}

		$url = self::feed_image_url( $group );
		if ( '' !== $url ) {
			$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
			if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				$body = (string) wp_remote_retrieve_body( $resp );
				if ( '' !== $body ) {
					return array( $body, 'feed' );
				}
			}
		}

		return array( null, 'none' );
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
	 * Localized kicker: "Market update · 19 Jun".
	 *
	 * @param int    $post_id Post id.
	 * @param string $lang    Language.
	 */
	private static function kicker( int $post_id, string $lang ): string {
		$label = 'pt' === $lang ? 'Atualização de mercado' : 'Market update';
		$date  = get_post_time( 'j M', false, $post_id, true );
		return $label . ' · ' . ( $date ? $date : wp_date( 'j M' ) );
	}

	/**
	 * Short card disclaimer.
	 *
	 * @param string $lang Language.
	 */
	private static function disclaimer( string $lang ): string {
		return 'pt' === $lang
			? 'Conteúdo educativo sobre literacia financeira. Não é aconselhamento financeiro, de investimento, fiscal ou jurídico. Investir envolve risco, incluindo a perda de capital. Exemplos ilustrativos e apenas por classe de ativos.'
			: 'Educational financial-literacy content. Not financial, investment, tax or legal advice. Investing involves risk, including loss of capital. Illustrative examples, by asset class only.';
	}

	/**
	 * Save PNG bytes as an attachment parented to the post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $png     PNG bytes.
	 * @return int|\WP_Error Attachment id.
	 */
	private static function store( int $post_id, string $png ) {
		$upload = wp_upload_bits( 'rssai-card-' . $post_id . '-' . time() . '.png', null, $png );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'rssai_upload', (string) $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => get_the_title( $post_id ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id, true );
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
	 * Delete the previous card attachment so we don't orphan uploads.
	 *
	 * @param int $post_id    Post id.
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
			'rssai_social_image',
			__( 'Social image', 'hti-rss-ai' ),
			array( __CLASS__, 'render_meta_box' ),
			'news',
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
		$thumb = get_the_post_thumbnail( $post->ID, array( 280, 280 ) );
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
							__( 'Photo source: %s', 'hti-rss-ai' ),
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
			esc_html__( 'Regenerate image', 'hti-rss-ai' )
		);
		echo '<p class="description">' . esc_html__( 'Renders the branded square card (1080×1080) with a fresh AI photo.', 'hti-rss-ai' ) . '</p>';
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

		$lang  = (string) get_post_meta( $post_id, 'rssai_lang', true );
		$lang  = in_array( $lang, array( 'en', 'pt' ), true ) ? $lang : 'en';
		$group = null;
		$gid   = (int) get_post_meta( $post_id, 'rssai_group_id', true );
		if ( $gid > 0 ) {
			$group = Groups::get( $gid );
		}
		$data = array(
			'headline'           => get_the_title( $post_id ),
			'suggested_category' => '',
		);

		$ok = self::maybe_generate( $post_id, $data, $group, $lang );

		wp_safe_redirect(
			add_query_arg(
				array( 'rssai_card' => $ok ? 'ok' : 'fail' ),
				get_edit_post_link( $post_id, 'url' )
			)
		);
		exit;
	}
}
