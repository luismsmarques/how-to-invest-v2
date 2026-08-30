<?php
/**
 * Uninstall cleanup for HTI Forex.
 *
 * The bot's table holds a row per person who ever opened it — a Telegram
 * chat_id, and now the campaign they arrived on. That is personal data we keep
 * only to run a bot; once the plugin that runs it is gone the purpose is gone
 * too, and keeping the rows is keeping data for no reason. Same for the webhook
 * secret, which is a live credential.
 *
 * @package HTI_Forex
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The subscriber table — the reason this file has to exist.
$table = $wpdb->prefix . 'hti_forex_bot_subs';
$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

// Settings, bot state and the webhook secret.
$options = array(
	'hti_forex_settings',
	'hti_forex_rates',
	'hti_forex_rewrites',
	'hti_forex_sync_sig',
	'hti_forex_bot_secret',
	'hti_forex_bot_schema',
	'hti_forex_bot_buckets',
	'hti_forex_bot_sources',
	'hti_forex_bot_images',
	'hti_forex_bot_broadcast',
	'hti_forex_bot_broadcast_log',
	'hti_forex_broadcast_state',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Cached webhook health.
delete_transient( 'hti_forex_bot_health' );

// Anything the plugin scheduled. Left behind, these fire forever against a
// hook nothing answers.
foreach ( array( 'hti_forex_fetch_rates', 'hti_forex_content_sync', 'hti_forex_bot_broadcast' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// The /forex/ pages are content someone wrote and may still want; the rewrite
// rules that pointed at the redirector are not. Flushing costs nothing and
// stops /forex/go/ from resolving to a route that no longer exists.
flush_rewrite_rules( false );
