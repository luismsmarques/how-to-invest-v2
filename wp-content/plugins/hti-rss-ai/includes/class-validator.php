<?php
/**
 * Validates the generated article JSON before it becomes a post.
 *
 * Pragmatic guard rails (the pending-review step is the real editorial gate):
 * require the essentials + sources, and reject obvious advice language and
 * ticker symbols.
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
	 * Validate the parsed article.
	 *
	 * @param array<string,mixed> $data Parsed JSON.
	 * @return true|\WP_Error
	 */
	public static function validate( array $data ) {
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

		$text = strtolower(
			(string) $data['headline'] . ' ' . implode(
				' ',
				array_map(
					static fn( $block ) => is_array( $block ) ? (string) ( $block['text'] ?? '' ) : '',
					$data['body_blocks']
				)
			)
		);

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

		if ( preg_match( '/\$[A-Z]{2,5}\b/', (string) $data['headline'] . ' ' . $text ) ) {
			return new \WP_Error( 'rssai_ticker', __( 'Generated article names a ticker symbol — rejected.', 'hti-rss-ai' ) );
		}

		return true;
	}
}
