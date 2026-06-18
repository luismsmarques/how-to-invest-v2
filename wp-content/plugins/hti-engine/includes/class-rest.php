<?php
/**
 * REST API for the engine (Modelo_Dados §5). Namespace `htinvest/v1`.
 *
 * This commit ships `/recommend`: it takes answers, runs the deterministic
 * engine, gets a validated explanation (LLM or fallback), saves an anonymous
 * profile + result, and returns the contract response. The decision never
 * depends on the LLM — invalid answers are 422; everything else returns 200
 * with a coherent result (fallback text if needed).
 *
 * Account/RGPD routes (claim-profile, my-profiles, account, export) follow.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the plugin's REST routes.
 */
class REST {

	private const NAMESPACE = 'htinvest/v1';

	/**
	 * Hook route registration.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/recommend',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'recommend' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
				'args'                => array(
					'locale'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'answers' => array(
						'type'     => 'object',
						'required' => true,
					),
					'consent' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		// Create a native account and sign in (returns a fresh nonce).
		register_rest_route(
			self::NAMESPACE,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'register_user' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
				'args'                => array(
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Sign in to a native account (returns a fresh nonce).
		register_rest_route(
			self::NAMESPACE,
			'/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login_user' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
				'args'                => array(
					'login'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Link an anonymous profile (by session token) to the logged-in account.
		register_rest_route(
			self::NAMESPACE,
			'/claim-profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'claim_profile' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
				'args'                => array(
					'session_token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// List the authenticated user's saved profiles (dashboard).
		register_rest_route(
			self::NAMESPACE,
			'/my-profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'my_profiles' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
			)
		);

		// RGPD: export all of the user's data (access/portability).
		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
			)
		);

		// RGPD: delete account + all profiles/results in cascade (erasure).
		register_rest_route(
			self::NAMESPACE,
			'/account',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
				'args'                => array(
					'confirm' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Verify the REST nonce (CSRF protection; works for anonymous sessions too).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'hti_forbidden', __( 'Invalid or missing nonce.', 'hti-engine' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Standard 429 response when a per-IP limit is hit.
	 *
	 * @return WP_Error
	 */
	private static function too_many(): WP_Error {
		return new WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'hti-engine' ), array( 'status' => 429 ) );
	}

	/**
	 * Require an authenticated user with a valid nonce (account/RGPD routes).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_auth( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'hti_unauthorized', __( 'You must be signed in.', 'hti-engine' ), array( 'status' => 401 ) );
		}
		return self::check_nonce( $request );
	}

	/**
	 * POST /recommend — classify, explain, persist, return.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function recommend( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'recommend' ) ) {
			return self::too_many();
		}

		$locale  = self::locale( (string) $request->get_param( 'locale' ) );
		$answers = self::sanitize_answers( (array) $request->get_param( 'answers' ) );

		// Deterministic decision (invalid answers → 422).
		try {
			$result = Engine::recommend( $answers );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'hti_invalid_answers', __( 'Some answers are missing or invalid.', 'hti-engine' ), array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'hti_engine_error', __( 'Could not produce a recommendation.', 'hti-engine' ), array( 'status' => 500 ) );
		}

		$archetypes = Config::archetypes();
		$label      = $archetypes[ $result['archetype_id'] ]['label'][ $locale ]
			?? $archetypes[ $result['archetype_id'] ]['label']['en']
			?? '';

		// Explanation (LLM → validate → fallback; always succeeds).
		$explained = Explainer::explain( $result, $answers, $locale, $label );

		$session_token = wp_generate_uuid4();
		$consent       = self::sanitize_consent( (array) $request->get_param( 'consent' ) );

		$profile_id = self::store_profile( $result, $answers, $explained, $locale, $label, $session_token, $consent );
		if ( is_wp_error( $profile_id ) ) {
			return new WP_Error( 'hti_persist_error', __( 'Could not save the profile.', 'hti-engine' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'profile_id'    => $profile_id,
				'session_token' => $session_token,
				'archetype'     => array(
					'id'    => $result['archetype_id'],
					'label' => $label,
				),
				'allocation'    => $result['allocation'],
				'explanation'   => $explained['explanation'],
				'safety_flags'  => $result['safety_flags'],
				'disclaimer'    => Disclaimer::contextual( $locale ),
			),
			200
		);
	}

	/**
	 * POST /register — create a native subscriber account and sign in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register_user( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return new WP_REST_Response( array( 'user_id' => get_current_user_id(), 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
		}

		if ( RateLimit::exceeded( 'register' ) ) {
			return self::too_many();
		}

		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'hti_invalid_email', __( 'Please enter a valid email.', 'hti-engine' ), array( 'status' => 422 ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'hti_weak_password', __( 'Password must be at least 8 characters.', 'hti-engine' ), array( 'status' => 422 ) );
		}
		if ( email_exists( $email ) || username_exists( $email ) ) {
			return new WP_Error( 'hti_exists', __( 'An account with that email already exists.', 'hti-engine' ), array( 'status' => 409 ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'hti_register_failed', __( 'Could not create the account.', 'hti-engine' ), array( 'status' => 500 ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return new WP_REST_Response(
			array(
				'user_id' => (int) $user_id,
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * POST /login — authenticate against a native account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login_user( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'login' ) ) {
			return self::too_many();
		}

		$user = wp_signon(
			array(
				'user_login'    => sanitize_text_field( (string) $request->get_param( 'login' ) ),
				'user_password' => (string) $request->get_param( 'password' ),
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'hti_bad_credentials', __( 'Incorrect email or password.', 'hti-engine' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		return new WP_REST_Response(
			array(
				'user_id' => (int) $user->ID,
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * POST /claim-profile — attach an anonymous profile to the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function claim_profile( WP_REST_Request $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		if ( '' === $token ) {
			return new WP_Error( 'hti_invalid', __( 'Missing session token.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		$user_id = get_current_user_id();

		$found = get_posts(
			array(
				'post_type'   => 'htinvest_profile',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => 'hti_session_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( empty( $found ) ) {
			return new WP_Error( 'hti_not_found', __( 'No matching profile to claim.', 'hti-engine' ), array( 'status' => 404 ) );
		}

		$profile_id = (int) $found[0];
		$owner      = (int) get_post_meta( $profile_id, 'hti_user_id', true );
		if ( $owner && $owner !== $user_id ) {
			return new WP_Error( 'hti_conflict', __( 'This profile is already linked to another account.', 'hti-engine' ), array( 'status' => 409 ) );
		}

		// Identity is set only by this conscious action (data minimization).
		wp_update_post(
			array(
				'ID'          => $profile_id,
				'post_author' => $user_id,
			)
		);
		update_post_meta( $profile_id, 'hti_user_id', $user_id );
		delete_post_meta( $profile_id, 'hti_session_token' );

		return new WP_REST_Response(
			array(
				'profile_id' => $profile_id,
				'claimed'    => true,
			),
			200
		);
	}

	/**
	 * GET /my-profiles — summaries of the user's saved profiles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function my_profiles( WP_REST_Request $request ) {
		$profiles = array();
		foreach ( self::user_profile_ids( get_current_user_id() ) as $id ) {
			$profiles[] = array(
				'profile_id'   => $id,
				'archetype'    => array(
					'id'    => (int) get_post_meta( $id, 'hti_archetype_id', true ),
					'label' => (string) get_post_meta( $id, 'hti_archetype_label', true ),
				),
				'allocation'   => get_post_meta( $id, 'hti_allocation', true ),
				'safety_flags' => get_post_meta( $id, 'hti_safety_flags', true ),
				'locale'       => (string) get_post_meta( $id, 'hti_locale', true ),
				'generated_at' => (string) get_post_meta( $id, 'hti_generated_at', true ),
			);
		}

		return new WP_REST_Response( array( 'profiles' => $profiles ), 200 );
	}

	/**
	 * GET /export — all of the user's data (RGPD access/portability).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		$profiles = array();
		foreach ( self::user_profile_ids( $user->ID ) as $id ) {
			$profiles[] = array(
				'profile_id'         => $id,
				'created_at'         => get_post_field( 'post_date_gmt', $id ),
				'locale'             => get_post_meta( $id, 'hti_locale', true ),
				'answers'            => get_post_meta( $id, 'hti_answers', true ),
				'score'              => get_post_meta( $id, 'hti_score', true ),
				'archetype_id'       => get_post_meta( $id, 'hti_archetype_id', true ),
				'archetype_label'    => get_post_meta( $id, 'hti_archetype_label', true ),
				'allocation'         => get_post_meta( $id, 'hti_allocation', true ),
				'safety_flags'       => get_post_meta( $id, 'hti_safety_flags', true ),
				'explanation'        => get_post_meta( $id, 'hti_explanation', true ),
				'explanation_source' => get_post_meta( $id, 'hti_explanation_source', true ),
				'consent'            => get_post_meta( $id, 'hti_consent', true ),
				'engine_version'     => get_post_meta( $id, 'hti_engine_version', true ),
				'disclaimer_version' => get_post_meta( $id, 'hti_disclaimer_version', true ),
				'generated_at'       => get_post_meta( $id, 'hti_generated_at', true ),
			);
		}

		$data = array(
			'exported_at' => gmdate( 'c' ),
			'account'     => array(
				'id'           => $user->ID,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'registered'   => $user->user_registered,
			),
			'profiles'    => $profiles,
		);

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Content-Disposition', 'attachment; filename="howtoinvest-data-export.json"' );
		return $response;
	}

	/**
	 * DELETE /account — erase account + profiles + results (RGPD, irreversible).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_account( WP_REST_Request $request ) {
		if ( true !== rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'hti_unconfirmed', __( 'Deletion must be explicitly confirmed.', 'hti-engine' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();

		// Cascade: remove every profile (and its meta) first.
		foreach ( self::user_profile_ids( $user_id ) as $id ) {
			wp_delete_post( $id, true );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wp_delete_user( $user_id );

		if ( ! $deleted ) {
			return new WP_Error( 'hti_delete_failed', __( 'Could not delete the account.', 'hti-engine' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * IDs of all profiles owned by a user.
	 *
	 * @param int $user_id User id.
	 * @return list<int>
	 */
	private static function user_profile_ids( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'htinvest_profile',
					'post_status' => 'any',
					'author'      => $user_id,
					'numberposts' => -1,
					'fields'      => 'ids',
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			)
		);
	}

	/**
	 * Persist an anonymous profile + saved result as a private CPT post.
	 *
	 * @param array<string,mixed> $result        Engine result.
	 * @param array<string,mixed> $answers       Sanitized answers.
	 * @param array<string,mixed> $explained     Explainer output.
	 * @param string              $locale        Locale.
	 * @param string              $label         Archetype label.
	 * @param string              $session_token Session token.
	 * @param array<string,mixed> $consent       Consent record.
	 * @return int|WP_Error Post ID or error.
	 */
	private static function store_profile( array $result, array $answers, array $explained, string $locale, string $label, string $session_token, array $consent ) {
		$user_id = get_current_user_id();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'htinvest_profile',
				'post_status' => 'private',
				'post_author' => $user_id,
				'post_title'  => 'profile-' . $session_token,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			'hti_user_id'             => $user_id ? $user_id : null,
			'hti_session_token'       => $session_token,
			'hti_locale'              => $locale,
			'hti_answers'             => $answers,
			'hti_score'               => $result['score'],
			'hti_archetype_id'        => $result['archetype_id'],
			'hti_archetype_label'     => $label,
			'hti_allocation'          => $result['allocation'],
			'hti_safety_flags'        => $result['safety_flags'],
			'hti_explanation'         => $explained['explanation'],
			'hti_explanation_source'  => $explained['source'],
			'hti_consent'             => $consent,
			'hti_engine_version'      => $result['engine_version'],
			'hti_disclaimer_version'  => Disclaimer::VERSION,
			'hti_generated_at'        => gmdate( 'c' ),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return (int) $post_id;
	}

	/**
	 * Normalize a locale to 'en' or 'pt'.
	 *
	 * @param string $locale Raw locale.
	 */
	private static function locale( string $locale ): string {
		return str_starts_with( strtolower( $locale ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Sanitize the answers payload into engine-ready values.
	 *
	 * @param array<string,mixed> $raw Raw answers.
	 * @return array<string,mixed>
	 */
	private static function sanitize_answers( array $raw ): array {
		$out = array();
		foreach ( array( 'p1_horizon', 'p2_goal', 'p3_drop_reaction', 'p4_capacity', 'p5_experience', 'p7_esg', 'p8_crypto' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}
		// P6 is a strict boolean (the emergency-fund trap).
		if ( array_key_exists( 'p6_emergency_fund', $raw ) ) {
			$out['p6_emergency_fund'] = rest_sanitize_boolean( $raw['p6_emergency_fund'] );
		}
		return $out;
	}

	/**
	 * Sanitize the consent record (RGPD).
	 *
	 * @param array<string,mixed> $raw Raw consent.
	 * @return array{analytics:bool,timestamp:string}
	 */
	private static function sanitize_consent( array $raw ): array {
		return array(
			'analytics' => isset( $raw['analytics'] ) ? rest_sanitize_boolean( $raw['analytics'] ) : false,
			'timestamp' => gmdate( 'c' ),
		);
	}
}
