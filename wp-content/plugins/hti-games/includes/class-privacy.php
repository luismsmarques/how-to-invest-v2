<?php
/**
 * Data-subject rights for the games, on four separate paths.
 *
 * A player's data can be reached in exactly four ways, and each exists because
 * the other three cannot reach some of it:
 *
 *   1. hti-engine's own export/delete — the site's account pages. This plugin
 *      joins them through the `hti_export_data` filter and the
 *      `hti_account_hard_delete` action that already exist there. Without
 *      that, deleting an account would leave a player row keyed to a user id
 *      that no longer exists: not merely untidy, a retained record of a person
 *      who exercised their right to be forgotten.
 *
 *   2. WordPress core's own privacy tools (Tools → Export/Erase Personal
 *      Data). The site uses neither today, but an administrator answering a
 *      request through the screen WordPress ships must not silently miss the
 *      games. Registering here costs two filters and closes that gap.
 *
 *   3. DELETE /games/me — because the first two paths both start from an
 *      email address, and the overwhelming majority of players never give one.
 *      An anonymous player is unreachable by any account-shaped mechanism, so
 *      without a self-serve erase their only remedy would be to clear a
 *      cookie and leave the row behind. It mirrors hti-engine's
 *      DELETE /learn-progress.
 *
 *   4. Retention — the same anonymous rows, pruned after 180 days idle,
 *      because data minimisation is not satisfied by "the visitor could have
 *      deleted it". This hangs off hti-engine's ALREADY SCHEDULED
 *      `hti_prune_profiles` daily action and registers NO new schedule of its
 *      own: WP-Cron is disabled in production and driven externally, so a new
 *      hook would be a job nobody runs and a retention promise nobody keeps.
 *
 * Note what is not here: the players table holds no email and no IP. The email
 * lives in wp_users, which means it is already inside WordPress's own account
 * deletion cascade and there is no second copy of it for this plugin to miss.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

use HTI\Engine\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Export, erasure and retention for game data.
 */
class Privacy {

	/**
	 * Anonymous rows idle longer than this are pruned.
	 */
	private const RETENTION_DAYS = 180;

	/**
	 * How many players one prune pass handles. The job shares its run with
	 * hti-engine's profile prune, so it stays small and finishes tomorrow.
	 */
	private const BATCH = 200;

	/**
	 * Hook the four paths.
	 */
	public static function init(): void {
		add_filter( 'hti_export_data', array( __CLASS__, 'export_data' ), 10, 2 );
		add_action( 'hti_account_hard_delete', array( __CLASS__, 'hard_delete' ), 10, 2 );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );

