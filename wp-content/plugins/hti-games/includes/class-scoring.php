<?php
/**
 * What a pile of run rows says about a player.
 *
 * Pure functions over rows, so every number on the profile, the calendar and
 * the daily board is derived from the same records rather than from counters
 * kept in parallel — a counter that can drift from the rows it counts always
 * eventually does.
 *
 * A row is what Store hands back for one day of one game:
 *
 *     day      string  'Y-m-d', the game day (see Day — the boundary is IST)
 *     decision string  'buy' | 'sell' | 'pass' | 'invest'
 *     outcome  string  'stop' | 'target' | 'open' | 'pass'
 *     risk_bp  int     risk tier in basis points; 0 for a pass or a Reveal row
 *     pnl      int     whole dollars, signed
 *     died     bool    whether this row blew the account
 *
 * Every read goes through the accessors below, so a row missing a key gives a
 * neutral answer instead of a notice. Rows are assumed to be one player's, one
 * game's; mixing games in one call would average two different risk scales.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Streaks, averages, the calendar and the board score. Pure.
 */
class Scoring {

	/**
	 * Days in a week, and in the calendar grid the profile draws.
	 */
	public const WEEK  = 7;
	public const MONTH = 28;

	/**
	 * Seconds in a day, as Day counts them. Local rather than borrowed from
	 * WordPress's DAY_IN_SECONDS so this class stays runnable on its own.
	 */
	private const DAY = 86400;

	/**
	 * The tier at or below which a position counts as small — 1% of capital.
	 *
	 * Not a design opinion: it is the tier the game is trying to move people
	 * towards, and the badge that rewards it has to point at a number.
	 */
	public const SMALL_BP = 100;

	/**
	 * How many small positions in a row earn the restraint badge, and how many
	 * passes earn the patience one.
	 */
	public const SMALL_TARGET    = 10;
	public const PATIENCE_TARGET = 5;

	/**
	 * How many runs before "still above where you started" means anything.
	 */
	public const SURVIVOR_TARGET = 10;

	/**
	 * The current unbroken run of days played, ending at the most recent row.
	 *
	 * A pass extends the streak. That is deliberate: the streak measures
	 * showing up, not trading, and a game that only rewarded taking a position
	 * would be teaching the opposite of its own lesson.
	 *
	 * A death ends it — the run that died is not counted, because the streak
	 * belongs to the account and that account is gone. A missed day ends it
	 * too: consecutive means consecutive, which is what makes it worth
	 * protecting.
	 *
	 * @param array<int,array<string,mixed>> $rows Run rows, any order.
	 * @return int Days.
	 */
	public static function streak_from( array $rows ): int {
		$rows = self::by_day( $rows );

		if ( ! $rows ) {
			return 0;
		}

		$streak   = 0;
		$expected = Day::index( self::day( $rows[ count( $rows ) - 1 ] ) );

		for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
			if ( Day::index( self::day( $rows[ $i ] ) ) !== $expected ) {
				break;
			}
			if ( self::died( $rows[ $i ] ) ) {
				break;
			}
			++$streak;
			--$expected;
		}

