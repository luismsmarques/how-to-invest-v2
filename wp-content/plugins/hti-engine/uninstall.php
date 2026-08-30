<?php
/**
 * Uninstall cleanup for HTI Engine.
 *
 * Two kinds of thing live here and they are treated differently on purpose.
 *
 * The plugin's OWN data goes: settings, counters, feedback, the private
 * `htinvest_profile` posts (a questionnaire answer set and the portfolio it
 * produced — pseudonymous, but personal, and kept only to run the engine), and
 * the user meta it wrote. Once the engine is gone the purpose is gone with it,
 * and data kept past its purpose is exactly what the GDPR calls unlawful.
 *
 * The SITE's content stays: `learn`, `glossary`, `news` and `broker` are
 * articles somebody wrote. The plugin registers those post types, but it does
 * not own the words. Deleting a plugin must never be how a site loses its
 * content — and anyone reinstalling would find the posts intact and unregistered
 * rather than gone.
 *
 * @package HTI_Engine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * The private profile CPT — the personal data.
 *
 * Deleted in batches with a wall-clock budget, because an uninstall is one HTTP
 * request and a site with tens of thousands of profiles would otherwise time
 * out halfway and leave the job half done with no way to resume. Whatever is
 * left survives to the next uninstall; that is better than a timeout that also
 * loses the options telling us what to clean.
 *
 * `hti_engine_uninstall_delete_profiles` can be filtered to false by anyone who
 * deliberately wants to keep them (a migration, say).
 */
if ( (bool) apply_filters( 'hti_engine_uninstall_delete_profiles', true ) ) {
	$deadline = microtime( true ) + 20.0;

	do {
		$ids = get_posts(
			array(
				'post_type'        => 'htinvest_profile',
				'post_status'      => 'any',
				'numberposts'      => 200,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	} while ( ! empty( $ids ) && microtime( true ) < $deadline );
}

// Feedback + NPS rows.
$table = $wpdb->prefix . 'hti_feedback';
$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

// Settings, curated config, counters and admin bookkeeping.
$options = array(
	'htinvest_settings',
	'htinvest_scoring',
	'htinvest_archetypes',
	'hti_invest_questions',
	'hti_metrics',
	'hti_nps_responses',
	'hti_deposits',
	'hti_go_links',
	'hti_content_sync_sig',
	'hti_content_last_sync',
	'hti_pending_source',
	'hti_rewrite_version',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

foreach ( array( 'hti_seed_report', 'hti_content_sync_notice', 'hti_go_link_notice', 'hti_feedback_page_id' ) as $transient ) {
	delete_transient( $transient );
}

// Per-user state the engine wrote. A LIKE over usermeta is the only way: there
// is no WP API for "every user with this meta key", and the keys are literals
// from this file, never from input.
foreach ( array( 'hti_prefs', 'hti_pref_locale', 'hti_onboarded', 'hti_nps_done', 'hti_delete_at', 'hti_google_sub', 'hti_email_token', 'hti_verify_token', 'hti_ebook_pending' ) as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}

// Scheduled work. Left behind, these fire forever against a hook nothing answers.
foreach ( array( 'hti_content_sync', 'hti_prune_profiles', 'hti_weekly_newsletter', 'hti_daily_digest' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// Rate-limit counters (hti_rl_*) live as transients in the options table when
// there is no persistent object cache.
$like = $wpdb->esc_like( '_transient_hti_rl_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$like = $wpdb->esc_like( '_transient_timeout_hti_rl_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

flush_rewrite_rules( false );
