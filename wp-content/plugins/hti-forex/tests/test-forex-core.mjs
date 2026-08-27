/**
 * Node test for the forex math core. Run: node tests/test-forex-core.mjs
 */
import { createRequire } from 'module';
const require = createRequire( import.meta.url );
const F = require( '../assets/js/forex-core.js' );

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
	ok( Math.abs( a - b ) <= ( eps || 0.5 ), msg + ' (got ' + a + ', want ~' + b + ')' );
}

const RATES = { USDINR: 83, USDJPY: 147 };

// --- PAIRS table lock (mirrors includes/class-config.php::pairs()) ----------
const expectPairs = {
	EURUSD: { quote: 'USD', pipSize: 0.0001, contractSize: 100000 },
	GBPUSD: { quote: 'USD', pipSize: 0.0001, contractSize: 100000 },
	USDJPY: { quote: 'JPY', pipSize: 0.01, contractSize: 100000 },
	XAUUSD: { quote: 'USD', pipSize: 0.1, contractSize: 100 },
	USDINR: { quote: 'INR', pipSize: 0.0025, contractSize: 100000 },
};
ok( Object.keys( F.PAIRS ).join( ',' ) === Object.keys( expectPairs ).join( ',' ), 'PAIRS keys match the config' );
for ( const [ sym, spec ] of Object.entries( expectPairs ) ) {
	ok(
		F.PAIRS[ sym ] &&
			F.PAIRS[ sym ].quote === spec.quote &&
			F.PAIRS[ sym ].pipSize === spec.pipSize &&
			F.PAIRS[ sym ].contractSize === spec.contractSize,
		sym + ' spec matches the config table'
	);
}

// --- pipValue ---------------------------------------------------------------
let p = F.pipValue( 'EURUSD', 1, RATES );
near( p.usd, 10, 1e-9, 'EURUSD 1 lot = $10/pip' );
near( p.inr, 830, 1e-9, 'EURUSD 1 lot = ₹830/pip at 83' );

p = F.pipValue( 'EURUSD', 0.01, RATES );
near( p.inr, 8.3, 1e-9, 'EURUSD micro lot = ₹8.30/pip' );

p = F.pipValue( 'USDJPY', 1, RATES );
near( p.quote, 1000, 1e-9, 'USDJPY 1 lot = ¥1000/pip' );
near( p.usd, 1000 / 147, 1e-9, 'USDJPY pip converted to USD via USDJPY' );
near( p.inr, ( 1000 / 147 ) * 83, 1e-9, 'USDJPY pip converted on to INR' );

p = F.pipValue( 'XAUUSD', 1, RATES );
near( p.usd, 10, 1e-9, 'XAUUSD 1 lot = $10/pip ($0.10 on 100oz)' );
near( p.inr, 830, 1e-9, 'XAUUSD 1 lot = ₹830/pip at 83' );

p = F.pipValue( 'USDINR', 1, RATES );
near( p.quote, 250, 1e-9, 'USDINR 1 lot = ₹250/pip (0.0025 tick)' );
near( p.inr, 250, 1e-9, 'USDINR pip is already INR' );
near( p.usd, 250 / 83, 1e-9, 'USDINR pip back-converted to USD' );

ok( null === F.pipValue( 'NOPE', 1, RATES ), 'unknown pair → null' );
ok( null === F.pipValue( 'EURUSD', 0, RATES ), 'zero lots → null' );
ok( null === F.pipValue( 'USDJPY', 1, { USDINR: 83 } ), 'missing USDJPY rate → null for yen pairs' );
ok( null === F.pipValue( 'EURUSD', 1, { USDINR: 0 } ), 'missing USDINR rate → null' );

// --- positionSize -----------------------------------------------------------
// Reference example (also asserted manually on staging): ₹100,000 account,
// 1% risk, 20-pip stop, EUR/USD at 83 → 0.06 lots, ₹996 actually at risk.
let s = F.positionSize( 100000, 1, 20, 'EURUSD', RATES );
near( s.riskINR, 1000, 1e-9, 'chosen risk = ₹1,000' );
near( s.lots, 0.06, 1e-9, 'reference example → 0.06 lots' );
ok( 6000 === s.units, 'reference example → 6,000 units' );
near( s.actualRiskINR, 996, 1e-9, 'actual risk floors to ₹996' );
ok( s.actualRiskINR <= s.riskINR, 'actual risk never exceeds chosen risk' );
ok( ! s.tooSmall, 'reference example is not tooSmall' );

// Rounding is a floor, not a round.
s = F.positionSize( 100000, 1.16, 20, 'EURUSD', RATES );
near( s.lots, 0.06, 1e-9, '0.0698 raw lots floors to 0.06 (never rounds up)' );