		return $streak;
	}

	/**
	 * Mean risk per position, in basis points.
	 *
	 * Only rows that actually took a position count. Folding passes in as
	 * zeros would let a player who plays once at 25% and passes nine times
	 * report an average of 250 bp — a number that describes their attendance,
	 * not their sizing, and that hides exactly the behaviour this metric
	 * exists to surface.
	 *
	 * @param array<int,array<string,mixed>> $rows Run rows.
	 * @return int Basis points, or 0 when nothing was ever staked.
	 */
	public static function average_risk_bp( array $rows ): int {
		$staked = array_values( array_filter( $rows, array( self::class, 'is_staked' ) ) );
		$count  = count( $staked );

		if ( 0 === $count ) {
			return 0;
		}

		$sum = 0;
		foreach ( $staked as $row ) {
			$sum += self::risk_bp( $row );
		}

		return STC_Engine::round_half_away_from_zero( $sum / $count );
	}

	/**
	 * Mean risk per week, oldest week first — the learning metric.
	 *
	 * This is the one chart on the profile that is allowed to be the point of
	 * the whole game: a player who has understood it sizes down over time, and
	 * this is where they see it happen. It is deliberately NOT the P&L curve,
	 * which is mostly noise over a few dozen days and rewards whoever got the
	 * kind chart.
	 *
	 * Weeks are seven-day buckets counted back from the most recent row, not
	 * calendar weeks: a player who starts on a Thursday should not have their
	 * first week be one day long.
	 *
	 * A week with no positions reports runs 0 and average_bp 0. The chart must
	 * skip those rather than draw them, or a fortnight away from the game
	 * renders as a heroic collapse in risk.
	 *
	 * @param array<int,array<string,mixed>> $rows  Run rows.
	 * @param int                            $weeks How many weeks back to report.
	 * @return array<int,array{from:string,to:string,runs:int,average_bp:int}> Empty when there are no rows to anchor to.
	 */
	public static function risk_by_week( array $rows, int $weeks ): array {
		$rows = self::by_day( $rows );

		if ( ! $rows || $weeks < 1 ) {
			return array();
		}

		$anchor  = Day::index( self::day( $rows[ count( $rows ) - 1 ] ) );
		$buckets = array();

		foreach ( $rows as $row ) {
			if ( ! self::is_staked( $row ) ) {
				continue;
			}
			$back = $anchor - Day::index( self::day( $row ) );
			if ( $back < 0 ) {
				continue;
			}
			$bucket = intdiv( $back, self::WEEK );
			if ( $bucket >= $weeks ) {
				continue;
			}
			$buckets[ $bucket ][] = self::risk_bp( $row );
		}

		$out = array();
		for ( $bucket = $weeks - 1; $bucket >= 0; $bucket-- ) {
			$values = $buckets[ $bucket ] ?? array();
			$count  = count( $values );
			$out[]  = array(
				'from'       => self::key_at( $anchor - ( $bucket * self::WEEK ) - ( self::WEEK - 1 ) ),
				'to'         => self::key_at( $anchor - ( $bucket * self::WEEK ) ),
				'runs'       => $count,
				'average_bp' => 0 === $count ? 0 : STC_Engine::round_half_away_from_zero( array_sum( $values ) / $count ),
			);
		}

		return $out;
	}

	/**
	 * The grid: one cell per day, oldest first, ending on $today.
	 *
	 * Five states, not four. "Missed" is a day with no row at all and is worth
	 * showing — a habit is visible in the gaps. "Flat" is the honest one the
	 * obvious design leaves out: a position that touched neither level and
	 * rounds to exactly zero dollars is not a loss, and colouring it as one
	 * would be a small lie repeated daily.
	 *
	 * @param array<int,array<string,mixed>> $rows  Run rows.
	 * @param string                         $today The most recent game day, 'Y-m-d'.
	 * @param int                            $days  Cells to return, e.g. Scoring::MONTH.
	 * @return array<int,array{day:string,state:string,pnl:int,died:bool}> Empty when $today is not a real date.
	 */
	public static function calendar( array $rows, string $today, int $days ): array {
		if ( ! Day::valid( $today ) || $days < 1 ) {
			return array();
		}

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ self::day( $row ) ] = $row;
		}

		$end  = Day::index( $today );
		$grid = array();

		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$key = self::key_at( $end - $offset );
			$row = $by_day[ $key ] ?? null;

			if ( null === $row ) {
				$grid[] = array(
					'day'   => $key,
					'state' => 'missed',
					'pnl'   => 0,
					'died'  => false,
				);
				continue;
			}

			$pnl = self::pnl( $row );

			if ( 'pass' === self::decision( $row ) ) {
				$state = 'passed';
			} elseif ( $pnl > 0 ) {
				$state = 'won';
			} elseif ( $pnl < 0 ) {
				$state = 'lost';
			} else {
				$state = 'flat';
			}

			$grid[] = array(
				'day'   => $key,
				'state' => $state,
				'pnl'   => $pnl,
				'died'  => self::died( $row ),
			);
		}

		return $grid;
	}

	/**
	 * The badge table: every badge, whether it is earned, and the progress.
	 *
	 * Keys only — the names and the sentences are bilingual and live in
	 * Strings. The whole table is returned rather than just what is earned so
	 * the profile can show what is still ahead, which is the only part of a
	 * badge that changes behaviour.
	 *
	 * Note which behaviours are rewarded: showing up, passing, sizing small,
	 * and sizing smaller than you used to. Nothing here rewards a big number.
	 * "Blown" is earned by dying, and it is earned on purpose — an account
	 * that dies in week one at 25% has taught its owner more than any warning
	 * label on the tier button, and the game should mark the lesson rather
	 * than hide it.
	 *
	 * @param array<int,array<string,mixed>> $rows Run rows.
	 * @param array<string,mixed>            $run  Current run state; 'capital' is read.
	 * @return array<int,array{key:string,earned:bool,progress:int,target:int}>
	 */
	public static function badges( array $rows, array $run ): array {
		$rows    = self::by_day( $rows );
		$played  = count( $rows );
		$streak  = self::streak_from( $rows );
		$capital = isset( $run['capital'] ) ? (int) $run['capital'] : Config::CAPITAL_START;

		$passes = 0;
		$deaths = 0;
		foreach ( $rows as $row ) {
			if ( 'pass' === self::decision( $row ) ) {
				++$passes;
			}
			if ( self::died( $row ) ) {
				++$deaths;
			}
		}

		$small = self::small_tail( $rows );
		$weeks = self::risk_by_week( $rows, 2 );
		$calmer = 2 === count( $weeks )
			&& $weeks[0]['runs'] > 0
			&& $weeks[1]['runs'] > 0
			&& $weeks[1]['average_bp'] < $weeks[0]['average_bp'];

		return array(
			self::badge( 'first_chart', $played, 1 ),
			self::badge( 'week', $streak, self::WEEK ),
			self::badge( 'month', $streak, self::MONTH ),
			self::badge( 'patience', $passes, self::PATIENCE_TARGET ),
			self::badge( 'small_size', $small, self::SMALL_TARGET ),
			self::badge( 'de_risked', $calmer ? 1 : 0, 1 ),
			self::badge( 'blown', $deaths, 1 ),
			self::badge( 'survivor', $capital >= Config::CAPITAL_START ? $played : 0, self::SURVIVOR_TARGET ),
		);
	}

	/**
	 * The daily board's ranking number: the P&L this decision would have made
	 * at a 1% risk tier.
	 *
	 * READ THIS BEFORE SIMPLIFYING IT AWAY. Ranking the board by raw P&L —
	 * which is the obvious thing, and what the prototype did — makes the
	 * fastest route to the top of a public leaderboard "put 25% of the account
	 * on one chart". Next to a public average-risk profile chart that is not
	 * just a wrong incentive, it is the exact inverse of the lesson the game
	 * exists to teach, in the one place on the site where players compare
	 * themselves to each other.
	 *
	 * Normalising by the risk taken removes the reward for size entirely: two
	 * players who read the same chart the same way score the same, and the one
	 * who bet the account gains nothing on the board for it — they only carry
	 * the drawdown. What is left to compete on is being right, which is the
	 * only thing worth ranking.
	 *
	 * A pass scores zero, and so does a zero tier: there is no position to
	 * normalise, and nothing to divide by.
	 *
	 * @param int $pnl     Whole dollars, signed.
	 * @param int $risk_bp Risk tier in basis points of capital.
	 * @return int Board points.
	 */
	public static function board_score( int $pnl, int $risk_bp ): int {
		if ( 0 === $risk_bp ) {
			return 0;
		}

		return STC_Engine::round_half_away_from_zero( ( $pnl * self::SMALL_BP ) / $risk_bp );
	}

	/**
	 * How many staked rows in a row, most recent first, were at or under 1%.
	 *
	 * Passes are skipped rather than counted or breaking the run: declining to
	 * trade is not oversizing, and it should neither earn the badge nor cost
	 * it.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows, already day-sorted.
	 * @return int
	 */
	private static function small_tail( array $rows ): int {
		$tail = 0;

		for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
			if ( ! self::is_staked( $rows[ $i ] ) ) {
				continue;
			}
			if ( self::risk_bp( $rows[ $i ] ) > self::SMALL_BP ) {
				break;
			}
			++$tail;
		}

		return $tail;
	}

	/**
	 * One row of the badge table.
	 *
	 * @param string $key      Badge key; the wording lives in Strings.
	 * @param int    $progress How far along.
	 * @param int    $target   What earns it.
	 * @return array{key:string,earned:bool,progress:int,target:int}
	 */
	private static function badge( string $key, int $progress, int $target ): array {
		return array(
			'key'      => $key,
			'earned'   => $progress >= $target,
			'progress' => min( $progress, $target ),
			'target'   => $target,
		);
	}

	/**
	 * Rows sorted oldest first, so every walk below can assume an order.
	 *
	 * @param array<int,array<string,mixed>> $rows Run rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function by_day( array $rows ): array {
		$rows = array_values( $rows );

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( self::day( $a ), self::day( $b ) );
			}
		);

		return $rows;
	}

	/**
	 * A day key from a day index.
	 *
	 * @param int $index Days since the epoch, as Day::index() counts them.
	 * @return string 'Y-m-d'.
	 */
	private static function key_at( int $index ): string {
		return gmdate( 'Y-m-d', $index * self::DAY );
	}

	/**
	 * Whether a row put money at risk.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return bool
	 */
	private static function is_staked( array $row ): bool {
		return 'pass' !== self::decision( $row ) && self::risk_bp( $row ) > 0;
	}

	/**
	 * Row accessor.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return string
	 */
	private static function day( array $row ): string {
		return isset( $row['day'] ) ? (string) $row['day'] : '';
	}

	/**
	 * Row accessor.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return string
	 */
	private static function decision( array $row ): string {
		return isset( $row['decision'] ) ? (string) $row['decision'] : '';
	}

	/**
	 * Row accessor.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return int
	 */
	private static function risk_bp( array $row ): int {
		return isset( $row['risk_bp'] ) ? (int) $row['risk_bp'] : 0;
	}

	/**
	 * Row accessor.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return int
	 */
	private static function pnl( array $row ): int {
		return isset( $row['pnl'] ) ? (int) $row['pnl'] : 0;
	}

	/**
	 * Row accessor.
	 *
	 * @param array<string,mixed> $row Run row.
	 * @return bool
	 */
	private static function died( array $row ): bool {
		return ! empty( $row['died'] );
	}
}
