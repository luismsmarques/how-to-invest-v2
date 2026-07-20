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
		if ( ! post_type_exists( Settings::post_type() ) ) {
			return new \WP_Error( 'rssai_no_type', __( 'The configured target post type does not exist — pick one in Settings.', 'hti-rss-ai' ) );
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
		$lang = Settings::valid_lang( (string) $group->lang );

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

		$valid = Validator::validate( $data, $lang );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post_id = self::create_news( $group, $data, $lang );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Featured image: best-effort, never blocks the article.
		Featured_Image::maybe_generate( $post_id, $data, $group, $lang );

		Groups::set_status( $group_id, 'generated' );
		Items::update_status( array_map( static fn( $item ) => (int) $item->id, $items ), 'used' );
		self::bump_daily();

		return $post_id;
	}

	/**
	 * Generate an article of a chosen format from a SINGLE draft item (any
	 * item, not only a group). Video items keep their transcript-based path;
	 * every other item is written from its headline + summary, grounded with
	 * web search so real sources are gathered and cited.
	 *
	 * @param int    $item_id Item id.
	 * @param string $type    news|quote|tutorial|summary.
	 * @return int|\WP_Error New post id, or error.
	 */
	public static function generate_from_item( int $item_id, string $type ) {
		$type = array_key_exists( $type, Prompt::content_types() ) ? $type : 'news';

		if ( ! post_type_exists( Settings::post_type() ) ) {
			return new \WP_Error( 'rssai_no_type', __( 'The configured target post type does not exist — pick one in Settings.', 'hti-rss-ai' ) );
		}
		if ( ! Gemini_Client::available() ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}
		if ( self::over_daily_limit() ) {
			return new \WP_Error( 'rssai_limit', __( 'Daily generation limit reached.', 'hti-rss-ai' ) );
		}

		$item = Items::get( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'rssai_no_item', __( 'Item not found.', 'hti-rss-ai' ) );
		}

		// A YouTube video is best written from its transcript.
		if ( ! empty( $item->video_id ) ) {
			return YouTube_Generator::generate( $item_id, $type );
		}

		$lang = Settings::valid_lang( (string) $item->lang );

		$result = Gemini_Client::generate_grounded( Prompt::item_system( $lang, $type ), Prompt::item_user( $item, $type ) );
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

		// Always attribute the original item (and guarantee a non-empty list).
		$existing = isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array();
		if ( '' !== (string) $item->link ) {
			array_unshift(
				$existing,
				array(
					'title' => trim( ( (string) $item->source ) . ' — ' . (string) $item->title ),
					'url'   => (string) $item->link,
				)
			);
		}
		$data['sources'] = $existing;

		$valid = Validator::validate( $data, $lang, (string) $item->description );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post_id = self::create_news_from_item( $item, $data, $lang, $type );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Featured image: best-effort (AI illustration), never blocks the article.
		Featured_Image::maybe_generate( $post_id, $data, null, $lang );

		Items::update( $item_id, array( 'status' => 'used' ) );
		self::bump_daily();

		return $post_id;
	}

	/**
	 * Create the pending `news` post from a single item + generated data.
	 *
	 * @param object              $item Item row.
	 * @param array<string,mixed> $data Validated article.
	 * @param string              $lang Language slug.
	 * @param string              $type Content type.
	 * @return int|\WP_Error
	 */
	private static function create_news_from_item( object $item, array $data, string $lang, string $type ) {
		$content  = self::blocks_to_html( (array) $data['body_blocks'] );
		$content .= self::sources_html( (array) ( $data['sources'] ?? array() ), $lang );
		$content .= self::disclaimer_html( $lang );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Settings::post_type(),
					'post_status'  => Settings::post_status(),
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

		update_post_meta( $post_id, 'rssai_source_kind', 'rss' );
		update_post_meta( $post_id, 'rssai_content_type', $type );
		update_post_meta( $post_id, 'rssai_source_url', (string) $item->link );
		update_post_meta( $post_id, 'rssai_lang', $lang );
		update_post_meta( $post_id, 'rssai_sources', array_values( (array) ( $data['sources'] ?? array() ) ) );
		update_post_meta( $post_id, 'rssai_model', (string) Settings::get( 'gemini_model', '' ) );
		update_post_meta( $post_id, 'rssai_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_rssai_meta_description', sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) ) );

		$taxonomy = Settings::taxonomy();
		if ( ! empty( $data['suggested_category'] ) && '' !== $taxonomy ) {
			$term = get_term_by( 'name', sanitize_text_field( (string) $data['suggested_category'] ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
			}
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}

		Logger::log( 'generate', sprintf( 'Item %s → post %d: %s', $type, $post_id, (string) $data['headline'] ) );
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
					'post_type'    => Settings::post_type(),
					'post_status'  => Settings::post_status(),
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
		update_post_meta( $post_id, 'rssai_lang', $lang );
		update_post_meta( $post_id, 'rssai_sources', array_values( (array) ( $data['sources'] ?? array() ) ) );
		update_post_meta( $post_id, 'rssai_model', (string) Settings::get( 'gemini_model', '' ) );
		update_post_meta( $post_id, 'rssai_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_rssai_meta_description', sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) ) );

		$taxonomy = Settings::taxonomy();
		if ( ! empty( $data['suggested_category'] ) && '' !== $taxonomy ) {
			$term = get_term_by( 'name', sanitize_text_field( (string) $data['suggested_category'] ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
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
	public static function blocks_to_html( array $blocks ): string {
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
	public static function sources_html( array $sources, string $lang ): string {
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
	public static function disclaimer_html( string $lang ): string {
		$raw = (string) Settings::get( 'disclaimer', '' );
		if ( '' !== trim( $raw ) ) {
			$text = trim( $raw ); // Custom disclaimer for any site.
		} elseif ( '' !== $raw ) {
			return ''; // Whitespace-only = explicitly no disclaimer.
		} else {
			// Built-in educational default (this site / finance).
			$text = 'pt' === $lang
				? 'Conteúdo educativo e informativo. Não constitui aconselhamento financeiro, de investimento, fiscal ou jurídico, nem recomendação de compra ou venda de qualquer ativo.'
				: 'Educational and informational content. Not financial, investment, tax or legal advice, nor a recommendation to buy or sell any asset.';
		}
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><em>' . esc_html( $text ) . '</em></p><!-- /wp:paragraph -->' . "\n";
	}

	/**
	 * Whether today's generation limit is reached.
	 */
	public static function over_daily_limit(): bool {
		$max = (int) Settings::get( 'max_generations_day', 10 );
		return (int) get_option( 'rssai_gen_' . gmdate( 'Ymd' ), 0 ) >= $max;
	}

	/**
	 * Increment today's generation counter.
	 */
	public static function bump_daily(): void {
		$key = 'rssai_gen_' . gmdate( 'Ymd' );
		update_option( $key, (int) get_option( $key, 0 ) + 1, false );
	}
}
