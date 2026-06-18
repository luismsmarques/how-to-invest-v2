<?php
/**
 * Legacy URL redirects (Base44 → WordPress).
 *
 * Maps the old Base44 CamelCase paths to their new canonical WordPress URLs
 * with a permanent (301) redirect, preserving SEO equity during migration.
 *
 * The map is filterable via `hti_legacy_redirects` so slugs can be adjusted
 * to match the pages actually created (without a code deploy).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Performs 301 redirects from legacy Base44 paths.
 */
class Redirects {

	/**
	 * Hook into the request lifecycle.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Old path (lowercase, no slashes) => new path (relative to home, leading slash).
	 *
	 * @return array<string,string>
	 */
	private static function map(): array {
		$map = array(
			'about'                => '/about/',
			'contact'              => '/contact/',
			'financialnews'        => '/financial-news/',
			'financialnewsarticle' => '/financial-news/',
			'howtostart'           => '/how-to-start-investing/',
			'privacypolicy'        => '/privacy-policy/',
			'questionnaire'        => '/investor-profile-quiz/',
			'termsandconditions'   => '/terms-and-conditions/',
		);

		/**
		 * Filter the legacy redirect map.
		 *
		 * @param array<string,string> $map Old path (lowercase, no slashes) => new relative path.
		 */
		return (array) apply_filters( 'hti_legacy_redirects', $map );
	}

	/**
	 * Redirect the current request if it matches a legacy path.
	 */
	public static function maybe_redirect(): void {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path is sanitized below.
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$key     = strtolower( trim( $path, '/' ) );

		if ( '' === $key ) {
			return;
		}

		$map = self::map();
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}

		$target = home_url( $map[ $key ] );

		// Avoid redirecting a URL onto itself.
		if ( untrailingslashit( $target ) === untrailingslashit( home_url( $path ) ) ) {
			return;
		}

		wp_safe_redirect( $target, 301, 'HowToInvest' );
		exit;
	}
}
