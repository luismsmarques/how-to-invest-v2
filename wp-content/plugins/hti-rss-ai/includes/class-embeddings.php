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
	 * Whether embeddings are enabled and a key is available.
	 */
	public static function enabled(): bool {
		return ! empty( Settings::get( 'enable_embeddings', 0 ) ) && Gemini_Client::available();
	}

	/**
	 * Compute + store embeddings for up to $cap ungrouped items of a language
	 * that still lack one. Best-effort; stops on the first API error (grouping
	 * will just use the lexical signal for the rest).
	 *
	 * @param string $lang Language code.
	 * @param int    $cap  Max items to embed this run.
	 * @return int Items embedded.
	 */
	public static function backfill( string $lang, int $cap ): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$items = Items::needing_embeddings( $lang, max( 1, $cap ) );
		if ( ! $items ) {
			return 0;
		}

		$done = 0;
		foreach ( array_chunk( $items, self::BATCH ) as $chunk ) {
			$ids   = array();
			$texts = array();
			foreach ( $chunk as $item ) {
				$ids[]   = (int) $item->id;
				$texts[] = self::text_for( $item );
			}
			$vectors = Gemini_Client::embed( $texts );
			if ( is_wp_error( $vectors ) ) {
				Logger::log( 'embed', 'error: ' . $vectors->get_error_message() );
				break;
			}
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
