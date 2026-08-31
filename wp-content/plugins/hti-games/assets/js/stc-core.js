/**
 * Survive the Charts — decision core (pure functions, no DOM).
 *
 * The mirror of includes/class-stc-engine.php. The PHP decides on the server;
 * this animates the same decision on the client, and the two have to reach the
 * same dollar, not the same neighbourhood. Everything is integer: prices are
 * ticks (price × TICK_SCALE), risk is basis points of capital, R is basis
 * points of the risk, money is whole dollars.
 *
 * Two rules keep the ports in step and both look like fussiness until they are
 * not: integer division truncates toward zero (idiv, never Math.floor, which
 * disagrees on negatives), and money rounds halves away from zero
 * (roundHalfAwayFromZero, never Math.round, which sends -0.5 to -0 while PHP
 * sends it to -1).
 *
 * tests/fixtures/parity.json is generated from this file and asserted by both
 * suites. Change the maths here without regenerating and the PHP goes red.
 *
 * Educational, illustrative, virtual money only: nothing here is advice.
 *
 * Works as a browser global (window.HTIGamesSTC) and as a CommonJS module.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	root.HTIGamesSTC = api;
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Mirrors includes/class-config.php. tests/fixtures/parity.json carries
	 * this object and the PHP suite asserts it against the Config constants,
	 * so the two tables cannot drift apart unnoticed.
	 */
	var CONFIG = {
		capital_start: 10000,
		capital_floor: 1000,
		tick_scale: 100000,
		visible: 80,
		outcome: 40,
		atr_period: 14,
		target_num: 3,
		target_den: 2,
		double: 2,
		risk_bp: [ 50, 100, 200, 500, 1000, 2500 ]
	};

	/** One hundred percent, in basis points. */
	var BP = 10000;

	/** A stop is a full R against the position. */
	var R_STOP = -BP;

	/** The most iterations lossesToRuin() will walk before giving up. */
	var RUIN_CAP = 100000;

	/**
	 * Integer division truncating toward zero — PHP's intdiv().
	 *
	 * Math.trunc( a / b ) is the whole answer for the magnitudes here, with
	 * one theoretical exception: a quotient whose true value sits a hair below
	 * an integer can round up to exactly that integer in a double, and trunc
	 * would then return one too many. The correction costs two comparisons and
	 * removes the need to reason about it ever again.
	 *
	 * @param {number} a Numerator.
	 * @param {number} b Denominator.
	 * @return {number}
	 */
	function idiv( a, b ) {
		var q = Math.trunc( a / b );
		if ( Math.abs( q * b ) > Math.abs( a ) ) {
			q += q > 0 ? -1 : 1;
		}
		return q;
	}

	/**
	 * Round to a whole dollar, halves away from zero.
	 *
	 * NOT Math.round(). Math.round(-0.5) is -0 and Math.round(-2.5) is -2,
	 * because it rounds halves toward +Infinity; PHP's round() sends both away
	 * from zero. A stop of exactly -$6.50 would be booked as -$7 by the server
	 * and shown as -$6 by this replay, and the player would watch the chart
	 * disagree with their balance. The parity fixture carries a negative half
	 * precisely so that anyone who "simplifies" this back finds out at once.
	 *
	 * @param {number} v Value to round.
	 * @return {number} Whole dollars, signed.
	 */
	function roundHalfAwayFromZero( v ) {
		return v < 0 ? -Math.floor( -v + 0.5 ) : Math.floor( v + 0.5 );
	}

	/**
	 * A target is 1.5R with the position, in basis points of R.
	 *
	 * Derived from the CONFIG fraction, not typed as 15000, so the level drawn
	 * on the chart and the payout in the ledger are the same 1.5.
	 *
	 * @return {number}
	 */
	function rTarget() {
		return idiv( BP * CONFIG.target_num, CONFIG.target_den );
	}

	/**
	 * Average true range over the last `period` candles, in ticks.
	 *
	 * The plain mean of the high-low ranges, not Wilder's smoothed ATR: a
	 * player can verify it by eye, and it needs no seed value, so neither port
	 * can start its recursion somewhere the other did not.
	 *
	 * @param {Array} ticks  Candles {o,h,l,c} in ticks; only the last `period` are read.
	 * @param {number} period Window length.
	 * @return {number} ATR in ticks, or 0 when there are not `period` candles.
	 */
	function atr( ticks, period ) {
		if ( ! ticks || period < 1 || ticks.length < period ) {
			return 0;
		}

		var window = ticks.slice( -period );
		var sum = 0;
		var i;

		for ( i = 0; i < window.length; i++ ) {
			sum += window[ i ].h - window[ i ].l;
		}

		return idiv( sum, period );
	}

	/**
	 * Where the trade dies and where it wins, in ticks.
	 *
	 * Stop one ATR against, target one and a half with: a 1.5 reward-to-risk
	 * that only needs to be right a bit over four times in ten to break even.
	 * Anything that is not 'sell' is read as a long — a position has two
	 * sides, and a pass has no levels.
	 *
	 * @param {number} entry     Entry price in ticks.
	 * @param {number} atrTicks  ATR in ticks.
	 * @param {string} direction 'buy' or 'sell'.
	 * @return {{stop:number,target:number}}
	 */
	function levels( entry, atrTicks, direction ) {
		var reach = idiv( atrTicks * CONFIG.target_num, CONFIG.target_den );

		if ( 'sell' === direction ) {
			return { stop: entry + atrTicks, target: entry - reach };
		}

		return { stop: entry - atrTicks, target: entry + reach };
	}

	/**
	 * The dollars a tier actually puts at risk — the "At risk −$X" figure.
	 *
	 * Truncated, so the stake is never a dollar more than the tier chosen.
	 *
	 * @param {number} capital    Capital before the decision.
	 * @param {number} riskBp     Risk tier in basis points.
	 * @param {number} multiplier 1 or CONFIG.double.
	 * @return {number} Whole dollars.
	 */
	function atRisk( capital, riskBp, multiplier ) {
		return idiv( capital * Math.max( 0, riskBp ), BP ) * multiplier;
	}

	/**
	 * What an R multiple is worth, in whole dollars.
	 *
	 * Two steps rather than one product, and the split matters twice over.
	 * Arithmetically, capital × riskBp × multiplier × rBp passes
	 * Number.MAX_SAFE_INTEGER once a compounding run reaches a capital around
	 * 1e8, where PHP's 64-bit integers stay exact and a double does not — the
	 * server would book one number and this replay would animate another,
	 * silently. Semantically, atRisk() is the very figure the tier screen
	 * showed the player, so a stop costs exactly what the button said.
	 *
	 * @param {number} capital    Capital before the decision.
	 * @param {number} riskBp     Risk tier in basis points.
	 * @param {number} multiplier 1 or CONFIG.double.
	 * @param {number} rBp        R multiple in basis points; -10000 is a full stop.
	 * @return {number} Whole dollars, signed.
	 */
	function cash( capital, riskBp, multiplier, rBp ) {
		return roundHalfAwayFromZero( ( atRisk( capital, riskBp, multiplier ) * rBp ) / BP );
	}

	/**
	 * Walk one side of the trade through the outcome window.
	 *
	 * @param {Array}  window     Outcome candles, already truncated.
	 * @param {number} entry      Entry price in ticks.
	 * @param {number} atrTicks   ATR in ticks.
	 * @param {string} direction  'buy' or 'sell'.
	 * @param {number} riskBp     Risk tier in basis points.
	 * @param {number} multiplier 1 or CONFIG.double.
	 * @param {number} capital    Capital before the decision.
	 * @return {Object} Leg, snake_case keys — this is the wire shape the PHP returns.
	 */
	function leg( window, entry, atrTicks, direction, riskBp, multiplier, capital ) {
		var lv = levels( entry, atrTicks, direction );
		var stop = lv.stop;
		var target = lv.target;
		var long = 'sell' !== direction;
		var i, high, low, stopHit, targetHit, last, move, rBp;

		// A flat window has no distance between entry and stop, so there is
		// nothing to risk and no denominator for R. Only a malformed scenario
		// gets here, and a free flat day is the one outcome that cannot invent
		// a loss the chart never showed.
		if ( atrTicks > 0 ) {
			for ( i = 0; i < window.length; i++ ) {
				high = window[ i ].h;
				low = window[ i ].l;

				stopHit = long ? low <= stop : high >= stop;
				targetHit = long ? high >= target : low <= target;

				// The order of these two statements IS the tie rule: a candle
				// whose range contains both levels resolves as a stop, because
				// nothing in an OHLC bar says which price came first. Reading
				// it pessimistically is pedagogically right — the game must
				// never flatter a position — and it ends every argument about
				// a chart the player is looking at.
				if ( stopHit ) {
					return {
						direction: long ? 'buy' : 'sell',
						stop: stop,
						target: target,
						outcome: 'stop',
						candle: i + 1,
						exit: stop,
						r_bp: R_STOP,
						pnl: cash( capital, riskBp, multiplier, R_STOP )
					};
				}

				if ( targetHit ) {
					return {
						direction: long ? 'buy' : 'sell',
						stop: stop,
						target: target,
						outcome: 'target',
						candle: i + 1,
						exit: target,
						r_bp: rTarget(),
						pnl: cash( capital, riskBp, multiplier, rTarget() )
					};
				}
			}
		}

		// Neither level inside the window: marked to the last close and paid
		// at whatever fraction of R it reached.
		last = window.length ? window[ window.length - 1 ].c : entry;
		move = ( last - entry ) * ( long ? 1 : -1 );
		rBp = atrTicks > 0 ? idiv( move * BP, atrTicks ) : 0;

		// Unreachable on well-formed candles — a close beyond a level implies
		// a high or low beyond it, which would have touched — so the clamp is
		// the guard against a broken scenario paying more than a win.
		rBp = Math.max( -rTarget(), Math.min( rTarget(), rBp ) );

		return {
			direction: long ? 'buy' : 'sell',
			stop: stop,
			target: target,
			outcome: 'open',
			candle: 0,
			exit: last,
			r_bp: rBp,
			pnl: cash( capital, riskBp, multiplier, rBp )
		};
	}

	/**
	 * Play a decision out against the hidden candles.
	 *
	 * The entry is the close of the last visible candle and the ATR is
	 * measured over the last CONFIG.atr_period candles the player could see:
	 * nobody is stopped out by a number they could not have computed
	 * themselves.
	 *
	 * A pass costs nothing and still reports both sides, because "you passed"
	 * is not a lesson and "a buy would have lost $200, a sell would have made
	 * $300" is.
	 *
	 * @param {Array}   visible   The candles the player saw.
	 * @param {Array}   after     The hidden candles; only the first CONFIG.outcome are walked.
	 * @param {string}  direction 'buy', 'sell' or 'pass'.
	 * @param {number}  riskBp    Risk tier in basis points.
	 * @param {boolean} double    Whether the double-stake multiplier is on.
	 * @param {number}  capital   Capital before the decision.
	 * @return {Object} Result, snake_case keys, identical to the PHP.
	 */
	function resolve( visible, after, direction, riskBp, double, capital ) {
		visible = visible || [];
		var window = ( after || [] ).slice( 0, CONFIG.outcome );
		var entry = visible.length ? visible[ visible.length - 1 ].c : 0;
		var atrTicks = atr( visible, CONFIG.atr_period );
		var multiplier = double ? CONFIG.double : 1;
		var side;

		// The direction arrives from the open web. The vocabulary is closed
		// and everything outside it becomes the decision that cannot cost the
		// player money.
		if ( 'buy' !== direction && 'sell' !== direction ) {
			return {
				direction: 'pass',
				risk_bp: riskBp,
				multiplier: multiplier,
				entry: entry,
				atr: atrTicks,
				stop: 0,
				target: 0,
				outcome: 'pass',
				candle: 0,
				exit: entry,
				r_bp: 0,
				pnl: 0,
				would: {
					buy: leg( window, entry, atrTicks, 'buy', riskBp, multiplier, capital ),
					sell: leg( window, entry, atrTicks, 'sell', riskBp, multiplier, capital )
				}
			};
		}

		side = leg( window, entry, atrTicks, direction, riskBp, multiplier, capital );

		return {
			direction: side.direction,
			risk_bp: riskBp,
			multiplier: multiplier,
			entry: entry,
			atr: atrTicks,
			stop: side.stop,
			target: side.target,
			outcome: side.outcome,
			candle: side.candle,
			exit: side.exit,
			r_bp: side.r_bp,
			pnl: side.pnl,
			would: null
		};
	}

	/**
	 * How much of the account is left, as 0..1 — the survival bar.
	 *
	 * The one function here allowed to return a fraction, because nothing
	 * decides anything on it.
	 *
	 * @param {number} capital Current capital in dollars.
	 * @return {number} 0 at the floor, 1 at the starting capital or above.
	 */
	function survival( capital ) {
		var span = CONFIG.capital_start - CONFIG.capital_floor;

		if ( span <= 0 ) {
			return 0;
		}

		return Math.max( 0, Math.min( 1, ( capital - CONFIG.capital_floor ) / span ) );
	}

	/**
	 * Book a P&L against the account, and say whether that killed it.
	 *
	 * Death is `<=` the floor, not `<`: landing exactly on $1,000 is a blown
	 * account. The reset hands back a fresh starting capital; keeping the
	 * record of the run that died is the caller's job, and the point of the
	 * whole mechanic.
	 *
	 * @param {number} capital Capital before the decision.
	 * @param {number} pnl     Result of the decision.
	 * @return {{capital:number,died:boolean}}
	 */
	function apply( capital, pnl ) {
		var closing = capital + pnl;

		if ( closing <= CONFIG.capital_floor ) {
			return { capital: CONFIG.capital_start, died: true };
		}

		return { capital: closing, died: false };
	}

	/**
	 * How many losses in a row it takes to blow the account, at a given tier.
	 *
	 * Compounding, not linear: each loss risks the same fraction of what is
	 * LEFT. The linear model — 90% of the account divided by the risk — says
	 * 45 losses at 2% and 9 at 10%; the truth is 114 and 22. The warning copy
	 * carries a placeholder and fills it from here, so the sentence on the
	 * screen and the maths in the engine are the same number by construction.
	 *
	 * A loop rather than a logarithm, so both ports multiply the same doubles
	 * in the same order and cannot round a boundary differently.
	 *
	 * @param {number}  riskBp  Risk tier in basis points.
	 * @param {boolean} double  Whether the double-stake multiplier is on.
	 * @param {number}  capital Starting capital (defaults to CONFIG.capital_start).
	 * @param {number}  floor   Ruin threshold (defaults to CONFIG.capital_floor).
	 * @return {number} Consecutive losses to ruin.
	 */
	function lossesToRuin( riskBp, double, capital, floor ) {
		var riskedBp = Math.max( 0, riskBp ) * ( double ? CONFIG.double : 1 );
		var start = 'number' === typeof capital ? capital : CONFIG.capital_start;
		var dead = 'number' === typeof floor ? floor : CONFIG.capital_floor;
		var keepBp, balance, losses;

		// Risking nothing never blows up; risking everything blows up on the
		// first loss. Neither should reach the loop.
		if ( riskedBp <= 0 ) {
			return 0;
		}
		if ( riskedBp >= BP ) {
			return 1;
		}

		keepBp = BP - riskedBp;
		balance = start;
		losses = 0;

		while ( balance > dead && losses < RUIN_CAP ) {
			balance = ( balance * keepBp ) / BP;
			losses++;
		}

		return losses;
	}

	return {
		CONFIG: CONFIG,
		BP: BP,
		R_STOP: R_STOP,
		rTarget: rTarget,
		idiv: idiv,
		roundHalfAwayFromZero: roundHalfAwayFromZero,
		atr: atr,
		levels: levels,
		atRisk: atRisk,
		cash: cash,
		resolve: resolve,
		survival: survival,
		apply: apply,
		lossesToRuin: lossesToRuin
	};
} ) );
