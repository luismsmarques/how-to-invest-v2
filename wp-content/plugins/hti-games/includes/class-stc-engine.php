<?php
/**
 * Survive the Charts: the arithmetic that resolves a day.
 *
 * Pure — no WordPress, no state, no I/O — because this engine exists twice:
 * here, where it decides on the server, and in assets/js/stc-core.js, where it
 * animates on the client. The verdict the server records and the replay the
 * player watches have to be the same verdict, not the same verdict to within
 * an epsilon. That is why every step below is integer arithmetic: prices are
 * ticks (price × Config::TICK_SCALE), risk is basis points of the current
 * capital, R is basis points of the risk, and money is whole dollars.
 *
 * The only division that leaves the integers is the last one, where a P&L
 * becomes dollars — and even that is rounded by a helper both languages ship
 * identically, because JavaScript's Math.round() sends -0.5 to -0 while PHP's
 * round() sends it to -1. See round_half_away_from_zero().
 *
 * tests/fixtures/parity.json is the contract between the two ports: the JS
 * writes it, both suites assert against it, and changing the maths on one side
 * without regenerating turns the other side red.
 *
 * A candle is array{o:int,h:int,l:int,c:int}, in ticks.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The chart game's decision arithmetic. Pure.
 */
class STC_Engine {

	/**
	 * One hundred percent, in basis points.
	 *
	 * The risk tier and the R multiple are both carried in basis points, so a
	 * payout divides by this once on the way to the dollars at stake and once
	 * on the way from there to the result.
	 */
	public const BP = 10000;

	/**
	 * A stop is a full R against the position: -1.0R, in basis points.
	 */
	public const R_STOP = -self::BP;

	/**
	 * The most iterations losses_to_ruin() will walk before giving up.
	 *
	 * The offered tiers need at most 460, but the argument is an int and a
	 * one-basis-point tier would spin twenty thousand times; the cap turns a
	 * pathological input into a wrong number instead of a hung request.
	 */
	private const RUIN_CAP = 100000;

	/**
	 * A target is 1.5R with the position, in basis points of R.
	 *
	 * Derived from the Config fraction rather than typed as 15000, so the
	 * level on the chart and the payout in the ledger are the same 1.5 and
	 * cannot drift apart. A method rather than a constant because deriving it
	 * needs intdiv(), and a constant expression would have to divide in
	 * floating point to get there.
	 *
	 * @return int
	 */
	public static function r_target(): int {
		return intdiv( self::BP * Config::STC_TARGET_NUM, Config::STC_TARGET_DEN );
	}

	/**
	 * Average true range over the last $period candles, in ticks.
	 *
	 * The plain mean of the high-low ranges, not Wilder's smoothed ATR. Two
	 * reasons: a player can verify it by eye on the fourteen candles in front
	 * of them, and it needs no seed value, so the JavaScript port cannot start
	 * its recursion from a different place than the PHP one.
	 *
	 * The division truncates toward zero (intdiv here, Math.trunc there —
	 * never floor), so the two ports cannot disagree over a remainder.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $ticks  Candles; only the last $period are read.
	 * @param int                                       $period Window length.
	 * @return int ATR in ticks, or 0 when there are not $period candles to read.
	 */
	public static function atr( array $ticks, int $period ): int {
		$ticks = array_values( $ticks );

		if ( $period < 1 || count( $ticks ) < $period ) {
			return 0;
		}

		$sum = 0;
		foreach ( array_slice( $ticks, -$period ) as $candle ) {
			$sum += (int) $candle['h'] - (int) $candle['l'];
		}

		return intdiv( $sum, $period );
	}

