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
		add_filter( 'hti_rate_limits', array( __CLASS__, 'rate_limits' ) );
	}

	/**
	 * Who may use the local ffmpeg mirror: anyone who edits content here.
	 *
	 * Cheap and local — it prepares files already on this server.
	 */
	public static function may_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Who may spend money.
	 *
	 * /tts and /caption each call Gemini, so the capability is the budget.
	 * `edit_posts` includes Contributors — people trusted to draft, not to
	 * spend — so the AI routes ask for `publish_posts` instead. It is the
	 * smallest change that stops a single drafting account (or one whose
	 * password leaked) from emptying the quota unattended.
	 */
	public static function may_spend(): bool {
		return current_user_can( 'publish_posts' );
	}

	/**
	 * Stop one account from looping a paid endpoint.
	 *
	 * A capability answers "should this person do it at all"; nothing answered
	 * "how often". Uses hti-engine's limiter when it is around, and does not
	 * invent one when it is not — hti-social is useless without hti-engine
	 * anyway, and a second half-limiter would be worse than none.
	 *
	 * @param string $bucket Rate-limit bucket name.
	 * @return \WP_Error|null Error when the caller must slow down.
	 */
	private static function slow_down( string $bucket ): ?\WP_Error {
		if ( ! class_exists( '\\HTI\\Engine\\RateLimit' ) ) {
			return null;
		}
		if ( ! \HTI\Engine\RateLimit::exceeded( $bucket ) ) {
			return null;
		}
		return new \WP_Error(
			'hti_social_rate_limited',
			__( 'Too many AI requests in a short window. Wait a minute and try again.', 'hti-social' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Budgets for the two paid routes.
	 *
	 * Generous enough for a real editing session, small enough that a loop
	 * costs pennies before it is stopped. TTS is per line of script, so it
	 * legitimately fires far more often than a caption.
	 *
	 * @param array<string,array{0:int,1:int}> $limits Existing buckets.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function rate_limits( array $limits ): array {
		$limits['hti_social_tts']     = array( 120, 3600 );
		$limits['hti_social_caption'] = array( 40, 3600 );
		return $limits;
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
				'permission_callback' => array( __CLASS__, 'may_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'log_event' ),
				'permission_callback' => array( __CLASS__, 'may_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'tts' ),
				'permission_callback' => array( __CLASS__, 'may_spend' ),
				'args'                => array(
					'text'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'voice' => array(
						'type'              => 'string',
						'default'           => 'Kore',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/caption',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'caption' ),
				'permission_callback' => array( __CLASS__, 'may_spend' ),
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
	 * Record a client-side log event (from reels.js).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function log_event( \WP_REST_Request $request ) {
		$level   = (string) $request->get_param( 'level' );
		$event   = (string) $request->get_param( 'event' );
		$message = (string) $request->get_param( 'message' );
		$context = $request->get_param( 'context' );
		Logger::log(
			$level ? $level : 'info',
			$event ? $event : 'client',
			$message,
			is_array( $context ) ? $context : array(),
			'client'
		);
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
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
		$slow = self::slow_down( 'hti_social_caption' );
		if ( $slow ) {
			return $slow;
		}

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
	 * Narrate a line of script via Gemini TTS. Returns a base64 WAV the browser
	 * decodes and schedules on the reel's audio timeline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function tts( \WP_REST_Request $request ) {
		$slow = self::slow_down( 'hti_social_tts' );
		if ( $slow ) {
			return $slow;
		}

		$text = trim( (string) $request->get_param( 'text' ) );
		if ( '' === $text ) {
			return new \WP_Error( 'hti_social_tts_text', __( 'Nothing to narrate.', 'hti-social' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > 600 ) {
			$text = mb_substr( $text, 0, 600 );
		}
		if ( ! Gemini::is_configured() ) {
			return new \WP_Error( 'hti_social_no_key', __( 'The Gemini API key is not configured on the server.', 'hti-social' ), array( 'status' => 503 ) );
		}

		$result = Gemini::tts( $text, (string) $request->get_param( 'voice' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'hti_social_tts', $result->get_error_message(), array( 'status' => 502 ) );
		}
		return new \WP_REST_Response( $result, 200 );
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
