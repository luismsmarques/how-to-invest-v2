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

// Settings::normalize_archetypes uses wp_strip_all_tags for labels.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

// In-memory transient + helper shims so RateLimit is testable without WordPress.
$GLOBALS['__hti_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		return $GLOBALS['__hti_transients'][ $key ] ?? false;
	}
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function set_transient( $key, $value ) {
		$GLOBALS['__hti_transients'][ $key ] = $value;
		return true;
	}
	/**
	 * @param string $key Key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['__hti_transients'][ $key ] );
		return true;
	}
}
// Minimal filter registry so tests can override filtered values.
$GLOBALS['__hti_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $tag      Hook.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_filter( $tag, $callback ) {
		$GLOBALS['__hti_filters'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $tag   Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		if ( ! empty( $GLOBALS['__hti_filters'][ $tag ] ) ) {
			foreach ( $GLOBALS['__hti_filters'][ $tag ] as $cb ) {
				$value = $cb( $value );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-engine.php';
require_once __DIR__ . '/../includes/class-fallback.php';
require_once __DIR__ . '/../includes/class-validator.php';
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

require_once __DIR__ . '/../includes/class-prompt.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-rate-limit.php';
require_once __DIR__ . '/../includes/class-cron.php';
require_once __DIR__ . '/../includes/class-mailer.php';
