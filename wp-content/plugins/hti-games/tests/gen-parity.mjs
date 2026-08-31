/**
 * Regenerate fixtures/parity.json from the two JavaScript cores.
 *
 *   node tests/gen-parity.mjs
 *
 * The fixture is the contract between the two implementations of the same
 * arithmetic: the JavaScript the browser animates with, and the PHP the server
 * decides with. test-games-core.mjs asserts the JS still matches it;
 * test-stc-engine.php and test-reveal-engine.php assert the PHP does. Change
 * the maths on either side without regenerating and one of the two suites goes
 * red, which is the entire point.
 *
 * The cases are chosen to sit on the edges, not in the middle: the same-candle
 * tie, a negative half-dollar (where Math.round and PHP's round() disagree),
 * a capital large enough to strain a double, and a clamp that only a malformed
 * scenario can reach. A fixture full of comfortable numbers proves nothing.
 *
 * Run this only when the maths is meant to change, and read the diff.
 */
import { createRequire } from 'module';
import { writeFileSync, mkdirSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const require = createRequire( import.meta.url );
const STC = require( '../assets/js/stc-core.js' );
const REVEAL = require( '../assets/js/reveal-core.js' );
const here = dirname( fileURLToPath( import.meta.url ) );

/** One candle, in integer ticks. */
const candle = ( o, h, l, c ) => ( { o, h, l, c } );

/**
 * A run of identical candles closing at `close` with a `range`-tick span.
 * Fourteen of these give an ATR of exactly `range`, which makes every level
 * in the compact scenarios computable in the head.
 */
function flat( n, close, range ) {
	const half = Math.trunc( range / 2 );
	return Array.from( { length: n }, () => candle( close, close + half, close - half, close ) );
}

/**
 * Park–Miller. Chosen over a bigger LCG because 16807 × 2^31 stays inside
 * 2^53, so the sequence is exactly reproducible in a double — the generated
 * scenario has to be the same bytes on every machine that regenerates it.
 */
function prng( seed ) {
	let s = seed % 2147483647;
	if ( s <= 0 ) {
		s += 2147483646;
	}
	return () => ( s = ( s * 16807 ) % 2147483647 );
}

/** A realistic integer random walk: drift, then wicks around it. */
function walk( seed, n, start ) {
	const rnd = prng( seed );
	const out = [];
	let price = start;

	for ( let i = 0; i < n; i++ ) {
		const o = price;
		const c = o + ( ( rnd() % 201 ) - 100 );
		out.push( candle( o, Math.max( o, c ) + ( rnd() % 60 ), Math.min( o, c ) - ( rnd() % 60 ), c ) );
		price = c;
	}

	return out;
}

const ENTRY = 100000;
const VISIBLE = flat( 14, ENTRY, 100 ); // ATR 100 → stop 99900 / target 100150 long.
const GENERATED = walk( 20260830, 120, ENTRY );
const GENERATED_B = walk( 12345, 120, ENTRY );

/**
 * Named scenarios the cases refer to, so a 120-candle series is stored once.
 */
export const SCENARIOS = {
	// A single candle whose range contains BOTH levels. The rule under test.
	tie: { visible: VISIBLE, after: [ candle( ENTRY, 100160, 99890, ENTRY ) ] },
	// Wide enough to span both levels on the short side too.
	tie_wide: { visible: VISIBLE, after: [ candle( ENTRY, 100160, 99800, ENTRY ) ] },
	// Quiet candle, then the target, cleanly, on the second.
	target_first: {
		visible: VISIBLE,
		after: [ candle( ENTRY, 100040, 99960, 100020 ), candle( 100020, 100150, 99950, 100100 ) ],
	},
	// The stop, cleanly, on the first.
	stop_first: { visible: VISIBLE, after: [ candle( ENTRY, 100050, 99900, 99920 ) ] },
	// Neither level: drifts up half an ATR and stops there.
	drift_up: {
		visible: VISIBLE,
		after: [ candle( ENTRY, 100040, 99960, 100020 ), candle( 100020, 100090, 99980, 100050 ) ],
	},
	// Neither level, downward, ending 65 ticks under entry: -0.65R, which at a
	// $10 stake is exactly -$6.50 — the half that Math.round() gets wrong.
	drift_down: {
		visible: VISIBLE,
		after: [ candle( ENTRY, 100010, 99930, 99960 ), candle( 99960, 99980, 99920, 99935 ) ],
	},
	// No outcome candles at all: a scenario with nothing after the entry.
	empty: { visible: VISIBLE, after: [] },
	// Malformed — a close outside its own high/low, which real data cannot
	// contain. Only route to the ±1.5R clamp, and the reason it exists.
	broken_up: { visible: VISIBLE, after: [ candle( ENTRY, 100100, 99950, 100400 ) ] },
	broken_down: { visible: VISIBLE, after: [ candle( ENTRY, 100050, 99950, 99000 ) ] },
	// The real shape: 80 visible candles and 40 hidden ones. Two walks, one
	// that stops both ways and one that grinds 18 candles to a target, so the
	// long path through the loop is covered on data nobody hand-tuned.
	generated: { visible: GENERATED.slice( 0, 80 ), after: GENERATED.slice( 80 ) },
	generated_b: { visible: GENERATED_B.slice( 0, 80 ), after: GENERATED_B.slice( 80 ) },
};

/** scenario, direction, risk_bp, double, capital. */
export const STC_CASES = [
	[ 'tie', 'buy', 100, false, 10000 ],
	[ 'tie_wide', 'sell', 100, false, 10000 ],
	[ 'target_first', 'buy', 100, false, 10000 ],
	[ 'target_first', 'buy', 100, true, 10000 ],
	[ 'stop_first', 'buy', 2500, true, 10000 ],
	[ 'stop_first', 'buy', 50, false, 4100 ],
	[ 'drift_up', 'buy', 100, false, 10000 ],
	[ 'drift_up', 'sell', 100, false, 10000 ],
	// -$6.50 → -$7 away from zero, -$6 under Math.round(). The parity bug.
	[ 'drift_down', 'buy', 50, false, 2000 ],
	[ 'empty', 'buy', 200, false, 10000 ],
	[ 'broken_up', 'buy', 100, false, 10000 ],
	[ 'broken_down', 'buy', 100, false, 10000 ],
	[ 'tie', 'pass', 100, false, 10000 ],
	[ 'drift_up', 'pass', 2500, true, 10000 ],
	[ 'generated', 'buy', 100, false, 10000 ],
	[ 'generated', 'sell', 500, false, 10000 ],
	[ 'generated', 'pass', 1000, true, 7500 ],
	[ 'generated_b', 'buy', 200, false, 10000 ],
	[ 'generated_b', 'sell', 200, true, 10000 ],
	// A capital seventeen winning days of maximum risk would actually reach.
	// The one-product form of cash() overflows a double here; the two-step
	// form does not, and this row is what proves it.
	[ 'target_first', 'buy', 2500, true, 100000000 ],
	[ 'stop_first', 'buy', 2500, true, 100000000 ],
];

/** ticks source, period. */
export const ATR_CASES = [
	[ 'tie', 14 ],
	[ 'generated', 14 ],
	[ 'generated_b', 14 ],
	[ 'generated', 5 ],
	[ 'generated', 200 ],
];

/** entry, atr, direction. */
export const LEVEL_CASES = [
	[ 100000, 100, 'buy' ],
	[ 100000, 100, 'sell' ],
	[ 100000, 101, 'buy' ],
	[ 100000, 101, 'sell' ],
	[ 0, 0, 'buy' ],
];

/** capital, pnl. */
export const APPLY_CASES = [
	[ 10000, -8999 ],
	[ 10000, -9000 ],
	[ 1300, -300 ],
	[ 1301, -300 ],
	[ 10000, 150 ],
];

/** risk_bp, double. */
export const RUIN_CASES = [
	[ 50, false ],
	[ 100, false ],
	[ 200, false ],
	[ 500, false ],
	[ 1000, false ],
	[ 2500, false ],
	[ 1000, true ],
	[ 2500, true ],
	[ 5000, true ],
	[ 0, false ],
];

/** capital, size_pct, r_bp. */
export const REVEAL_PNL_CASES = [
	[ 10000, 10, 18200 ],
	[ 10000, 50, -10000 ],
	[ 10000, 25, 0 ],
	[ 10000, 5, 4350 ],
	// 5% of $4,100 is $205; -2.44% of that is -$5.002, and the neighbouring
	// tier lands on a half. Small money is where the rounding shows.
	[ 4100, 5, -2440 ],
	[ 4100, 5, -2439 ],
	// $50 committed against a -13% five-year return is exactly -$6.50: the
	// half that Math.round() books as -$6 and PHP books as -$7.
	[ 1010, 5, -1300 ],
	[ 100000000, 50, 18200 ],
];

/** index_cap, r_idx_bp. */
export const REVEAL_INDEX_CASES = [
	[ 10000, 6000 ],
	[ 10000, -3000 ],
	[ 10000, 5 ],
	[ 9999, -5 ],
	// $100 of index exposure at -65.5% is exactly -$65.50: the negative half
	// again, on the side of the ledger nobody thinks to check.
	[ 1005, -6550 ],
	[ 100000000, 18200 ],
];

/** r_bp, r_idx_bp, decision, size_pct, capital, index_cap. */
export const REVEAL_CASES = [
	[ 18200, 6000, 'invest', 25, 10000, 10000 ],
	[ -10000, 6000, 'invest', 50, 10000, 10000 ],
	[ 18200, 6000, 'pass', 25, 10000, 10000 ],
	// An "invest" with no size behind it is a pass, whatever it calls itself.
	[ 18200, 6000, 'invest', 0, 10000, 10000 ],
	[ -4500, -3000, 'invest', 5, 4100, 12345 ],
];

/** Values whose rounding differs between Math.round() and PHP's round(). */
export const ROUNDING_CASES = [ 0.5, -0.5, 1.5, -1.5, 2.5, -2.5, 0.4, -0.4, -6.5, 0, -0.49999999999999994 ];

export function build() {
	return {
		note: 'Generated by tests/gen-parity.mjs — do not hand-edit.',
		config: { stc: STC.CONFIG, reveal: REVEAL.CONFIG },
		rounding: ROUNDING_CASES.map( ( v ) => ( { v, out: STC.roundHalfAwayFromZero( v ) } ) ),
		scenarios: SCENARIOS,
		atr: ATR_CASES.map( ( [ scenario, period ] ) => ( {
			scenario,
			period,
			atr: STC.atr( SCENARIOS[ scenario ].visible, period ),
		} ) ),
		levels: LEVEL_CASES.map( ( [ entry, atr, direction ] ) => ( {
			entry,
			atr,
			direction,
			...STC.levels( entry, atr, direction ),
		} ) ),
		stc: STC_CASES.map( ( [ scenario, direction, risk_bp, double, capital ] ) => {
			const s = SCENARIOS[ scenario ];
			const result = STC.resolve( s.visible, s.after, direction, risk_bp, double, capital );
			return {
				scenario,
				direction,
				risk_bp,
				double,
				capital,
				at_risk: STC.atRisk( capital, risk_bp, double ? STC.CONFIG.double : 1 ),
				result,
				after: STC.apply( capital, result.pnl ),
				survival: STC.survival( STC.apply( capital, result.pnl ).capital ),
			};
		} ),
		survival: [ 500, 1000, 1001, 5500, 10000, 20000 ].map( ( capital ) => ( {
			capital,
			survival: STC.survival( capital ),
		} ) ),
		apply: APPLY_CASES.map( ( [ capital, pnl ] ) => ( { capital, pnl, out: STC.apply( capital, pnl ) } ) ),
		ruin: RUIN_CASES.map( ( [ risk_bp, double ] ) => ( {
			risk_bp,
			double,
			losses: STC.lossesToRuin( risk_bp, double ),
		} ) ),
		reveal_pnl: REVEAL_PNL_CASES.map( ( [ capital, size_pct, r_bp ] ) => ( {
			capital,
			size_pct,
			r_bp,
			committed: REVEAL.committed( capital, size_pct ),
			pnl: REVEAL.pnl( capital, size_pct, r_bp ),
		} ) ),
		reveal_index: REVEAL_INDEX_CASES.map( ( [ index_cap, r_idx_bp ] ) => ( {
			index_cap,
			r_idx_bp,
			index_pnl: REVEAL.indexPnl( index_cap, r_idx_bp ),
			index_step: REVEAL.indexStep( index_cap, r_idx_bp ),
		} ) ),
		reveal: REVEAL_CASES.map( ( [ r_bp, r_idx_bp, decision, size_pct, capital, index_cap ] ) => ( {
			r_bp,
			r_idx_bp,
			decision,
			size_pct,
			capital,
			index_cap,
			result: REVEAL.resolve( r_bp, r_idx_bp, decision, size_pct, capital, index_cap ),
		} ) ),
	};
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const out = join( here, 'fixtures' );
	mkdirSync( out, { recursive: true } );
	writeFileSync( join( out, 'parity.json' ), JSON.stringify( build(), null, '\t' ) + '\n' );
	console.log( 'Wrote tests/fixtures/parity.json' );
}
