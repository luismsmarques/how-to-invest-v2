<?php
/**
 * The two public boards, computed on read.
 *
 * ---------------------------------------------------------------------------
 * Why the daily board ranks by board_score and never by P&L
 * ---------------------------------------------------------------------------
 *
 * A leaderboard is a teaching instrument here, not a scoreboard. Ranking the
 * day by raw profit would make the top of the board a list of the people who
 * bet the most, because with a fixed target and stop the largest position wins
 * the largest number every single time direction goes their way. Print that
 * next to the "average risk today" chart the design puts on the same page and
 * the lesson a player actually takes home is "size up to climb" — the precise
 * opposite of the one the game exists to teach, and the one that empties real
 * accounts.
 *
 * So the ranking key is Scoring::board_score(), which normalises the day's
 * P&L by the risk taken to get it: being right at 0.5% and being right at 25%
 * score the same, and being wrong at 25% costs what it should. The raw P&L is
 * still returned — it is the honest number and the player earned it — but it
 * is a column, not the sort.
 *
 * ---------------------------------------------------------------------------
 * Why there is no leaderboard table
 * ---------------------------------------------------------------------------
 *
 * A materialised board would need something to materialise it, and WP-Cron is
 * disabled in production: a board that depended on a schedule would be a board
 * that quietly stops updating. Both queries are single-index reads (`board`
 * covers the daily one, `stc_capital` orders the survival one) capped at 50
 * rows, wrapped in a 60-second transient. The visitor's own row is deliberately
 * NOT part of that cache — it is per-player, it is two tiny queries, and a
 * player who just decided must see their own result immediately even while the
 * top 50 is still a minute stale.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Daily and survival leaderboards.
 */
class Leaderboard {

	/**
	 * How many rows a board shows.
	 */
	public const SIZE = 50;

	/**
	 * Cache window. Short enough that the board feels live during the
	 * evening rush, long enough that a thousand people opening it at the
	 * reset is one query rather than a thousand.
	 */
	private const TTL = 60;

	/**
	 * Board identifiers, as the REST layer accepts them.
	 */
	public const BOARD_DAILY    = 'daily';
	public const BOARD_SURVIVAL = 'survival';

	/**
	 * Whether a board id is one we serve. Pure.
	 *
	 * @param string $board Candidate.
	 */
	public static function is_board( string $board ): bool {
		return self::BOARD_DAILY === $board || self::BOARD_SURVIVAL === $board;
	}

	/* ---------------------------------------------------------------- */
	/* Daily                                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * The day's top 50 by risk-normalised score, plus the caller's own row.
	 *
	 * @param string $game      Game id.
	 * @param string $day_key   Day key, 'Y-m-d'.
	 * @param int    $player_id The requesting player, or 0.
	 * @return array{board:string,game:string,day:string,rows:array<int,array<string,mixed>>,me:array<string,mixed>|null,stats:array<string,int>}
	 */
	public static function daily( string $game, string $day_key, int $player_id = 0 ): array {
		$cache = 'hti_games_lb_d_' . $game . '_' . $day_key;
		$rows  = get_transient( $cache );

		if ( ! is_array( $rows ) ) {
			$rows = self::query_daily( $game, $day_key );
			set_transient( $cache, $rows, self::TTL );
		}

		return array(
			'board' => self::BOARD_DAILY,
			'game'  => $game,
			'day'   => $day_key,
			'rows'  => $rows,
			'me'    => self::me_daily( $game, $day_key, $player_id ),
			'stats' => self::day_stats( $game, $day_key ),
		);
	}

