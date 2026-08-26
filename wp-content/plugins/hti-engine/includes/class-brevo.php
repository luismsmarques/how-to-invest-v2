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
	 * The configured newsletter list id for a language. Falls back to the
	 * legacy single list when the per-language one isn't set.
	 *
	 * @param string $locale 'en' | 'pt' | '' (legacy/any).
	 */
	public static function list_id( string $locale = '' ): int {
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		$settings = is_array( $settings ) ? $settings : array();

		if ( 'pt' === $locale && ! empty( $settings['brevo_list_id_pt'] ) ) {
			return (int) $settings['brevo_list_id_pt'];
		}
		if ( 'en' === $locale && ! empty( $settings['brevo_list_id_en'] ) ) {
			return (int) $settings['brevo_list_id_en'];
		}
		// Legacy single list (or fallback when a language list is missing).
		return (int) ( $settings['brevo_list_id'] ?? 0 );
	}

	/**
	 * Map of languages that have a configured list: [ locale => list_id ].
	 *
	 * @return array<string,int>
	 */
	public static function lists_by_language(): array {
		$out = array();
		foreach ( array( 'en', 'pt' ) as $locale ) {
			$id = self::list_id( $locale );
			if ( $id > 0 ) {
				$out[ $locale ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether at least one newsletter list is configured.
	 */
	public static function any_list_configured(): bool {
		return ! empty( self::lists_by_language() );
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
	 * Permanently delete a contact from Brevo (RGPD erasure). A 404 (already
	 * gone) counts as success. Returns false if the key lacks delete rights or
	 * the request errors — the caller should then fall back to list removal.
	 *
	 * @param string $email Contact email.
	 */
	public static function delete_contact( string $email ): bool {
		if ( ! self::configured() || ! is_email( $email ) ) {
			return false;
		}
		$res = self::request_data( 'DELETE', '/contacts/' . rawurlencode( $email ) );
		return $res['ok'] || 404 === (int) $res['code'];
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
	 * @return array{ok:bool,code:int,data:array<string,mixed>}
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
			'code' => $code,
			'data' => is_array( $data ) ? $data : array(),
		);
	}
}