// Float-noise guard: raw 0.29 must not floor to 0.28.
s = F.positionSize( 100000, 4.814, 20, 'EURUSD', RATES );
near( s.lots, 0.29, 1e-9, 'float noise does not misfloor 0.29' );

// $100-ish account: below one micro lot.
s = F.positionSize( 8500, 1, 20, 'EURUSD', RATES );
ok( s.tooSmall, '₹8,500 at 1%/20 pips is below one micro lot' );
near( s.lots, 0, 1e-9, 'tooSmall reports 0 lots' );
near( s.actualRiskINR, 0, 1e-9, 'tooSmall reports ₹0 actual risk' );

ok( null === F.positionSize( 0, 1, 20, 'EURUSD', RATES ), 'zero balance → null' );
ok( null === F.positionSize( 100000, 1, 0, 'EURUSD', RATES ), 'zero stop → null' );

// --- profitLoss -------------------------------------------------------------
// Buy 0.10 EURUSD 1.0900 → 1.0920: +20 pips, +$20, +₹1,660 at 83.
let pl = F.profitLoss( 'EURUSD', 'buy', 0.1, 1.09, 1.092, RATES );
near( pl.pips, 20, 1e-6, 'EURUSD buy: +20 pips' );
near( pl.usd, 20, 1e-9, 'EURUSD buy: +$20' );
near( pl.inr, 1660, 1e-9, 'EURUSD buy: +₹1,660 at 83' );

// The same move sold is the mirror loss.
pl = F.profitLoss( 'EURUSD', 'sell', 0.1, 1.09, 1.092, RATES );
near( pl.pips, -20, 1e-6, 'EURUSD sell into a rise: -20 pips' );
near( pl.inr, -1660, 1e-9, 'EURUSD sell into a rise: -₹1,660' );

// Sell profits when price falls.
pl = F.profitLoss( 'EURUSD', 'sell', 0.1, 1.092, 1.09, RATES );
ok( pl.inr > 0, 'sell profits when price falls' );

// USDJPY: quote is yen, converted via USDJPY then USDINR.
pl = F.profitLoss( 'USDJPY', 'buy', 1, 147.0, 147.3, RATES );
near( pl.pips, 30, 1e-6, 'USDJPY buy: +30 pips' );
near( pl.quote, 30000, 1e-6, 'USDJPY buy: +¥30,000' );
near( pl.usd, 30000 / 147, 1e-9, 'USDJPY P/L converted to USD' );
near( pl.inr, ( 30000 / 147 ) * 83, 1e-9, 'USDJPY P/L converted on to INR' );

// XAUUSD: $5 move on 100oz × 0.10 lots = $50.
pl = F.profitLoss( 'XAUUSD', 'buy', 0.1, 3300, 3305, RATES );
near( pl.usd, 50, 1e-9, 'XAUUSD buy: +$50 on a $5 move at 0.10 lots' );
near( pl.pips, 50, 1e-6, 'XAUUSD: $5 move = 50 pips ($0.10 convention)' );

// Flat trade: zero everywhere.
pl = F.profitLoss( 'EURUSD', 'buy', 1, 1.09, 1.09, RATES );
near( pl.inr, 0, 1e-9, 'flat trade → ₹0' );

// Guards.
ok( null === F.profitLoss( 'NOPE', 'buy', 1, 1, 2, RATES ), 'profitLoss: unknown pair → null' );
ok( null === F.profitLoss( 'EURUSD', 'buy', 0, 1, 2, RATES ), 'profitLoss: zero lots → null' );
ok( null === F.profitLoss( 'EURUSD', 'buy', 1, 0, 2, RATES ), 'profitLoss: zero entry → null' );
ok( null === F.profitLoss( 'USDJPY', 'buy', 1, 147, 148, { USDINR: 83 } ), 'profitLoss: missing USDJPY rate → null' );

// --- marginRequired ---------------------------------------------------------
// 0.06 lots EURUSD @1.09, 1:500 at 83: notional $6,540 → ₹542,820; margin ₹1,085.64.
let mg = F.marginRequired( 'EURUSD', 0.06, 1.09, 500, RATES );
near( mg.notionalUSD, 6540, 1e-9, 'EURUSD notional = units × price' );
near( mg.notionalINR, 542820, 1e-6, 'EURUSD notional in ₹ at 83' );
near( mg.marginINR, 1085.64, 1e-6, 'EURUSD margin ₹ at 1:500' );

