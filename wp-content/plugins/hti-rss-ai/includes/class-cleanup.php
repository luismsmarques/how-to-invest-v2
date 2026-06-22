<?php
/**
 * Daily cleanup: prune old draft items and log entries so the tables/option
 * don't grow without bound. Scheduled via WP-Cron; also runnable on demand.
 *
 * Retention is the "cleanup_days" setting (default 30). Items older than that
 * are removed regardless of status (they have already been processed or are
 * stale); old non-open groups are removed too; logs older than the window are
 * trimmed. Generated `news` posts are never touched.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Cron-driven maintenance.
 */
class Cleanup {

	/**
	 * Hook the cron event + scheduler.
	 */
	public static function init(): void {
		add_action( CLEANUP_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( 'admin_post_rssai_cleanup_now', array( __CLASS__, 'handle_now' ) );
	}

	/**
	 * Ensure a daily cleanup event is scheduled.
	 */
	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CLEANUP_HOOK );
		}
	}

	/**
	 * Run the cleanup. Returns a small report.
	 *
	 * @return array{items:int,groups:int,logs:int,days:int}
	 */
	public static function run(): array {
		global $wpdb;

		$days = max( 1, (int) Settings::get( 'cleanup_days', 30 ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$items_table  = Activator::items_table();
		$groups_table = Activator::groups_table();

		// Old draft items (any status) — generated news posts are separate.
		$items = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `$items_table` WHERE fetched_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// Old groups that are no longer open.
		$groups = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `$groups_table` WHERE created_at < %s AND status <> 'open'", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// Old log entries.
		$logs = Logger::prune( $days );

		Logger::log( 'cleanup', sprintf( 'Pruned items=%d groups=%d logs=%d (older than %d days)', $items, $groups, $logs, $days ) );

		return array(
			'items'  => $items,
			'groups' => $groups,
			'logs'   => $logs,
			'days'   => $days,
		);
	}

	/**
	 * Manual "Run cleanup now" from the admin.
	 */
	public static function handle_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( 'rssai_cleanup_now' );
		$report = self::run();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => Settings::MENU_SLUG,
					'rssai_cleaned' => 1,
					'ci'            => (int) $report['items'],
					'cg'            => (int) $report['groups'],
					'cl'            => (int) $report['logs'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
