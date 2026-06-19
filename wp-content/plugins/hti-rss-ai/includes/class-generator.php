<?php
/**
 * Orchestrates article generation for a group: grounded research → JSON →
 * validation → a `news` post in "pending review".
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a group into a pending news article.
 */
class Generator {

	/**
	 * Generate an article from a group.
	 *
	 * @param int $group_id Group id.
	 * @return int|\WP_Error New post id, or error.
	 */
	public static function generate( int $group_id ) {
		if ( ! post_type_exists( 'news' ) ) {
			return new \WP_Error( 'rssai_no_news', __( 'The “news” content type is missing (activate HTI Engine).', 'hti-rss-ai' ) );
		}
		if ( ! Gemini_Client::available() ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}
		if ( self::over_daily_limit() ) {
			return new \WP_Error( 'rssai_limit', __( 'Daily generation limit reached.', 'hti-rss-ai' ) );
		}

		$group = Groups::get( $group_id );
		if ( ! $group ) {
			return new \WP_Error( 'rssai_no_group', __( 'Group not found.', 'hti-rss-ai' ) );
		}
		$items = Groups::items( $group_id );
		if ( ! $items ) {
			return new \WP_Error( 'rssai_no_items', __( 'Group has no items.', 'hti-rss-ai' ) );
		}
		$lang = in_array( $group->lang, array( 'en', 'pt' ), true ) ? $group->lang : 'en';

		$result = Gemini_Client::generate_grounded( Prompt::system( $lang ), Prompt::user( $group, $items ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = Gemini_Client::extract_json( $result['text'] );
		if ( null === $data ) {
			return new \WP_Error( 'rssai_parse', __( 'Could not parse the model output as JSON.', 'hti-rss-ai' ) );
		}
		if ( empty( $data['sources'] ) && ! empty( $result['sources'] ) ) {
			$data['sources'] = $result['sources'];
		}

		$valid = Validator::validate( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post_id = self::create_news( $group, $data, $lang );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Groups::set_status( $group_id, 'generated' );
		Items::update_status( array_map( static fn( $item ) => (int) $item->id, $items ), 'used' );
		self::bump_daily();

		return $post_id;
	}

	/**
	 * Create the pending `news` post.
	 *
	 * @param object               $group Group row.
	 * @param array<string,mixed>  $data  Validated article.
	 * @param string               $lang  Language slug.
	 * @return int|\WP_Error
	 */
	private static function create_news( object $group, array $data, string $lang ) {
		$content  = self::blocks_to_html( (array) $data['body_blocks'] );
		$content .= self::sources_html( (array) ( $data['sources'] ?? array() ), $lang );
		$content .= self::disclaimer_html( $lang );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'news',
					'post_status'  => 'pending',
					'post_title'   => sanitize_text_field( (string) $data['headline'] ),
					'post_name'    => sanitize_title( (string) ( $data['slug'] ?? $data['headline'] ) ),
					'post_content' => $content,
					'post_excerpt' => sanitize_text_field( (string) ( $data['dek'] ?? $data['meta_description'] ?? '' ) ),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, 'rssai_group_id', (int) $group->id );
		update_post_meta( $post_id, 'rssai_sources', array_values( (array) ( $data['sources'] ?? array() ) ) );
		update_post_meta( $post_id, 'rssai_model', (string) Settings::get( 'gemini_model', '' ) );
		update_post_meta( $post_id, 'rssai_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_rssai_meta_description', sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) ) );

		if ( ! empty( $data['suggested_category'] ) && taxonomy_exists( 'news_category' ) ) {
			$term = get_term_by( 'name', sanitize_text_field( (string) $data['suggested_category'] ), 'news_category' );
			if ( $term instanceof \WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'news_category' );
			}
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}

		return $post_id;
	}

	/**
	 * Convert body blocks to Gutenberg block markup.
	 *
	 * @param array<int,mixed> $blocks Body blocks.
	 */
	private static function blocks_to_html( array $blocks ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$text = trim( (string) ( $block['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( 'heading' === ( $block['type'] ?? 'paragraph' ) ) {
				$html .= '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->' . "\n\n";
			} else {
				$html .= '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' . "\n\n";
			}
		}
		return $html;
	}

	/**
	 * Sources list block (transparency / attribution).
	 *
	 * @param array<int,mixed> $sources Sources.
	 * @param string           $lang    Language.
	 */
	private static function sources_html( array $sources, string $lang ): string {
		$clean = array();
		foreach ( $sources as $source ) {
			if ( is_array( $source ) && ! empty( $source['url'] ) ) {
				$clean[] = $source;
			}
		}
		$clean = array_slice( $clean, 0, 12 );
		if ( ! $clean ) {
			return '';
		}
		$label = 'pt' === $lang ? 'Fontes' : 'Sources';
		$list  = '';
		foreach ( $clean as $source ) {
			$title = (string) ( $source['title'] ?? '' );
			$url   = (string) $source['url'];
			$list .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( '' !== $title ? $title : $url ) . '</a></li>';
		}
		return '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $label ) . '</h3><!-- /wp:heading -->' . "\n"
			. '<!-- wp:list --><ul class="wp-block-list">' . $list . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Educational disclaimer block.
	 *
	 * @param string $lang Language.
	 */
	private static function disclaimer_html( string $lang ): string {
		$text = 'pt' === $lang
			? 'Conteúdo educativo e informativo. Não constitui aconselhamento financeiro, de investimento, fiscal ou jurídico, nem recomendação de compra ou venda de qualquer ativo.'
			: 'Educational and informational content. Not financial, investment, tax or legal advice, nor a recommendation to buy or sell any asset.';
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><em>' . esc_html( $text ) . '</em></p><!-- /wp:paragraph -->' . "\n";
	}

	/**
	 * Whether today's generation limit is reached.
	 */
	private static function over_daily_limit(): bool {
		$max = (int) Settings::get( 'max_generations_day', 10 );
		return (int) get_option( 'rssai_gen_' . gmdate( 'Ymd' ), 0 ) >= $max;
	}

	/**
	 * Increment today's generation counter.
	 */
	private static function bump_daily(): void {
		$key = 'rssai_gen_' . gmdate( 'Ymd' );
		update_option( $key, (int) get_option( $key, 0 ) + 1, false );
	}
}
