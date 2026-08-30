<?php
/**
 * Uninstall cleanup for HTI Social.
 *
 * Nothing here is personal data — the plugin renders cards from posts that
 * already exist. What it does leave is an API key, a log, and roughly 32 MB of
 * mirrored ffmpeg files in uploads/ that nothing will ever reference again.
 *
 * @package HTI_Social
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hti_social_log' );
delete_option( 'hti_social_ffmpeg_hashes' );

// The mirrored ffmpeg build. Deleted file by file from a fixed directory —
// never a recursive delete of a path that came from anywhere else.
$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$dir = trailingslashit( $uploads['basedir'] ) . 'hti-social/ffmpeg';
	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		wp_delete_file( $dir . '/.htaccess' );
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.directory_rmdir -- best effort; a non-empty directory is left alone on purpose.
	}
}
