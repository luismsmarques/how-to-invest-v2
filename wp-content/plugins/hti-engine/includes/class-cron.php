<?php
/**
 * Scheduled cleanup of stale anonymous profiles (data minimization, L1).
 *
 * Anonymous, unclaimed profiles (no `hti_user_id`) carry no identity but
 * accumulate. A daily job prunes those older than the retention window
 * (default 90 days, filterable), reinforcing RGPD minimization and limiting
 * abuse-driven growth. Claimed/account profiles are never touched.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Daily pruning of stale anonymous profiles.
 */
class Cron {

	private const HOOK      = 'hti_prune_profiles';
	private const BATCH     = 200;
	private const DEFAULT_DAYS = 90;

	/**
	 * Hook the job and make sure it is scheduled.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Ensure the daily event exists.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the scheduled event (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The GMT cutoff datetime for the retention window.
	 *
	 * @param int $days Retention days.
	 * @param int $now  Current unix time.
	 */
	public static function cutoff_gmt( int $days, int $now ): string {
		return gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Delete a batch of stale anonymous profiles.
	 */
	public static function prune(): void {
		/**
		 * Filter the anonymous-profile retention window in days.
		 *
		 * @param int $days Retention days.
		 */
		$days = (int) apply_filters( 'hti_profile_retention_days', self::DEFAULT_DAYS );
		if ( $days < 1 ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'htinvest_profile',
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'before' => self::cutoff_gmt( $days, time() ),
						'column' => 'post_date_gmt',
					),
				),
				// Anonymous = no owner set (claimed/account profiles have hti_user_id > 0).
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'hti_user_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'hti_user_id',
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		// Also remove unverified accounts that were never confirmed.
		Verification::prune_unverified();
	}
}
