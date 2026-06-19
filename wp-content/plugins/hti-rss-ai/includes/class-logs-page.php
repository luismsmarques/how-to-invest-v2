<?php
/**
 * Read-only Logs admin page.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Shows recent activity and lets the admin clear it.
 */
class Logs_Page {

	public const PAGE = 'rssai-logs';

	/**
	 * Hook menu + clear handler.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 14 );
		add_action( 'admin_post_rssai_clear_logs', array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * Add the Logs submenu.
	 */
	public static function menu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Logs', 'hti-rss-ai' ),
			__( 'Logs', 'hti-rss-ai' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the log table.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$logs  = Logger::all();
		$clear = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_clear_logs' ), 'rssai_clear_logs' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Logs', 'hti-rss-ai' ); ?></h1>
			<?php if ( $logs ) : ?>
				<a href="<?php echo esc_url( $clear ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Clear all logs?', 'hti-rss-ai' ) ); ?>')"><?php echo esc_html__( 'Clear', 'hti-rss-ai' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:160px"><?php echo esc_html__( 'Time', 'hti-rss-ai' ); ?></th>
						<th style="width:100px"><?php echo esc_html__( 'Type', 'hti-rss-ai' ); ?></th>
						<th><?php echo esc_html__( 'Message', 'hti-rss-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $logs ) : ?>
						<tr><td colspan="3"><?php echo esc_html__( 'No activity yet.', 'hti-rss-ai' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $logs as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $entry['t'] ); ?></td>
							<td><code><?php echo esc_html( (string) $entry['type'] ); ?></code></td>
							<td><?php echo esc_html( (string) $entry['msg'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Clear logs.
	 */
	public static function handle_clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( 'rssai_clear_logs' );
		Logger::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
