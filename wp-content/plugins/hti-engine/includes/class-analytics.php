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
	 * Hook front-end asset loading.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The configured GA4 measurement ID, or '' when analytics is disabled.
	 *
	 * Config-only: there is NO hardcoded default. An empty Settings field means
	 * HTI does not load its own gtag at all — which is exactly what you want when
	 * GA4 is managed by Google Tag Manager instead (leaving both would double up).
	 */
	public static function measurement_id(): string {
		$settings = function_exists( 'get_option' ) ? get_option( 'htinvest_settings' ) : array();
		$id       = is_array( $settings ) && ! empty( $settings['ga_id'] ) ? trim( (string) $settings['ga_id'] ) : '';

		/**
		 * Filter the GA4 measurement ID (empty disables HTI's own gtag).
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

		// Consent-gated event helper (window.HTITrack). Registered/enqueued
		// FIRST and unconditionally on the front end, so every script that
		// reports events (questionnaire, account, subscribe, contact) can list
		// it as a dependency and rely on window.HTITrack existing. It is a no-op
		// until analytics consent + gtag are present, so it is safe even when GA
		// is not configured.
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
		// First-party anonymous beacon endpoint for the self-hosted funnel panel.
		wp_localize_script(
			'hti-track',
			'HTI_TRACK',
			array( 'beacon' => esc_url_raw( rest_url( 'htinvest/v1/event' ) ) )
		);
		wp_enqueue_script( 'hti-track' );

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
	}
}
