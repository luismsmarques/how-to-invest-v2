<?php
/**
 * Test bootstrap: minimal WordPress shims so the pure units run without WP.
 *
 * @package HTI_RSS_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return is_string( $text ) ? trim( $text ) : $text;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $string ) {
		$map = array(
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
			'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
			'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
			'ç' => 'c', 'ñ' => 'n',
			'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
			'Ã' => 'A', 'Õ' => 'O', 'Ç' => 'C',
		);
		return strtr( (string) $string, $map );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

$GLOBALS['__rssai_tests'] = array(
	'pass' => 0,
	'fail' => 0,
);

function rssai_ok( bool $cond, string $msg ): void {
	if ( $cond ) {
		++$GLOBALS['__rssai_tests']['pass'];
	} else {
		++$GLOBALS['__rssai_tests']['fail'];
		echo "  FAIL: $msg\n";
	}
}

function rssai_done( string $suite ): void {
	$t = $GLOBALS['__rssai_tests'];
	echo "$suite: {$t['pass']} passed, {$t['fail']} failed\n";
	exit( $t['fail'] ? 1 : 0 );
}