		// Someone else's schedule, deliberately. See the file docblock.
		add_action( 'hti_prune_profiles', array( __CLASS__, 'prune' ) );
	}

	/* ---------------------------------------------------------------- */
	/* 1. hti-engine's export and delete                                 */
	/* ---------------------------------------------------------------- */

	/**
	 * Add a `games` section to the site's data export.
	 *
	 * Everything the plugin holds about this person: the player row, every
	 * run, the nickname, and the acknowledgement record — when they confirmed
	 * they understood the simulation, and which version of those words they
	 * were shown. That last pair is exported rather than kept internal because
	 * a record held ABOUT someone is theirs to see, including the ones that
	 * exist to protect us.
	 *
	 * @param array<string,mixed> $data    Assembled export.
	 * @param int                 $user_id User being exported.
	 * @return array<string,mixed>
	 */
	public static function export_data( $data, $user_id ): array {
		$data = is_array( $data ) ? $data : array();
		$row  = Player::by_user( (int) $user_id );

		if ( ! $row ) {
			$data['games'] = array(
				'player' => null,
				'runs'   => array(),
				'note'   => 'No game data is stored for this account.',
			);
			return $data;
		}

		$data['games'] = array(
			'player'          => array(
				'created_at'  => (string) $row['created_at'],
				'last_seen'   => (string) $row['last_seen'],
				'language'    => (string) $row['lang'],
				'nickname'    => (string) $row['nickname'],
				'newsletter'  => (bool) (int) $row['newsletter'],
				'survive_the_charts' => array(
					'capital'     => (int) $row['stc_capital'],
					'streak'      => (int) $row['stc_streak'],
					'best_streak' => (int) $row['stc_best_streak'],
					'deaths'      => (int) $row['stc_deaths'],
					'last_day'    => (string) $row['stc_last_day'],
				),
				'the_reveal'  => array(
					'capital'     => (int) $row['rev_capital'],
					'index'       => (int) $row['rev_index_cap'],
					'streak'      => (int) $row['rev_streak'],
					'best_streak' => (int) $row['rev_best_streak'],
					'deaths'      => (int) $row['rev_deaths'],
					'last_day'    => (string) $row['rev_last_day'],
				),
			),
			// The acknowledgement, kept distinct from anything consent-shaped:
			// it records that the simulation warning was shown and confirmed,
			// which is not a permission and must never be exported as one.
			'acknowledgement' => array(
				'confirmed_at' => (string) $row['ack_at'],
				'text_version' => (string) $row['ack_ver'],
				'meaning'      => 'Confirmed understanding that the games are an educational simulation with virtual money and no real trading. This is an acknowledgement, not a consent basis.',
			),
			'runs'            => self::runs_for_player( (int) $row['id'] ),
		);

		return $data;
	}

	/**
	 * Erase everything when an account is hard-deleted.
	 *
	 * Fired by hti-engine BEFORE wp_delete_user(), so the row is still
	 * findable by user id. Rows in a plugin's own table are exactly what that
	 * hook exists to catch: nothing in WordPress core knows this table is
	 * keyed to a user.
	 *
	 * @param int    $user_id User being erased.
	 * @param string $email   Their email (unused — this plugin stores none).
	 */
	public static function hard_delete( $user_id, $email = '' ): void {
		unset( $email );
		self::erase_user( (int) $user_id );
	}

	/**
	 * Delete the player row and every run belonging to a user id.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether anything was removed.
	 */
	public static function erase_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$row = Player::by_user( $user_id );
		if ( ! $row ) {
			return false;
		}

		return self::erase_player( (int) $row['id'] );
	}

	/**
	 * Delete one player row and all of its runs.
	 *
	 * Runs first: if the process dies between the two statements, orphaned
	 * runs pointing at a live player are recoverable, while a live player
	 * pointing at nothing is a row we would have to reconstruct.
	 *
	 * @param int $player_id Player row id.
	 * @return bool
	 */
	public static function erase_player( int $player_id ): bool {
		if ( $player_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API; erasure must be immediate and unconditional.
		$wpdb->delete( Store::runs_table(), array( 'player_id' => $player_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
		$deleted = $wpdb->delete( Store::players_table(), array( 'id' => $player_id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/* ---------------------------------------------------------------- */
	/* 2. WordPress core's privacy tools                                 */
	/* ---------------------------------------------------------------- */

	/**
	 * Register the core exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_exporter( $exporters ): array {
		$exporters                = is_array( $exporters ) ? $exporters : array();
		$exporters['hti-games'] = array(
			'exporter_friendly_name' => __( 'HowToInvest games', 'hti-games' ),
			'callback'               => array( __CLASS__, 'core_exporter' ),
		);
		return $exporters;
	}

	/**
	 * Register the core eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_eraser( $erasers ): array {
		$erasers                = is_array( $erasers ) ? $erasers : array();
		$erasers['hti-games'] = array(
			'eraser_friendly_name' => __( 'HowToInvest games', 'hti-games' ),
			'callback'             => array( __CLASS__, 'core_eraser' ),
		);
		return $erasers;
	}

	/**
	 * Core exporter callback: the games section as name/value pairs.
	 *
	 * One page: a player has one row and a bounded history, so there is
	 * nothing to paginate and `done` is always true.
	 *
	 * @param string $email_address Data subject.
	 * @param int    $page          Page (unused).
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function core_exporter( $email_address, $page = 1 ): array {
		unset( $page );

		$empty = array(
			'data' => array(),
			'done' => true,
		);

		$user = get_user_by( 'email', (string) $email_address );
		if ( ! $user instanceof \WP_User ) {
			return $empty;
		}

		$row = Player::by_user( (int) $user->ID );
		if ( ! $row ) {
			return $empty;
		}

		$items = array(
			array(
				'name'  => __( 'Nickname', 'hti-games' ),
				'value' => (string) $row['nickname'],
			),
			array(
				'name'  => __( 'First played', 'hti-games' ),
				'value' => (string) $row['created_at'],
			),
			array(
				'name'  => __( 'Last seen', 'hti-games' ),
				'value' => (string) $row['last_seen'],
			),
			array(
				'name'  => __( 'Simulation acknowledged at', 'hti-games' ),
				'value' => (string) $row['ack_at'],
			),
			array(
				'name'  => __( 'Survive the Charts — capital, streak, deaths', 'hti-games' ),
				'value' => sprintf( '%d / %d / %d', (int) $row['stc_capital'], (int) $row['stc_streak'], (int) $row['stc_deaths'] ),
			),
			array(
				'name'  => __( 'The Reveal — capital, streak, deaths', 'hti-games' ),
				'value' => sprintf( '%d / %d / %d', (int) $row['rev_capital'], (int) $row['rev_streak'], (int) $row['rev_deaths'] ),
			),
			array(
				'name'  => __( 'Runs recorded', 'hti-games' ),
				'value' => (string) count( self::runs_for_player( (int) $row['id'] ) ),
			),
		);

		return array(
			'data' => array(
				array(
					'group_id'    => 'hti-games',
					'group_label' => __( 'HowToInvest games', 'hti-games' ),
					'item_id'     => 'hti-games-player',
					'data'        => $items,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Core eraser callback.
	 *
	 * @param string $email_address Data subject.
	 * @param int    $page          Page (unused).
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function core_eraser( $email_address, $page = 1 ): array {
		unset( $page );

		$removed = false;
		$user    = get_user_by( 'email', (string) $email_address );

		if ( $user instanceof \WP_User ) {
			$removed = self::erase_user( (int) $user->ID );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/* ---------------------------------------------------------------- */
	/* 3. Self-serve erasure                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * DELETE /games/me — erase this player, account or not.
	 *
	 * Deliberately available to an anonymous player: they are the ones no
	 * other path can reach. Authorisation is possession of the identity —
	 * the cookie or the header — which is the same thing that authorises
	 * playing as them, plus the nonce the permission callback already checked.
	 * There is nothing here that a person who holds the cookie should be able
	 * to play with but not delete.
	 *
	 * Irreversible and immediate: no grace period, because unlike an account
	 * there is no sign-in that could undo an accidental one, and nothing here
	 * is worth more than the promise that the button does what it says.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_forget( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_forget' ) ) {
			return new WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'hti-games' ), array( 'status' => 429 ) );
		}

		$row = Player::resolve( $request );

		// The cookie goes either way. Someone who asked to be forgotten and
		// had nothing stored should still leave without an identifier on them.
		Player::clear_cookie();

		if ( ! $row ) {
			return new WP_REST_Response( array( 'forgotten' => true ), 200 );
		}

		self::erase_player( (int) $row['id'] );

		return new WP_REST_Response( array( 'forgotten' => true ), 200 );
	}

	/* ---------------------------------------------------------------- */
	/* 4. Retention                                                      */
	/* ---------------------------------------------------------------- */

	/**
	 * Prune anonymous players idle beyond the retention window.
	 *
	 * Only `user_id = 0` rows. A row bound to an account is already governed
	 * by that account's own lifecycle — export, scheduled deletion, the hard
	 * delete above — and dropping it for idleness would quietly destroy the
	 * history of somebody who simply took a season off, which is not
	 * minimisation, it is data loss.
	 */
	public static function prune(): void {
		/**
		 * Filter the anonymous player retention window, in days.
		 *
		 * @param int $days Retention days.
		 */
		$days = (int) apply_filters( 'hti_games_retention_days', self::RETENTION_DAYS );
		if ( $days < 1 ) {
			return;
		}

		global $wpdb;

		$players = Store::players_table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; the table name comes from $wpdb->prefix and cannot be a placeholder, every value is prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$players}` WHERE user_id = 0 AND last_seen < %s ORDER BY last_seen ASC LIMIT %d",
				$cutoff,
				self::BATCH
			)
		);

		foreach ( (array) $ids as $id ) {
			self::erase_player( (int) $id );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Shared                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * Every run belonging to a player, oldest first, shaped for an export.
	 *
	 * @param int $player_id Player row id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function runs_for_player( int $player_id ): array {
		global $wpdb;

		$runs = Store::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; an export must read the live rows, never a cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT game, day_key, decision, risk_bp, multiplier, outcome, board_score, pnl, cap_before, cap_after, idx_before, idx_after, died, streak_after, lang, created_at
				 FROM `{$runs}` WHERE player_id = %d ORDER BY id ASC",
				$player_id
			),
			ARRAY_A
		);

		$stc = Config::game_id( Config::GAME_STC );
		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'game'         => (int) $row['game'] === $stc ? Config::GAME_STC : Config::GAME_REVEAL,
				'day'          => (string) $row['day_key'],
				'decision'     => (string) $row['decision'],
				'risk_bp'      => (int) $row['risk_bp'],
				'multiplier'   => (int) $row['multiplier'],
				'outcome'      => (string) $row['outcome'],
				'board_score'  => (int) $row['board_score'],
				'pnl'          => (int) $row['pnl'],
				'cap_before'   => (int) $row['cap_before'],
				'cap_after'    => (int) $row['cap_after'],
				'idx_before'   => (int) $row['idx_before'],
				'idx_after'    => (int) $row['idx_after'],
				'died'         => (bool) (int) $row['died'],
				'streak_after' => (int) $row['streak_after'],
				'language'     => (string) $row['lang'],
				'played_at'    => (string) $row['created_at'],
			);
		}

		return $out;
	}
}
