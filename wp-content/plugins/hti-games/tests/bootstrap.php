<?php
/**
 * Shims so the pure classes can be exercised without WordPress.
 *
 * Same shape as hti-forex/tests/bootstrap.php: every shim is guarded with
 * function_exists() so a test file can define a richer version first. Unlike
 * hti-engine's, this one DOES provide get_option/update_option — nearly every
 * test here needs them and redefining them per file is how they drift.
 *
 * @package HTI_Games
 */

define( 'ABSPATH', __DIR__ );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_filters'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory option store.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Fallback.
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['__hti_options'][ $key ] ?? $default;
	}

	/**
	 * In-memory option store.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 */
	function update_option( $key, $value ) {
		$GLOBALS['__hti_options'][ $key ] = $value;
		return true;
	}

	/**
	 * In-memory option store.
	 *
	 * @param string $key Option name.
	 */
	function delete_option( $key ) {
		unset( $GLOBALS['__hti_options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Minimal filter registry.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority (ignored; registration order wins).
	 * @param int      $args     Accepted args.
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		unset( $priority );
		$GLOBALS['__hti_filters'][ $hook ][] = array( $callback, $args );
		return true;
	}

	/**
	 * Minimal filter registry.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value being filtered.
	 * @param mixed  ...$rest Extra args.
	 */
	function apply_filters( $hook, $value, ...$rest ) {
		foreach ( $GLOBALS['__hti_filters'][ $hook ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $rest, 0, max( 0, $entry[1] - 1 ) ) ) );
		}
		return $value;
	}

	/**
	 * Minimal action registry (actions are filters whose result is discarded).
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_filter( $hook, $callback, $priority, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode.
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strip tags and control characters.
	 *
	 * @param string $str Input.
	 */
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
	}

	/**
	 * Strip tags.
	 *
	 * @param string $str Input.
	 */
	function wp_strip_all_tags( $str ) {
		return strip_tags( (string) $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Lowercase alphanumerics, dashes and underscores.
	 *
	 * @param string $key Input.
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape for HTML text.
	 *
	 * @param string $str Input.
	 */
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape for an HTML attribute.
	 *
	 * @param string $str Input.
	 */
	function esc_attr( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Passthrough translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		unset( $domain );
		return $text;
	}
}

/**
 * Assertion helper shared by every test file.
 *
 * Kept here rather than copied into each file so a change to the reporting
 * format is one edit. Files still own their own $passes/$failures totals and
 * their own exit code, as in the other suites.
 *
 * @param bool   $cond  Condition.
 * @param string $label Human-readable claim.
 */
function hti_games_check( bool $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['passes'];
		echo "\033[32m✓\033[0m {$label}\n";
	} else {
		++$GLOBALS['failures'];
		echo "\033[31m✗\033[0m {$label}\n";
	}
}

$GLOBALS['passes']   = 0;
$GLOBALS['failures'] = 0;

/**
 * Print the tally and exit with the right code.
 */
function hti_games_done(): void {
	echo "\n=== {$GLOBALS['passes']} passed, {$GLOBALS['failures']} failed ===\n";
	exit( $GLOBALS['failures'] > 0 ? 1 : 0 );
}
