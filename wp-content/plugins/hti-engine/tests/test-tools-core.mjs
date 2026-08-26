/**
 * Node test for the tools math core. Run: node tests/test-tools-core.mjs
 */
import { createRequire } from 'module';
const require = createRequire( import.meta.url );
const T = require( '../assets/js/tools-core.js' );

let pass = 0,
	fail = 0;
function ok( cond, msg ) {
	if ( cond ) {
		pass++;
	} else {
		fail++;
		console.log( '  FAIL: ' + msg );
	}
}
function near( a, b, eps, msg ) {
	ok( Math.abs( a - b ) <= ( eps || 0.5 ), msg + ' (got ' + a.toFixed( 2 ) + ', want ~' + b + ')' );
}

// Zero rate: pure sum.
near( T.futureValue( 1000, 100, 0, 10 ), 1000 + 100 * 120, 1e-6, 'zero-rate future value = sum' );
near( T.totalContributed( 1000, 100, 10 ), 13000, 1e-6, 'total contributed' );

// 100/month, 7%/yr, 30y → classic compound-interest result ~ €121,997.
near( T.futureValue( 0, 100, 7, 30 ), 121997, 1500, '100/mo 7% 30y future value' );

// Future value grows with rate.
ok( T.futureValue( 0, 100, 7, 30 ) > T.futureValue( 0, 100, 3, 30 ), 'higher rate → higher FV' );

// Required monthly: if I need exactly the contributions at 0% → goal/months.
near( T.requiredMonthly( 12000, 0, 0, 10 ), 100, 1e-6, 'required monthly at 0% = goal/months' );
// With growth, required monthly is lower than the zero-rate one.
ok( T.requiredMonthly( 100000, 0, 7, 20 ) < T.requiredMonthly( 100000, 0, 0, 20 ), 'growth lowers required monthly' );
// Reaching the required monthly hits the goal.
const need = T.requiredMonthly( 100000, 0, 6, 20 );
near( T.futureValue( 0, need, 6, 20 ), 100000, 5, 'required monthly reaches the goal' );

// Inflation: €1000 at 3% for 20y loses ~45% of purchasing power (~€553).
near( T.purchasingPower( 1000, 3, 20 ), 553.68, 1, 'purchasing power erosion' );
near( T.amountToMatch( 1000, 3, 20 ), 1806.11, 1, 'amount to match purchasing power' );
ok( Math.abs( T.purchasingPower( 1000, 3, 20 ) * T.amountToMatch( 1, 3, 20 ) - 1000 ) < 1e-6, 'inflation funcs are inverses' );

// Cost of waiting: delaying always costs (>= 0) and now > delayed.
const cw = T.costOfWaiting( 200, 7, 30, 10 );
ok( cw.now > cw.delayed && cw.cost > 0, 'waiting has a positive cost' );
near( cw.cost, cw.now - cw.delayed, 1e-6, 'cost = now - delayed' );

// Series spans year 0..years and ends at the full future value.
const s = T.series( 1000, 100, 5, 10 );
ok( s.length === 11 && s[ 0 ].year === 0, 'series covers year 0..10' );
near( s[ 10 ].value, T.futureValue( 1000, 100, 5, 10 ), 1e-6, 'series last value = future value' );

// Emergency fund: target = expenses × months; gap nets out current savings.
const ef = T.emergencyFund( 1500, 6, 2000, 200 );
near( ef.target, 9000, 1e-6, 'emergency target = 1500 × 6' );
near( ef.gap, 7000, 1e-6, 'emergency gap = target − saved' );
near( ef.monthsToReach, 35, 1e-6, 'emergency months = ceil(gap / monthly)' );
ok( T.emergencyFund( 1000, 3, 5000, 100 ).gap === 0, 'fully funded → no gap' );
ok( T.emergencyFund( 1000, 3, 5000, 100 ).monthsToReach === 0, 'fully funded → 0 months' );
ok( ! isFinite( T.emergencyFund( 1500, 6, 0, 0 ).monthsToReach ), 'no monthly saving → unreachable' );

// Rule of 72: years to double = 72 / rate; multiple = 2^doublings.
near( T.rule72( 6, 24 ).yearsToDouble, 12, 1e-6, 'rule of 72 at 6% doubles in 12y' );
near( T.rule72( 6, 24 ).doublings, 2, 1e-6, 'doublings over 24y at 6% = 2' );
near( T.rule72( 6, 24 ).multiple, 4, 1e-6, 'multiple = 2^2 = 4' );
ok( ! isFinite( T.rule72( 0, 24 ).yearsToDouble ), 'zero rate never doubles' );

// Fee impact: a yearly fee always lowers the net result vs gross.
const fi = T.feeImpact( 10000, 200, 6, 1, 25 );
ok( fi.gross > fi.net && fi.lost > 0, 'fee lowers the net value' );
near( fi.lost, fi.gross - fi.net, 1e-6, 'lost = gross − net' );
near( T.feeImpact( 10000, 200, 6, 0, 25 ).lost, 0, 1e-6, 'zero fee → nothing lost' );

console.log( 'tools-core: ' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