	/**
	 * Where the trade dies and where it wins, in ticks.
	 *
	 * Stop is one ATR against the position and target is one and a half with
	 * it: a 1.5 reward-to-risk that only has to be right a bit more than four
	 * times in ten to break even. The asymmetry is the point of the lesson —
	 * the player who survives is not the one who is right more often.
	 *
	 * Anything that is not 'sell' is read as a long, because a position has
	 * exactly two sides. A pass has no levels and never reaches here.
	 *
	 * @param int    $entry     Entry price in ticks.
	 * @param int    $atr       ATR in ticks.
	 * @param string $direction 'buy' or 'sell'.
	 * @return array{stop:int,target:int}
	 */
	public static function levels( int $entry, int $atr, string $direction ): array {
		$reach = intdiv( $atr * Config::STC_TARGET_NUM, Config::STC_TARGET_DEN );

		if ( 'sell' === $direction ) {
			return array(
				'stop'   => $entry + $atr,
				'target' => $entry - $reach,
			);
		}

		return array(
			'stop'   => $entry - $atr,
			'target' => $entry + $reach,
		);
	}

	/**
	 * Play a decision out against the hidden candles.
	 *
	 * The entry is the close of the last visible candle — the price the player
	 * was looking at when they chose — and the ATR is measured over the last
	 * Config::STC_ATR_PERIOD candles they could see. Nothing in the outcome
	 * window feeds a level; the player is never stopped out by a number they
	 * could not have computed themselves.
	 *
	 * A pass costs nothing and still reports both sides, because "you passed"
	 * is not a lesson and "a buy here would have lost $200 and a sell would
	 * have made $300" is. Reporting both rather than picking one avoids
	 * inventing a direction the player never chose.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $visible   The candles the player saw.
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $after     The hidden candles; only the first Config::STC_OUTCOME are walked.
	 * @param string                                    $direction 'buy', 'sell' or 'pass'; anything else is read as a pass.
	 * @param int                                       $risk_bp   Risk tier in basis points of capital.
	 * @param bool                                      $double    Whether the double-stake multiplier is on.
	 * @param int                                       $capital   Capital before the decision, in dollars.
	 * @return array{direction:string,risk_bp:int,multiplier:int,entry:int,atr:int,stop:int,target:int,outcome:string,candle:int,exit:int,r_bp:int,pnl:int,would:?array}
	 */
	public static function resolve( array $visible, array $after, string $direction, int $risk_bp, bool $double, int $capital ): array {
		$visible    = array_values( $visible );
		$window     = array_slice( array_values( $after ), 0, Config::STC_OUTCOME );
		$entry      = $visible ? (int) $visible[ count( $visible ) - 1 ]['c'] : 0;
		$atr        = self::atr( $visible, Config::STC_ATR_PERIOD );
		$multiplier = $double ? Config::STC_DOUBLE : 1;

		// The direction arrives from the open web. The vocabulary is closed
		// and everything outside it becomes the decision that cannot cost the
		// player money; membership is still checked again at the REST layer.
		if ( 'buy' !== $direction && 'sell' !== $direction ) {
			return array(
				'direction'  => 'pass',
				'risk_bp'    => $risk_bp,
				'multiplier' => $multiplier,
				'entry'      => $entry,
				'atr'        => $atr,
				'stop'       => 0,
				'target'     => 0,
				'outcome'    => 'pass',
				'candle'     => 0,
				'exit'       => $entry,
				'r_bp'       => 0,
				'pnl'        => 0,
				'would'      => array(
					'buy'  => self::leg( $window, $entry, $atr, 'buy', $risk_bp, $multiplier, $capital ),
					'sell' => self::leg( $window, $entry, $atr, 'sell', $risk_bp, $multiplier, $capital ),
				),
			);
		}

		$leg = self::leg( $window, $entry, $atr, $direction, $risk_bp, $multiplier, $capital );

		return array(
			'direction'  => $leg['direction'],
			'risk_bp'    => $risk_bp,
			'multiplier' => $multiplier,
			'entry'      => $entry,
			'atr'        => $atr,
			'stop'       => $leg['stop'],
			'target'     => $leg['target'],
			'outcome'    => $leg['outcome'],
			'candle'     => $leg['candle'],
			'exit'       => $leg['exit'],
			'r_bp'       => $leg['r_bp'],
			'pnl'        => $leg['pnl'],
			'would'      => null,
		);
	}

