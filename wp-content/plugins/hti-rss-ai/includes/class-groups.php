<?php
/**
 * Data access for clusters of related items (the rssai_groups table).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for groups.
 */
class Groups {

	/**
	 * Groups table name.
	 */
	public static function table(): string {
		return Activator::groups_table();
	}

	/**
	 * Insert a group; returns the new id.
	 *
	 * @param array<string,mixed> $data Group fields.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::table(),
			array(
				'label'      => (string) ( $data['label'] ?? '' ),
				'lang'       => (string) ( $data['lang'] ?? 'en' ),
				'status'     => (string) ( $data['status'] ?? 'open' ),
				'score'      => (float) ( $data['score'] ?? 0 ),
				'size'       => (int) ( $data['size'] ?? 0 ),
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%f', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Groups, optionally filtered by status.
	 *
	 * @param string $status Status filter ('' = any).
	 * @return array<int,object>
	 */
	public static function all( string $status = 'open' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' !== $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE status = %s ORDER BY created_at DESC, id DESC", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( "SELECT * FROM `$table` ORDER BY created_at DESC, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Recently-active open groups for a language, newest first. Used by the
	 * grouper so new items can join an existing story instead of spawning a
	 * duplicate group across fetch cycles.
	 *
	 * @param string $lang Language code.
	 * @param int    $days Only groups touched within this many days.
	 * @return array<int,object>
	 */
	public static function open_recent( string $lang, int $days ): array {
		global $wpdb;
		$table = self::table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE status = 'open' AND lang = %s AND updated_at >= %s ORDER BY updated_at DESC, id DESC", $lang, $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One group by id.
	 *
	 * @param int $id Group id.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Items belonging to a group.
	 *
	 * @param int $group_id Group id.
	 * @return array<int,object>
	 */
	public static function items( int $group_id ): array {
		return Items::query(
			array(
				'group_id' => $group_id,
				'per_page' => 100,
				'offset'   => 0,
			)
		);
	}

	/**
	 * Set a group's status.
	 *
	 * @param int    $id     Group id.
	 * @param string $status New status.
	 */
	public static function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Recount the live number of items in a group and persist it as `size`.
	 * The stored `size` was frozen at creation; this keeps it honest after
	 * items are added, removed or pruned.
	 *
	 * @param int  $id    Group id.
	 * @param bool $touch Also bump updated_at.
	 * @return int Current live item count.
	 */
	public static function recount( int $id, bool $touch = false ): int {
		global $wpdb;
		$items = Items::table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$items` WHERE group_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data  = array( 'size' => $count );
		$fmt   = array( '%d' );
		if ( $touch ) {
			$data['updated_at'] = current_time( 'mysql' );
			$fmt[]              = '%s';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $fmt, array( '%d' ) );
		return $count;
	}

	/**
	 * Mark a group as recently active (updates updated_at).
	 *
	 * @param int $id Group id.
	 */
	public static function touch( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Hard-delete a group row (used for empty/orphan open groups). Does not
	 * touch the items table — callers reset item group_id/status first.
	 *
	 * @param int $id Group id.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Dismiss a group and ignore its items.
	 *
	 * @param int $id Group id.
	 */
	public static function dismiss( int $id ): void {
		self::set_status( $id, 'dismissed' );
		$ids = array_map( static fn( $item ) => (int) $item->id, self::items( $id ) );
		if ( $ids ) {
			Items::update_status( $ids, 'ignored' );
		}
	}
}
