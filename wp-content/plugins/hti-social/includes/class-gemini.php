<?php
/**
 * Minimal server-side Gemini client for social-caption generation.
 *
 * The API key stays server-side (invariant: never in client JS/HTML). Reads the
 * shared HTI_GEMINI_API_KEY constant, overridable via the
 * `hti_social_gemini_api_key` filter. JSON mode; safe, best-effort.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny Gemini wrapper.
 */
class Gemini {

	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * The configured model (filterable).
	 */
	public static function model(): string {
		return (string) apply_filters( 'hti_social_gemini_model', 'gemini-2.5-flash' );
	}

	/**
	 * The API key, or '' if not configured. Resolved like hti-engine: the
	 * wp-config constant first, then the shared `htinvest_settings` option
	 * (Settings → HowToInvest), then the filter override.
	 */
	public static function api_key(): string {
		$key = '';
		if ( defined( 'HTI_GEMINI_API_KEY' ) && is_string( HTI_GEMINI_API_KEY ) ) {
			$key = trim( (string) HTI_GEMINI_API_KEY );
		}
		if ( '' === $key && function_exists( 'get_option' ) ) {
			$settings = get_option( 'htinvest_settings' );
			if ( is_array( $settings ) && ! empty( $settings['gemini_api_key'] ) ) {
				$key = trim( (string) $settings['gemini_api_key'] );
			}
		}
		return (string) apply_filters( 'hti_social_gemini_api_key', $key );
	}

	/**
	 * Whether a key is available.
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Generate a JSON object from a prompt. Returns an associative array, or a
	 * WP_Error on failure.
	 *
	 * @param string $prompt The full prompt.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function json( string $prompt ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'hti_social_no_key', __( 'Gemini API key is not configured.', 'hti-social' ) );
		}

		$url  = self::ENDPOINT . rawurlencode( self::model() ) . ':generateContent?key=' . rawurlencode( $key );
		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.85,
				'responseMimeType' => 'application/json',
			),
		);

		$started = microtime( true );
		Logger::log( 'info', 'gemini_request', 'Calling Gemini', array( 'model' => self::model() ) );

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			Logger::log( 'error', 'gemini_error', $res->get_error_message() );
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== (int) $code ) {
			Logger::log( 'error', 'gemini_http', sprintf( 'Gemini HTTP %d', (int) $code ), array( 'body' => substr( (string) wp_remote_retrieve_body( $res ), 0, 200 ) ) );
			return new \WP_Error( 'hti_social_http', sprintf( 'Gemini HTTP %d', (int) $code ) );
		}
		Logger::log( 'info', 'gemini_ok', 'Gemini responded', array( 'ms' => (int) ( ( microtime( true ) - $started ) * 1000 ) ) );

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( '' === $text ) {
			return new \WP_Error( 'hti_social_empty', __( 'Empty response from Gemini.', 'hti-social' ) );
		}

		$parsed = json_decode( (string) $text, true );
		if ( ! is_array( $parsed ) ) {
			return new \WP_Error( 'hti_social_parse', __( 'Could not parse the AI response.', 'hti-social' ) );
		}
		return $parsed;
	}
}
