<?php
/**
 * Front-end mount for the questionnaire/result app (E5–E7).
 *
 * Registers the `[hti_questionnaire]` shortcode, enqueues the lightweight
 * vanilla JS + CSS only on the page that uses it, localizes the questions and
 * UI strings (EN/PT), and marks that page noindex. The app talks to
 * `/wp-json/htinvest/v1/recommend`; all scoring stays server-side.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode + asset wiring for the interactive app.
 */
class Frontend {

	private const SHORTCODE = 'hti_questionnaire';

	/**
	 * Hook shortcode, assets and robots.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Whether the current singular view contains the questionnaire shortcode.
	 */
	private static function is_app_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Enqueue and localize the app assets on the app page only.
	 */
	public static function enqueue(): void {
		if ( ! self::is_app_page() ) {
			return;
		}

		$locale = self::locale();

		wp_enqueue_style(
			'hti-app',
			HTI_ENGINE_URL . 'assets/css/app.css',
			array(),
			VERSION
		);

		wp_register_script(
			'hti-result',
			HTI_ENGINE_URL . 'assets/js/result.js',
			array(),
			VERSION,
			array( 'in_footer' => true )
		);
		wp_register_script(
			'hti-questionnaire',
			HTI_ENGINE_URL . 'assets/js/questionnaire.js',
			array( 'hti-result' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'hti-questionnaire',
			'HTI_DATA',
			array(
				'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/recommend' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => $locale,
				'data'    => Questions::payload( $locale ),
			)
		);

		wp_enqueue_script( 'hti-result' );
		wp_enqueue_script( 'hti-questionnaire' );
	}

	/**
	 * Shortcode output: the mount point + a no-JS message.
	 *
	 * @return string
	 */
	public static function render(): string {
		$noscript = esc_html__( 'This questionnaire needs JavaScript enabled in your browser.', 'hti-engine' );

		return '<div id="hti-app" class="hti-app" aria-live="polite"></div>'
			. '<noscript><p class="hti-noscript">' . $noscript . '</p></noscript>';
	}

	/**
	 * Mark the questionnaire/result page noindex (it is not SEO content).
	 *
	 * @param array<string,bool> $robots Robots directives.
	 * @return array<string,bool>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_app_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
		}
		return $robots;
	}
}
