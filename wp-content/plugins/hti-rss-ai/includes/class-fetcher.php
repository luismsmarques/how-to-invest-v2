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
			'feeds'     => 0,
			'items'     => 0,
			'dupes'     => 0,
			'neardupes' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		);
		foreach ( Feeds::all() as $feed ) {
			if ( empty( $feed->status ) ) {
				continue;
			}
			// Don't hammer a feed that just failed — let its back-off elapse first.
			if ( self::in_backoff( $feed ) ) {
				++$report['skipped'];
				continue;
			}
			++$report['feeds'];
			$result = self::fetch_one( $feed );
			if ( null === $result ) {
				++$report['errors'];
				continue;
			}
			$report['items']     += $result['items'];
			$report['dupes']     += $result['dupes'];
			$report['neardupes'] += $result['neardupes'] ?? 0;
		}

		Logger::log(
			'fetch',
			sprintf( 'feeds=%d new=%d dupes=%d neardupes=%d errors=%d skipped=%d', $report['feeds'], $report['items'], $report['dupes'], $report['neardupes'], $report['errors'], $report['skipped'] )
		);

		// Auto-group freshly ingested items so nothing waits for a manual click.
		if ( $report['items'] > 0 ) {
			$grouped = Grouping::run();
			Logger::log( 'fetch', sprintf( 'auto-group: groups=%d joined=%d items=%d', $grouped['groups'], $grouped['joined'], $grouped['items'] ) );
		}
		return $report;
	}

	/**
	 * Growing back-off between retries of a failing feed: 5 min × 2^errors,
	 * capped at a day. Pure; testable.
	 *
	 * @param int $error_count Consecutive error count.
	 */
	public static function backoff_seconds( int $error_count ): int {
		$n    = max( 0, $error_count );
		$day  = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$secs = 300 * ( 2 ** min( $n, 12 ) );
		return (int) min( $secs, $day );
	}

	/**
	 * Whether a feed is still within its error back-off window.
	 *
	 * @param object $feed Feed row.
	 */
	private static function in_backoff( object $feed ): bool {
		$errors = (int) ( $feed->error_count ?? 0 );
		if ( $errors < 1 ) {
			return false;
		}
		$last = (string) ( $feed->last_error ?? '' );
		if ( '' === $last || str_starts_with( $last, '0000' ) ) {
			return false;
		}
		$ts = strtotime( $last . ' UTC' );
		if ( ! $ts ) {
			return false;
		}
		return ( time() - $ts ) < self::backoff_seconds( $errors );
	}

	/**
	 * Fetch a single feed and store new items.
	 *
	 * @param object $feed Feed row.
	 * @return array{items:int,dupes:int}|null Null on read error.
	 */
	public static function fetch_one( object $feed ): ?array {
		if ( 'youtube' === ( $feed->kind ?? 'rss' ) ) {
			return self::fetch_youtube( $feed );
		}
		include_once ABSPATH . WPINC . '/feed.php';
		$rss = fetch_feed( $feed->url );

		if ( is_wp_error( $rss ) ) {
			self::record_error( (int) $feed->id );
			return null;
		}

		$max       = $rss->get_item_quantity( (int) Settings::get( 'max_per_fetch', 50 ) );
		$items     = $rss->get_items( 0, $max );
		$created   = 0;
		$dupes     = 0;
		$neardupes = 0;
		$since     = gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) );

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

			// Suppress the same story syndicated across feeds under a new guid.
			$fingerprint = Grouping::fingerprint( $title );
			if ( '' !== $fingerprint && Items::fingerprint_exists( $fingerprint, (string) $feed->lang, $since ) ) {
				++$neardupes;
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
					'fingerprint'  => $fingerprint,
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
			'items'     => $created,
			'dupes'     => $dupes,
			'neardupes' => $neardupes,
		);
	}

	/**
	 * Fetch a YouTube channel's recent uploads as items (no transcript yet —
	 * that is fetched on demand when generating).
	 *
	 * @param object $feed Feed row (url = channel id).
	 * @return array{items:int,dupes:int}|null
	 */
	private static function fetch_youtube( object $feed ): ?array {
		if ( ! YouTube::is_configured() ) {
			Logger::log( 'youtube', 'Skipped "' . $feed->name . '": YouTube API key not configured.' );
			self::record_error( (int) $feed->id );
			return null;
		}

		$videos = YouTube::recent_uploads( (string) $feed->url, (int) Settings::get( 'max_per_fetch', 50 ) );
		if ( is_wp_error( $videos ) ) {
			self::record_error( (int) $feed->id );
			return null;
		}

		$created   = 0;
		$dupes     = 0;
		$neardupes = 0;
		$since     = gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) );
		foreach ( $videos as $v ) {
			$hash = sha1( 'yt:' . $v['video_id'] );
			if ( Items::exists( $hash ) ) {
				++$dupes;
				continue;
			}
			$title = sanitize_text_field( (string) $v['title'] );
			if ( '' === $title ) {
				continue;
			}
			$fingerprint = Grouping::fingerprint( $title );
			if ( '' !== $fingerprint && Items::fingerprint_exists( $fingerprint, (string) $feed->lang, $since ) ) {
				++$neardupes;
				continue;
			}
			$ts  = '' !== $v['published_at'] ? strtotime( (string) $v['published_at'] ) : false;
			$pub = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );

			$id = Items::insert(
				array(
					'feed_id'      => (int) $feed->id,
					'guid_hash'    => $hash,
					'title'        => $title,
					'description'  => self::clean_text( (string) $v['description'] ),
					'video_id'     => sanitize_text_field( (string) $v['video_id'] ),
					'image_url'    => esc_url_raw( (string) $v['thumbnail'] ),
					'source'       => sanitize_text_field( '' !== $v['channel'] ? (string) $v['channel'] : (string) $feed->name ),
					'link'         => esc_url_raw( (string) $v['url'] ),
					'published_at' => $pub,
					'lang'         => $feed->lang,
					'fingerprint'  => $fingerprint,
					'status'       => 'new',
				)
			);
			if ( $id ) {
				++$created;
			} else {
				++$dupes;
			}
		}

		self::record_fetched( (int) $feed->id );
		return array(
			'items'     => $created,
			'dupes'     => $dupes,
			'neardupes' => $neardupes,
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
		$table = Feeds::table();
		// Clear the error counter and back-off marker on a clean fetch.
		$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET last_fetched = %s, error_count = 0, last_error = NULL WHERE id = %d", current_time( 'mysql' ), $feed_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Record a feed read error: bump the counter, stamp the back-off marker,
	 * and auto-pause the feed once it has failed too many times in a row.
	 *
	 * @param int $feed_id Feed id.
	 */
	private static function record_error( int $feed_id ): void {
		global $wpdb;
		$table = Feeds::table();
		$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET error_count = error_count + 1, last_error = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $feed_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$max  = max( 1, (int) Settings::get( 'feed_max_errors', 5 ) );
		$feed = Feeds::get( $feed_id );
		if ( $feed && ! empty( $feed->status ) && (int) $feed->error_count >= $max ) {
			$wpdb->update( $table, array( 'status' => 0 ), array( 'id' => $feed_id ), array( '%d' ), array( '%d' ) );
			Logger::log( 'fetch', sprintf( 'Auto-paused feed #%d "%s" after %d consecutive errors', $feed_id, (string) ( $feed->name ?? '' ), (int) $feed->error_count ) );
		}
	}
}
