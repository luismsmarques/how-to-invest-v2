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
	 * The configured TTS model (filterable).
	 */
	public static function tts_model(): string {
		return (string) apply_filters( 'hti_social_gemini_tts_model', 'gemini-2.5-flash-preview-tts' );
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

	/**
	 * Text-to-speech: narrate a line and return a self-contained WAV (so the
	 * browser can decodeAudioData it directly). Gemini's TTS returns raw 16-bit
	 * PCM; we wrap it in a WAV header server-side.
	 *
	 * @param string $text  The line to speak (already sanitised by the caller).
	 * @param string $voice A Gemini prebuilt voice name (e.g. Kore, Puck).
	 * @return array{wav:string,mime:string,rate:int}|\WP_Error
	 */
	public static function tts( string $text, string $voice = 'Kore' ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'hti_social_no_key', __( 'Gemini API key is not configured.', 'hti-social' ) );
		}
		$text = trim( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'hti_social_tts_empty', __( 'Nothing to narrate.', 'hti-social' ) );
		}
		$voice = preg_replace( '/[^A-Za-z]/', '', $voice );
		$voice = '' !== $voice ? $voice : 'Kore';

		$url  = self::ENDPOINT . rawurlencode( self::tts_model() ) . ':generateContent?key=' . rawurlencode( $key );
		$body = array(
			'contents'         => array(
				array( 'parts' => array( array( 'text' => $text ) ) ),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'AUDIO' ),
				'speechConfig'       => array(
					'voiceConfig' => array(
						'prebuiltVoiceConfig' => array( 'voiceName' => $voice ),
					),
				),
			),
		);

		$started = microtime( true );
		Logger::log( 'info', 'tts_request', 'Calling Gemini TTS', array( 'model' => self::tts_model(), 'voice' => $voice, 'chars' => strlen( $text ) ) );

		// The preview TTS model returns transient 429/5xx sporadically. Retry a
		// few times with backoff so one flaky segment doesn't kill the batch.
		$res      = null;
		$code     = 0;
		$attempts = 0;
		$max      = 3;
		while ( $attempts < $max ) {
			++$attempts;
			$res  = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( ! is_wp_error( $res ) && 200 === $code ) {
				break;
			}
			$transient = is_wp_error( $res ) || in_array( $code, array( 429, 500, 502, 503, 504 ), true );
			if ( ! $transient || $attempts >= $max ) {
				break;
			}
			Logger::log(
				'info',
				'tts_retry',
				sprintf( 'TTS retry %d/%d', $attempts, $max ),
				array( 'reason' => is_wp_error( $res ) ? $res->get_error_message() : ( 'HTTP ' . $code ) )
			);
			sleep( $attempts ); // 1s, then 2s.
		}

		if ( is_wp_error( $res ) ) {
			Logger::log( 'error', 'tts_error', $res->get_error_message() );
			return $res;
		}
		if ( 200 !== (int) $code ) {
			Logger::log( 'error', 'tts_http', sprintf( 'Gemini TTS HTTP %d', (int) $code ), array( 'body' => substr( (string) wp_remote_retrieve_body( $res ), 0, 300 ) ) );
			return new \WP_Error( 'hti_social_http', sprintf( 'Gemini TTS HTTP %d', (int) $code ) );
		}

		$data   = json_decode( wp_remote_retrieve_body( $res ), true );
		$part   = $data['candidates'][0]['content']['parts'][0]['inlineData'] ?? array();
		$b64    = $part['data'] ?? '';
		$mime   = (string) ( $part['mimeType'] ?? 'audio/L16;rate=24000' );
		if ( '' === $b64 ) {
			return new \WP_Error( 'hti_social_tts_empty', __( 'Empty audio from Gemini.', 'hti-social' ) );
		}

		$pcm = base64_decode( $b64, true );
		if ( false === $pcm || '' === $pcm ) {
			return new \WP_Error( 'hti_social_tts_decode', __( 'Could not decode the audio.', 'hti-social' ) );
		}

		$rate = 24000;
		if ( preg_match( '/rate=(\d+)/', $mime, $m ) ) {
			$rate = (int) $m[1];
		}
		$wav = self::pcm_to_wav( $pcm, $rate );

		Logger::log( 'info', 'tts_ok', 'Gemini TTS responded', array( 'ms' => (int) ( ( microtime( true ) - $started ) * 1000 ), 'bytes' => strlen( $pcm ) ) );

		return array(
			'wav'  => base64_encode( $wav ),
			'mime' => 'audio/wav',
			'rate' => $rate,
		);
	}

	/**
	 * Wrap raw 16-bit little-endian mono PCM in a minimal WAV container.
	 *
	 * @param string $pcm  Raw PCM bytes.
	 * @param int    $rate Sample rate (Hz).
	 * @return string WAV bytes.
	 */
	private static function pcm_to_wav( string $pcm, int $rate ): string {
		$channels    = 1;
		$bits        = 16;
		$data_len    = strlen( $pcm );
		$byte_rate   = $rate * $channels * ( $bits / 8 );
		$block_align = $channels * ( $bits / 8 );

		$header  = 'RIFF';
		$header .= pack( 'V', 36 + $data_len );
		$header .= 'WAVE';
		$header .= 'fmt ';
		$header .= pack( 'V', 16 );
		$header .= pack( 'v', 1 ); // PCM.
		$header .= pack( 'v', $channels );
		$header .= pack( 'V', $rate );
		$header .= pack( 'V', (int) $byte_rate );
		$header .= pack( 'v', (int) $block_align );
		$header .= pack( 'v', $bits );
		$header .= 'data';
		$header .= pack( 'V', $data_len );

		return $header . $pcm;
	}
}
