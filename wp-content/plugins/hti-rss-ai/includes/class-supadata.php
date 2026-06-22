<?php
/**
 * Supadata transcript client (supadata.ai): fetch the transcript of a YouTube
 * video as plain text. Server-side; the key never reaches the browser.
 *
 * The base URL/path are filterable so the exact endpoint can be corrected
 * without a code change if Supadata's API differs.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Thin Supadata wrapper.
 */
class Supadata {

	private const ENDPOINT = 'https://api.supadata.ai/v1/youtube/transcript';

	/**
	 * Whether an API key is available.
	 */
	public static function is_configured(): bool {
		return '' !== Settings::supadata_api_key();
	}

	/**
	 * Transcript endpoint (filterable).
	 */
	public static function endpoint(): string {
		return (string) apply_filters( 'rssai_supadata_endpoint', self::ENDPOINT );
	}

	/**
	 * Fetch the transcript text for a video.
	 *
	 * @param string $video_url Full watch URL.
	 * @param string $lang      Preferred language code (optional).
	 * @return string|\WP_Error  Plain-text transcript, or error.
	 */
	public static function transcript( string $video_url, string $lang = '' ) {
		$key = Settings::supadata_api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rssai_supa_no_key', __( 'Supadata API key is not configured.', 'hti-rss-ai' ) );
		}

		$params = array(
			'url'  => $video_url,
			'text' => 'true',
		);
		if ( '' !== $lang ) {
			$params['lang'] = $lang;
		}
		$url = self::endpoint() . '?' . http_build_query( $params );

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 45,
				'headers' => array( 'x-api-key' => $key ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Logger::log( 'supadata', 'Request error: ' . $res->get_error_message() );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = $body['message'] ?? ( $body['error'] ?? ( 'HTTP ' . $code ) );
			Logger::log( 'supadata', 'HTTP ' . $code . ': ' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
			return new \WP_Error( 'rssai_supa_http', is_string( $msg ) ? $msg : ( 'HTTP ' . $code ) );
		}

		$text = self::extract_text( $body );
		if ( '' === $text ) {
			Logger::log( 'supadata', 'Empty transcript for ' . $video_url );
			return new \WP_Error( 'rssai_supa_empty', __( 'No transcript was returned for this video.', 'hti-rss-ai' ) );
		}
		Logger::log( 'supadata', 'Transcript fetched (' . strlen( $text ) . ' chars) for ' . $video_url );
		return $text;
	}

	/**
	 * Pull plain text out of Supadata's response shape (string content, or an
	 * array of segments with "text").
	 *
	 * @param mixed $body Decoded response.
	 */
	private static function extract_text( $body ): string {
		if ( ! is_array( $body ) ) {
			return is_string( $body ) ? trim( $body ) : '';
		}
		$content = $body['content'] ?? ( $body['transcript'] ?? '' );
		if ( is_string( $content ) ) {
			return trim( $content );
		}
		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $seg ) {
				if ( is_array( $seg ) && isset( $seg['text'] ) ) {
					$parts[] = (string) $seg['text'];
				} elseif ( is_string( $seg ) ) {
					$parts[] = $seg;
				}
			}
			return trim( implode( ' ', $parts ) );
		}
		return '';
	}
}
