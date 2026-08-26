<?php
/**
 * Daily cleanup: prune old draft items and log entries so the tables/option
 * don't grow without bound. Scheduled via WP-Cron; also runnable on demand.
 *
 * Retention is the "cleanup_days" setting (default 30). Items older than that
 * are removed regardless of status (they have already been processed or are
 * stale); old non-open groups are removed too; logs older than the window are
 * trimmed.
 *
 * It also keeps groups honest: after items are pruned, group sizes are
 * recomputed, empty open groups are deleted, open groups with no activity for
 * "open_max_days" are dismissed (abandoned stories), and items pointing at a
 * group that no longer exists are un-linked.
 *
 * Finally, when "enable_post_cleanup" is on, stale AI drafts that were never
 * reviewed (post_status pending/draft, older than "post_cleanup_days") are
 * moved to Trash. PUBLISHED news posts are never touched (SEO value).
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
	 * @return array{items:int,groups:int,logs:int,posts:int,days:int}
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

		// Reconcile groups after item deletion: no zombie/empty/abandoned groups.
		$groups += self::reconcile_groups();

		// Old log entries.
		$logs = Logger::prune( $days );

		// Stale, never-reviewed AI drafts → Trash (published posts are kept).
		$posts = self::prune_posts();

		Logger::log( 'cleanup', sprintf( 'Pruned items=%d groups=%d logs=%d posts=%d (older than %d days)', $items, $groups, $logs, $posts, $days ) );

		return array(
			'items'  => $items,
			'groups' => $groups,
			'logs'   => $logs,
			'posts'  => $posts,
			'days'   => $days,
		);
	}

	/**
	 * Keep the groups table consistent with the items table:
	 * - dismiss open groups with no activity for `open_max_days` (abandoned);
	 * - delete open groups that have no items left (zombies);
	 * - un-link items whose group_id points at a group that no longer exists.
	 *
	 * @return int Groups removed/dismissed (for the report/log).
	 */
	private static function reconcile_groups(): int {
		global $wpdb;

		$items_table  = Activator::items_table();
		$groups_table = Activator::groups_table();
		$open_days    = max( 1, (int) Settings::get( 'open_max_days', 14 ) );
		$open_cut     = gmdate( 'Y-m-d H:i:s', time() - ( $open_days * DAY_IN_SECONDS ) );
		$touched      = 0;

		// Abandoned open groups (no activity within the join window). Dismissing
		// flips their items to 'ignored'; the non-open delete removes them later.
		$stale = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `$groups_table` WHERE status = 'open' AND updated_at < %s", $open_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $stale as $gid ) {
			Groups::dismiss( (int) $gid );
			++$touched;
		}

		// Empty open groups (lost all items to pruning) — delete outright.
		$empty = (array) $wpdb->get_col( "SELECT g.id FROM `$groups_table` g LEFT JOIN `$items_table` i ON i.group_id = g.id WHERE g.status = 'open' AND i.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $empty as $gid ) {
			Groups::delete( (int) $gid );
			++$touched;
		}

		// Orphan items whose group vanished — clear the dangling pointer.
		$wpdb->query( "UPDATE `$items_table` i LEFT JOIN `$groups_table` g ON i.group_id = g.id SET i.group_id = 0 WHERE i.group_id IS NOT NULL AND i.group_id > 0 AND g.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return $touched;
	}

	/**
	 * Move stale, never-published AI drafts to Trash. Only posts created by the
	 * pipeline (meta `rssai_generated_at`) in `pending`/`draft` status and older
	 * than `post_cleanup_days` are trashed. Published posts are never touched.
	 *
	 * @return int Posts trashed.
	 */
	private static function prune_posts(): int {
		if ( empty( Settings::get( 'enable_post_cleanup', 1 ) ) ) {
			return 0;
		}
		$days = max( 1, (int) Settings::get( 'post_cleanup_days', 45 ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$ids = get_posts(
			array(
				'post_type'        => Settings::post_type(),
				'post_status'      => array( 'pending', 'draft' ),
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'rssai_generated_at',
						'compare' => 'EXISTS',
					),
				),
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $cut,
					),
				),
			)
		);

		$trashed = 0;
		foreach ( (array) $ids as $pid ) {
			if ( wp_trash_post( (int) $pid ) ) {
				++$trashed;
			}
		}
		return $trashed;
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
					'cp'            => (int) $report['posts'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
