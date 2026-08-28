<?php
/**
 * Legacy URL redirects (Base44 → WordPress).
 *
 * Maps the old Base44 CamelCase paths to their new canonical WordPress URLs
 * with a permanent (301) redirect, preserving SEO equity during migration.
 *
 * Three classes of legacy URL are handled:
 *
 * 1. **Flat page paths** — `/About`, `/HowToStart`, `/EducationalResources`…
 *    resolved through {@see Redirects::map()}, which is filterable via
 *    `hti_legacy_redirects` so slugs can be adjusted without a code deploy.
 * 2. **News articles** — Base44 served every article from a single path with
 *    the article in a query string (`/FinancialNews?slug=…`). Dropping the
 *    query string would collapse every article onto the archive, which reads
 *    as a soft-404 to search engines and burns the ranking each article
 *    already earned, so the slug is resolved back to the real `news` post.
 * 3. **Dead language prefixes** — Base44 auto-translated the site into
 *    languages the project no longer maintains (`/es/…`, `/fr/…`). Those
 *    paths are folded onto the default-language equivalent. Languages that
 *    Polylang actually has configured are never treated as dead, so enabling
 *    a language in Polylang is enough to take it out of this net.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Performs 301 redirects from legacy Base44 paths.
 */
class Redirects {

	/**
	 * Where a news request lands when the article cannot be resolved.
	 */
	private const NEWS_ARCHIVE = '/financial-news/';

	/**
	 * Legacy paths that carried an article slug in the query string.
	 *
	 * @var list<string>
	 */
	private const NEWS_PATHS = array( 'financialnews', 'financialnewsarticle' );

	/**
	 * Hook into the request lifecycle.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Old path (lowercase, no slashes) => new path (relative to home, leading slash).
	 *
	 * @return array<string,string>
	 */
	private static function map(): array {
		$map = array(
			// Base44 CamelCase pages.
			'about'                => '/about/',
			'contact'              => '/contact/',
			'educationalresources' => '/learn/',
			'educationmodule'      => '/learn/',
			'financialnews'        => self::NEWS_ARCHIVE,
			'financialnewsarticle' => self::NEWS_ARCHIVE,
			'home'                 => '/',
			'howtostart'           => '/how-to-start-investing/',
			'localizedpage'        => '/',
			'privacypolicy'        => '/privacy-policy/',
			'profilebuilder'       => '/investor-profile-quiz/',
			'profilesettings'      => '/my-account/',
			'questionnaire'        => '/investor-profile-quiz/',
			'results'              => '/investor-profile-quiz/',
			'sitemap'              => '/',
			'termsandconditions'   => '/terms-and-conditions/',

			/*
			 * Duplicate slug competing with the canonical chapter: Search
			 * Console showed /HowToStart, /how-to-start and
			 * /how-to-start-investing/ splitting impressions three ways.
			 */
			'how-to-start'         => '/how-to-start-investing/',

			/*
			 * The term-deposit comparator's real slugs are
			 * comparador-de-depositos-a-prazo and, for the methodology,
			 * metodologia-do-comparador-de-depositos under /pt/. Three call
			 * sites linked it by hand and each got it wrong, so these URLs were
			 * served to crawlers from our own navigation for as long as the
			 * literals were there. The links are fixed at the source; these
			 * entries turn the 404s already in the index into 301s.
			 */
			'comparador-de-depositos'                => '/pt/comparador-de-depositos-a-prazo/',
			'pt/comparador-de-depositos'             => '/pt/comparador-de-depositos-a-prazo/',
			'metodologia-do-comparador-de-depositos' => '/pt/metodologia-do-comparador-de-depositos/',
		);

		$map = array_merge( $map, self::tool_moves() );

		/**
		 * Filter the legacy redirect map.
		 *
		 * @param array<string,string> $map Old path (lowercase, no slashes) => new relative path.
		 */
		return (array) apply_filters( 'hti_legacy_redirects', $map );
	}

