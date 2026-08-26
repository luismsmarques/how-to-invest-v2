<?php
/**
 * Capped activity log for the plugin (server + client events), stored in a
 * single option. Powers the Logs page so we can see exactly what happens during
 * caption generation, the ffmpeg mirror and reel rendering/export.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny ring-buffer logger.
 */
class Logger {

	private const OPTION = 'hti_social_log';
	private const MAX    = 300;

	/**
	 * Append an entry.
	 *
	 * @param string               $level   info|warn|error.
	 * @param string               $event   Short machine-ish event key.
	 * @param string               $message Human message.
	 * @param array<string,mixed>  $context Small context map (scalars).
	 * @param string               $src     server|client.
	 */
	public static function log( string $level, string $event, string $message = '', array $context = array(), string $src = 'server' ): void {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'ts'    => time(),
			'level' => in_array( $level, array( 'info', 'warn', 'error' ), true ) ? $level : 'info',
			'src'   => 'client' === $src ? 'client' : 'server',
			'event' => substr( sanitize_text_field( $event ), 0, 60 ),
			'msg'   => substr( wp_strip_all_tags( (string) $message ), 0, 500 ),
			'ctx'   => self::clean_context( $context ),
		);

		if ( count( $entries ) > self::MAX ) {
			$entries = array_slice( $entries, -self::MAX );
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * All entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_reverse( $entries );
	}

	/**
	 * Empty the log.
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Sanitize a context map to short scalar strings.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,string>
	 */
	private static function clean_context( array $context ): array {
		$out = array();
		$i   = 0;
		foreach ( $context as $k => $v ) {
			if ( $i++ >= 12 ) {
				break;
			}
			if ( is_bool( $v ) ) {
				$v = $v ? 'true' : 'false';
			} elseif ( is_scalar( $v ) || null === $v ) {
				$v = (string) $v;
			} else {
				$v = wp_json_encode( $v );
			}
			$out[ substr( sanitize_key( (string) $k ), 0, 40 ) ] = substr( wp_strip_all_tags( (string) $v ), 0, 300 );
		}
		return $out;
	}
}
