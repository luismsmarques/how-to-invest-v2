<?php
/**
 * Template registry metadata shared by PHP (which templates to surface where)
 * and JS (which holds the actual HTML in assets/js/templates.js). Keeping the
 * category map here lets the meta box and the admin page agree on grouping.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical template categories + post-type mapping.
 */
class Templates {

	/**
	 * Template categories → human label (bilingual handled in JS for UI).
	 *
	 * @return array<string,string>
	 */
	public static function categories(): array {
		return array(
			'news'      => 'News',
			'glossary'  => 'Glossary',
			'fact'      => 'Fun fact',
			'cta'       => 'Quiz CTA',
			'og'        => 'og:image',
			'editorial' => 'Editorial',
			'carousel'  => 'Carousel',
		);
	}

	/**
	 * Which template categories make sense to pre-fill for a given post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int,string>
	 */
	public static function categories_for_post_type( string $post_type ): array {
		switch ( $post_type ) {
			case 'news':
				/*
				 * A news item supports far more than a news card: the concept
				 * behind it, a myth it contradicts, a question it raises, a
				 * quiz prompt. Those families were already built but unreachable
				 * from a post, which is most of why the generator went unused.
				 */
				return array( 'news', 'og', 'editorial', 'glossary', 'fact', 'cta', 'carousel' );
			case 'glossary':
				return array( 'glossary', 'fact', 'cta' );
			default:
				return array();
		}
	}

	/**
	 * Post types that get the social meta box.
	 *
	 * @return array<int,string>
	 */
	public static function metabox_post_types(): array {
		return array( 'news', 'glossary' );
	}
}
