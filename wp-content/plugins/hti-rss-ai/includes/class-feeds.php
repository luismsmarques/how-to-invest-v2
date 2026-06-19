<?php
/**
 * Data access for RSS feeds (the rssai_feeds table).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD helpers for feed sources.
 */
class Feeds {

	/**
	 * Feeds table name.
	 */
	public static function table(): string {
		return Activator::feeds_table();
	}

	/**
	 * All feeds, ordered by name.
	 *
	 * @return array<int,object>
	 */
	public static function all(): array {
		global $wpdb;
		$table = self::table();
		// $table is an internal, non-user value; safe to interpolate.
		return (array) $wpdb->get_results( "SELECT * FROM `$table` ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One feed by id.
	 *
	 * @param int $id Feed id.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Insert a feed. Returns the new id.
	 *
	 * @param array<string,mixed> $data Raw form data.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( self::table(), self::clean( $data ), self::formats() );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a feed.
	 *
	 * @param int                 $id   Feed id.
	 * @param array<string,mixed> $data Raw form data.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table(), self::clean( $data ), array( 'id' => $id ), self::formats(), array( '%d' ) );
	}

	/**
	 * Delete a feed.
	 *
	 * @param int $id Feed id.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Sanitize the writable columns.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>
	 */
	private static function clean( array $data ): array {
		$lang = in_array( $data['lang'] ?? 'en', array( 'en', 'pt' ), true ) ? $data['lang'] : 'en';
		return array(
			'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'url'              => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'default_category' => absint( $data['default_category'] ?? 0 ),
			'lang'             => $lang,
			'status'           => empty( $data['status'] ) ? 0 : 1,
		);
	}

	/**
	 * Column formats matching clean().
	 *
	 * @return array<int,string>
	 */
	private static function formats(): array {
		return array( '%s', '%s', '%d', '%s', '%d' );
	}
}
