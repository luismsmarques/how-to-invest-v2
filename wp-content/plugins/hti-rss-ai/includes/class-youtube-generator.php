<?php
/**
 * Generate a `news` post from a single YouTube video item: fetch the transcript
 * (Supadata, on demand) → Gemini (the transcript is the source) → validated,
 * attributed, pending-review article. Reuses Generator's HTML + daily-limit
 * helpers.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube-video → article generator.
 */
class YouTube_Generator {

	/**
	 * Generate an article of a given type from a video item.
	 *
	 * @param int    $item_id Item id (a YouTube video).
	 * @param string $type    news|quote|tutorial|summary.
	 * @return int|\WP_Error New post id, or error.
	 */
	public static function generate( int $item_id, string $type ) {
		$type = array_key_exists( $type, Prompt::youtube_types() ) ? $type : 'news';

		if ( ! post_type_exists( Settings::post_type() ) ) {
			return new \WP_Error( 'rssai_no_type', __( 'The configured target post type does not exist — pick one in Settings.', 'hti-rss-ai' ) );
		}
		if ( ! Gemini_Client::available() ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}
		if ( Generator::over_daily_limit() ) {
			return new \WP_Error( 'rssai_limit', __( 'Daily generation limit reached.', 'hti-rss-ai' ) );
		}

		$item = Items::get( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'rssai_no_item', __( 'Item not found.', 'hti-rss-ai' ) );
		}
		if ( empty( $item->video_id ) ) {
			return new \WP_Error( 'rssai_not_video', __( 'This item is not a YouTube video.', 'hti-rss-ai' ) );
		}

		$transcript = (string) ( $item->transcript ?? '' );
		if ( '' === trim( $transcript ) ) {
			$fetched = Supadata::transcript( (string) $item->link, (string) $item->lang );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			$transcript = (string) $fetched;
			Items::update( $item_id, array( 'transcript' => $transcript ) );
		}

		$lang = Settings::valid_lang( (string) $item->lang );

		$result = Gemini_Client::generate(
			Prompt::youtube_system( $lang, $type ),
			Prompt::youtube_user( $item, $transcript, $type )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = Gemini_Client::extract_json( $result['text'] );
		if ( null === $data ) {
			return new \WP_Error( 'rssai_parse', __( 'Could not parse the model output as JSON.', 'hti-rss-ai' ) );
		}

		// Always attribute the source video (and guarantee a non-empty sources list).
		$video_source = array(
			'title' => trim( ( (string) $item->source ) . ' — ' . (string) $item->title ),
			'url'   => (string) $item->link,
		);
		$existing = isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array();
		array_unshift( $existing, $video_source );
		$data['sources'] = $existing;

		$valid = Validator::validate( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post_id = self::create_news( $item, $data, $lang, $type );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::maybe_set_thumbnail( $post_id, (string) $item->image_url, (string) $item->title );

		Items::update( $item_id, array( 'status' => 'used' ) );
		Generator::bump_daily();

		return $post_id;
	}

	/**
	 * Create the pending news post from the video + generated data.
	 *
	 * @param object              $item Item row.
	 * @param array<string,mixed> $data Validated article.
	 * @param string              $lang Language slug.
	 * @param string              $type Content type.
	 * @return int|\WP_Error
	 */
	private static function create_news( object $item, array $data, string $lang, string $type ) {
		$content  = Generator::blocks_to_html( (array) $data['body_blocks'] );
		$content .= Generator::sources_html( (array) ( $data['sources'] ?? array() ), $lang );
		$content .= Generator::disclaimer_html( $lang );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Settings::post_type(),
					'post_status'  => Settings::post_status(),
					'post_title'   => sanitize_text_field( (string) $data['headline'] ),
					'post_name'    => sanitize_title( (string) ( $data['slug'] ?? $data['headline'] ) ),
					'post_content' => $content,
					'post_excerpt' => sanitize_text_field( (string) ( $data['dek'] ?? $data['meta_description'] ?? '' ) ),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, 'rssai_source_kind', 'youtube' );
		update_post_meta( $post_id, 'rssai_youtube_type', $type );
		update_post_meta( $post_id, 'rssai_youtube_video_id', (string) $item->video_id );
		update_post_meta( $post_id, 'rssai_lang', $lang );
		update_post_meta( $post_id, 'rssai_sources', array_values( (array) ( $data['sources'] ?? array() ) ) );
		update_post_meta( $post_id, 'rssai_model', (string) Settings::get( 'gemini_model', '' ) );
		update_post_meta( $post_id, 'rssai_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_rssai_meta_description', sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) ) );

		$taxonomy = Settings::taxonomy();
		if ( ! empty( $data['suggested_category'] ) && '' !== $taxonomy ) {
			$term = get_term_by( 'name', sanitize_text_field( (string) $data['suggested_category'] ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
			}
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}

		Logger::log( 'generate', sprintf( 'YouTube %s → post %d: %s', $type, $post_id, (string) $data['headline'] ) );
		return $post_id;
	}

	/**
	 * Best-effort: sideload the video thumbnail as the featured image.
	 *
	 * @param int    $post_id Post id.
	 * @param string $url     Thumbnail URL.
	 * @param string $alt     Alt/description.
	 */
	private static function maybe_set_thumbnail( int $post_id, string $url, string $alt ): void {
		if ( '' === $url || has_post_thumbnail( $post_id ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return;
		}
		$file = array(
			'name'     => 'youtube-' . $post_id . '.jpg',
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return;
		}
		set_post_thumbnail( $post_id, (int) $attachment_id );
	}
}
