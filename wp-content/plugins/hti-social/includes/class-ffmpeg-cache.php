<?php
/**
 * Same-origin cache for the ffmpeg.wasm files.
 *
 * Browsers refuse to import the ffmpeg core / spawn its worker from a
 * cross-origin CDN. To make MP4 export reliable we mirror the library, its
 * util, the worker, the core and the wasm into uploads/ (downloaded
 * server-side, once) and serve them from our own origin. Keeps the ~32 MB out
 * of the git repo.
 *
 * The library and its util used to be loaded straight from the CDN with a
 * <script> tag and no integrity attribute, which is the same trust with none of
 * the control: mirroring them costs nothing extra and puts them behind the same
 * gate as the rest.
 *
 * Serving them from our own origin is the whole point and also the whole risk:
 * once a file is here it is FIRST-PARTY script in the browser of whoever is
 * signed in to wp-admin. Downloading bytes from a CDN and then lending them our
 * origin means a single bad response — a compromised registry, a DNS answer we
 * did not expect — is pinned on this server for as long as the file survives.
 * So a download is not trusted because it arrived: it is trusted because its
 * SHA-256 matches what we expected.
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
	 * Option holding the SHA-256 recorded for each cached file.
	 */
	private const OPTION_HASHES = 'hti_social_ffmpeg_hashes';

	/**
	 * The filename each asset is stored under.
	 *
	 * Fixed here rather than taken from the URL path. The source URLs come
	 * through a filter, so deriving the filename from them would let whatever
	 * sets that filter choose what gets written into uploads/ — a decision that
	 * has no business travelling with a URL.
	 */
	private const NAMES = array(
		'ffmpeg' => 'ffmpeg.js',
		'util'   => 'ffmpeg-util.js',
		'worker' => 'ffmpeg-worker.js',
		'core'   => 'ffmpeg-core.js',
		'wasm'   => 'ffmpeg-core.wasm',
	);

	/**
	 * Expected SHA-256 per asset key.
	 *
	 * Empty until someone reads the digests off the registry and pastes them in
	 * (the filter below exists for exactly that, so it can be done in
	 * wp-config without a deploy). While a key is empty the FIRST download
	 * records its own digest and every later download of that key is checked
	 * against the recording — which does not protect the first fetch, but does
	 * mean a file that changes underneath us is refused and said out loud
	 * instead of being served quietly to an administrator.
	 *
	 * @return array<string,string>
	 */
	public static function expected_hashes(): array {
		$hashes = (array) apply_filters(
			'hti_social_ffmpeg_hashes',
			array(
				'ffmpeg' => '',
				'util'   => '',
				'worker' => '',
				'core'   => '',
				'wasm'   => '',
			)
		);

		$out = array();
		foreach ( self::NAMES as $key => $unused ) {
			$hash = strtolower( trim( (string) ( $hashes[ $key ] ?? '' ) ) );
			$out[ $key ] = 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
		}
		return $out;
	}

	/**
	 * The digests actually recorded for the files on disk.
	 *
	 * Surfaced so the values can be compared against the registry's published
	 * integrity and then promoted into expected_hashes().
	 *
	 * @return array<string,string>
	 */
	public static function recorded_hashes(): array {
		$stored = get_option( self::OPTION_HASHES, array() );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Ensure the worker/core/wasm are mirrored locally; return their URLs.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function ensure() {
		$srcs     = Assets::ffmpeg_urls();
		$expected = self::expected_hashes();
		$recorded = self::recorded_hashes();

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

		$out   = array();
		$dirty = false;

		foreach ( self::NAMES as $key => $name ) {
			$src  = (string) ( $srcs[ $key ] ?? '' );
			$path = trailingslashit( $dir ) . $name;

			if ( ! file_exists( $path ) || filesize( $path ) < 1024 ) {
				if ( '' === $src ) {
					return new \WP_Error( 'hti_social_src', 'Missing source URL for ' . $key );
				}
				if ( ! str_starts_with( $src, 'https://' ) ) {
					// Plain http would let anyone on the path choose what we
					// serve from our own origin.
					Logger::log( 'error', 'ffmpeg_src_scheme', 'Refusing a non-https source for ' . $key, array( 'src' => $src ) );
					return new \WP_Error( 'hti_social_src_scheme', 'The source URL for ' . $key . ' must be https.' );
				}

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

				// The gate. A configured digest wins; otherwise the one this
				// server recorded the first time. Either way nothing that does
				// not match is written, because writing it is what lends it our
				// origin.
				$got  = hash( 'sha256', $body );
				$want = '' !== $expected[ $key ] ? $expected[ $key ] : (string) ( $recorded[ $key ] ?? '' );

				if ( '' !== $want && ! hash_equals( $want, $got ) ) {
					Logger::log(
						'error',
						'ffmpeg_hash_mismatch',
						'Refused ' . $key . ': the download does not match the expected SHA-256.',
						array(
							'src'      => $src,
							'expected' => $want,
							'received' => $got,
						)
					);
					return new \WP_Error(
						'hti_social_hash',
						sprintf(
							/* translators: %s: asset key (worker, core, wasm). */
							__( 'Refused to cache %s: what was downloaded does not match the expected checksum. Nothing was written.', 'hti-social' ),
							$key
						)
					);
				}

				if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					Logger::log( 'error', 'ffmpeg_write', 'Could not write ' . $name );
					return new \WP_Error( 'hti_social_write', 'Could not write ' . $name );
				}

				if ( '' === $want ) {
					// First sighting: record it, and say the digest out loud so
					// it can be checked against the registry and promoted.
					$recorded[ $key ] = $got;
					$dirty            = true;
					Logger::log( 'info', 'ffmpeg_hash_recorded', 'Recorded SHA-256 for ' . $key, array( 'sha256' => $got ) );
				}

				Logger::log( 'info', 'ffmpeg_cached', 'Cached ' . $key, array( 'bytes' => strlen( $body ) ) );
			}

			$out[ $key ] = trailingslashit( $url ) . $name;
		}

		if ( $dirty ) {
			update_option( self::OPTION_HASHES, $recorded, false );
		}

		return $out;
	}

	/**
	 * Rules for the cache directory.
	 *
	 * The MIME type is why this file started existing; denying execution is why
	 * it should have. Nothing in here is ever meant to be interpreted by the
	 * server — it is script for a browser and a wasm blob — so anything that
	 * ends up here that Apache would otherwise run should not run.
	 *
	 * Rewritten when the rules change, rather than left alone once created.
	 *
	 * @param string $dir Cache directory.
	 */
	private static function write_htaccess( string $dir ): void {
		$file  = trailingslashit( $dir ) . '.htaccess';
		$rules = "AddType application/wasm .wasm\n"
			. "\n"
			. "# Nothing in this directory is ever executed by the server.\n"
			. "php_flag engine off\n"
			. "<IfModule mod_rewrite.c>\n"
			. "\tRewriteEngine Off\n"
			. "</IfModule>\n"
			. "<FilesMatch \"\\.(?i:php|phtml|php[0-9]|phar|cgi|pl|py|sh)$\">\n"
			. "\tRequire all denied\n"
			. "</FilesMatch>\n";

		if ( file_exists( $file ) && $rules === (string) @file_get_contents( $file ) ) { // phpcs:ignore
			return;
		}

		@file_put_contents( $file, $rules ); // phpcs:ignore
	}
}
