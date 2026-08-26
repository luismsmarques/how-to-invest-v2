<?php
/**
 * Logs admin page (Social → Logs): a readable table of every server and client
 * event, with level badges, a source column, expandable context and a Clear
 * button. Read-only snapshot; refresh to update.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Logs submenu page.
 */
class Logs_Page {

	/**
	 * Hook the menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
	}

	/**
	 * Register the Logs submenu under the Social menu.
	 */
	public static function menu(): void {
		add_submenu_page(
			'hti-social',
			__( 'Social Logs', 'hti-social' ),
			__( 'Logs', 'hti-social' ),
			'edit_posts',
			'hti-social-logs',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page (and handle the Clear action).
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( isset( $_POST['hti_social_clear'] ) && check_admin_referer( 'hti_social_clear_logs' ) ) {
			Logger::clear();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs cleared.', 'hti-social' ) . '</p></div>';
		}

		$entries = Logger::all();

		echo '<div class="wrap">';
		echo '<h1 style="display:flex;align-items:center;gap:14px;">' . esc_html__( 'Social — Logs', 'hti-social' );
		echo '<span style="font:600 12px sans-serif;color:#646970;">' . esc_html( sprintf( /* translators: %d: number of entries */ __( '%d entries', 'hti-social' ), count( $entries ) ) ) . '</span></h1>';

		echo '<p class="description">' . esc_html__( 'Everything the plugin does — AI caption calls, the ffmpeg mirror, reel rendering and MP4 export — server and browser side. Newest first.', 'hti-social' ) . '</p>';

		echo '<form method="post" style="margin:12px 0;">';
		wp_nonce_field( 'hti_social_clear_logs' );
		echo '<button type="submit" name="hti_social_clear" value="1" class="button">' . esc_html__( 'Clear logs', 'hti-social' ) . '</button> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=hti-social-logs' ) ) . '" class="button">' . esc_html__( 'Refresh', 'hti-social' ) . '</a>';
		echo '</form>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No log entries yet. Generate a caption or render a reel to see activity here.', 'hti-social' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:150px;">' . esc_html__( 'Time', 'hti-social' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Level', 'hti-social' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Source', 'hti-social' ) . '</th>';
		echo '<th style="width:160px;">' . esc_html__( 'Event', 'hti-social' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'hti-social' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $e ) {
			$level = isset( $e['level'] ) ? (string) $e['level'] : 'info';
			$color = 'error' === $level ? '#b32d2e' : ( 'warn' === $level ? '#bd8600' : '#2271b1' );
			$when  = isset( $e['ts'] ) ? wp_date( 'Y-m-d H:i:s', (int) $e['ts'] ) : '';

			$ctx = '';
			if ( ! empty( $e['ctx'] ) && is_array( $e['ctx'] ) ) {
				$bits = array();
				foreach ( $e['ctx'] as $k => $v ) {
					$bits[] = esc_html( $k . '=' . $v );
				}
				$ctx = '<div style="color:#646970;font:12px/1.5 monospace;margin-top:4px;">' . implode( ' · ', $bits ) . '</div>';
			}

			echo '<tr>';
			echo '<td style="white-space:nowrap;color:#646970;">' . esc_html( $when ) . '</td>';
			echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;font:700 11px sans-serif;text-transform:uppercase;background:' . esc_attr( $color ) . ';">' . esc_html( $level ) . '</span></td>';
			echo '<td>' . esc_html( isset( $e['src'] ) ? (string) $e['src'] : 'server' ) . '</td>';
			echo '<td><code>' . esc_html( isset( $e['event'] ) ? (string) $e['event'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $e['msg'] ) ? (string) $e['msg'] : '' ) . wp_kses_post( $ctx ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
