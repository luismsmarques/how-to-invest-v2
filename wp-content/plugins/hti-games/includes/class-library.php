<?php
/**
 * Which challenge is today's, and which ones are allowed to be served at all.
 *
 * The rotation is computed on read from the day index (Day::index) and never
 * scheduled: WP-Cron is disabled in production, so a game that needed a cron
 * job to roll over would be a game that stops rolling over. Today's challenge
 * is therefore a pure function of the date and the pool, and two servers, or a
 * server and a test, always agree on it.
 *
 * The pool query itself is the second half of The Reveal's safety story. The
 * admin publish gate (Case_Admin) stops an unverified case from reaching
 * `publish`; this query refuses to serve one even if it somehow got there —
 * a direct database edit, an importer bug, a `wp_update_post()` from another
 * plugin. Two independent controls, because "a named real company with an
 * unsourced number attached" is the one output this section must never
 * produce (CLAUDE.md invariant 2).
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The servable pool, and the deterministic pick for a given day.
 */
class Library {

	/**
	 * Transient prefix for the cached pools. Twelve hours, so a pool survives
	 * a traffic spike, and every write to either post type busts it anyway.
	 */
	private const POOL_PREFIX = 'hti_games_pool_';

	/**
	 * Transient prefix for the cached "is the pool really real" answer.
	 */
	private const REAL_PREFIX = 'hti_games_real_';

	/**
	 * Cache lifetime.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Invalidate the cached pools whenever either post type changes.
	 *
	 * Three hooks rather than one because they catch genuinely different
	 * events: `save_post` an edit, `deleted_post` a removal that never passes
	 * through a status transition, and `transition_post_status` a scheduled
	 * post going live or an editor unpublishing one — the case that matters
	 * most here, since unpublishing is how a case gets pulled in a hurry.
	 */
	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Pure — no WordPress, unit-tested.
	 * ------------------------------------------------------------------- */

	/**
	 * The rotation: entry `$index` of a pool, wrapping.
	 *
	 * @param array<int,int> $pool  Post ids, in a stable order.
	 * @param int            $index Day index.
	 * @return int Post id, or 0 when there is nothing to serve.
	 */
	public static function pick( array $pool, int $index ): int {
		$count = count( $pool );
		if ( 0 === $count ) {
			return 0;
		}
		$pool = array_values( $pool );

		// Day indexes are always positive in practice; the extra modulo keeps
		// a negative one (a filtered day offset, a test) from indexing off the
		// front of the array instead of wrapping.
		$at = ( ( $index % $count ) + $count ) % $count;

		return (int) $pool[ $at ];
	}

	/**
	 * The rotation, with pins.
	 *
	 * A pin is `hti_stc_slot` / `hti_rev_slot`, and it reads two ways on
	 * purpose, without ambiguity, because the two number ranges cannot
	 * overlap:
	 *
	 *  - A large value is an absolute day index (Day::index() is days since
	 *    the epoch, ~20 000 and rising): "serve this one on that date". This
	 *    is how a case is lined up with an anniversary.
	 *  - A value below the pool size is a rotation slot: "serve this one at
	 *    position N of every cycle", which is how the first-ever visitor is
	 *    guaranteed the scenario written to be somebody's first.
	 *
	 * A pin always wins over the rotation, and two pins on the same day are
	 * resolved by the more specific one (the absolute date).
	 *
	 * @param array<int,int> $pool  Post ids, in a stable order.
	 * @param array<int,int> $pins  Slot => post id.
	 * @param int            $index Day index.
	 * @return int Post id, or 0 when there is nothing to serve.
	 */
	public static function pick_pinned( array $pool, array $pins, int $index ): int {
		$count = count( $pool );
		if ( 0 === $count ) {
			return 0;
		}

		if ( isset( $pins[ $index ] ) ) {
			return (int) $pins[ $index ];
		}

		$slot = ( ( $index % $count ) + $count ) % $count;
		if ( isset( $pins[ $slot ] ) ) {
			return (int) $pins[ $slot ];
		}

		return self::pick( $pool, $index );
	}

	/* ---------------------------------------------------------------------
	 * WordPress-bound.
	 * ------------------------------------------------------------------- */

	/**
	 * The ids that may be served, in a stable order.
	 *
	 * Ordered by ID ascending so the rotation is reproducible. Adding content
	 * changes `count($pool)` and therefore reshuffles which id lands on which
	 * future day — that is harmless, because a day already played is pinned by
	 * the `content_id` recorded on the run row, not recomputed.
	 *
	 * @param string $game Config::GAME_STC or Config::GAME_REVEAL.
	 * @return array<int,int>
	 */
	public static function published_ids( string $game ): array {
		if ( ! Config::is_game( $game ) ) {
			return array();
		}

		$key    = self::POOL_PREFIX . $game;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args = array(
			'post_type'              => self::post_type( $game ),
			'post_status'            => 'publish',
			'numberposts'            => 500,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// The pool is language-agnostic — one post carries both languages
			// in its meta — so Polylang must not filter it down to one.
			'suppress_filters'       => true,
		);

		if ( Config::GAME_REVEAL === $game ) {
			// The query refuses a case that has not met the conditions for
			// what it claims to be. This duplicates the admin publish gate
			// deliberately: a bypassed gate (direct SQL, another plugin's
			// wp_update_post) must still not be able to put a named real
			// company in front of a player.
			//
			// Two branches because there are two claims a case can make, and
			// Case_Admin::missing() is built on the same split. A VERIFIED
			// case is one whose figures came out of a document, so it needs
			// that document and the tick. An ILLUSTRATIVE case claims no
			// document, so what it needs instead is a dossier with something
			// in it — the fundamentals and the headlines are checked here
			// because those two empty would serve a blank file with a real
			// company's name at the end of it. The rest of the completeness
			// rule stays in the gate, where it can be expressed without five
			// more joins.
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- the result is cached for 12h and busted on write; correctness here outranks the join.
				'relation' => 'OR',
				array(
					'relation' => 'AND',
					array(
						'key'   => 'hti_rev_provenance',
						'value' => 'illustrative',
					),
					array(
						'key'     => 'hti_rev_fundamentals',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => 'hti_rev_headlines',
						'value'   => '',
						'compare' => '!=',
					),
				),
				array(
					'relation' => 'AND',
					array(
						'key'   => 'hti_rev_verified',
						'value' => '1',
					),
					array(
						'key'     => 'hti_rev_source_url',
						'value'   => '',
						'compare' => '!=',
					),
				),
			);
		}

