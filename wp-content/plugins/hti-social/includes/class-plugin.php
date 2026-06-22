<?php
/**
 * Plugin bootstrap: wires the admin page, the per-post meta box and the assets.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level init.
 */
class Plugin {

	/**
	 * Hook everything.
	 */
	public static function init(): void {
		Assets::init();
		Admin::init();
		Metabox::init();
	}

	/**
	 * Site locale reduced to a supported key (en|pt).
	 */
	public static function locale(): string {
		$loc = strtolower( (string) ( function_exists( 'get_locale' ) ? get_locale() : 'en' ) );
		return str_starts_with( $loc, 'pt' ) ? 'pt' : 'en';
	}
}