	/**
	 * Walk one side of the trade through the outcome window.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $window     Outcome candles, already truncated.
	 * @param int                                       $entry      Entry price in ticks.
	 * @param int                                       $atr        ATR in ticks.
	 * @param string                                    $direction  'buy' or 'sell'.
	 * @param int                                       $risk_bp    Risk tier in basis points.
	 * @param int                                       $multiplier Stake multiplier, 1 or Config::STC_DOUBLE.
	 * @param int                                       $capital    Capital before the decision.
	 * @return array{direction:string,stop:int,target:int,outcome:string,candle:int,exit:int,r_bp:int,pnl:int}
	 */
	private static function leg( array $window, int $entry, int $atr, string $direction, int $risk_bp, int $multiplier, int $capital ): array {
		$levels = self::levels( $entry, $atr, $direction );
		$stop   = $levels['stop'];
		$target = $levels['target'];
		$long   = 'sell' !== $direction;

		$leg = array(
			'direction' => $long ? 'buy' : 'sell',
			'stop'      => $stop,
			'target'    => $target,
		);

		// A flat window has no distance between entry and stop, so there is
		// nothing to risk and no denominator for R. It only happens on a
		// malformed scenario, and resolving it as a free, flat day is the one
		// outcome that cannot invent a loss the chart never showed.
		if ( $atr > 0 ) {
			$count = count( $window );

			for ( $i = 0; $i < $count; $i++ ) {
				$high = (int) $window[ $i ]['h'];
				$low  = (int) $window[ $i ]['l'];

				$stop_hit   = $long ? $low <= $stop : $high >= $stop;
				$target_hit = $long ? $high >= $target : $low <= $target;

				// The order of these two statements IS the tie rule: a candle
				// whose range contains both levels resolves as a stop, because
				// nothing in an OHLC bar says which price came first. Reading
				// it pessimistically is pedagogically right — the game must
				// never flatter a position — and it ends every argument about
				// a chart the player is looking at, which the generous reading
				// would not.
				if ( $stop_hit ) {
					return $leg + array(
						'outcome' => 'stop',
						'candle'  => $i + 1,
						'exit'    => $stop,
						'r_bp'    => self::R_STOP,
						'pnl'     => self::cash( $capital, $risk_bp, $multiplier, self::R_STOP ),
					);
				}

				if ( $target_hit ) {
					return $leg + array(
						'outcome' => 'target',
						'candle'  => $i + 1,
						'exit'    => $target,
						'r_bp'    => self::r_target(),
						'pnl'     => self::cash( $capital, $risk_bp, $multiplier, self::r_target() ),
					);
				}
			}
		}

		// Neither level inside the window: the position is marked to the last
		// close and paid at whatever fraction of R it reached.
		$last = $window ? (int) $window[ count( $window ) - 1 ]['c'] : $entry;
		$move = ( $last - $entry ) * ( $long ? 1 : -1 );
		$r_bp = $atr > 0 ? intdiv( $move * self::BP, $atr ) : 0;

		// The clamp is unreachable on well-formed candles — a close beyond a
		// level implies a high or low beyond it, which would have touched —
		// so it is here as the guard against a broken scenario paying more
		// than a winning trade ever could.
		$r_bp = max( -self::r_target(), min( self::r_target(), $r_bp ) );

		return $leg + array(
			'outcome' => 'open',
			'candle'  => 0,
			'exit'    => $last,
			'r_bp'    => $r_bp,
			'pnl'     => self::cash( $capital, $risk_bp, $multiplier, $r_bp ),
		);
	}