		$ids = array_map( 'intval', (array) get_posts( $args ) );
		set_transient( $key, $ids, self::TTL );

		return $ids;
	}

	/**
	 * The pinned slots of a game: slot => post id.
	 *
	 * Read from the same cached pool, so a pin on an unpublished (or, for a
	 * case, unverified) post is simply not a pin.
	 *
	 * @param string $game Game id.
	 * @return array<int,int>
	 */
	public static function pins( string $game ): array {
		$meta_key = Config::GAME_REVEAL === $game ? 'hti_rev_slot' : 'hti_stc_slot';
		$pins     = array();

		foreach ( self::published_ids( $game ) as $id ) {
			$slot = (int) get_post_meta( $id, $meta_key, true );
			if ( $slot > 0 && ! isset( $pins[ $slot ] ) ) {
				// First pin wins, so two posts claiming one slot is a visible
				// editorial mistake rather than a value that flips at random.
				$pins[ $slot ] = $id;
			}
		}

		return $pins;
	}

	/**
	 * The post id to serve for a day key. Computed on read; no cron.
	 *
	 * @param string $game    Game id.
	 * @param string $day_key Day key, 'Y-m-d'.
	 * @return int Post id, or 0 when the pool is empty.
	 */
	public static function for_day( string $game, string $day_key ): int {
		$pool = self::published_ids( $game );
		if ( array() === $pool ) {
			return 0;
		}

		return self::pick_pinned( $pool, self::pins( $game ), Day::index( $day_key ) );
	}

	/**
	 * Whether the section may describe its charts as real market data.
	 *
	 * Computed from the content, never a setting an admin can tick. A manual
	 * "these are real" toggle is a claim that stays true in the database long
	 * after somebody tops the pool up with generated charts on a busy day —
	 * i.e. a false claim with a plausible excuse. Deriving it means the
	 * sentence on the page can only be there while it is true.
	 *
	 * @param string $game Game id.
	 */
	public static function is_real( string $game ): bool {
		if ( ! Config::is_game( $game ) ) {
			return false;
		}

		$key    = self::REAL_PREFIX . $game;
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return '1' === $cached;
		}

		$pool = self::published_ids( $game );
		$real = count( $pool ) >= Config::REAL_CLAIM_MIN_POOL;

		if ( $real && Config::GAME_STC === $game ) {
			// One query for the whole pool's meta before the loop. The pool is
			// ids only, so WP_Query never primed it, and a pool that is now a
			// year long would otherwise cost 365 single-row reads every time
			// this transient is rebuilt — which is on every write to a
			// scenario, i.e. throughout an install.
			update_meta_cache( 'post', $pool );

			// Every scenario, not most: one generated chart in the pool makes
			// "these are real charts" false on the day it is served.
			foreach ( $pool as $id ) {
				if ( '1' !== (string) get_post_meta( $id, 'hti_stc_real', true ) ) {
					$real = false;
					break;
				}
			}
		}

		set_transient( $key, $real ? '1' : '0', self::TTL );

		return $real;
	}

	/* ---------------------------------------------------------------------
	 * Cache invalidation.
	 * ------------------------------------------------------------------- */

	/**
	 * Flush on save/delete.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post object, when the hook passes one.
	 */
	public static function on_save( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : (string) get_post_type( (int) $post_id );
		self::flush_type( $type );
	}

	/**
	 * Flush on a status transition (publish, unpublish, schedule, trash).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		unset( $new_status, $old_status );
		if ( $post instanceof \WP_Post ) {
			self::flush_type( $post->post_type );
		}
	}

	/**
	 * Drop the caches belonging to a post type.
	 *
	 * @param string $post_type Post type.
	 */
	private static function flush_type( string $post_type ): void {
		if ( Config::CPT_SCENARIO === $post_type ) {
			self::flush( Config::GAME_STC );
		} elseif ( Config::CPT_CASE === $post_type ) {
			self::flush( Config::GAME_REVEAL );
		}
	}

	/**
	 * Drop one game's caches.
	 *
	 * @param string $game Game id.
	 */
	public static function flush( string $game ): void {
		delete_transient( self::POOL_PREFIX . $game );
		delete_transient( self::REAL_PREFIX . $game );
	}

	/**
	 * The post type behind a game id.
	 *
	 * @param string $game Game id.
	 */
	private static function post_type( string $game ): string {
		return Config::GAME_REVEAL === $game ? Config::CPT_CASE : Config::CPT_SCENARIO;
	}
}