	/**
	 * The uncached top-50 read.
	 *
	 * Only rows whose player has chosen a nickname appear: a public board of
	 * "Player 4831" teaches nothing and invites nobody, and a row nobody can
	 * be identified by is not a row worth publishing either.
	 *
	 * @param string $game    Game id.
	 * @param string $day_key Day key.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_daily( string $game, string $day_key ): array {
		global $wpdb;

		$runs    = Store::runs_table();
		$players = Store::players_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, no core API; caching is the transient in the caller, and the two table names are built from $wpdb->prefix (a placeholder cannot carry an identifier) while every value is prepared.
		$raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.nickname, r.board_score, r.pnl, r.risk_bp, r.multiplier, r.decision, r.outcome
				 FROM `{$runs}` r
				 INNER JOIN `{$players}` p ON p.id = r.player_id
				 WHERE r.game = %d AND r.day_key = %s AND p.nickname_key IS NOT NULL AND p.nickname <> ''
				 ORDER BY r.board_score DESC, r.id ASC
				 LIMIT %d",
				Config::game_id( $game ),
				$day_key,
				self::SIZE
			),
			ARRAY_A
		);

		$rows = array();
		$rank = 0;
		foreach ( (array) $raw as $row ) {
			++$rank;
			$rows[] = array(
				'rank'        => $rank,
				'nickname'    => self::safe_nickname( (string) $row['nickname'] ),
				'board_score' => (int) $row['board_score'],
				'pnl'         => (int) $row['pnl'],
				'risk_bp'     => (int) $row['risk_bp'],
				'multiplier'  => (int) $row['multiplier'],
				'decision'    => (string) $row['decision'],
				'outcome'     => (string) $row['outcome'],
			);
		}

		return $rows;
	}

	/**
	 * The requesting player's own row and rank, whether or not it is top 50.
	 *
	 * The design pins this row to the bottom of the board, which is the only
	 * way a board of 50 means anything to the ten thousandth player.
	 *
	 * `rank` is 0 when the player has no nickname: they are not on a public
	 * board, so they have no position on one. Their score is still returned —
	 * it is theirs — and the UI offers them a name.
	 *
	 * @param string $game      Game id.
	 * @param string $day_key   Day key.
	 * @param int    $player_id Player row id.
	 * @return array<string,mixed>|null
	 */
	public static function me_daily( string $game, string $day_key, int $player_id ): ?array {
		if ( $player_id <= 0 ) {
			return null;
		}

		global $wpdb;

		$runs    = Store::runs_table();
		$players = Store::players_table();
		$game_id = Config::game_id( $game );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; per-player and must not be cached, or a player would see somebody else's rank.
		$mine = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.id, r.board_score, r.pnl, r.risk_bp, r.multiplier, r.decision, r.outcome, p.nickname
				 FROM `{$runs}` r
				 INNER JOIN `{$players}` p ON p.id = r.player_id
				 WHERE r.player_id = %d AND r.game = %d AND r.day_key = %s",
				$player_id,
				$game_id,
				$day_key
			),
			ARRAY_A
		);

		if ( ! is_array( $mine ) ) {
			return null; // Has not played today.
		}

		$rank = 0;
		if ( '' !== (string) $mine['nickname'] ) {
			// Rank by counting what beats it rather than by paging the board:
			// one indexed COUNT beats materialising ten thousand rows to find
			// where one of them sits. The id tiebreak mirrors the ORDER BY
			// above exactly, so the pinned rank and the listed rank agree.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$rank = 1 + (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM `{$runs}` r
					 INNER JOIN `{$players}` p ON p.id = r.player_id
					 WHERE r.game = %d AND r.day_key = %s
					   AND p.nickname_key IS NOT NULL AND p.nickname <> ''
					   AND ( r.board_score > %d OR ( r.board_score = %d AND r.id < %d ) )",
					$game_id,
					$day_key,
					(int) $mine['board_score'],
					(int) $mine['board_score'],
					(int) $mine['id']
				)
			);
		}

		return array(
			'rank'        => $rank,
			'nickname'    => self::safe_nickname( (string) $mine['nickname'] ),
			'board_score' => (int) $mine['board_score'],
			'pnl'         => (int) $mine['pnl'],
			'risk_bp'     => (int) $mine['risk_bp'],
			'multiplier'  => (int) $mine['multiplier'],
			'decision'    => (string) $mine['decision'],
			'outcome'     => (string) $mine['outcome'],
		);
	}

	/**
	 * How the day went for everybody: how many played and what they risked.
	 *
	 * This is the number the "average risk today" chart is drawn from, and the
	 * reason the ranking above had to be risk-normalised before this could be
	 * shown at all.
	 *
	 * @param string $game    Game id.
	 * @param string $day_key Day key.
	 * @return array<string,int>
	 */
	public static function day_stats( string $game, string $day_key ): array {
		global $wpdb;

		$cache = 'hti_games_lb_st_' . $game . '_' . $day_key;
		$stats = get_transient( $cache );
		if ( is_array( $stats ) ) {
			return $stats;
		}

		$runs = Store::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; cached in the transient immediately below.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS players, AVG(risk_bp) AS avg_risk, SUM(died) AS deaths
				 FROM `{$runs}` WHERE game = %d AND day_key = %s",
				Config::game_id( $game ),
				$day_key
			),
			ARRAY_A
		);

		$stats = array(
			'players'     => (int) ( $row['players'] ?? 0 ),
			'avg_risk_bp' => (int) round( (float) ( $row['avg_risk'] ?? 0 ) ),
			'deaths'      => (int) ( $row['deaths'] ?? 0 ),
		);

		set_transient( $cache, $stats, self::TTL );
		return $stats;
	}

	/* ---------------------------------------------------------------- */
	/* Survival                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * The all-time survival board: who still has the most virtual capital.
	 *
	 * This one is off the players table, not the runs table, because the
	 * question it answers is about the account rather than about a day. It is
	 * ordered by capital and then by the current streak, which is the tiebreak
	 * that matches how the page reads: same money, longer alive, higher.
	 *
	 * @param int $player_id The requesting player, or 0.
	 * @return array{board:string,rows:array<int,array<string,mixed>>,me:array<string,mixed>|null}
	 */
	public static function survival( int $player_id = 0 ): array {
		$cache = 'hti_games_lb_s';
		$rows  = get_transient( $cache );

		if ( ! is_array( $rows ) ) {
			$rows = self::query_survival();
			set_transient( $cache, $rows, self::TTL );
		}

		return array(
			'board' => self::BOARD_SURVIVAL,
			'game'  => Config::GAME_STC,
			'rows'  => $rows,
			'me'    => self::me_survival( $player_id ),
		);
	}

	/**
	 * The uncached survival read.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_survival(): array {
		global $wpdb;

		$players = Store::players_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; caching is the transient in the caller.
		$raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT nickname, stc_capital, stc_streak, stc_best_streak, stc_deaths
				 FROM `{$players}`
				 WHERE nickname_key IS NOT NULL AND nickname <> ''
				 ORDER BY stc_capital DESC, stc_streak DESC, id ASC
				 LIMIT %d",
				self::SIZE
			),
			ARRAY_A
		);

		$rows = array();
		$rank = 0;
		foreach ( (array) $raw as $row ) {
			++$rank;
			$rows[] = array(
				'rank'        => $rank,
				'nickname'    => self::safe_nickname( (string) $row['nickname'] ),
				'capital'     => (int) $row['stc_capital'],
				'streak'      => (int) $row['stc_streak'],
				'best_streak' => (int) $row['stc_best_streak'],
				'deaths'      => (int) $row['stc_deaths'],
			);
		}

		return $rows;
	}

	/**
	 * The requesting player's survival row and rank.
	 *
	 * @param int $player_id Player row id.
	 * @return array<string,mixed>|null
	 */
	public static function me_survival( int $player_id ): ?array {
		if ( $player_id <= 0 ) {
			return null;
		}

		$row = Player::by_id( $player_id );
		if ( ! $row ) {
			return null;
		}

		global $wpdb;
		$players = Store::players_table();
		$rank    = 0;

		if ( '' !== (string) $row['nickname'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; per-player, must not be cached.
			$rank = 1 + (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$players}`
					 WHERE nickname_key IS NOT NULL AND nickname <> ''
					   AND ( stc_capital > %d
					      OR ( stc_capital = %d AND stc_streak > %d )
					      OR ( stc_capital = %d AND stc_streak = %d AND id < %d ) )",
					(int) $row['stc_capital'],
					(int) $row['stc_capital'],
					(int) $row['stc_streak'],
					(int) $row['stc_capital'],
					(int) $row['stc_streak'],
					(int) $row['id']
				)
			);
		}

		return array(
			'rank'        => $rank,
			'nickname'    => self::safe_nickname( (string) $row['nickname'] ),
			'capital'     => (int) $row['stc_capital'],
			'streak'      => (int) $row['stc_streak'],
			'best_streak' => (int) $row['stc_best_streak'],
			'deaths'      => (int) $row['stc_deaths'],
		);
	}

	/* ---------------------------------------------------------------- */
	/* Output                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * A nickname on its way to a public page. Pure.
	 *
	 * Player::validate_nickname() already restricts what can be stored to
	 * letters, digits, `_` and `-`, so in principle there is nothing here to
	 * strip. This runs anyway because "nothing can be in that column" is a
	 * claim about today's validator, not about the row a looser one may have
	 * written last year — and a board is exactly the page where being wrong
	 * about that is expensive. Fails closed: anything outside the charset is
	 * dropped, not escaped and shown.
	 *
	 * @param string $nickname Stored nickname.
	 */
	public static function safe_nickname( string $nickname ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $nickname ), 0, 24 );
	}
}
