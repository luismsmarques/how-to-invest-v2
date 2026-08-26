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
	 * Curated starter feeds (financial/market sources). Validate each with
	 * "Test feed" — RSS URLs change over time. EN by default, a few PT sources.
	 *
	 * @return array<int,array{name:string,url:string,lang:string}>
	 */
	public static function suggested(): array {
		return array(
			// Broad market news (EN).
			array( 'name' => 'MarketWatch — Top Stories', 'url' => 'https://feeds.marketwatch.com/marketwatch/topstories/', 'lang' => 'en' ),
			array( 'name' => 'CNBC — Top News', 'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html', 'lang' => 'en' ),
			array( 'name' => 'CNBC — Markets', 'url' => 'https://www.cnbc.com/id/20910258/device/rss/rss.html', 'lang' => 'en' ),
			array( 'name' => 'Investing.com — News', 'url' => 'https://www.investing.com/rss/news.rss', 'lang' => 'en' ),
			array( 'name' => 'BBC — Business', 'url' => 'https://feeds.bbci.co.uk/news/business/rss.xml', 'lang' => 'en' ),
			array( 'name' => 'The Guardian — Business', 'url' => 'https://www.theguardian.com/business/rss', 'lang' => 'en' ),
			// Primary / authoritative sources (EN).
			array( 'name' => 'Federal Reserve — Press', 'url' => 'https://www.federalreserve.gov/feeds/press_all.xml', 'lang' => 'en' ),
			array( 'name' => 'The Economist — Finance & economics', 'url' => 'https://www.economist.com/finance-and-economics/rss.xml', 'lang' => 'en' ),
			// Portuguese sources (PT).
			array( 'name' => 'ECO', 'url' => 'https://eco.sapo.pt/feed/', 'lang' => 'pt' ),
			array( 'name' => 'Observador — Economia', 'url' => 'https://observador.pt/seccao/economia/feed/', 'lang' => 'pt' ),
			array( 'name' => 'Jornal de Negócios', 'url' => 'https://www.jornaldenegocios.pt/rss', 'lang' => 'pt' ),
		);
	}

	/**
	 * Insert the suggested feeds that aren't already present (matched by URL).
	 * Idempotent. New feeds are added active. Returns how many were added.
	 */
	public static function seed_suggested(): int {
		$existing = array();
		foreach ( self::all() as $feed ) {
			$existing[ untrailingslashit( strtolower( (string) $feed->url ) ) ] = true;
		}
		$added = 0;
		foreach ( self::suggested() as $feed ) {
			$key = untrailingslashit( strtolower( $feed['url'] ) );
			if ( isset( $existing[ $key ] ) ) {
				continue;
			}
			self::insert(
				array(
					'name'   => $feed['name'],
					'url'    => $feed['url'],
					'lang'   => $feed['lang'],
					'status' => 1,
				)
			);
			++$added;
		}
		return $added;
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
		$lang = Settings::valid_lang( (string) ( $data['lang'] ?? '' ) );
		$kind = in_array( $data['kind'] ?? 'rss', array( 'rss', 'youtube' ), true ) ? (string) $data['kind'] : 'rss';
		// For a YouTube feed, "url" holds a channel id (UC…), not a real URL.
		$raw = (string) ( $data['url'] ?? '' );
		$url = 'youtube' === $kind ? sanitize_text_field( $raw ) : esc_url_raw( $raw );
		return array(
			'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'url'              => $url,
			'kind'             => $kind,
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
		return array( '%s', '%s', '%s', '%d', '%s', '%d' );
	}
}
