<?php
/**
 * Minimal bootstrap so the pure engine can be tested without WordPress.
 *
 * Defines the constants/shims the engine files reference, then loads the
 * deterministic classes (which contain no other WordPress dependencies).
 *
 * @package HTI_Engine
 */

// Satisfy the `defined( 'ABSPATH' ) || exit;` guards in the class files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// The engine uses wp_json_encode only inside an exception message.
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-engine.php';
require_once __DIR__ . '/../includes/class-fallback.php';
require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-prompt.php';
