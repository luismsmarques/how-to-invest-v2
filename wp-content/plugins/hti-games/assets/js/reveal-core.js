/**
 * The Reveal — decision core (pure functions, no DOM).
 *
 * The mirror of includes/class-reveal-engine.php. Returns are basis points
 * (+182% is 18200, a wipeout is -10000), sizes are whole percents, money is
 * whole dollars, and nothing here opines on anything: it multiplies what a
 * real company really did by what the player committed, and puts the result
 * next to what doing nothing would have paid.
 *
 * roundHalfAwayFromZero() and idiv() are deliberate copies of the two in
 * stc-core.js rather than an import. Each core is one file the browser can
 * load on its own, and a shared dependency between two UMD bundles buys a
 * loading order to get wrong in exchange for eight lines. The copies are
 * asserted identical by the parity fixture.
 *
 * Educational, illustrative, virtual money only: nothing here is advice.
 *
 * Works as a browser global (window.HTIGamesReveal) and as a CommonJS module.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	root.HTIGamesReveal = api;
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Mirrors includes/class-config.php; locked against it by the PHP suite
	 * through tests/fixtures/parity.json.
	 */
	var CONFIG = {
		capital_start: 10000,
		capital_floor: 1000,
		index_step_bp: 1000,
		min_age_years: 5,
		sizes: [ 5, 10, 25, 50 ]
	};

	/** One hundred percent, in basis points, and in percent. */
	var BP = 10000;
	var PCT = 100;

	/**
	 * Integer division truncating toward zero — PHP's intdiv().
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
	 * NOT Math.round(), which sends -0.5 to -0 while PHP's round() sends it to
	 * -1. The server would book a dollar the screen never showed.
	 *
	 * @param {number} v Value to round.
	 * @return {number} Whole dollars, signed.
	 */
	function roundHalfAwayFromZero( v ) {
		return v < 0 ? -Math.floor( -v + 0.5 ) : Math.floor( v + 0.5 );
	}

	/**
	 * The dollars a decision actually puts on the table.
	 *
	 * Truncated, so a commitment is never a dollar more than the share chosen.
	 *
	 * @param {number} capital Capital before the decision.
	 * @param {number} sizePct Share of the account committed, in percent.
	 * @return {number} Whole dollars.
	 */
	function committed( capital, sizePct ) {
		return idiv( capital * Math.max( 0, sizePct ), PCT );
	}

	/**
	 * What a decision made or lost, in whole dollars.
	 *
	 * Applied to committed() rather than formed as one product: the single
	 * product needs an intermediate a compounding account eventually pushes
	 * past Number.MAX_SAFE_INTEGER, where PHP stays exact and this does not,
	 * and the return should land on exactly the figure the screen told the
	 * player they were committing.
	 *
	 * @param {number} capital Capital before the decision.
	 * @param {number} sizePct Share of the account committed, in percent.
	 * @param {number} rBp     The case's real five-year return, in basis points.
	 * @return {number} Whole dollars, signed.
	 */
	function pnl( capital, sizePct, rBp ) {
		return roundHalfAwayFromZero( ( committed( capital, sizePct ) * rBp ) / BP );
	}

	/**
	 * The dollars one index step moved, signed.
	 *
	 * @param {number} indexCap Index capital before the step.
	 * @param {number} rIdxBp   The index's return over the case's period, in basis points.
	 * @return {number} Whole dollars, signed.
	 */
	function indexPnl( indexCap, rIdxBp ) {
		var exposure = idiv( indexCap * CONFIG.index_step_bp, BP );

		return roundHalfAwayFromZero( ( exposure * rIdxBp ) / BP );
	}

	/**
	 * Compound the index player's capital by one case.
	 *
	 * The index player's entire strategy is doing nothing, and the point of
	 * carrying their balance alongside is that it is usually winning. One case
	 * advances them by a tenth of the index's return over the same period,
	 * because one dossier is one decision out of a life of them, not five
	 * years of the player's own money.
	 *
	 * Only the step is rounded, never the running balance, so a hundred days
	 * of compounding do not accumulate a hundred half-dollar errors.
	 *
	 * @param {number} indexCap Index capital before the step.
	 * @param {number} rIdxBp   The index's return, in basis points.
	 * @return {number} Index capital after the step.
	 */
	function indexStep( indexCap, rIdxBp ) {
		return indexCap + indexPnl( indexCap, rIdxBp );
	}

	/**
	 * The three lines the result screen is built around.
	 *
	 * What you did, what doing nothing would have done, and what the index
	 * did. The middle line is always zero and is shown anyway: without it a
	 * loss reads as bad luck instead of a decision, and a gain as skill
	 * instead of a market that went up for everybody.
	 *
	 * Keys, not sentences — the wording is bilingual and lives in PHP.
	 *
	 * @param {number} playerPnl What the player's decision made or lost.
	 * @param {number} idxPnl    What the index did over the same period.
	 * @return {Array}
	 */
	function threeLines( playerPnl, idxPnl ) {
		return [
			{ key: 'you', pnl: playerPnl },
			{ key: 'pass', pnl: 0 },
			{ key: 'index', pnl: idxPnl }
		];
	}

	/**
	 * Score one dossier.
	 *
	 * A pass costs nothing and is a real answer: "I could not tell from this"
	 * is the correct response to most dossiers, and the game says so by
	 * putting the pass line next to the other two rather than hiding it. The
	 * index advances whatever the player did — the money that stayed out still
	 * had a year, so a pass is not compared against zero.
	 *
	 * Death is deliberately not decided here: the floor is one rule for both
	 * games and it lives in HTIGamesSTC.apply(), which the caller runs on the
	 * capital this returns.
	 *
	 * @param {number} rBp      The case's real five-year return, in basis points.
	 * @param {number} rIdxBp   The index's return over the same period, in basis points.
	 * @param {string} decision 'invest' or 'pass'; anything else is read as a pass.
	 * @param {number} sizePct  Share of the account committed, in percent.
	 * @param {number} capital  Capital before the decision.
	 * @param {number} indexCap Index capital before the decision.
	 * @return {Object} Result, snake_case keys, identical to the PHP.
	 */
	function resolve( rBp, rIdxBp, decision, sizePct, capital, indexCap ) {
		// A decision arriving from the open web with no size behind it is a
		// pass whatever it calls itself.
		var invest = 'pass' !== decision && sizePct > 0;
		var size = invest ? sizePct : 0;
		var playerPnl = invest ? pnl( capital, size, rBp ) : 0;
		var idxPnl = indexPnl( indexCap, rIdxBp );

		return {
			decision: invest ? 'invest' : 'pass',
			size_pct: size,
			committed: invest ? committed( capital, size ) : 0,
			r_bp: rBp,
			r_idx_bp: rIdxBp,
			pnl: playerPnl,
			capital: capital + playerPnl,
			index_pnl: idxPnl,
			index_cap: indexCap + idxPnl,
			lines: threeLines( playerPnl, idxPnl )
		};
	}

	return {
		CONFIG: CONFIG,
		BP: BP,
		PCT: PCT,
		idiv: idiv,
		roundHalfAwayFromZero: roundHalfAwayFromZero,
		committed: committed,
		pnl: pnl,
		indexPnl: indexPnl,
		indexStep: indexStep,
		threeLines: threeLines,
		resolve: resolve
	};
} ) );