// USD-base pairs: notional is price-independent (price ignored, even 0).
mg = F.marginRequired( 'USDJPY', 1, 0, 100, RATES );
near( mg.notionalUSD, 100000, 1e-9, 'USDJPY notional = units, price-independent' );
near( mg.marginUSD, 1000, 1e-9, 'USDJPY margin $1,000 at 1:100' );

// XAUUSD: oz × price.
mg = F.marginRequired( 'XAUUSD', 0.1, 3300, 500, RATES );
near( mg.notionalUSD, 33000, 1e-9, 'XAUUSD notional = 10oz × $3,300' );
near( mg.marginINR, ( 33000 * 83 ) / 500, 1e-6, 'XAUUSD margin ₹ at 1:500' );

// Guards.
ok( null === F.marginRequired( 'EURUSD', 1, 0, 500, RATES ), 'margin: EUR-base needs a price' );
ok( null === F.marginRequired( 'EURUSD', 1, 1.09, 0, RATES ), 'margin: zero leverage → null' );
ok( null === F.marginRequired( 'NOPE', 1, 1, 500, RATES ), 'margin: unknown pair → null' );

// --- sessions in IST --------------------------------------------------------
function windowsAt( iso ) {
	const now = Date.parse( iso );
	const w = {};
	F.sessionWindowsIST( now ).forEach( ( x ) => ( w[ x.id ] = x ) );
	w.overlap = F.overlapLondonNY( now );
	return w;
}

// Winter (both on standard time): overlap 18:30–22:30 IST.
let w = windowsAt( '2026-01-15T12:00:00Z' );
ok( w.london.openIST === '13:30', 'winter: London opens 13:30 IST' );
ok( w.london.closeIST === '22:30', 'winter: London closes 22:30 IST' );
ok( w.new_york.openIST === '18:30', 'winter: New York opens 18:30 IST' );
ok( w.overlap.startIST === '18:30' && w.overlap.endIST === '22:30', 'winter overlap 18:30–22:30 IST' );
ok( w.new_york.closesNextDay, 'winter: New York closes after IST midnight' );
// Australian DST is inverted (AEDT Nov–Mar): the northern winter is when
// Sydney runs EARLIER in IST — the untested session the first PDF got wrong.
ok( w.sydney.openIST === '01:30' && w.sydney.closeIST === '10:30', 'winter: Sydney 01:30–10:30 IST (AEDT)' );

// Summer (both on DST): overlap 17:30–21:30 IST.
w = windowsAt( '2026-07-15T12:00:00Z' );
ok( w.london.openIST === '12:30', 'summer: London opens 12:30 IST' );
ok( w.sydney.openIST === '02:30' && w.sydney.closeIST === '11:30', 'summer: Sydney 02:30–11:30 IST (AEST)' );
ok( w.overlap.startIST === '17:30' && w.overlap.endIST === '21:30', 'summer overlap 17:30–21:30 IST' );

// March desync (US already on DST since Mar 8, UK not until Mar 29).
w = windowsAt( '2026-03-10T12:00:00Z' );
ok( w.overlap.startIST === '17:30' && w.overlap.endIST === '22:30', 'March desync overlap 17:30–22:30 IST' );

// Late-October desync (UK back on GMT since Oct 25, US on DST until Nov 1).
w = windowsAt( '2026-10-28T12:00:00Z' );
ok( w.overlap.startIST === '17:30' && w.overlap.endIST === '22:30', 'October desync overlap 17:30–22:30 IST' );

// Open/closed state: 19:00 IST on a Thursday in January → London and NY open,
// Tokyo closed; overlap active.
w = windowsAt( '2026-01-15T13:30:00Z' );
ok( w.london.isOpen, '19:00 IST winter: London open' );
ok( w.new_york.isOpen, '19:00 IST winter: New York open' );
ok( ! w.tokyo.isOpen, '19:00 IST winter: Tokyo closed' );
ok( w.overlap.active, '19:00 IST winter: overlap active' );

// Weekend: Saturday never shows open sessions.
w = windowsAt( '2026-01-17T13:30:00Z' );
ok( ! w.london.isOpen && ! w.new_york.isOpen, 'Saturday: nothing is open' );
ok( ! w.overlap.active, 'Saturday: overlap not active' );

// Offset sanity: IST is fixed +330.
ok( 330 === F.zoneOffsetMinutes( 'Asia/Kolkata', new Date( '2026-01-15T12:00:00Z' ) ), 'IST offset +5:30 in winter' );
ok( 330 === F.zoneOffsetMinutes( 'Asia/Kolkata', new Date( '2026-07-15T12:00:00Z' ) ), 'IST offset +5:30 in summer' );

console.log( pass + ' passed, ' + fail + ' failed' );
process.exit( fail > 0 ? 1 : 0 );
