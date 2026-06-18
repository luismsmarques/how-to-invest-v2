<?php
/**
 * Server-side Google Gemini client (Prompt_LLM_Schema §6).
 *
 * The API key lives server-side only (wp-config constant / env var / option)
 * and is sent as a request header — never echoed, never reaches the client.
 * JSON mode, low temperature, short timeout, one retry, then the caller falls
 * back. Returns the decoded explanation object or null on any failure.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Gemini generateContent client.
 */
class Gemini {

	private const DEFAULT_MODEL = 'gemini-2.5-flash';
	private const ENDPOINT      = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
	private const TIMEOUT       = 8;
	private const MAX_ATTEMPTS  = 2;

	/**
	 * Resolve the API key: constant, then env var, then option. Never logged.
	 */
	public static function api_key(): string {
		if ( defined( 'HTI_GEMINI_API_KEY' ) && is_string( HTI_GEMINI_API_KEY ) ) {
			return trim( HTI_GEMINI_API_KEY );
		}
		$env = getenv( 'GEMINI_API_KEY' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		if ( function_exists( 'get_option' ) ) {
			$settings = get_option( 'htinvest_settings' );
			if ( is_array( $settings ) && ! empty( $settings['gemini_api_key'] ) ) {
				return trim( (string) $settings['gemini_api_key'] );
			}
		}
		return '';
	}

	/**
	 * Whether a key is configured (so the caller can skip straight to fallback).
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * The model id (option override, else default).
	 */
	private static function model(): string {
		if ( function_exists( 'get_option' ) ) {
			$settings = get_option( 'htinvest_settings' );
			if ( is_array( $settings ) && ! empty( $settings['gemini_model'] ) ) {
				return (string) $settings['gemini_model'];
			}
		}
		return self::DEFAULT_MODEL;
	}

	/**
	 * Generate the explanation JSON. Returns a decoded array, or null on failure.
	 *
	 * @param string $system System instruction.
	 * @param string $user   User prompt.
	 * @return array<string,mixed>|null
	 */
	public static function generate( string $system, string $user ): ?array {
		$key = self::api_key();
		if ( '' === $key ) {
			return null;
		}

		$body = wp_json_encode(
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $user ) ),
					),
				),
				'generationConfig'  => array(
					'temperature'      => 0.3,
					'responseMimeType' => 'application/json',
					'maxOutputTokens'  => 800,
				),
			)
		);

		$url  = sprintf( self::ENDPOINT, rawurlencode( self::model() ) );
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'x-goog-api-key'  => $key,
			),
			'body'    => $body,
		);

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				self::log( 'request error: ' . $response->get_error_code() );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				self::log( "http {$code}" );
				// Client errors won't improve on retry.
				if ( $code >= 400 && $code < 500 ) {
					return null;
				}
				continue;
			}

			$parsed = self::parse( wp_remote_retrieve_body( $response ) );
			if ( null !== $parsed ) {
				return $parsed;
			}
			self::log( 'unparseable response' );
		}

		return null;
	}

	/**
	 * Extract and decode the JSON object from a Gemini response body.
	 *
	 * @param string $raw Response body.
	 * @return array<string,mixed>|null
	 */
	private static function parse( string $raw ): ?array {
		$envelope = json_decode( $raw, true );
		if ( ! is_array( $envelope ) ) {
			return null;
		}

		$text = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if ( ! is_string( $text ) ) {
			return null;
		}

		$data = json_decode( trim( $text ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Log a diagnostic message (never includes the key or PII).
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[hti-engine][gemini] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