	/**
	 * The dollars a tier actually puts at risk — the "At risk −$X" figure.
	 *
	 * Truncating rather than rounding, so the amount at stake is never a cent
	 * more than the tier the player chose, and exact in both languages
	 * (intdiv here, Math.trunc there).
	 *
	 * A negative tier would turn a loss into a gain, so it floors at zero
	 * rather than trusting whatever arrived.
	 *
	 * @param int $capital    Capital before the decision, in dollars.
	 * @param int $risk_bp    Risk tier in basis points of capital.
	 * @param int $multiplier Stake multiplier, 1 or Config::STC_DOUBLE.
	 * @return int Whole dollars at risk.
	 */
	public static function at_risk( int $capital, int $risk_bp, int $multiplier ): int {
		return intdiv( $capital * max( 0, $risk_bp ), self::BP ) * $multiplier;
	}

	/**
	 * What an R multiple is worth, in whole dollars.
	 *
	 * Two steps, not one, and the split is load-bearing in both directions.
	 *
	 * Arithmetically: forming capital × risk_bp × multiplier × r_bp before
	 * dividing needs an intermediate near 7.5e15 once a run compounds its way
	 * to a capital around 1e8 — which a fortnight of maximum-tier wins does.
	 * PHP's 64-bit integers stay exact there; a JavaScript double does not,
	 * past Number.MAX_SAFE_INTEGER. The server would book one number and the
	 * replay would animate another, silently, in the one region the fixture
	 * does not sample. Splitting caps the intermediate at capital × 2 × 15000,
	 * which is still exact at a capital of a billion.
	 *
	 * Semantically: at_risk() is precisely the "At risk −$X" figure the tier
	 * screen already showed the player before they committed. The engine now
	 * pays out on the number the interface promised rather than on an
	 * algebraically equivalent one, so a stop is exactly the amount the button
	 * said it would be — not that amount plus or minus a rounding.
	 *
	 * @param int $capital    Capital before the decision, in dollars.
	 * @param int $risk_bp    Risk tier in basis points of capital.
	 * @param int $multiplier Stake multiplier, 1 or Config::STC_DOUBLE.
	 * @param int $r_bp       R multiple in basis points; -10000 is a full stop.
	 * @return int Whole dollars, signed.
	 */
	public static function cash( int $capital, int $risk_bp, int $multiplier, int $r_bp ): int {
		return self::round_half_away_from_zero( ( self::at_risk( $capital, $risk_bp, $multiplier ) * $r_bp ) / self::BP );
	}

	/**
	 * How much of the account is left, as 0..1.
	 *
	 * Display only — the survival bar on the result screen — which is why it
	 * is the one function here allowed to return a float. Nothing decides
	 * anything on it.
	 *
	 * @param int $capital Current capital in dollars.
	 * @return float 0 at the floor, 1 at the starting capital or above.
	 */
	public static function survival( int $capital ): float {
		$span = Config::CAPITAL_START - Config::CAPITAL_FLOOR;

		if ( $span <= 0 ) {
			return 0.0;
		}

		return max( 0.0, min( 1.0, ( $capital - Config::CAPITAL_FLOOR ) / $span ) );
	}

	/**
	 * Book a P&L against the account, and say whether that killed it.
	 *
	 * Death is `<=` the floor, not `<`: landing exactly on $1,000 is a blown
	 * account. The reset hands back a fresh Config::CAPITAL_START so the next
	 * day is playable; keeping the record of the run that died is the caller's
	 * job, and the point of the whole mechanic — a run nobody remembers taught
	 * nothing.
	 *
	 * @param int $capital Capital before the decision, in dollars.
	 * @param int $pnl     Result of the decision, in dollars.
	 * @return array{capital:int,died:bool} Capital to carry forward.
	 */
	public static function apply( int $capital, int $pnl ): array {
		$closing = $capital + $pnl;

		if ( $closing <= Config::CAPITAL_FLOOR ) {
			return array(
				'capital' => Config::CAPITAL_START,
				'died'    => true,
			);
		}

		return array(
			'capital' => $closing,
			'died'    => false,
		);
	}

