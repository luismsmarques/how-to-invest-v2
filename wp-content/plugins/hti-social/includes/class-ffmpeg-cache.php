<?php
/**
 * Same-origin cache for the ffmpeg.wasm files.
 *
 * Browsers refuse to import the ffmpeg core / spawn its worker from a
 * cross-origin CDN. To make MP4 export reliable we mirror the worker, core and
 * wasm into uploads/ (downloaded server-side, once) and serve them from our own
 * origin. Keeps the ~32 MB out of the git repo.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads + serves the ffmpeg assets from uploads/.
 */
class Ffmpeg_Cache {

	private const SUBDIR = 'hti-social/ffmpeg';

	/**
	 * Ensure the worker/core/wasm are mirrored locally; return their URLs.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function ensure() {
		$srcs = Assets::ffmpeg_urls();
		$need = array(
			'worker' => $srcs['worker'] ?? '',
			'core'   => $srcs['core'] ?? '',
			'wasm'   => $srcs['wasm'] ?? '',
		);

		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return new \WP_Error( 'hti_social_uploads', (string) $up['error'] );
		}
		$dir = trailingslashit( $up['basedir'] ) . self::SUBDIR;
		$url = trailingslashit( $up['baseurl'] ) . self::SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'hti_social_mkdir', __( 'Could not create the ffmpeg cache directory.', 'hti-social' ) );
		}
		self::write_htaccess( $dir );

		$out = array();
		foreach ( $need as $key => $src ) {
			if ( '' === $src ) {
				return new \WP_Error( 'hti_social_src', 'Missing source URL for ' . $key );
			}
			$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			$path = trailingslashit( $dir ) . $name;

			if ( ! file_exists( $path ) || filesize( $path ) < 1024 ) {
				Logger::log( 'info', 'ffmpeg_download', 'Downloading ' . $key, array( 'src' => $src ) );
				$res = wp_remote_get( $src, array( 'timeout' => 120 ) );
				if ( is_wp_error( $res ) ) {
					Logger::log( 'error', 'ffmpeg_dl_error', $res->get_error_message(), array( 'file' => $key ) );
					return $res;
				}
				$http = (int) wp_remote_retrieve_response_code( $res );
				if ( 200 !== $http ) {
					Logger::log( 'error', 'ffmpeg_dl_http', sprintf( 'HTTP %d for %s', $http, $key ), array( 'src' => $src ) );
					return new \WP_Error( 'hti_social_dl', sprintf( 'HTTP %d downloading %s', $http, $src ) );
				}
				$body = wp_remote_retrieve_body( $res );
				if ( strlen( $body ) < 1024 ) {
					Logger::log( 'error', 'ffmpeg_dl_small', 'Tiny download for ' . $key, array( 'bytes' => strlen( $body ) ) );
					return new \WP_Error( 'hti_social_dl_small', 'Unexpectedly small download for ' . $key );
				}
				if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					Logger::log( 'error', 'ffmpeg_write', 'Could not write ' . $name );
					return new \WP_Error( 'hti_social_write', 'Could not write ' . $name );
				}
				Logger::log( 'info', 'ffmpeg_cached', 'Cached ' . $key, array( 'bytes' => strlen( $body ) ) );
			}
			$out[ $key ] = trailingslashit( $url ) . $name;
		}
		return $out;
	}

	/**
	 * Make sure the .wasm is served with the right MIME (Apache).
	 *
	 * @param string $dir Cache directory.
	 */
	private static function write_htaccess( string $dir ): void {
		$file = trailingslashit( $dir ) . '.htaccess';
		if ( file_exists( $file ) ) {
			return;
		}
		$rules = "AddType application/wasm .wasm\n";
		@file_put_contents( $file, $rules ); // phpcs:ignore
	}
}
