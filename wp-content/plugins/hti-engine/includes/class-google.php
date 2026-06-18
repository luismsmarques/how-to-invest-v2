<?php
/**
 * "Sign in with Google" — OAuth 2.0 Authorization Code flow (P1).
 *
 * Server-side only: the client secret never reaches the browser. The flow binds
 * a server-stored `state` (CSRF) that also carries the anonymous session token
 * to claim and the locale. On callback we verify the Google-issued id_token
 * (received directly from Google over TLS), find or create a native wp_user by
 * the verified email, sign them in, and claim their pending profile.
 *
 * Configure HTI_GOOGLE_CLIENT_ID / HTI_GOOGLE_CLIENT_SECRET (wp-config or env),
 * or the Settings fields. Register the redirect URI shown on the settings page
 * in the Google Cloud console.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Google OAuth login.
 */
class Google {

	private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	private const STATE_TTL      = 600;
	private const STATE_PREFIX   = 'hti_goog_';

	/**
	 * Hook the start + callback handler.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	/**
	 * Client id (constant / env / settings).
	 */
	public static function client_id(): string {
		if ( defined( 'HTI_GOOGLE_CLIENT_ID' ) && is_string( HTI_GOOGLE_CLIENT_ID ) ) {
			return trim( HTI_GOOGLE_CLIENT_ID );
		}
		$env = getenv( 'HTI_GOOGLE_CLIENT_ID' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		return is_array( $settings ) && ! empty( $settings['google_client_id'] ) ? trim( (string) $settings['google_client_id'] ) : '';
	}

	/**
	 * Client secret (constant / env / settings). Never echoed.
	 */
	private static function client_secret(): string {
		if ( defined( 'HTI_GOOGLE_CLIENT_SECRET' ) && is_string( HTI_GOOGLE_CLIENT_SECRET ) ) {
			return trim( HTI_GOOGLE_CLIENT_SECRET );
		}
		$env = getenv( 'HTI_GOOGLE_CLIENT_SECRET' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		return is_array( $settings ) && ! empty( $settings['google_client_secret'] ) ? trim( (string) $settings['google_client_secret'] ) : '';
	}

	/**
	 * Whether Google login is configured.
	 */
	public static function is_configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * The redirect URI (must be registered in the Google console, verbatim).
	 */
	public static function redirect_uri(): string {
		return add_query_arg( 'hti_google', 'callback', home_url( '/' ) );
	}

	/**
	 * Front-end "start" URL the button points to.
	 */
	public static function start_url(): string {
		return add_query_arg( 'hti_google', 'start', home_url( '/' ) );
	}

	/**
	 * Route the start/callback requests.
	 */
	public static function handle(): void {
		$step = isset( $_GET['hti_google'] ) ? sanitize_text_field( wp_unslash( $_GET['hti_google'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is the CSRF token.
		if ( 'start' === $step ) {
			self::start();
		} elseif ( 'callback' === $step ) {
			self::callback();
		}
	}

	/**
	 * Build state, stash the pending claim, and redirect to Google.
	 */
	private static function start(): void {
		if ( ! self::is_configured() ) {
			self::fail();
		}

		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$state = wp_generate_password( 32, false );
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'token'  => $token,
				'locale' => $locale,
			),
			self::STATE_TTL
		);

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( self::client_id() ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'access_type'   => 'online',
				'prompt'        => 'select_account',
			),
			self::AUTH_ENDPOINT
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external OAuth provider.
		exit;
	}

	/**
	 * Validate state, exchange the code, sign in, claim, redirect.
	 */
	private static function callback(): void {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data  = $state ? get_transient( self::STATE_PREFIX . $state ) : false;
		if ( ! is_array( $data ) ) {
			self::fail();
		}
		delete_transient( self::STATE_PREFIX . $state );

		if ( isset( $_GET['error'] ) || empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::fail();
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$claims = self::exchange( $code );
		if ( null === $claims || empty( $claims['email'] ) || empty( $claims['email_verified'] ) ) {
			self::fail();
		}

		$user = self::find_or_create( $claims );
		if ( ! $user instanceof \WP_User ) {
			self::fail();
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		if ( ! empty( $data['token'] ) ) {
			REST::claim_for_user( $user->ID, (string) $data['token'] );
		}

		wp_safe_redirect( add_query_arg( 'verified', '1', home_url( '/my-account/' ) ) );
		exit;
	}

	/**
	 * Exchange the auth code for tokens and return the id_token claims.
	 *
	 * @param string $code Authorization code.
	 * @return array<string,mixed>|null
	 */
	private static function exchange( string $code ): ?array {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => array(
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['id_token'] ) ) {
			return null;
		}

		// The id_token comes directly from Google over TLS, so its claims can be
		// trusted without re-verifying the signature.
		return self::decode_jwt_payload( (string) $body['id_token'] );
	}

	/**
	 * Decode (without verifying) the payload of a JWT. Pure — unit-tested.
	 *
	 * @param string $jwt JWT string.
	 * @return array<string,mixed>|null
	 */
	public static function decode_jwt_payload( string $jwt ): ?array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$b64 = strtr( $parts[1], '-_', '+/' );
		$pad = strlen( $b64 ) % 4;
		if ( $pad ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}
		$json = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Find a user by verified email, or create a verified subscriber.
	 *
	 * @param array<string,mixed> $claims id_token claims.
	 * @return \WP_User|null
	 */
	private static function find_or_create( array $claims ) {
		$email = sanitize_email( (string) $claims['email'] );
		if ( ! is_email( $email ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, Verification::META_VERIFIED, '1' ); // Google verified the email.
			if ( ! empty( $claims['sub'] ) && '' === (string) get_user_meta( $user->ID, 'hti_google_sub', true ) ) {
				update_user_meta( $user->ID, 'hti_google_sub', sanitize_text_field( (string) $claims['sub'] ) );
			}
			return $user;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => isset( $claims['name'] ) ? sanitize_text_field( (string) $claims['name'] ) : $email,
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		update_user_meta( $user_id, Verification::META_VERIFIED, '1' );
		if ( ! empty( $claims['sub'] ) ) {
			update_user_meta( $user_id, 'hti_google_sub', sanitize_text_field( (string) $claims['sub'] ) );
		}

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Abort the flow gracefully back to the account page.
	 */
	private static function fail(): void {
		wp_safe_redirect( add_query_arg( 'verify_error', '1', home_url( '/my-account/' ) ) );
		exit;
	}
}
