<?php
/**
 * Data access for ingested feed items / drafts (the rssai_items table).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + queries for draft items.
 */
class Items {

	/**
	 * Items table name.
	 */
	public static function table(): string {
		return Activator::items_table();
	}

	/**
	 * Whether an item with this guid hash already exists (dedupe).
	 *
	 * @param string $hash sha1 of the item guid/link.
	 */
	public static function exists( string $hash ): bool {
		global $wpdb;
		$table = self::table();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE guid_hash = %s LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $id;
	}

	/**
	 * Insert a draft item.
	 *
	 * @param array<string,mixed> $data Already-sanitized values.
	 * @return int New id (0 on failure).
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'feed_id'      => (int) ( $data['feed_id'] ?? 0 ),
				'guid_hash'    => (string) ( $data['guid_hash'] ?? '' ),
				'title'        => (string) ( $data['title'] ?? '' ),
				'description'  => (string) ( $data['description'] ?? '' ),
				'transcript'   => (string) ( $data['transcript'] ?? '' ),
				'video_id'     => (string) ( $data['video_id'] ?? '' ),
				'image_url'    => (string) ( $data['image_url'] ?? '' ),
				'source'       => (string) ( $data['source'] ?? '' ),
				'link'         => (string) ( $data['link'] ?? '' ),
				'published_at' => (string) ( $data['published_at'] ?? current_time( 'mysql' ) ),
				'lang'         => (string) ( $data['lang'] ?? 'en' ),
				'status'       => (string) ( $data['status'] ?? 'new' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Build the WHERE clause + params from filter args.
	 *
	 * @param array<string,mixed> $args Filters: feed_id, status, lang.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function where( array $args ): array {
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['feed_id'] ) ) {
			$where[]  = 'feed_id = %d';
			$params[] = (int) $args['feed_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['lang'] ) ) {
			$where[]  = 'lang = %s';
			$params[] = (string) $args['lang'];
		}
		if ( ! empty( $args['group_id'] ) ) {
			$where[]  = 'group_id = %d';
			$params[] = (int) $args['group_id'];
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Assign several items to a group and flag them grouped.
	 *
	 * @param array<int,int> $ids      Item ids.
	 * @param int            $group_id Group id.
	 * @return int Rows affected.
	 */
	public static function set_group( array $ids, int $group_id ): int {
		global $wpdb;
		$table = self::table();
		$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$place  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge( array( $group_id ), $ids );
		$sql    = "UPDATE `$table` SET group_id = %d, status = 'grouped' WHERE id IN ($place)";
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Query items with filters + paging.
	 *
	 * @param array<string,mixed> $args feed_id, status, lang, per_page, offset.
	 * @return array<int,object>
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table              = self::table();
		list( $w, $params ) = self::where( $args );
		$params[]           = (int) ( $args['per_page'] ?? 20 );
		$params[]           = (int) ( $args['offset'] ?? 0 );
		$sql                = "SELECT * FROM `$table` WHERE $w ORDER BY published_at DESC, id DESC LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count items matching filters.
	 *
	 * @param array<string,mixed> $args feed_id, status, lang.
	 */
	public static function count( array $args ): int {
		global $wpdb;
		$table              = self::table();
		list( $w, $params ) = self::where( $args );
		$sql                = "SELECT COUNT(*) FROM `$table` WHERE $w";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Set the status of several items.
	 *
	 * @param array<int,int> $ids    Item ids.
	 * @param string         $status New status.
	 * @return int Rows affected.
	 */
	public static function update_status( array $ids, string $status ): int {
		global $wpdb;
		$table = self::table();
		$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$place  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge( array( $status ), $ids );
		$sql    = "UPDATE `$table` SET status = %s WHERE id IN ($place)";
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
