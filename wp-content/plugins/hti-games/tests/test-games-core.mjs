/**
 * Node test for the two game cores. Run: node tests/test-games-core.mjs
 *
 * Most of the work here is one comparison: rebuild the parity fixture from the
 * live JavaScript and check it against the committed file. Every case the PHP
 * suites assert against goes through that, so any drift in the JS maths turns
 * this red before it can quietly disagree with the server.
 *
 * The handful of direct assertions below cover the two things a fixture
 * comparison cannot: that the rounding and division helpers really do differ
 * from the language defaults someone would replace them with, and that the two
 * cores agree with each other on the numbers they both carry.
 */
import { createRequire } from 'module';
import { readFileSync } from 'fs';
import { build } from './gen-parity.mjs';

const require = createRequire( import.meta.url );
const STC = require( '../assets/js/stc-core.js' );
const REVEAL = require( '../assets/js/reveal-core.js' );

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
function same( a, b, msg ) {
	ok( JSON.stringify( a ) === JSON.stringify( b ), msg );
}

// --- The committed fixture ---------------------------------------------------
const fixture = JSON.parse( readFileSync( new URL( './fixtures/parity.json', import.meta.url ), 'utf8' ) );
const fresh = build();

for ( const section of Object.keys( fresh ) ) {
	same( fresh[ section ], fixture[ section ], 'section "' + section + '" still matches the committed parity fixture' );
}
same(
	Object.keys( fresh ),
	Object.keys( fixture ),
	'the fixture has exactly the sections the generator writes'
);

// --- The rounding rule, and why it is not Math.round -------------------------
ok( -1 === STC.roundHalfAwayFromZero( -0.5 ), 'a negative half rounds away from zero, to -1' );
ok( 1 === STC.roundHalfAwayFromZero( 0.5 ), 'a positive half rounds away from zero, to 1' );
ok( -3 === STC.roundHalfAwayFromZero( -2.5 ), '-2.5 rounds to -3, as PHP does' );
ok( -0 === Math.round( -0.5 ), 'Math.round really does send -0.5 to -0 — the bug this replaces' );
ok(
	STC.roundHalfAwayFromZero( -0.5 ) !== Math.round( -0.5 ),
	'the helper and Math.round disagree, which is the entire reason it exists'
);
same(
	[ STC.roundHalfAwayFromZero( -6.5 ), REVEAL.roundHalfAwayFromZero( -6.5 ) ],
	[ -7, -7 ],
	'both cores round the same way — the copies have not drifted'
);

// --- Integer division truncates toward zero, like PHP's intdiv --------------
ok( -3 === STC.idiv( -7, 2 ), 'integer division truncates toward zero, not toward -Infinity' );
ok( 3 === STC.idiv( 7, 2 ), 'and it truncates positives the same way' );
ok( STC.idiv( -7, 2 ) !== Math.floor( -7 / 2 ), 'Math.floor would disagree on a negative — hence idiv' );
ok( -3 === REVEAL.idiv( -7, 2 ), 'the Reveal core divides identically' );

// --- The tie rule ------------------------------------------------------------
const visible = Array.from( { length: 14 }, () => ( { o: 100000, h: 100050, l: 99950, c: 100000 } ) );
ok( 100 === STC.atr( visible, 14 ), 'fourteen 100-tick candles give an ATR of 100' );

const both = [ { o: 100000, h: 100160, l: 99890, c: 100000 } ];
const tie = STC.resolve( visible, both, 'buy', 100, false, 10000 );
ok( 'stop' === tie.outcome, 'a candle whose range contains both levels resolves as a stop' );
ok( -100 === tie.pnl, 'and it is paid as a full stop, not as a win' );

// --- The two cores agree on what they both carry -----------------------------
ok(
	STC.CONFIG.capital_start === REVEAL.CONFIG.capital_start &&
		STC.CONFIG.capital_floor === REVEAL.CONFIG.capital_floor,
	'both games start and die at the same numbers'
);
ok( 15000 === STC.rTarget(), 'the target is 1.5R, derived from the config fraction' );

// --- Ruin, compounding, not linear -------------------------------------------
same(
	[ 50, 100, 200, 500, 1000, 2500 ].map( ( bp ) => STC.lossesToRuin( bp ) ),
	[ 460, 230, 114, 45, 22, 9 ],
	'the six tiers take 460/230/114/45/22/9 losses to ruin, not the linear 180/90/45/18/9/4'
);
ok( 11 === STC.lossesToRuin( 1000, true ), 'a doubled 10% tier is a 20% tier: eleven losses' );
ok( 0 === STC.lossesToRuin( 0 ), 'risking nothing never blows up' );
ok( 1 === STC.lossesToRuin( 10000 ), 'risking everything blows up on the first loss' );

// --- The Reveal's three lines ------------------------------------------------
const reveal = REVEAL.resolve( 18200, 6000, 'pass', 25, 10000, 10000 );
ok( 'pass' === reveal.decision && 0 === reveal.pnl, 'a pass costs nothing' );
ok( 600 === reveal.index_pnl, 'the index advances anyway — a pass is not measured against zero' );
same(
	reveal.lines.map( ( l ) => l.key ),
	[ 'you', 'pass', 'index' ],
	'the result carries all three lines, always in the same order'
);
ok( 0 === reveal.lines[ 1 ].pnl, 'the pass line is always zero, and is shown anyway' );

console.log( pass + ' passed, ' + fail + ' failed' );
process.exit( fail > 0 ? 1 : 0 );
