<?php
/**
 * Minimal bootstrap so the pure hti-forex classes can be tested without
 * WordPress. Mirrors hti-engine/tests/bootstrap.php: define the constants and
 * shims the classes reference, keep everything in-memory.
 *
 * @package HTI_Forex
 */

// Satisfy the `defined( 'ABSPATH' ) || exit;` guards in the class files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

// In-memory options so Settings/Rates are testable without WordPress.
$GLOBALS['__hti_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['__hti_options'][ $key ] ?? $default;
	}
	/**
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $key, $value ) {
		$GLOBALS['__hti_options'][ $key ] = $value;
		return true;
	}
	/**
	 * @param string $key Option name.
	 * @return bool
	 */
	function delete_option( $key ) {
		unset( $GLOBALS['__hti_options'][ $key ] );
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
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Value.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Simplified shim: keeps http(s) URLs, drops everything else — enough for
	 * asserting the https enforcement in Settings::normalize_settings().
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		return preg_match( '#^https?://#i', $url ) ? filter_var( $url, FILTER_SANITIZE_URL ) : '';
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component constant.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
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

// Cron shims: the broadcast state machine schedules and clears events, and the
// harness only needs them to be callable and observable.
$GLOBALS['__hti_cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * @param string $hook Hook.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['__hti_cron'][ $hook ] ?? false;
	}
	/**
	 * @param int    $timestamp When.
	 * @param string $hook      Hook.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['__hti_cron'][ $hook ] = $timestamp;
		return true;
	}
	/**
	 * @param string $hook Hook.
	 * @return int
	 */
	function wp_clear_scheduled_hook( $hook ) {
		unset( $GLOBALS['__hti_cron'][ $hook ] );
		return 1;
	}
}

// Enough of the REST layer to exercise the bot webhook's authentication.
if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int $length Length.
	 * @return string
	 */
	function wp_generate_password( $length = 12 ) {
		return substr( str_repeat( 'abcdef0123456789', 8 ), 0, (int) $length );
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal stand-in. */
	class WP_REST_Request {
		/** @var array<string,string> */
		private $headers;
		/** @var array<string,mixed>|null */
		private $json;
		/**
		 * @param array<string,string>    $headers Headers.
		 * @param array<string,mixed>|null $json   Body.
		 */
		public function __construct( array $headers = array(), $json = array() ) {
			$this->headers = $headers;
			$this->json    = $json;
		}
		/**
		 * @param string $key Header key.
		 * @return string
		 */
		public function get_header( $key ) {
			return $this->headers[ $key ] ?? '';
		}
		/**
		 * @return array<string,mixed>|null
		 */
		public function get_json_params() {
			return $this->json;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	/** Minimal stand-in. */
	class WP_REST_Response {
		/** @var mixed */
		public $data;
		/** @var int */
		public $status;
		/**
		 * @param mixed $data   Payload.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		/**
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}