	/**
	 * How many losses in a row it takes to blow the account, at a given tier.
	 *
	 * Compounding, not linear: each loss risks the same fraction of what is
	 * LEFT, so the account decays geometrically and never quite reaches zero.
	 * The answer is the smallest n where capital × (1 − r)^n <= floor, with
	 * r = risk_bp × multiplier / 10000.
	 *
	 * This exists because the obvious model is wrong in the direction that
	 * flatters the player. Dividing the 90% of the account that is losable by
	 * the risk percentage — the linear model — says 45 losses at 2% and 9 at
	 * 10%. The truth is 114 and 22. A game whose entire subject is risk
	 * arithmetic cannot ship warning copy that is a factor of four out, so the
	 * warnings carry a placeholder and fill it from here: the sentence on the
	 * screen and the maths in the engine are the same number by construction.
	 *
	 * For the six offered tiers this returns 460 / 230 / 114 / 45 / 22 / 9,
	 * and the pair worth noticing is 25% → 9 against 10% → 22: doubling the
	 * risk does not halve the runway, it quarters it.
	 *
	 * The loop is deliberate rather than a logarithm: ceil( ln(floor/capital)
	 * / ln(1-r) ) is the same answer until a tier lands exactly on the floor,
	 * where the two ports would round the boundary differently. Multiplying
	 * step by step in a fixed order gives the PHP and the JavaScript the same
	 * doubles in the same sequence, which is the only kind of agreement this
	 * codebase accepts.
	 *
	 * @param int  $risk_bp Risk tier in basis points of capital.
	 * @param bool $double  Whether the double-stake multiplier is on.
	 * @param int  $capital Starting capital in dollars.
	 * @param int  $floor   The balance at or below which the account is blown.
	 * @return int Consecutive losses to ruin; 0 when nothing is at risk or the account is already at the floor, 1 when a single loss takes everything.
	 */
	public static function losses_to_ruin( int $risk_bp, bool $double = false, int $capital = Config::CAPITAL_START, int $floor = Config::CAPITAL_FLOOR ): int {
		$risked_bp = max( 0, $risk_bp ) * ( $double ? Config::STC_DOUBLE : 1 );

		// Risking nothing never blows up, and risking everything blows up on
		// the first loss. Both are degenerate and neither should reach the
		// loop, where they would spin forever or divide the runway by zero.
		if ( $risked_bp <= 0 ) {
			return 0;
		}
		if ( $risked_bp >= self::BP ) {
			return 1;
		}

		$keep_bp = self::BP - $risked_bp;
		$balance = (float) $capital;
		$losses  = 0;

		while ( $balance > $floor && $losses < self::RUIN_CAP ) {
			$balance = $balance * $keep_bp / self::BP;
			++$losses;
		}

		return $losses;
	}

	/**
	 * Round to a whole dollar, halves away from zero, in both languages.
	 *
	 * PHP's round() already does this and JavaScript's Math.round() does not:
	 * Math.round(-0.5) is -0 and Math.round(-2.5) is -2, because it rounds
	 * halves toward +Infinity. A stop of exactly -$20.50 would therefore be
	 * booked as -$21 by the server and -$20 by the replay, and the player
	 * would watch a chart disagree with their balance by a dollar.
	 *
	 * So neither port uses its language's default. Both use this, and
	 * tests/fixtures/parity.json carries a negative half specifically so that
	 * anyone who "simplifies" it back to Math.round() finds out immediately.
	 *
	 * @param float $v Value to round.
	 * @return int
	 */
	public static function round_half_away_from_zero( float $v ): int {
		return (int) ( $v < 0 ? -floor( -$v + 0.5 ) : floor( $v + 0.5 ) );
	}
}
