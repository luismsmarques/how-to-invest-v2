<?php
/**
 * Localized permalinks for our own pages.
 *
 * One resolver for "give me the URL of the page whose English slug is X, in the
 * language the visitor is reading". Everywhere this was written by hand it was
 * written wrong: the term-deposit comparator was linked from three places with
 * three different URLs, none of which existed, because each call site spelled
 * the slug out and guessed at the /pt/ prefix instead of asking Polylang.
 *
 * Mirrors the theme's page_url() without depending on it — the plugin has no
 * dependency on the theme and must keep none.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Internal link resolution.
 */
class Links {

	/**
	 * Permalink of a page identified by its English slug, in the current
	 * language.
	 *
	 * @param string $en_slug English page slug, or a hierarchical path
	 *                        ("tools/compound-interest-calculator").
	 * @param string $fallback Path to return when the page does not exist.
	 *                         Defaults to "/{$en_slug}/", which is where the
	 *                         page would live once it is seeded.
	 * @return string Absolute URL.
	 */
	public static function page_url( string $en_slug, string $fallback = '' ): string {
		$page = get_page_by_path( $en_slug, OBJECT, 'page' );

		if ( $page instanceof \WP_Post ) {
			$id = (int) $page->ID;

			if ( function_exists( 'pll_get_post' ) ) {
				$translated = pll_get_post( $id, self::lang() );
				if ( $translated ) {
					$id = (int) $translated;
				}
			}

			$url = get_permalink( $id );
			if ( $url ) {
				return (string) $url;
			}
		}

		return home_url( '' !== $fallback ? $fallback : '/' . $en_slug . '/' );
	}

	/**
	 * Whether the page exists at all, in any language.
	 *
	 * Lets a caller hide a navigation item rather than point it at a 404 —
	 * which is what the deposits menu item did for as long as its URL was
	 * hardcoded.
	 *
	 * @param string $en_slug English page slug or path.
	 */
	public static function page_exists( string $en_slug ): bool {
		return get_page_by_path( $en_slug, OBJECT, 'page' ) instanceof \WP_Post;
	}

	/**
	 * Current language as a Polylang slug ('pt' or 'en').
	 */
	private static function lang(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = (string) pll_current_language( 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}
}
