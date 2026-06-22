/**
 * HowToInvest — tools math core (pure functions, no DOM).
 *
 * Educational, illustrative only: every rate is hypothetical and nothing here
 * is advice. Monthly compounding; contributions treated as end-of-month.
 *
 * Works as a browser global (window.HTITools) and as a CommonJS module (tests).
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	root.HTITools = api;
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function months( years ) {
		return Math.max( 0, Math.round( years * 12 ) );
	}

	/**
	 * Future value of an initial amount plus fixed monthly contributions.
	 *
	 * @param {number} initial       Starting amount.
	 * @param {number} monthly       Monthly contribution.
	 * @param {number} annualRatePct Annual return, in percent.
	 * @param {number} years         Number of years.
	 * @return {number}
	 */
	function futureValue( initial, monthly, annualRatePct, years ) {
		var i = annualRatePct / 100 / 12;
		var n = months( years );
		var fromInitial = initial * Math.pow( 1 + i, n );
		var fromMonthly = 0 === i ? monthly * n : monthly * ( ( Math.pow( 1 + i, n ) - 1 ) / i );
		return fromInitial + fromMonthly;
	}

	/**
	 * Total amount actually paid in (no growth).
	 */
	function totalContributed( initial, monthly, years ) {
		return initial + monthly * months( years );
	}

	/**
	 * Monthly contribution needed to reach a goal.
	 *
	 * @param {number} goal          Target amount.
	 * @param {number} initial       Starting amount.
	 * @param {number} annualRatePct Annual return, in percent.
	 * @param {number} years         Number of years.
	 * @return {number} Required monthly contribution (>= 0).
	 */
	function requiredMonthly( goal, initial, annualRatePct, years ) {
		var i = annualRatePct / 100 / 12;
		var n = months( years );
		if ( 0 === n ) {
			return 0;
		}
		var fromInitial = initial * Math.pow( 1 + i, n );
		var factor = 0 === i ? n : ( ( Math.pow( 1 + i, n ) - 1 ) / i );
		if ( factor <= 0 ) {
			return 0;
		}
		return Math.max( 0, ( goal - fromInitial ) / factor );
	}

	/**
	 * What an amount today will be worth (in today's money) after N years.
	 */
	function purchasingPower( amount, inflationPct, years ) {
		return amount / Math.pow( 1 + inflationPct / 100, years );
	}

	/**
	 * How much you would need in N years to match an amount's purchasing power.
	 */
	function amountToMatch( amount, inflationPct, years ) {
		return amount * Math.pow( 1 + inflationPct / 100, years );
	}

	/**
	 * The cost of delaying: future value started now vs after a delay, both
	 * measured at the same end year.
	 */
	function costOfWaiting( monthly, annualRatePct, years, delayYears ) {
		var now = futureValue( 0, monthly, annualRatePct, years );
		var delayed = futureValue( 0, monthly, annualRatePct, Math.max( 0, years - delayYears ) );
		return { now: now, delayed: delayed, cost: Math.max( 0, now - delayed ) };
	}

	/**
	 * Emergency-fund target and time to reach it.
	 *
	 * @param {number} monthlyExpenses Essential spending per month.
	 * @param {number} monthsCover     Months of expenses to hold.
	 * @param {number} currentSaved    Amount already set aside.
	 * @param {number} monthlySaving   Amount saved toward it each month.
	 * @return {{target:number,gap:number,monthsToReach:number}}
	 */
	function emergencyFund( monthlyExpenses, monthsCover, currentSaved, monthlySaving ) {
		var target = Math.max( 0, monthlyExpenses ) * Math.max( 0, monthsCover );
		var gap = Math.max( 0, target - currentSaved );
		var monthsToReach = 0;
		if ( gap > 0 ) {
			monthsToReach = monthlySaving > 0 ? Math.ceil( gap / monthlySaving ) : Infinity;
		}
		return { target: target, gap: gap, monthsToReach: monthsToReach };
	}

	/**
	 * Rule of 72: years to double, how many times money doubles over a span, and
	 * the resulting multiple (consistent with the rule's doublings).
	 *
	 * @param {number} annualRatePct Annual return, in percent.
	 * @param {number} years         Number of years to project.
	 * @return {{yearsToDouble:number,doublings:number,multiple:number}}
	 */
	function rule72( annualRatePct, years ) {
		var yearsToDouble = annualRatePct > 0 ? 72 / annualRatePct : Infinity;
		var doublings = isFinite( yearsToDouble ) && yearsToDouble > 0 ? years / yearsToDouble : 0;
		var multiple = Math.pow( 2, doublings );
		return { yearsToDouble: yearsToDouble, doublings: doublings, multiple: multiple };
	}

	/**
	 * Impact of an annual fee: the fee is modelled as a drag on the gross return.
	 *
	 * @param {number} initial      Starting amount.
	 * @param {number} monthly      Monthly contribution.
	 * @param {number} grossRatePct Gross annual return, in percent.
	 * @param {number} feePct       Annual fee, in percent.
	 * @param {number} years        Number of years.
	 * @return {{gross:number,net:number,lost:number}}
	 */
	function feeImpact( initial, monthly, grossRatePct, feePct, years ) {
		var gross = futureValue( initial, monthly, grossRatePct, years );
		var net = futureValue( initial, monthly, Math.max( 0, grossRatePct - feePct ), years );
		return { gross: gross, net: net, lost: Math.max( 0, gross - net ) };
	}

	/**
	 * Year-by-year series (year 0..years) of contributed vs portfolio value.
	 *
	 * @return {Array<{year:number,contributed:number,value:number}>}
	 */
	function series( initial, monthly, annualRatePct, years ) {
		var out = [];
		var whole = Math.max( 0, Math.floor( years ) );
		for ( var y = 0; y <= whole; y++ ) {
			out.push( {
				year: y,
				contributed: totalContributed( initial, monthly, y ),
				value: futureValue( initial, monthly, annualRatePct, y ),
			} );
		}
		return out;
	}

	return {
		futureValue: futureValue,
		totalContributed: totalContributed,
		requiredMonthly: requiredMonthly,
		purchasingPower: purchasingPower,
		amountToMatch: amountToMatch,
		costOfWaiting: costOfWaiting,
		emergencyFund: emergencyFund,
		rule72: rule72,
		feeImpact: feeImpact,
		series: series,
	};
} ) );
