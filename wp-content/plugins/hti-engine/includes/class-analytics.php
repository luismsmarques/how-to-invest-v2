<?php
/**
 * Google Analytics (GA4) loading — strictly gated by consent.
 *
 * The gtag.js tag is NOT loaded until the visitor opts in to analytics via the
 * consent banner (RGPD invariant: no non-essential trackers before consent).
 * A tiny loader (analytics.js) injects gtag only when consent is granted, and
 * reacts to the `hti-consent-changed` event so it loads immediately on accept,
 * without a page reload.
 *
 * Measurement ID: filterable (`hti_ga_id`) and editable in Settings.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the consent-gated GA loader.
 */
class Analytics {

	/**
	 * Default GA4 measurement ID (override in Settings or via the filter).
	 */
	private const DEFAULT_ID = 'G-QWST7PZNBT';

	/**
	 * Hook front-end asset loading.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The configured GA4 measurement ID (settings → default → filter).
	 */
	public static function measurement_id(): string {
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		$id       = is_array( $settings ) && ! empty( $settings['ga_id'] ) ? (string) $settings['ga_id'] : self::DEFAULT_ID;

		/**
		 * Filter the GA4 measurement ID (empty disables analytics).
		 *
		 * @param string $id Measurement ID.
		 */
		return trim( (string) apply_filters( 'hti_ga_id', $id ) );
	}

	/**
	 * Enqueue the loader on the front-end (when an ID is set).
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		$id = self::measurement_id();
		if ( '' === $id ) {
			return;
		}

		wp_register_script(
			'hti-analytics',
			HTI_ENGINE_URL . 'assets/js/analytics.js',
			array( 'hti-consent' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( 'hti-analytics', 'HTI_GA', array( 'id' => $id ) );
		wp_enqueue_script( 'hti-analytics' );

		// Consent-gated event helper (window.HTITrack). Loaded site-wide so the
		// questionnaire, account, subscribe, contact and theme CTAs can all
		// report events. No-op until analytics consent + gtag are present.
		wp_register_script(
			'hti-track',
			HTI_ENGINE_URL . 'assets/js/track.js',
			array( 'hti-consent' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script( 'hti-track' );
	}
}
