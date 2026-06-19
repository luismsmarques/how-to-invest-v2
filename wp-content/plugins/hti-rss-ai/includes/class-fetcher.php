<?php
/**
 * Feed fetcher: pulls active feeds, parses items, extracts an image, dedupes
 * by guid hash, and stores them as drafts. Scheduled via WP-Cron.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and stores feed items.
 */
class Fetcher {

	/**
	 * Hook the cron event + scheduler.
	 */
	public static function init(): void {
		add_action( CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Keep the scheduled event in sync with the configured interval.
	 */
	public static function ensure_schedule(): void {
		$desired = (string) Settings::get( 'fetch_interval', 'hourly' );
		$current = wp_get_schedule( CRON_HOOK );
		if ( $current === $desired ) {
			return;
		}
		$timestamp = wp_next_scheduled( CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, CRON_HOOK );
		}
		wp_schedule_event( time() + 300, $desired, CRON_HOOK );
	}

	/**
	 * Fetch every active feed.
	 *
	 * @return array{feeds:int,items:int,dupes:int,errors:int}
	 */
	public static function run(): array {
		$report = array(
			'feeds'  => 0,
			'items'  => 0,
			'dupes'  => 0,
			'errors' => 0,
		);
		foreach ( Feeds::all() as $feed ) {
			if ( empty( $feed->status ) ) {
				continue;
			}
			++$report['feeds'];
			$result = self::fetch_one( $feed );
			if ( null === $result ) {
				++$report['errors'];
				continue;
			}
			$report['items'] += $result['items'];
			$report['dupes'] += $result['dupes'];
		}

		Logger::log(
			'fetch',
			sprintf( 'feeds=%d new=%d dupes=%d errors=%d', $report['feeds'], $report['items'], $report['dupes'], $report['errors'] )
		);
		return $report;
	}

	/**
	 * Fetch a single feed and store new items.
	 *
	 * @param object $feed Feed row.
	 * @return array{items:int,dupes:int}|null Null on read error.
	 */
	public static function fetch_one( object $feed ): ?array {
		include_once ABSPATH . WPINC . '/feed.php';
		$rss = fetch_feed( $feed->url );

		if ( is_wp_error( $rss ) ) {
			self::record_error( (int) $feed->id );
			return null;
		}

		$max     = $rss->get_item_quantity( (int) Settings::get( 'max_per_fetch', 50 ) );
		$items   = $rss->get_items( 0, $max );
		$created = 0;
		$dupes   = 0;

		foreach ( $items as $item ) {
			$guid = (string) ( $item->get_id() ?: $item->get_permalink() );
			if ( '' === $guid ) {
				continue;
			}
			$hash = sha1( $guid );
			if ( Items::exists( $hash ) ) {
				++$dupes;
				continue;
			}
			$title = sanitize_text_field( (string) $item->get_title() );
			if ( '' === $title ) {
				continue;
			}

			$source = $item->get_feed() ? (string) $item->get_feed()->get_title() : '';
			$id     = Items::insert(
				array(
					'feed_id'      => (int) $feed->id,
					'guid_hash'    => $hash,
					'title'        => $title,
					'description'  => self::clean_text( (string) ( $item->get_description() ?: $item->get_content() ) ),
					'image_url'    => self::extract_image( $item ),
					'source'       => sanitize_text_field( '' !== $source ? $source : $feed->name ),
					'link'         => esc_url_raw( (string) $item->get_permalink() ),
					'published_at' => $item->get_date( 'Y-m-d H:i:s' ) ?: current_time( 'mysql' ),
					'lang'         => $feed->lang,
					'status'       => 'new',
				)
			);
			if ( $id ) {
				++$created;
			} else {
				++$dupes; // Unique-key race or empty.
			}
		}

		self::record_fetched( (int) $feed->id );
		return array(
			'items' => $created,
			'dupes' => $dupes,
		);
	}

	/**
	 * Strip tags/whitespace from a summary, capped to a sane length.
	 *
	 * @param string $html Raw HTML.
	 */
	private static function clean_text( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 1000 ) {
			$text = mb_substr( $text, 0, 1000 );
		}
		return $text;
	}

	/**
	 * Best-effort image URL: enclosure / media thumbnail / first inline <img>.
	 *
	 * @param object $item SimplePie item.
	 */
	private static function extract_image( object $item ): string {
		$enclosure = $item->get_enclosure();
		if ( $enclosure ) {
			$thumb = $enclosure->get_thumbnail();
			if ( $thumb ) {
				return esc_url_raw( (string) $thumb );
			}
			$link = (string) $enclosure->get_link();
			$type = (string) $enclosure->get_type();
			if ( '' !== $link && ( str_starts_with( $type, 'image' ) || preg_match( '/\.(jpe?g|png|webp|gif)(\?|#|$)/i', $link ) ) ) {
				return esc_url_raw( $link );
			}
		}

		foreach ( (array) $item->get_enclosures() as $enc ) {
			$thumb = $enc->get_thumbnail();
			if ( $thumb ) {
				return esc_url_raw( (string) $thumb );
			}
			$link = (string) $enc->get_link();
			if ( '' !== $link && str_starts_with( (string) $enc->get_type(), 'image' ) ) {
				return esc_url_raw( $link );
			}
		}

		$html = (string) ( $item->get_content() ?: $item->get_description() );
		if ( '' !== $html && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return esc_url_raw( $matches[1] );
		}

		return '';
	}

	/**
	 * Mark a feed as fetched (resets error counter).
	 *
	 * @param int $feed_id Feed id.
	 */
	private static function record_fetched( int $feed_id ): void {
		global $wpdb;
		$wpdb->update(
			Feeds::table(),
			array(
				'last_fetched' => current_time( 'mysql' ),
				'error_count'  => 0,
			),
			array( 'id' => $feed_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Increment a feed's error counter.
	 *
	 * @param int $feed_id Feed id.
	 */
	private static function record_error( int $feed_id ): void {
		global $wpdb;
		$table = Feeds::table();
		$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET error_count = error_count + 1 WHERE id = %d", $feed_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