	/**
	 * The calculators' old flat URLs => their new place under the Tools hub.
	 *
	 * Not a Base44 legacy path, but the same problem and the same fix: eight
	 * indexed EN URLs plus their eight PT twins moved from /{slug}/ to
	 * /tools/{slug}/, and dropping them would burn the ranking each already
	 * earned. Built from Tools_Content so a ninth calculator cannot ship
	 * without its redirect (the test suite asserts the two lists match).
	 *
	 * The PT keys carry their own "pt/" prefix because resolve() only strips
	 * language prefixes the site does NOT serve — Portuguese is live, so the
	 * prefix reaches the map intact.
	 *
	 * @return array<string,string>
	 */
	private static function tool_moves(): array {
		$moves = array();
		foreach ( Tools_Content::tools() as $slug => $tool ) {
			$moves[ $slug ] = '/' . Tools_Content::path( $slug ) . '/';

			$pt_slug = (string) $tool['pt_slug'];
			$moves[ 'pt/' . $pt_slug ] = '/pt/' . Tools_Content::HUB_SLUG_PT . '/' . $pt_slug . '/';
		}
		return $moves;
	}

	/**
	 * Language prefixes left behind by Base44 that the project no longer serves.
	 *
	 * Any language Polylang has configured is removed from the list, so a live
	 * language is never folded away by mistake.
	 *
	 * @return list<string>
	 */
	private static function dead_languages(): array {
		$dead = array(
			'ar',
			'bg',
			'cs',
			'da',
			'de',
			'el',
			'es',
			'fa',
			'fi',
			'fr',
			'he',
			'hi',
			'hu',
			'id',
			'it',
			'ja',
			'ko',
			'nl',
			'no',
			'pl',
			'ro',
			'ru',
			'sv',
			'th',
			'tr',
			'uk',
			'vi',
			'zh',
		);

		/**
		 * Filter the language prefixes treated as dead Base44 translations.
		 *
		 * @param list<string> $dead Two-letter language prefixes.
		 */
		$dead = array_map( 'strval', (array) apply_filters( 'hti_dead_language_prefixes', $dead ) );

		// Never fold a language the site actually serves.
		$live = function_exists( 'pll_languages_list' )
			? array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) )
			: array( 'en', 'pt' );

		return array_values( array_diff( $dead, $live ) );
	}

	/**
	 * Resolve a request URI to a legacy redirect target, or null to leave it alone.
	 *
	 * Pure: it takes the raw request URI and optional resolvers, and returns a
	 * path relative to the site root. Keeping the lookups injectable is what
	 * makes the whole mapping testable without WordPress.
	 *
	 * @param string        $request_uri Raw request URI (path plus optional query).
	 * @param callable|null $news_lookup Receives a sanitized slug, returns a
	 *                                   relative path or null when unknown.
	 * @param callable|null $exists      Receives ( English page path, language )
	 *                                   and returns whether that page exists.
	 *                                   Only consulted for the page moves in
	 *                                   page_moves(); null skips the check.
	 * @return string|null Relative target path, or null when nothing matches.
	 */
	public static function resolve( string $request_uri, ?callable $news_lookup = null, ?callable $exists = null ): ?string {
		$path  = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		$key   = strtolower( trim( $path, '/' ) );

		if ( '' === $key ) {
			return null;
		}

		$segments = explode( '/', $key );
		$prefix   = $segments[0];

		if ( in_array( $prefix, self::dead_languages(), true ) ) {
			array_shift( $segments );
			$rest = implode( '/', $segments );

			// A bare /es or /fr goes home; anything under it tries the map first.
			return '' === $rest
				? '/'
				: ( self::match( $rest, $query, $news_lookup, $exists ) ?? '/' );
		}

		return self::match( $key, $query, $news_lookup, $exists );
	}

	/**
	 * Match a normalized path key against the news rule and then the map.
	 *
	 * @param string        $key         Lowercased path without surrounding slashes.
	 * @param string        $query       Raw query string.
	 * @param callable|null $news_lookup Slug resolver.
	 * @param callable|null $exists      Destination existence check.
	 * @return string|null Relative target path, or null when nothing matches.
	 */
	private static function match( string $key, string $query, ?callable $news_lookup, ?callable $exists = null ): ?string {
		if ( in_array( $key, self::NEWS_PATHS, true ) ) {
			$slug = self::slug_from_query( $query );

			if ( '' !== $slug && null !== $news_lookup ) {
				$target = $news_lookup( $slug );
				if ( is_string( $target ) && '' !== $target ) {
					return $target;
				}
			}

			return self::NEWS_ARCHIVE;
		}

		$map = self::map();
		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		/*
		 * A page move only redirects once the page has actually moved. The
		 * deploy is a plain file copy — it runs no WP-CLI and fires no
		 * activation hook — so the redirects go live the moment the code
		 * lands, while the pages are re-parented later, by hand. Without this
		 * check every calculator would 301 to a URL that does not exist yet.
		 * With it the old URL keeps serving the old page until the migration
		 * runs, and starts redirecting by itself afterwards.
		 */
		$moves = self::page_moves();
		if ( null !== $exists && isset( $moves[ $key ] ) ) {
			[ $en_path, $lang ] = $moves[ $key ];
			if ( ! $exists( $en_path, $lang ) ) {
				return null;
			}
		}

		return (string) $map[ $key ];
	}

	/**
	 * Redirect keys whose destination is a page we seed, and how to check it.
	 *
	 * Maps the old path to [ English page path, language ]. The check is always
	 * expressed against the English path plus a language, never against a
	 * Portuguese path: nothing in this codebase resolves a PT page by a PT
	 * path — every PT lookup goes through pll_get_post() from the English id —
	 * and a PT path silently assumes both that the PT hub exists and that no
	 * slug was renamed on re-parent, neither of which is guaranteed.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function page_moves(): array {
		$moves = array();

		foreach ( Tools_Content::tools() as $slug => $tool ) {
			$path                                = Tools_Content::path( $slug );
			$moves[ $slug ]                      = array( $path, 'en' );
			$moves[ 'pt/' . $tool['pt_slug'] ]   = array( $path, 'pt' );
		}

		$moves['comparador-de-depositos']                = array( 'term-deposit-comparison-portugal', 'pt' );
		$moves['pt/comparador-de-depositos']             = array( 'term-deposit-comparison-portugal', 'pt' );
		$moves['metodologia-do-comparador-de-depositos'] = array( 'deposit-comparison-methodology', 'pt' );

		return $moves;
	}

	/**
	 * Extract and sanitize the `slug` parameter from a legacy query string.
	 *
	 * @param string $query Raw query string.
	 * @return string Sanitized slug, or '' when absent or unusable.
	 */
	private static function slug_from_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$args = array();
		parse_str( $query, $args );

		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $slug ) );

		// Guard the LIKE below against a pathologically long parameter.
		return substr( trim( $slug, '-' ), 0, 200 );
	}

	/**
	 * Find the `news` post a legacy Base44 slug refers to.
	 *
	 * Tries an exact slug match first. Migration truncated some long slugs, so
	 * a prefix match is attempted in both directions before giving up — that is
	 * the difference between an article keeping its ranking and every legacy
	 * article collapsing onto the archive.
	 *
	 * @param string $slug Sanitized legacy slug.
	 * @return string|null Relative permalink, or null when no article matches.
	 */
	private static function lookup_news( string $slug ): ?string {
		$post = get_page_by_path( $slug, OBJECT, 'news' );

		if ( ! $post instanceof \WP_Post ) {
			global $wpdb;

			// No WP_Query equivalent for a prefix match on post_name.
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'news' AND post_status = 'publish' AND post_name LIKE %s
					 ORDER BY CHAR_LENGTH( post_name ) ASC LIMIT 1",
					$wpdb->esc_like( $slug ) . '%'
				)
			);

			if ( $id <= 0 ) {
				// The legacy slug may itself be the longer of the two.
				$id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_type = 'news' AND post_status = 'publish'
						   AND CHAR_LENGTH( post_name ) > 20 AND %s LIKE CONCAT( post_name, '%%' )
						 ORDER BY CHAR_LENGTH( post_name ) DESC LIMIT 1",
						$slug
					)
				);
			}

			if ( $id > 0 ) {
				$post = get_post( $id );
			}
		}

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}

		$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}

		/*
		 * home_url() re-adds the base path, so strip it here — otherwise a
		 * WordPress installed in a subdirectory would get it twice.
		 */
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		return $path;
	}

	/**
	 * Redirect the current request if it matches a legacy path.
	 */
	public static function maybe_redirect(): void {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and normalized in resolve().
		$target  = self::resolve(
			(string) $request,
			array( __CLASS__, 'lookup_news' ),
			array( Links::class, 'translation_exists' )
		);

		if ( null === $target ) {
			return;
		}

		// A resolved news permalink is already an absolute path on this host.
		$url  = home_url( $target );
		$path = (string) wp_parse_url( $request, PHP_URL_PATH );

		// Avoid redirecting a URL onto itself.
		if ( untrailingslashit( $url ) === untrailingslashit( home_url( $path ) ) ) {
			return;
		}

		wp_safe_redirect( $url, 301, 'HowToInvest' );
		exit;
	}
}
