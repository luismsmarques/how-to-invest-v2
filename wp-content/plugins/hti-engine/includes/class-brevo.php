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
	 * Create an email campaign targeting a list and send it immediately.
	 * Returns whether it was created + sent.
	 *
	 * @param string $name    Internal campaign name.
	 * @param string $subject Email subject.
	 * @param string $html    Full HTML body (include an {{ unsubscribe }} link).
	 * @param int    $list_id Target list id.
	 */
	public static function send_campaign( string $name, string $subject, string $html, int $list_id ): bool {
		if ( ! self::configured() || $list_id <= 0 ) {
			return false;
		}
		$sender = Mailer::sender();
		$created = self::request_data(
			'POST',
			'/emailCampaigns',
			array(
				'name'       => $name,
				'subject'    => $subject,
				'sender'     => array( 'name' => $sender['name'], 'email' => $sender['email'] ),
				'htmlContent' => $html,
				'recipients' => array( 'listIds' => array( $list_id ) ),
			)
		);
		if ( ! $created['ok'] || empty( $created['data']['id'] ) ) {
			return false;
		}
		$id = (int) $created['data']['id'];
		return self::request( 'POST', '/emailCampaigns/' . $id . '/sendNow' );
	}

	/**
	 * Perform an API request; returns whether the response was 2xx.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path under the API base.
	 * @param array<string,mixed>|null $body   JSON body.
	 */
	private static function request( string $method, string $path, ?array $body = null ): bool {
		return self::request_data( $method, $path, $body )['ok'];
	}

	/**
	 * Perform an API request; returns the ok flag + decoded JSON body.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path under the API base.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return array{ok:bool,data:array<string,mixed>}
	 */
	private static function request_data( string $method, string $path, ?array $body = null ): array {
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
			return array( 'ok' => false, 'data' => array() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'ok'   => $code >= 200 && $code < 300,
			'data' => is_array( $data ) ? $data : array(),
		);
	}
}
