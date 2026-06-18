<?php
/**
 * Cookie consent banner (E8) — RGPD P0.
 *
 * Privacy-first: non-essential (analytics) is OFF until the visitor opts in.
 * The choice is recorded client-side in the `hti_consent` cookie with a
 * timestamp; non-essential scripts must check consent before running. A small,
 * dependency-free banner — no third-party consent platform required.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the consent banner and exposes a server-side consent gate.
 */
class Consent {

	private const COOKIE = 'hti_consent';

	/**
	 * Hook front-end asset loading.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the visitor has opted in to non-essential analytics.
	 *
	 * Gate non-essential scripts behind this (RGPD): consent before processing.
	 */
	public static function analytics_allowed(): bool {
		$allowed = false;
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$decoded = json_decode( wp_unslash( $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded + cast below.
			$allowed = is_array( $decoded ) && ! empty( $decoded['analytics'] );
		}

		/**
		 * Filter whether non-essential analytics may run.
		 *
		 * @param bool $allowed Whether analytics consent was given.
		 */
		return (bool) apply_filters( 'hti_analytics_allowed', $allowed );
	}

	/**
	 * Enqueue and localize the banner (front-end only).
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'hti-consent',
			HTI_ENGINE_URL . 'assets/css/consent.css',
			array(),
			VERSION
		);
		wp_register_script(
			'hti-consent',
			HTI_ENGINE_URL . 'assets/js/consent.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$pt = str_starts_with( strtolower( (string) get_locale() ), 'pt' );

		wp_localize_script(
			'hti-consent',
			'HTI_CONSENT',
			array(
				'cookie'     => self::COOKIE,
				'expiryDays' => 180,
				'privacyUrl' => esc_url( home_url( '/privacy-policy/' ) ),
				'strings'    => self::strings( $pt ),
			)
		);

		wp_enqueue_script( 'hti-consent' );
	}

	/**
	 * Localized banner strings.
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,string>
	 */
	private static function strings( bool $pt ): array {
		if ( $pt ) {
			return array(
				'aria'      => 'Consentimento de cookies',
				'message'   => 'Usamos cookies essenciais para o site funcionar. Os não-essenciais (analítica) só com a tua autorização.',
				'accept'    => 'Aceitar',
				'refuse'    => 'Recusar não-essenciais',
				'customize' => 'Personalizar',
				'save'      => 'Guardar escolhas',
				'essential' => 'Essenciais (sempre ativos)',
				'analytics' => 'Analítica (ajuda-nos a melhorar)',
				'privacy'   => 'Política de privacidade',
			);
		}
		return array(
			'aria'      => 'Cookie consent',
			'message'   => 'We use essential cookies to run the site. Non-essential ones (analytics) only with your consent.',
			'accept'    => 'Accept',
			'refuse'    => 'Refuse non-essential',
			'customize' => 'Customize',
			'save'      => 'Save choices',
			'essential' => 'Essential (always on)',
			'analytics' => 'Analytics (helps us improve)',
			'privacy'   => 'Privacy policy',
		);
	}
}
