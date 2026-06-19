<?php
/**
 * Social-media kit: on-demand, downloadable cards for a published `news` post.
 *
 * Renders the branded templates (square 1080×1080 + story 1080×1920) with the
 * post's AI featured image placed inside, plus the headline/date/disclaimer.
 * Reuses the already-generated featured image — no extra AI calls. Meant to be
 * used after publishing, to share on Facebook / Instagram feed & stories.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Editor meta box + the download handler.
 */
class Social_Kit {

	private const ACTION  = 'rssai_social_card';
	private const FORMATS = array( 'square', 'story' );

	/**
	 * Hook the meta box and the download handler.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_news', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Register the meta box on the news editor.
	 */
	public static function meta_box(): void {
		add_meta_box(
			'rssai_social_kit',
			__( 'Social media kit', 'hti-rss-ai' ),
			array( __CLASS__, 'render_meta_box' ),
			'news',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box: one download button per format.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		if ( ! Social_Card::available() ) {
			echo '<p class="description">' . esc_html__( 'GD with TrueType support is required to render social cards.', 'hti-rss-ai' ) . '</p>';
			return;
		}
		if ( ! has_post_thumbnail( $post->ID ) ) {
			echo '<p class="description">' . esc_html__( 'Set or generate the featured image first — it is placed inside the social cards.', 'hti-rss-ai' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Download branded cards with the featured image inside, for Facebook & Instagram.', 'hti-rss-ai' ) . '</p>';
		foreach ( array(
			'square' => __( 'Square 1080×1080 (feed)', 'hti-rss-ai' ),
			'story'  => __( 'Story 1080×1920 (stories)', 'hti-rss-ai' ),
		) as $format => $label ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION . '&format=' . $format . '&post=' . $post->ID ),
				self::ACTION . '_' . $post->ID
			);
			printf(
				'<p><a href="%1$s" class="button button-secondary" style="width:100%%;text-align:center;">%2$s</a></p>',
				esc_url( $url ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Render the chosen card and stream it as a download.
	 */
	public static function handle_download(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$format  = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( self::ACTION . '_' . $post_id );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			wp_die( esc_html__( 'Unknown format.', 'hti-rss-ai' ) );
		}

		$photo = self::featured_bytes( $post_id );
		if ( '' === $photo ) {
			wp_die( esc_html__( 'No featured image to place inside the card.', 'hti-rss-ai' ) );
		}

		$png = 'story' === $format
			? Social_Card::render_story( self::card_data( $post_id, $photo, true ) )
			: Social_Card::render_square( self::card_data( $post_id, $photo, false ) );

		if ( is_wp_error( $png ) ) {
			wp_die( esc_html( $png->get_error_message() ) );
		}

		$filename = sanitize_title( get_the_title( $post_id ) ) . '-' . $format . '.png';
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $png ) );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image.
		exit;
	}

	/**
	 * Build the template data for a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $photo   Featured-image bytes.
	 * @param bool   $story   Whether this is the story layout.
	 * @return array<string,mixed>
	 */
	private static function card_data( int $post_id, string $photo, bool $story ): array {
		$lang = (string) get_post_meta( $post_id, 'rssai_lang', true );
		$lang = in_array( $lang, array( 'en', 'pt' ), true ) ? $lang : 'en';
		$date = get_post_time( 'j M', false, $post_id, true );
		$date = $date ? $date : wp_date( 'j M' );

		$kicker = $story
			? ( 'pt' === $lang ? 'Atualização' : 'Update' ) . ' · ' . $date
			: ( 'pt' === $lang ? 'Atualização de mercado' : 'Market update' ) . ' · ' . $date;

		return array(
			'headline'   => get_the_title( $post_id ),
			'kicker'     => $kicker,
			'badge'      => 'pt' === $lang ? 'Notícias' : 'News',
			'dek'        => self::dek( $post_id ),
			'handle'     => 'howtoinvest',
			'domain'     => 'howtoinvest.pt',
			'disclaimer' => self::disclaimer( $lang ),
			'lang'       => $lang,
			'photo'      => $photo,
		);
	}

	/**
	 * Featured-image file bytes (the AI photo).
	 *
	 * @param int $post_id Post id.
	 */
	private static function featured_bytes( int $post_id ): string {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return '';
		}
		$file = get_attached_file( $thumb_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/**
	 * Short subtitle for the story (excerpt, trimmed).
	 *
	 * @param int $post_id Post id.
	 */
	private static function dek( int $post_id ): string {
		$excerpt = get_post_field( 'post_excerpt', $post_id );
		$excerpt = '' !== trim( (string) $excerpt ) ? (string) $excerpt : (string) get_post_meta( $post_id, '_rssai_meta_description', true );
		return wp_trim_words( wp_strip_all_tags( $excerpt ), 22 );
	}

	/**
	 * Short card disclaimer.
	 *
	 * @param string $lang Language.
	 */
	private static function disclaimer( string $lang ): string {
		return 'pt' === $lang
			? 'Conteúdo educativo sobre literacia financeira. Não é aconselhamento financeiro, de investimento, fiscal ou jurídico. Investir envolve risco, incluindo a perda de capital. Exemplos ilustrativos e apenas por classe de ativos.'
			: 'Educational financial-literacy content. Not financial, investment, tax or legal advice. Investing involves risk, including loss of capital. Illustrative examples, by asset class only.';
	}
}
