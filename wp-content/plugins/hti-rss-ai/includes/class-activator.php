<?php
/**
 * Activation / schema management for HTI RSS AI Feed.
 *
 * Creates the three custom tables (feeds, items, groups) with dbDelta and
 * keeps them in sync via a stored DB version. No user data is destroyed on
 * deactivation — only scheduled events are cleared.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin's database tables.
 */
class Activator {

	/**
	 * Bump when a table schema changes.
	 */
	private const DB_VERSION = '4';

	/**
	 * Option storing the installed schema version.
	 */
	private const DB_VERSION_OPTION = 'rssai_db_version';

	/**
	 * Feeds table name (with prefix).
	 */
	public static function feeds_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rssai_feeds';
	}

	/**
	 * Items (drafts) table name (with prefix).
	 */
	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rssai_items';
	}

	/**
	 * Groups table name (with prefix).
	 */
	public static function groups_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rssai_groups';
	}

	/**
	 * Activation: create tables.
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Deactivation: clear scheduled events (tables are kept).
	 */
	public static function deactivate(): void {
		// The two one-off hooks are cleared wholesale: a single event queued
		// seconds before deactivation would otherwise survive and fire into a
		// plugin that is no longer there.
		wp_clear_scheduled_hook( GROUP_HOOK );
		wp_clear_scheduled_hook( FETCH_MORE_HOOK );

		foreach ( array( CRON_HOOK, CLEANUP_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Option marking the one-off model-name migration as done.
	 */
	private const MODEL_MIGRATION_OPTION = 'rssai_model_migration';

	/**
	 * Run table creation if the stored DB version is behind, and retire any
	 * model name Google has switched off.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
		self::migrate_model_names();
	}

	/**
	 * Replace stored model names that Google has retired.
	 *
	 * This has to exist because changing the defaults is not enough: the
	 * settings screen writes every key on any save, and Settings::get() prefers
	 * the stored value over the default. An installation that has ever opened
	 * that screen keeps the dead name forever, which is exactly the state this
	 * site was found in — an image model retired in August and an embedding
	 * model retired in January, both still sitting in the options table,
	 * failing on every call.
	 *
	 * Runs once (it is a rescue, not a policy): after it, whatever is in the
	 * field is the operator's choice and stays there.
	 */
	private static function migrate_model_names(): void {
		if ( '1' === (string) get_option( self::MODEL_MIGRATION_OPTION, '' ) ) {
			return;
		}

		$settings = get_option( 'rssai_settings', null );
		if ( ! is_array( $settings ) ) {
			// Never configured — the defaults already point at live models.
			update_option( self::MODEL_MIGRATION_OPTION, '1', false );
			return;
		}

		$changed      = array();
		$replacements = Model_Catalog::replacements();
		foreach ( $replacements as $key => $replacement ) {
			$current = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			if ( Model_Catalog::is_retired( $key, $current ) ) {
				$settings[ $key ] = $replacement;
				$changed[]        = $current . ' → ' . $replacement;
			}
		}

		if ( $changed ) {
			update_option( 'rssai_settings', $settings );
			Logger::log( 'settings', 'Retired model names replaced: ' . implode( ', ', $changed ) );
		}
		update_option( self::MODEL_MIGRATION_OPTION, '1', false );
	}

	/**
	 * Create/upgrade the tables via dbDelta.
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$feeds   = self::feeds_table();
		$items   = self::items_table();
		$groups  = self::groups_table();

		dbDelta(
			"CREATE TABLE $feeds (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				url text NOT NULL,
				kind varchar(20) NOT NULL DEFAULT 'rss',
				default_category bigint(20) unsigned NOT NULL DEFAULT 0,
				lang varchar(5) NOT NULL DEFAULT 'en',
				status tinyint(1) NOT NULL DEFAULT 1,
				last_fetched datetime DEFAULT NULL,
				last_error datetime DEFAULT NULL,
				error_count int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $items (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				feed_id bigint(20) unsigned NOT NULL DEFAULT 0,
				guid_hash char(40) NOT NULL DEFAULT '',
				title text NOT NULL,
				description longtext NULL,
				transcript longtext NULL,
				video_id varchar(32) NOT NULL DEFAULT '',
				image_url text NULL,
				source varchar(191) NOT NULL DEFAULT '',
				link text NULL,
				published_at datetime DEFAULT NULL,
				lang varchar(5) NOT NULL DEFAULT 'en',
				fingerprint varchar(40) NOT NULL DEFAULT '',
				embedding longtext NULL,
				group_id bigint(20) unsigned DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				fetched_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY guid_hash (guid_hash),
				KEY feed_id (feed_id),
				KEY status (status),
				KEY group_id (group_id),
				KEY fingerprint (fingerprint)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $groups (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label text NULL,
				lang varchar(5) NOT NULL DEFAULT 'en',
				status varchar(20) NOT NULL DEFAULT 'open',
				score float NOT NULL DEFAULT 0,
				size int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY updated_at (updated_at)
			) $charset;"
		);
	}
}
