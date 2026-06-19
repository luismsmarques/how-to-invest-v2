<?php
/**
 * Uninstall cleanup for HTI RSS AI Feed.
 *
 * Drops the plugin's tables and options. Only runs on real uninstall.
 *
 * @package HTI_RSS_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'rssai_feeds', 'rssai_items', 'rssai_groups' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

delete_option( 'rssai_settings' );
delete_option( 'rssai_db_version' );
delete_option( 'rssai_logs' );

// Daily generation counters (rssai_gen_YYYYMMDD).
$like = $wpdb->esc_like( 'rssai_gen_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
