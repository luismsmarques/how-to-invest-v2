<?php
/**
 * Validates the generated article JSON before it becomes a post.
 *
 * Pragmatic guard rails (the pending-review step is the real editorial gate):
 * require the essentials + sources, clamp the meta description, and reject
 * obvious advice language, ticker symbols, wrong-language output and
 * near-verbatim copies of the source item.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Schema + safety validation.
 */
class Validator {

	/**
	 * Validate (and lightly normalise) the parsed article.
	 *
	 * `$data` is taken by reference so the meta description can be clamped in
	 * place; callers use `$data` afterwards to build the post.
	 *
	 * @param array<string,mixed> $data   Parsed JSON (mutated: meta_description clamped).
	 * @param string              $lang   Expected language (en|pt); '' skips the language check.
	 * @param string              $source Source text (the item's description/transcript); '' skips the copy check.
	 * @return true|\WP_Error
	 */
	public static function validate( array &$data, string $lang = '', string $source = '' ) {
		foreach ( array( 'headline', 'meta_description', 'body_blocks' ) as $required ) {
			if ( empty( $data[ $required ] ) ) {
				/* translators: %s: field name. */
				return new \WP_Error( 'rssai_invalid', sprintf( __( 'Generated article is missing “%s”.', 'hti-rss-ai' ), $required ) );
			}
		}
		if ( ! is_array( $data['body_blocks'] ) ) {
			return new \WP_Error( 'rssai_invalid', __( 'body_blocks must be a list.', 'hti-rss-ai' ) );
		}
		if ( empty( $data['sources'] ) || ! is_array( $data['sources'] ) ) {
			return new \WP_Error( 'rssai_no_sources', __( 'Generated article has no sources — rejected.', 'hti-rss-ai' ) );
		}

		// Enforce the meta-description length the prompts ask for (<=155).
		$data['meta_description'] = self::clamp_meta( (string) $data['meta_description'] );

		$text = strtolower(
			(string) $data['headline'] . ' ' . implode(
				' ',
				array_map(
					static fn( $block ) => is_array( $block ) ? (string) ( $block['text'] ?? '' ) : '',
					$data['body_blocks']
				)
			)
		);

		if ( (int) Settings::get( 'guard_advice', 1 ) ) {
			$banned = array(
				'you should buy', 'you should sell', 'buy now', 'sell now', 'price target',
				'we recommend', 'should invest', 'deves comprar', 'deves vender', 'recomendamos',
				'preço-alvo', 'preco-alvo', 'compre já', 'compre ja', 'venda já', 'venda ja',
			);
			foreach ( $banned as $phrase ) {
				if ( false !== strpos( $text, $phrase ) ) {
					return new \WP_Error( 'rssai_advice', __( 'Generated article reads like investment advice — rejected.', 'hti-rss-ai' ) );
				}
			}
		}

		if ( (int) Settings::get( 'guard_tickers', 1 ) && preg_match( '/\$[A-Z]{2,5}\b/', (string) $data['headline'] . ' ' . $text ) ) {
			return new \WP_Error( 'rssai_ticker', __( 'Generated article names a ticker symbol — rejected.', 'hti-rss-ai' ) );
		}

		// The article must be written in the item's language.
		if ( '' !== $lang && self::wrong_language( $text, $lang ) ) {
			return new \WP_Error( 'rssai_lang', __( 'Generated article is not in the expected language — rejected.', 'hti-rss-ai' ) );
		}

		// Near-verbatim copy of the source item — thin/plagiarised.
		if ( '' !== $source && self::is_near_copy( $text, $source ) ) {
			return new \WP_Error( 'rssai_copy', __( 'Generated article copies the source too closely — rejected.', 'hti-rss-ai' ) );
		}

		return true;
	}

	/**
	 * Clamp a meta description to Google's ~155-char recommendation, on a word
	 * boundary, with an ellipsis when cut.
	 *
	 * @param string $s Raw meta description.
	 */
	public static function clamp_meta( string $s ): string {
		$s = trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		if ( mb_strlen( $s ) <= 155 ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, 154 );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 120 ) {
			$cut = mb_substr( $cut, 0, $sp );
		}
		return rtrim( $cut, " ,.;:—-" ) . '…';
	}

	/**
	 * Conservative language check: true only when the OTHER language clearly
	 * dominates (distinctive stop-words), so a correct or mixed article is never
	 * wrongly rejected. News has no fallback, so false positives must be rare.
	 *
	 * @param string $text Lower-cased article text.
	 * @param string $lang Expected language (en|pt).
	 */
	private static function wrong_language( string $text, string $lang ): bool {
		$lang = str_starts_with( strtolower( $lang ), 'pt' ) ? 'pt' : 'en';

		$pad = ' ' . trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\s]+/u', ' ', $text ) ) ) . ' ';

		$pt_words = array( 'não', 'uma', 'com', 'dos', 'das', 'também', 'são', 'está', 'isto', 'porque', 'você', 'pelo', 'como', 'mais' );
		$en_words = array( 'the', 'and', 'of', 'to', 'is', 'are', 'that', 'this', 'with', 'for', 'you', 'from', 'have', 'about' );

		$count = static function ( array $words ) use ( $pad ): int {
			$n = 0;
			foreach ( $words as $w ) {
				$n += substr_count( $pad, ' ' . $w . ' ' );
			}
			return $n;
		};

		$pt = $count( $pt_words );
		$en = $count( $en_words );

		if ( 'pt' === $lang ) {
			return $en >= 6 && $en > $pt * 2;
		}
		return $pt >= 6 && $pt > $en * 2;
	}

	/**
	 * True when a 12-word verbatim run from the source appears in the article —
	 * a strong signal the model copied rather than rewrote. Skips sources too
	 * short to judge.
	 *
	 * @param string $text   Lower-cased article text.
	 * @param string $source Source item text.
	 */
	private static function is_near_copy( string $text, string $source ): bool {
		$norm = static function ( string $s ): string {
			$s = strtolower( wp_strip_all_tags( $s ) );
			return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) ) );
		};

		$t  = $norm( $text );
		$sw = explode( ' ', $norm( $source ) );
		if ( count( $sw ) < 14 ) {
			return false;
		}

		$total = count( $sw );
		for ( $i = 0; $i + 12 <= $total; $i += 4 ) {
			$window = implode( ' ', array_slice( $sw, $i, 12 ) );
			if ( '' !== $window && false !== strpos( $t, $window ) ) {
				return true;
			}
		}
		return false;
	}
}
