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
 * covers the daily one, `stc_capital` orders the survival one) capped at the
 * configured board size, wrapped in a 60-second transient. The visitor's own
 * row is deliberately NOT part of that cache — it is per-player, it is two tiny
 * queries, and a player who just decided must see their own result immediately
 * even while the top of the board is still a minute stale.
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
	 * Cache window. Short enough that the board feels live during the
	 * evening rush, long enough that a thousand people opening it at the
	 * reset is one query rather than a thousand.
	 */
	private const TTL = 60;

	/**
	 * How few players make a crowd percentage a lie.
	 *
	 * Two reasons for the floor, and either would be enough. Statistically,
	 * one player either way moves a proportion out of twelve by eight points:
	 * "67% lost today" off three runs is noise printed as a fact, on a page
	 * whose whole subject is not mistaking noise for a signal. And on a small
	 * day the same page carries a public board of nicknames, so a percentage
	 * over a handful of runs is arithmetic somebody can invert into who lost.
	 *
	 * Below this the row still appears and still says something true — how
	 * many have played — it just does not dress it as a rate.
	 */
	public const CROWD_MIN = 20;

	/**
	 * Board identifiers, as the REST layer accepts them.
	 */
	public const BOARD_DAILY    = 'daily';
	public const BOARD_SURVIVAL = 'survival';

	/**
	 * How far back a board may be asked for, in days.
	 *
	 * Not a gameplay rule — a cardinality cap. Each distinct day the endpoint
	 * is asked for mints two transients, i.e. four rows in wp_options, and
	 * `day` arrives from an anonymous GET whose only other check is "is this a
	 * real calendar date". Left open, a script walking back to 1970 turns a
	 * public leaderboard into a slow way of filling the options table, which is
	 * the failure mode the security skill calls out for any map a stranger can
	 * add a key to. Thirty days is more history than any screen asks for (the
	 * client only ever requests today) and caps the key space at 124 rows. The
	 * board size in the daily key does not multiply that: it is the site's own
	 * setting, not something the caller chooses.
	 */
	public const MAX_BACK_DAYS = 30;

	/**
	 * Whether a board id is one we serve. Pure.
	 *
	 * @param string $board Candidate.
	 */
	public static function is_board( string $board ): bool {
		return self::BOARD_DAILY === $board || self::BOARD_SURVIVAL === $board;
	}

	/**
	 * How many rows a board shows, as the owner configured it. Clamped. Pure.
	 *
	 * Clamped rather than trusted: `board_size` is normalised on the way into
	 * the option, but an option row written by an older version — or by hand —
	 * reaches the LIMIT of a public query, and a LIMIT is not a place to find
	 * out that a stored value was never checked.
	 *
	 * @param mixed $stored The stored `board_size`.
	 */
	public static function clamp_size( $stored ): int {
		return max( Settings::BOARD_MIN, min( Settings::BOARD_MAX, (int) $stored ) );
	}

	/**
	 * How many rows this site's boards show.
	 */
	public static function size(): int {
		return self::clamp_size( Settings::settings()['board_size'] );
	}

	/**
	 * Whether a day key is one a board may be built and cached for. Pure.
	 *
	 * A well-formed date is not enough: it also has to be inside the window,
	 * and never in the future. See MAX_BACK_DAYS.
	 *
	 * @param string $day   Candidate day key.
	 * @param string $today Today's day key.
	 */
	public static function is_servable_day( string $day, string $today ): bool {
		if ( ! Day::valid( $day ) || ! Day::valid( $today ) || $day > $today ) {
			return false;
		}

		return Day::index( $today ) - Day::index( $day ) <= self::MAX_BACK_DAYS;
	}

	/* ---------------------------------------------------------------- */
	/* Daily                                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * The day's top rows by risk-normalised score, plus the caller's own row.
	 *
	 * @param string $game      Game id.
	 * @param string $day_key   Day key, 'Y-m-d'.
	 * @param int    $player_id The requesting player, or 0.
	 * @return array{board:string,game:string,day:string,rows:array<int,array<string,mixed>>,me:array<string,mixed>|null,stats:array<string,int>}
	 */
	public static function daily( string $game, string $day_key, int $player_id = 0 ): array {
		$size = self::size();
		// The size is part of the key: a board cached at a hundred rows must
		// not keep being served after the owner cut it to twenty.
		$cache = 'hti_games_lb_d_' . $game . '_' . $day_key . '_' . $size;
		$rows  = get_transient( $cache );

		if ( ! is_array( $rows ) ) {
			$rows = self::query_daily( $game, $day_key, $size );
			set_transient( $cache, $rows, self::TTL );
		}

		$me = self::me_daily( $game, $day_key, $player_id );

		return array(
			'board' => self::BOARD_DAILY,
			'game'  => $game,
			'day'   => $day_key,
			'rows'  => $rows,
			'me'    => $me,
			// A public board is served to people who have not decided yet, so
			// it gets the stats without the crowd counts. See public_stats().
			'stats' => self::public_stats( self::day_stats( $game, $day_key ), null !== $me ),
		);
	}

	/**
	 * The day's statistics as a board may publish them. Pure.
	 *
	 * "How many lost on this one" is a lesson after a decision and a hint
	 * before one — the same number, and the only thing separating the two is
	 * which side of the INSERT it is read on. `GET /leaderboard` is public,
	 * unauthenticated and reachable from a second tab while the chart is still
	 * open in the first, so the counts are withheld from anybody the runs
	 * table does not already show as having played that day.
	 *
	 * The result screen does not go through here: it reads day_stats() whole,
	 * and it only exists because a run row does.
	 *
	 * @param array<string,int> $stats   day_stats() output.
	 * @param bool              $decided Whether the caller already has a run on this day.
	 * @return array<string,int>
	 */
	public static function public_stats( array $stats, bool $decided ): array {
		if ( $decided ) {
			return $stats;
		}

		unset( $stats['lost'], $stats['passed'] );

		return $stats;
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
	 * @param int    $size    How many rows, already clamped.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_daily( string $game, string $day_key, int $size ): array {
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
				$size
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
	 * How the day went for everybody: how many played, what they risked, how
	 * many lost and how many stayed out.
	 *
	 * This is the number the "average risk today" chart is drawn from, and the
	 * reason the ranking above had to be risk-normalised before this could be
	 * shown at all.
	 *
	 * Five aggregates, one row, one query, one transient. The two conditional
	 * sums cost nothing that COUNT(*) was not already paying: the WHERE is the
	 * same `board (game, day_key, board_score)` range scan either way, so the
	 * work is bounded by one game-day of runs and not by the table. They are
	 * counted here rather than derived on the client because "how many lost"
	 * has to be a fact about rows nobody can see, not a number a browser could
	 * be handed the ingredients for.
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
				"SELECT COUNT(*) AS players, AVG(risk_bp) AS avg_risk, SUM(died) AS deaths,
				        SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) AS lost,
				        SUM(CASE WHEN decision = 'pass' THEN 1 ELSE 0 END) AS passed
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
			// A losing run, not a losing player: a pass books zero and is
			// neither a loss nor a win, which is the same distinction
			// Scoring::calendar() draws between "lost" and "flat".
			'lost'        => (int) ( $row['lost'] ?? 0 ),
			'passed'      => (int) ( $row['passed'] ?? 0 ),
		);

		set_transient( $cache, $stats, self::TTL );
		return $stats;
	}

	/**
	 * The one crowd line a result screen shows: which sentence, and what
	 * percentage goes next to it. Pure.
	 *
	 * Which of the four sentences it is depends on what the player themselves
	 * did, because that is the only comparison that means anything to them. A
	 * player who took the trade is measured against the people who also took
	 * it — folding the people who passed into that denominator would report a
	 * number about attendance and call it a hit rate. A player who passed is
	 * measured against everybody, which is the honest answer to "was staying
	 * out the odd thing to do today".
	 *
	 * Below CROWD_MIN there is no percentage at all: the key names the
	 * small-sample sentence and `pct` is null, so the row says how many have
	 * played and stops there. Saying nothing untrue is worth more than filling
	 * the slot.
	 *
	 * @param array<string,int> $stats    day_stats() output.
	 * @param string            $game     Game id.
	 * @param string            $decision What the player did: 'pass' or anything else.
	 * @return array{key:string,pct:int|null}
	 */
	public static function crowd( array $stats, string $game, string $decision ): array {
		$players = max( 0, (int) ( $stats['players'] ?? 0 ) );
		$lost    = max( 0, (int) ( $stats['lost'] ?? 0 ) );
		$passed  = max( 0, (int) ( $stats['passed'] ?? 0 ) );
		$entered = max( 0, $players - $passed );
		$passing = 'pass' === $decision;

		if ( $players < self::CROWD_MIN ) {
			return array(
				'key' => 'crowd_thin',
				'pct' => null,
			);
		}

		if ( Config::GAME_REVEAL === $game ) {
			return array(
				'key' => $passing ? 'rev_crowd_passed' : 'rev_crowd_in',
				'pct' => self::share( $passing ? $passed : $entered, $players ),
			);
		}

		// Everybody passing is not a state a board of twenty can reach without
		// something being wrong, but a division by it would be, so the
		// all-players sentence is the fallback rather than a blank row.
		if ( $passing || $entered < 1 ) {
			return array(
				'key' => 'stc_crowd_lost',
				'pct' => self::share( $lost, $players ),
			);
		}

		return array(
			'key' => 'stc_crowd_entered',
			'pct' => self::share( $lost, $entered ),
		);
	}

	/**
	 * One count as a whole percentage of another. Pure.
	 *
	 * Rounded to a whole number and clamped to 0..100: a percentage on a
	 * result screen is read, not computed with, and a decimal place on it
	 * would be precision the sample does not have.
	 *
	 * @param int $part  Numerator.
	 * @param int $whole Denominator.
	 */
	private static function share( int $part, int $whole ): int {
		if ( $whole < 1 ) {
			return 0;
		}

		return max( 0, min( 100, (int) round( ( $part * 100 ) / $whole ) ) );
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
		$size  = self::size();
		$cache = 'hti_games_lb_s_' . $size;
		$rows  = get_transient( $cache );

		if ( ! is_array( $rows ) ) {
			$rows = self::query_survival( $size );
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
	 * @param int $size How many rows, already clamped.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_survival( int $size ): array {
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
				$size
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
