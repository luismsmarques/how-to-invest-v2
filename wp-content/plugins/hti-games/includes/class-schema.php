<?php
/**
 * JSON-LD for the /games/ pages, emitted on wp_head at priority 20 — the same
 * slot HTI\Engine\SEO and HTI\Forex\Schema use, so the three graphs sit next
 * to each other rather than fighting over one.
 *
 * The emitter is gated on the `hti_games_page` post meta the seeder writes,
 * with a shortcode sniff as a fallback so a hand-made page still gets its
 * schema. Without that gate a wp_head hook would fire on every page on the
 * site and describe an unrelated post as a game.
 *
 * Each game page gets TWO nodes, on purpose. `Game` says what the thing is —
 * a free, browser-based educational game, published by us. `WebApplication`
 * says what actually renders when the page loads — an application, in the
 * GameApplication category, that costs nothing. Search engines read the two
 * for different questions and neither one alone answers both; hti-forex
 * already ships the WebApplication half for its calculators, so this is the
 * same pattern with the half that a game also needs.
 *
 * What is deliberately absent: AggregateRating and Review. No ratings exist,
 * and inventing them to earn stars in a result page is a manual action under
 * Google's spam policies — the kind that costs a whole domain, not one page.
 * tests/test-schema.php asserts neither type can appear in the graph.
 *
 * The FAQPage is built from Seeder::faqs(), which is the same array the page
 * copy is rendered from, so the visible answer and the structured one cannot
 * drift apart. Non-indexable pages (the player profile) emit nothing at all:
 * structured data for a page carrying `noindex` is work nobody reads. Nor does
 * a game whose kill-switch is off — see should_emit().
 *
 * detect_page() is also what Seeder::robots() decides the noindex from, so the
 * page a crawler is told about and the page the JSON-LD describes are settled
 * by one function rather than by two that agree until one of them is edited.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Structured-data emitter for the games section.
 */
class Schema {

	/**
	 * Post meta the seeder writes, holding the Config::pages() key. The gate.
	 */
	public const PAGE_META = 'hti_games_page';

