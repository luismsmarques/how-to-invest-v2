<?php
/**
 * Minimal Gemini REST client with Google Search grounding.
 *
 * One grounded call returns the model's text (expected to contain a JSON
 * object) plus the grounding sources (web URIs/titles) for citation. The API
 * key is reused from HTI Engine (HTI_GEMINI_API_KEY or the rssai_gemini_api_key
 * filter); never stored by this plugin.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Gemini generateContent endpoint.
 */
class Gemini_Client {

	/**
	 * Resolve the API key (constant or filter).
	 */
	public static function api_key(): string {
		$key = defined( 'HTI_GEMINI_API_KEY' ) ? (string) constant( 'HTI_GEMINI_API_KEY' ) : '';
		return (string) apply_filters( 'rssai_gemini_api_key', $key );
	}

	/**
	 * Whether a key is configured.
	 */
	public static function available(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Grounded generation.
	 *
	 * @param string $system System instruction.
	 * @param string $user   User prompt.
	 * @return array{text:string,sources:array<int,array{title:string,url:string}>}|\WP_Error
	 */
	public static function generate_grounded( string $system, string $user ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}
		$model = (string) Settings::get( 'gemini_model', 'gemini-2.5-flash' );
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );

		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user ) ),
				),
			),
			'tools'             => array( array( 'google_search' => new \stdClass() ) ),
			'generationConfig'  => array( 'temperature' => 0.3 ),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'rssai_api', $message );
		}

		$candidate = $json['candidates'][0] ?? null;
		if ( ! is_array( $candidate ) ) {
			return new \WP_Error( 'rssai_empty', __( 'Empty response from Gemini.', 'hti-rss-ai' ) );
		}

		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}

		$sources = array();
		foreach ( (array) ( $candidate['groundingMetadata']['groundingChunks'] ?? array() ) as $chunk ) {
			if ( ! empty( $chunk['web']['uri'] ) ) {
				$sources[] = array(
					'title' => (string) ( $chunk['web']['title'] ?? '' ),
					'url'   => (string) $chunk['web']['uri'],
				);
			}
		}

		return array(
			'text'    => $text,
			'sources' => $sources,
		);
	}

	/**
	 * Extract the first JSON object from a text blob (tolerant of code fences).
	 *
	 * @param string $text Model text.
	 * @return array<string,mixed>|null
	 */
	public static function extract_json( string $text ): ?array {
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $matches ) ) {
			$text = $matches[1];
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : null;
	}
}
