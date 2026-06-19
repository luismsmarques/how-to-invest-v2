<?php
/**
 * Transactional email via Brevo (formerly Sendinblue), with a wp_mail fallback.
 *
 * The Brevo API key lives server-side only (constant / env / settings) and is
 * sent as a request header — never echoed. If Brevo is not configured the
 * mailer degrades to wp_mail() so verification still works in dev.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Sends transactional HTML email.
 */
class Mailer {

	private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';
	private const TIMEOUT  = 8;

	/**
	 * Resolve the Brevo API key: constant, then env, then option. Never logged.
	 */
	public static function api_key(): string {
		if ( defined( 'HTI_BREVO_API_KEY' ) && is_string( HTI_BREVO_API_KEY ) ) {
			return trim( HTI_BREVO_API_KEY );
		}
		$env = getenv( 'BREVO_API_KEY' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		if ( function_exists( 'get_option' ) ) {
			$settings = get_option( 'htinvest_settings' );
			if ( is_array( $settings ) && ! empty( $settings['brevo_api_key'] ) ) {
				return trim( (string) $settings['brevo_api_key'] );
			}
		}
		return '';
	}

	/**
	 * Whether Brevo is configured (else we fall back to wp_mail).
	 */
	public static function is_brevo_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Sender identity (settings override, else site defaults).
	 *
	 * @return array{email:string,name:string}
	 */
	public static function sender(): array {
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		$settings = is_array( $settings ) ? $settings : array();

		$email = ! empty( $settings['brevo_sender_email'] )
			? (string) $settings['brevo_sender_email']
			: (string) get_option( 'admin_email' );
		$name  = ! empty( $settings['brevo_sender_name'] )
			? (string) $settings['brevo_sender_name']
			: (string) get_option( 'blogname' );

		return array(
			'email' => $email,
			'name'  => $name,
		);
	}

	/**
	 * Build the Brevo API payload (pure — unit-tested).
	 *
	 * @param string                          $to_email Recipient.
	 * @param string                          $subject  Subject.
	 * @param string                          $html     HTML body.
	 * @param array{email:string,name:string} $sender   Sender identity.
	 * @param string                          $reply_to Optional Reply-To address.
	 * @return array<string,mixed>
	 */
	public static function build_payload( string $to_email, string $subject, string $html, array $sender, string $reply_to = '' ): array {
		$payload = array(
			'sender'      => array(
				'email' => $sender['email'],
				'name'  => $sender['name'],
			),
			'to'          => array( array( 'email' => $to_email ) ),
			'subject'     => $subject,
			'htmlContent' => $html,
		);
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$payload['replyTo'] = array( 'email' => $reply_to );
		}
		return $payload;
	}

	/**
	 * Send an HTML email. Returns whether it was accepted/queued.
	 *
	 * @param string $to_email Recipient.
	 * @param string $subject  Subject.
	 * @param string $html     HTML body.
	 * @param string $reply_to Optional Reply-To address (e.g. the visitor).
	 */
	public static function send( string $to_email, string $subject, string $html, string $reply_to = '' ): bool {
		if ( self::is_brevo_configured() ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'api-key'      => self::api_key(),
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode( self::build_payload( $to_email, $subject, $html, self::sender(), $reply_to ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			return $code >= 200 && $code < 300;
		}

		// Fallback: WordPress default mailer.
		$sender  = self::sender();
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $sender['name'], $sender['email'] ),
		);
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return (bool) wp_mail( $to_email, $subject, $html, $headers );
	}
}
