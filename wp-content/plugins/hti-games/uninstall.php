<?php
/**
 * Uninstall: leave nothing behind.
 *
 * Shipped with the first commit deliberately. hti-forex went to production
 * without one and still leaves Telegram chat ids in the database when it is
 * removed — a recorded mistake, and not one to repeat with a table that holds
 * player identities.
 *
 * Runs only on a real uninstall (deleting the plugin), never on deactivation,
 * and WordPress loads this file on its own with none of the plugin's classes
 * available — hence the literal table and option names.
 *
 * @package HTI_Games
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The two custom tables.
foreach ( array( 'hti_games_runs', 'hti_games_players' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall; a table name cannot be a placeholder and this one is built from a literal.
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
}

// Both content types, with their meta (wp_delete_post handles the meta rows).
foreach ( array( 'hti_stc_scenario', 'hti_reveal_case' ) as $post_type ) {
	$ids = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

// Options. Every one the plugin ever writes: the schema version (Store), the
// settings row (Settings), and the seeder's signature and last report.
// tests/test-security.php reads the source for update_option() calls and fails
// if one names an option this list does not.
foreach ( array( 'hti_games_schema', 'hti_games_settings', 'hti_games_sync_sig', 'hti_games_last_sync' ) as $option ) {
	delete_option( $option );
}

// Transients: the pool caches and the leaderboard caches are keyed per game,
// so they are cleared by prefix rather than by name.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall; there is no API for deleting transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_hti_games_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hti_games_' ) . '%'
	)
);

// User meta minted by the magic link.
foreach ( array( 'hti_games_link_token', 'hti_games_link_expires' ) as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}
