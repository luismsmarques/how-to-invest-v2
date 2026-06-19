<?php
/**
 * Minimal client for Google's image generation (Imagen) via the Generative
 * Language API. Returns raw image bytes for the featured-image card.
 *
 * Reuses the same API key resolution as the text client (Gemini_Client); the
 * key is never stored by this plugin. Image generation is a paid feature and
 * requires a billing-enabled key with image access — callers must degrade
 * gracefully when this returns a WP_Error.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Imagen predict endpoint.
 */
class Image_Client {

	/**
	 * Whether image generation is enabled and a key is available.
	 */
	public static function available(): bool {
		return ! empty( Settings::get( 'image_generate', 1 ) ) && Gemini_Client::available();
	}

	/**
	 * Generate one image from a prompt.
	 *
	 * @param string $prompt       Text prompt.
	 * @param string $aspect_ratio One of 1:1, 3:4, 4:3, 16:9, 9:16.
	 * @return string|\WP_Error Raw image bytes (PNG/JPEG), or error.
	 */
	public static function generate( string $prompt, string $aspect_ratio = '16:9' ) {
		$key = Gemini_Client::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}

		$model = (string) Settings::get( 'image_model', 'imagen-3.0-generate-002' );
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':predict?key=' . rawurlencode( $key );

		$body = array(
			'instances'  => array( array( 'prompt' => $prompt ) ),
			'parameters' => array(
				'sampleCount'      => 1,
				'aspectRatio'      => $aspect_ratio,
				'personGeneration' => 'dont_allow',
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
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
			return new \WP_Error( 'rssai_image_api', $message );
		}

		$b64 = self::extract_base64( is_array( $json ) ? $json : array() );
		if ( null === $b64 ) {
			return new \WP_Error( 'rssai_image_empty', __( 'No image returned by the model.', 'hti-rss-ai' ) );
		}

		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'rssai_image_decode', __( 'Could not decode the generated image.', 'hti-rss-ai' ) );
		}

		return $bytes;
	}

	/**
	 * Pull the base64 image out of the various response shapes Imagen/Gemini use.
	 *
	 * @param array<string,mixed> $json Decoded response.
	 */
	public static function extract_base64( array $json ): ?string {
		// Imagen predict: predictions[].bytesBase64Encoded.
		foreach ( (array) ( $json['predictions'] ?? array() ) as $pred ) {
			if ( is_array( $pred ) ) {
				if ( ! empty( $pred['bytesBase64Encoded'] ) ) {
					return (string) $pred['bytesBase64Encoded'];
				}
				if ( ! empty( $pred['image']['imageBytes'] ) ) {
					return (string) $pred['image']['imageBytes'];
				}
			}
		}
		// Gemini image generation: candidates[].content.parts[].inlineData.data.
		foreach ( (array) ( $json['candidates'] ?? array() ) as $cand ) {
			foreach ( (array) ( $cand['content']['parts'] ?? array() ) as $part ) {
				if ( ! empty( $part['inlineData']['data'] ) ) {
					return (string) $part['inlineData']['data'];
				}
				if ( ! empty( $part['inline_data']['data'] ) ) {
					return (string) $part['inline_data']['data'];
				}
			}
		}
		return null;
	}
}
