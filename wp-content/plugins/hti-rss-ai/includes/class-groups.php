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
		$wpdb->insert(
			self::table(),
			array(
				'label'  => (string) ( $data['label'] ?? '' ),
				'lang'   => (string) ( $data['lang'] ?? 'en' ),
				'status' => (string) ( $data['status'] ?? 'open' ),
				'score'  => (float) ( $data['score'] ?? 0 ),
				'size'   => (int) ( $data['size'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%f', '%d' )
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
