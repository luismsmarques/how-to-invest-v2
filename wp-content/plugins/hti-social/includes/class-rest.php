<?php
/**
 * REST: AI caption/description generation for reels (and cards later).
 *
 * Server-side only — the Gemini key never reaches the browser. Output follows
 * the brand rules: educational, conditional language, no advice, no named
 * instruments. The caller still edits everything before rendering.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class Rest {

	private const NS = 'hti-social/v1';

	/**
	 * Hook route registration.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/ffmpeg-assets',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ffmpeg_assets' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/caption',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'caption' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'brief' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'lang'  => array(
						'type'              => 'string',
						'default'           => 'pt',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Mirror the ffmpeg.wasm files locally and return their same-origin URLs.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function ffmpeg_assets() {
		$res = Ffmpeg_Cache::ensure();
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'hti_social_ffmpeg', $res->get_error_message(), array( 'status' => 502 ) );
		}
		return new \WP_REST_Response( $res, 200 );
	}

	/**
	 * Generate a title + caption + description (+ hashtags) from a brief.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function caption( \WP_REST_Request $request ) {
		$brief = trim( (string) $request->get_param( 'brief' ) );
		$pt    = 'pt' === $request->get_param( 'lang' );

		if ( '' === $brief ) {
			return new \WP_Error( 'hti_social_brief', __( 'Please describe the video first.', 'hti-social' ), array( 'status' => 400 ) );
		}
		if ( ! Gemini::is_configured() ) {
			return new \WP_Error( 'hti_social_no_key', __( 'The Gemini API key is not configured on the server.', 'hti-social' ), array( 'status' => 503 ) );
		}

		$result = Gemini::json( self::prompt( $brief, $pt ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'hti_social_ai', $result->get_error_message(), array( 'status' => 502 ) );
		}

		$hashtags = array();
		if ( ! empty( $result['hashtags'] ) && is_array( $result['hashtags'] ) ) {
			foreach ( $result['hashtags'] as $h ) {
				$tag = ltrim( wp_strip_all_tags( (string) $h ), '#' );
				if ( '' !== $tag ) {
					$hashtags[] = '#' . $tag;
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'title'       => self::clean( $result['title'] ?? '' ),
				'caption'     => self::clean( $result['caption'] ?? '' ),
				'description' => self::clean( $result['description'] ?? '' ),
				'hashtags'    => array_slice( $hashtags, 0, 8 ),
			),
			200
		);
	}

	/**
	 * Strip tags/whitespace from a model string.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function clean( $v ): string {
		return trim( wp_strip_all_tags( (string) $v ) );
	}

	/**
	 * The guard-railed prompt.
	 *
	 * @param string $brief The user's brief.
	 * @param bool   $pt    Portuguese output.
	 */
	private static function prompt( string $brief, bool $pt ): string {
		$lang  = $pt ? 'Portuguese (Portugal)' : 'English';
		$rules = implode(
			' ',
			array(
				'You write short, scroll-stopping social copy for HowToInvest, an educational financial-literacy brand (Instagram/Facebook reels).',
				'Return ONLY a JSON object with keys: "title" (a punchy hook, max 8 words), "caption" (one or two short sentences to burn onto the video, conversational and engaging), "description" (the post caption: 2-4 short sentences with line breaks, ending with a soft call to engage), "hashtags" (array of 4-8 relevant hashtags without the # sign).',
				'STRICT RULES: educational and neutral tone; plain language; conditional, never imperative; NEVER give financial, investment, tax or legal advice; NEVER tell anyone to buy or sell; NEVER name specific instruments, tickers, funds or companies as recommendations; never promise or imply returns; keep it suitable for a general audience.',
				'Write everything in ' . $lang . '.',
			)
		);
		return $rules . "\n\nBrief / video topic:\n" . $brief;
	}
}
