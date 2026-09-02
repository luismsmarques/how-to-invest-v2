<?php
/**
 * Best-effort semantic embeddings for draft items.
 *
 * When enabled, computes one vector per ungrouped item (via the Gemini
 * embeddings endpoint, server-side) and caches it in the item's `embedding`
 * column. The grouper then blends this with the deterministic lexical
 * similarity so semantically-close stories that share few words still cluster.
 *
 * Everything here is optional and degrades gracefully: no key, quota errors or
 * the feature switched off simply leaves items without vectors, and grouping
 * falls back to the lexical matcher.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Computes and stores item embeddings.
 */
class Embeddings {

	/**
	 * Texts embedded per API call.
	 */
	private const BATCH = 50;

	/**
	 * HTTP timeout (seconds) for one embedding batch when no deadline applies.
	 */
	private const HTTP_TIMEOUT = 60;

	/**
	 * Minimum runway (seconds) a batch needs before its deadline to be worth
	 * starting at all.
	 */
	private const MIN_CHUNK_SECONDS = 10;

	/**
	 * HTTP timeout for the next embedding batch under a deadline, or null when
	 * there is not enough budget left to start one. Pure; testable.
	 *
	 * One batch is one blocking HTTP call: started with three seconds of
	 * budget left it would blow through the caller's limit however fast the
	 * code around it is, so a batch needs a minimum runway and its timeout
	 * must never promise more time than the budget still has.
	 *
	 * @param float|null $deadline microtime(true) to stop at, or null.
	 * @param float      $now      Current microtime(true).
	 */
	public static function chunk_timeout( ?float $deadline, float $now ): ?int {
		if ( null === $deadline ) {
			return self::HTTP_TIMEOUT;
		}
		$remaining = $deadline - $now;
		if ( $remaining < self::MIN_CHUNK_SECONDS ) {
			return null;
		}
		return (int) min( self::HTTP_TIMEOUT, max( 5, floor( $remaining - 2 ) ) );
	}

	/**
	 * Whether embeddings are enabled and a key is available.
	 */
	public static function enabled(): bool {
		return ! empty( Settings::get( 'enable_embeddings', 0 ) ) && Gemini_Client::available();
	}

	/**
	 * Compute + store embeddings for up to $cap ungrouped items of a language
	 * that still lack one. Best-effort; stops on the first API error (grouping
	 * will just use the lexical signal for the rest), and stops early when the
	 * caller's wall-clock deadline leaves no room for another batch — the
	 * items not reached are picked up by the next run.
	 *
	 * @param string     $lang     Language code.
	 * @param int        $cap      Max items to embed this run.
	 * @param float|null $deadline microtime(true) to stop at, or null for no limit.
	 * @return int Items embedded.
	 */
	public static function backfill( string $lang, int $cap, ?float $deadline = null ): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$items = Items::needing_embeddings( $lang, max( 1, $cap ) );
		if ( ! $items ) {
			return 0;
		}

		$done = 0;
		foreach ( array_chunk( $items, self::BATCH ) as $chunk ) {
			$timeout = self::chunk_timeout( $deadline, microtime( true ) );
			if ( null === $timeout ) {
				break;
			}
			$ids   = array();
			$texts = array();
			foreach ( $chunk as $item ) {
				$ids[]   = (int) $item->id;
				$texts[] = self::text_for( $item );
			}
			$vectors = Gemini_Client::embed( $texts, $timeout );
			if ( is_wp_error( $vectors ) ) {
				// Counted, not just logged: grouping degrades to lexical-only
				// when this fails, which is invisible from the outside and can
				// run for weeks — as it did when the embedding model was retired.
				Health::record( 'embed', false, $vectors->get_error_message() );
				Logger::log( 'embed', 'error: ' . $vectors->get_error_message() );
				break;
			}
			Health::record( 'embed', true );
			foreach ( $vectors as $i => $vector ) {
				if ( isset( $ids[ $i ] ) && is_array( $vector ) && $vector ) {
					Items::set_embedding( $ids[ $i ], (string) wp_json_encode( $vector ) );
					++$done;
				}
			}
		}

		if ( $done ) {
			Logger::log( 'embed', sprintf( 'lang=%s embedded=%d', $lang, $done ) );
		}
		return $done;
	}

	/**
	 * The text an item is embedded from (title + a slice of description).
	 *
	 * @param object $item Item row.
	 */
	private static function text_for( object $item ): string {
		$title = trim( (string) ( $item->title ?? '' ) );
		$desc  = trim( (string) ( $item->description ?? '' ) );
		$text  = '' !== $desc ? $title . '. ' . $desc : $title;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );
	}
}
