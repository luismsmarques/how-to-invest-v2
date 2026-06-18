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
	 * POST /recommend — classify, explain, persist, return.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function recommend( WP_REST_Request $request ) {
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
