<?php
/**
 * Brevo Contacts/Lists API client (subscriber management).
 *
 * Transactional sending lives in class-mailer.php; this handles the contact
 * side: upserting subscribers into a list with attributes, and removing them on
 * unsubscribe. The API key is resolved through the Mailer (server-side only).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the Brevo REST API for contacts and lists.
 */
class Brevo {

	private const BASE    = 'https://api.brevo.com/v3';
	private const TIMEOUT = 8;

	/**
	 * Whether Brevo is usable (an API key is configured).
	 */
	public static function configured(): bool {
		return Mailer::is_brevo_configured();
	}

	/**
	 * The configured newsletter list id (0 when unset).
	 */
	public static function list_id(): int {
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		return is_array( $settings ) ? (int) ( $settings['brevo_list_id'] ?? 0 ) : 0;
	}

	/**
	 * Create or update a contact (and optionally add it to lists).
	 *
	 * @param string               $email      Contact email.
	 * @param array<string,mixed>  $attributes Brevo attributes (e.g. LANGUAGE).
	 * @param array<int,int>       $list_ids   Lists to add the contact to.
	 */
	public static function upsert_contact( string $email, array $attributes = array(), array $list_ids = array() ): bool {
		if ( ! self::configured() || ! is_email( $email ) ) {
			return false;
		}
		$body = array(
			'email'         => $email,
			'updateEnabled' => true,
		);
		if ( ! empty( $attributes ) ) {
			$body['attributes'] = $attributes;
		}
		if ( ! empty( $list_ids ) ) {
			$body['listIds'] = array_values( array_map( 'intval', $list_ids ) );
		}
		return self::request( 'POST', '/contacts', $body );
	}

	/**
	 * Remove a contact from a list (unsubscribe from that list).
	 *
	 * @param string $email   Contact email.
	 * @param int    $list_id List id.
	 */
	public static function remove_from_list( string $email, int $list_id ): bool {
		if ( ! self::configured() || $list_id <= 0 || ! is_email( $email ) ) {
			return false;
		}
		return self::request(
			'POST',
			'/contacts/lists/' . $list_id . '/contacts/remove',
			array( 'emails' => array( $email ) )
		);
	}

	/**
	 * Perform an API request; returns whether the response was 2xx.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path under the API base.
	 * @param array<string,mixed>|null $body   JSON body.
	 */
	private static function request( string $method, string $path, ?array $body = null ): bool {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'api-key'      => Mailer::api_key(),
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
