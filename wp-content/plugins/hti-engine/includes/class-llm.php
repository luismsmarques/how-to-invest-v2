<?php
/**
 * LLM transport adapter — prefers the WordPress 7.0 AI Client, falls back to
 * our direct Gemini client.
 *
 * This is ONLY the transport. The decision (engine), the prompt, the schema +
 * semantic validation and the curated fallback are unchanged — so swapping the
 * transport never weakens the safety guarantees. If WP's AI Client is present
 * and a connector is configured (Settings → Connectors), it is used (and the
 * provider/model — Gemini, Claude, OpenAI — is chosen there). Otherwise we use
 * the built-in Gemini client. Any error degrades to the next option.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic text generation for the explainer.
 */
class LLM {

	/**
	 * Generate the explanation JSON. Returns a decoded array, or null.
	 *
	 * @param string $system System instruction.
	 * @param string $user   User prompt.
	 * @return array<string,mixed>|null
	 */
	public static function generate( string $system, string $user ): ?array {
		if ( self::use_wp_ai_client() ) {
			$data = self::via_wp_ai_client( $system, $user );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		// Fallback transport: our own Gemini client.
		return Gemini::generate( $system, $user );
	}

	/**
	 * Whether the WordPress AI Client should be used (present + not filtered off).
	 */
	private static function use_wp_ai_client(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		/**
		 * Filter whether to route LLM calls through WordPress's AI Client.
		 *
		 * @param bool $use Default true when the AI Client is available.
		 */
		return (bool) apply_filters( 'hti_use_wp_ai_client', true );
	}

	/**
	 * Call the WordPress AI Client. Defensive: any API mismatch returns null so
	 * the caller falls back. The model/provider is whatever the site configured
	 * under Settings → Connectors.
	 *
	 * @param string $system System instruction.
	 * @param string $user   User prompt.
	 * @return array<string,mixed>|null
	 */
	private static function via_wp_ai_client( string $system, string $user ): ?array {
		try {
			// Our prompt already mandates JSON-only output, so a plain text
			// generation is enough; the validator guards the result either way.
			$builder = wp_ai_client_prompt( $system . "\n\n" . $user );

			if ( is_object( $builder ) && method_exists( $builder, 'using_temperature' ) ) {
				$builder = $builder->using_temperature( 0.3 );
			}

			$text = is_object( $builder ) && method_exists( $builder, 'generate_text' )
				? $builder->generate_text()
				: null;

			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				return null;
			}

			$data = json_decode( self::strip_fences( $text ), true );
			return is_array( $data ) ? $data : null;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[hti-engine][llm] wp_ai_client unavailable: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return null;
		}
	}

	/**
	 * Strip a Markdown ```json code fence if a model wrapped the JSON in one.
	 *
	 * @param string $text Raw text.
	 */
	public static function strip_fences( string $text ): string {
		$text = trim( $text );
		if ( str_starts_with( $text, '```' ) ) {
			$text = (string) preg_replace( '/^```[a-zA-Z]*\s*/', '', $text );
			$text = (string) preg_replace( '/\s*```$/', '', $text );
		}
		return trim( $text );
	}
}
