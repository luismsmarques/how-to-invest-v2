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
				'privacyUrl' => esc_url( self::privacy_url( $pt ) ),
				'strings'    => self::strings( $pt ),
			)
		);

		wp_enqueue_script( 'hti-consent' );
	}

	/**
	 * The privacy policy, in the language the banner is speaking.
	 *
	 * The banner is where someone decides whether to be measured, so the link
	 * it offers has to be readable by the person deciding. It was hard-coded to
	 * the English page, which meant a visitor reading the Portuguese banner was
	 * sent to a policy in a language they had not chosen.
	 *
	 * Polylang holds the translation when it is installed; the slug is the
	 * fallback, and the English page is the last resort, because a link to the
	 * wrong language still beats no link at all.
	 *
	 * @param bool $pt Whether the banner is rendering in Portuguese.
	 * @return string
	 */
	private static function privacy_url( bool $pt ): string {
		$en = home_url( '/privacy-policy/' );

		if ( ! $pt ) {
			return $en;
		}

		$page = get_page_by_path( 'privacy-policy' );
		if ( $page instanceof \WP_Post && function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( (int) $page->ID, 'pt' );
			if ( $translated ) {
				$maybe = get_post( (int) $translated );
				if ( $maybe instanceof \WP_Post && 'publish' === $maybe->post_status ) {
					return (string) get_permalink( $maybe );
				}
			}
		}

		$pt_page = get_page_by_path( 'politica-de-privacidade' );
		if ( $pt_page instanceof \WP_Post && 'publish' === $pt_page->post_status ) {
			return (string) get_permalink( $pt_page );
		}

		return $en;
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