	/**
	 * Hook the emitter.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'emit' ), 20 );
	}

	/**
	 * Which games page the current view is, or '' when it is none.
	 *
	 * Prefers the seeder's meta; falls back to sniffing the shortcode so a
	 * page an editor rebuilt by hand still describes itself correctly. Same
	 * approach as HTI\Forex\Schema::detect_page().
	 *
	 * @param \WP_Post $post Current page.
	 */
	public static function detect_page( \WP_Post $post ): string {
		$meta  = (string) get_post_meta( $post->ID, self::PAGE_META, true );
		$pages = Config::pages();
		if ( '' !== $meta && isset( $pages[ $meta ] ) ) {
			return $meta;
		}

		$content = (string) $post->post_content;
		if ( preg_match( '/\[hti_game\s+name="([a-z_]+)"/', $content, $m ) && isset( $pages[ $m[1] ] ) ) {
			return $m[1];
		}
		foreach ( array(
			'hub'         => '[hti_games_hub]',
			'leaderboard' => '[hti_games_leaderboard]',
			'profile'     => '[hti_games_profile]',
		) as $key => $shortcode ) {
			if ( str_contains( $content, $shortcode ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Whether a page key gets structured data at all. Pure.
	 *
	 * Only the indexable pages do. Describing the player profile in JSON-LD
	 * would be markup for a page that carries `noindex, follow` — nobody
	 * reads it, and a Game node on a per-visitor page is a small lie about
	 * what is there.
	 *
	 * A game whose kill-switch is off is the same lie in the other direction.
	 * The page survives the switch on purpose — the editorial half is what
	 * ranks, and it stays — but the shortcode renders "not available" where
	 * the game was, and a `Game` node saying "free, playable, in a browser"
	 * over that is a rich result promising something the visitor cannot do.
	 * The flag is passed in rather than read here so this stays pure.
	 *
	 * @param string $key      Page key from detect_page().
	 * @param bool   $playable Whether that game is switched on; ignored on
	 *                         pages that carry no game.
	 */
	public static function should_emit( string $key, bool $playable = true ): bool {
		$pages = Config::pages();
		if ( '' === $key || ! isset( $pages[ $key ] ) || empty( $pages[ $key ]['index'] ) ) {
			return false;
		}

		return ! Config::is_game( $key ) || $playable;
	}

	/**
	 * Emit the JSON-LD graph on games pages.
	 */
	public static function emit(): void {
		if ( ! is_page() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$key      = self::detect_page( $post );
		$settings = Settings::settings();
		if ( ! self::should_emit( $key, Settings::game_enabled( $key, $settings ) ) ) {
			return;
		}

		$lang = self::lang_of( (int) $post->ID );

		// The hub's hasPart points at the Game nodes by @id, so it may only
		// point at the ones that will actually be emitted: a reference to a
		// node no page carries is a dangling @id, and it would reappear the
		// moment a game is switched back on.
		$parts = array();
		foreach ( array( Config::GAME_STC, Config::GAME_REVEAL ) as $part_game ) {
			if ( Settings::game_enabled( $part_game, $settings ) ) {
				$parts[] = home_url( Seeder::url( $part_game, $lang ) );
			}
		}

		$graph = self::graph(
			array(
				'page'        => $key,
				'url'         => (string) get_permalink( $post ),
				'title'       => wp_strip_all_tags( (string) get_the_title( $post ) ),
				'description' => Seeder::c( $key . '_seo_desc', $lang ),
				'lang'        => 'pt' === $lang ? 'pt-PT' : 'en-US',
				'faqs'        => Seeder::faqs( $key, $lang, Seeder::stc_is_real() ),
				'home_url'    => home_url( '/' ),
				'hub_url'     => home_url( Seeder::url( 'hub', $lang ) ),
				'hub_title'   => Seeder::c( 'hub_title', $lang ),
				'parts'       => $parts,
				'home_title'  => 'pt' === $lang ? 'Início' : 'Home',
				'org_id'      => self::org_id(),
			)
		);

		if ( empty( $graph ) ) {
			return;
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
			. '</script>' . "\n";
	}

	/**
	 * Pure graph builder — every assertion in tests/test-schema.php runs
	 * against this, with no WordPress and no database.
	 *
	 * @param array{page:string,url:string,title:string,description:string,lang:string,faqs:array<int,array{q:string,a:string}>,home_url:string,hub_url:string,hub_title:string,home_title:string,org_id:string,parts?:array<int,string>} $ctx Context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function graph( array $ctx ): array {
		$graph   = array();
		$page    = (string) ( $ctx['page'] ?? '' );
		$url     = (string) ( $ctx['url'] ?? '' );
		$is_game = Config::is_game( $page );

		if ( $is_game ) {
			// What the thing IS: a free educational game, published by us.
			$graph[] = array(
				'@type'               => 'Game',
				'@id'                 => $url . '#game',
				'name'                => (string) $ctx['title'],
				'url'                 => $url,
				'description'         => (string) ( $ctx['description'] ?? '' ),
				'genre'               => 'educational',
				'gamePlatform'        => 'Web browser',
				'isAccessibleForFree' => true,
				'inLanguage'          => (string) $ctx['lang'],
				'numberOfPlayers'     => array(
					'@type' => 'QuantitativeValue',
					'value' => 1,
				),
				'isPartOf'            => array( '@id' => (string) $ctx['hub_url'] . '#collection' ),
				'publisher'           => array( '@id' => (string) $ctx['org_id'] ),
			);

			// What actually RENDERS: a browser application, free, in the
			// GameApplication category. price is a string because Google's
			// Offer parser has always been happier with "0" than with 0.
			$graph[] = array(
				'@type'               => 'WebApplication',
				'@id'                 => $url . '#app',
				'name'                => (string) $ctx['title'],
				'url'                 => $url,
				'applicationCategory' => 'GameApplication',
				'operatingSystem'     => 'Web browser',
				'browserRequirements' => 'Requires JavaScript',
				'isAccessibleForFree' => true,
				'inLanguage'          => (string) $ctx['lang'],
				'offers'              => array(
					'@type'         => 'Offer',
					'price'         => '0',
					'priceCurrency' => 'EUR',
				),
				'publisher'           => array( '@id' => (string) $ctx['org_id'] ),
			);
		} else {
			// The hub and the leaderboard are collections of the games, not
			// games themselves: describing either as a Game would claim
			// something playable is on the page when nothing is.
			$node = array(
				'@type'       => 'CollectionPage',
				'@id'         => $url . '#collection',
				'name'        => (string) $ctx['title'],
				'url'         => $url,
				'description' => (string) ( $ctx['description'] ?? '' ),
				'inLanguage'  => (string) $ctx['lang'],
				'isPartOf'    => array( '@id' => (string) $ctx['home_url'] . '#website' ),
				'publisher'   => array( '@id' => (string) $ctx['org_id'] ),
			);
			if ( 'hub' === $page && ! empty( $ctx['parts'] ) ) {
				// The hub points at the two Game nodes by @id rather than
				// re-describing them: one description of a game, in one place.
				$node['hasPart'] = array();
				foreach ( (array) $ctx['parts'] as $part ) {
					$node['hasPart'][] = array( '@id' => (string) $part . '#game' );
				}
			}
			$graph[] = $node;
		}

		if ( ! empty( $ctx['faqs'] ) ) {
			$questions = array();
			foreach ( $ctx['faqs'] as $faq ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => (string) $faq['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => (string) $faq['a'],
					),
				);
			}
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#faq',
				'inLanguage' => (string) $ctx['lang'],
				'mainEntity' => $questions,
			);
		}

		$crumbs = array( array( (string) $ctx['home_title'], (string) $ctx['home_url'] ) );
		if ( 'hub' !== $page ) {
			$crumbs[] = array( (string) $ctx['hub_title'], (string) $ctx['hub_url'] );
		}
		$crumbs[] = array( (string) $ctx['title'], $url );

		$items = array();
		foreach ( $crumbs as $i => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb[0],
				'item'     => $crumb[1],
			);
		}
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumbs',
			'itemListElement' => $items,
		);

		return $graph;
	}

	/**
	 * The @id of the publishing Organization.
	 *
	 * Asks hti-engine's SEO class when it is present so the reference lands on
	 * the node that actually exists in the page; falls back to computing the
	 * same value, so a graph emitted here is never left pointing at nothing.
	 */
	private static function org_id(): string {
		if ( class_exists( '\\HTI\\Engine\\SEO' ) && method_exists( '\\HTI\\Engine\\SEO', 'org_id' ) ) {
			return (string) \HTI\Engine\SEO::org_id();
		}
		return home_url( '/' ) . '#organization';
	}

	/**
	 * The language of one post, as this plugin's 'en'/'pt'.
	 *
	 * Polylang first (a PT page can exist on an EN-default site), the site
	 * locale second.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function lang_of( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$slug = (string) pll_get_post_language( $post_id, 'locale' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}
}
