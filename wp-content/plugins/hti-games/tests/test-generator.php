<?php
/**
 * The generator, and the claim its labels make.
 *
 * Two things this file is really guarding.
 *
 * First, that a seed is a permanent address. The hard-coded PRNG vector and
 * the three scenario checksums below are a regression lock: change the
 * arithmetic and they go red, which is the whole point, because a scenario
 * already stored with `hti_stc_seed` can only be reproduced if the same seed
 * still yields the same 120 candles. This is also why the generator does not
 * use mt_rand() — PHP has changed Mt19937's output before and nothing promises
 * it will not again.
 *
 * Second, and more important, that a label is a checked claim and not a
 * decoration. Every scenario is played back through STC_Engine::resolve() here,
 * exactly as the generator plays it before keeping it: a `trap` really does
 * stop out the direction its visible window implies, and quickly, and does not
 * pay the other side either; a `reasonable` really does reach the target; an
 * `ambiguous` really does resolve nothing. Without that, a library of 365
 * "generated" scenarios is noise with labels attached.
 *
 *   php wp-content/plugins/hti-games/tests/test-generator.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-stc-generator.php';

use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\STC_Engine;
use HTI\Games\STC_Generator;

/**
 * A flat candle, for the implied-direction cases.
 *
 * @param int $close Close price in ticks.
 * @return array{o:int,h:int,l:int,c:int}
 */
function hti_gen_candle( int $close ): array {
	return array(
		'o' => $close,
		'h' => $close + 5,
		'l' => $close - 5,
		'c' => $close,
	);
}

echo "The PRNG is a stable function of its seed\n";
$state  = STC_Generator::rng_state( 1 );
$vector = array();
for ( $i = 0; $i < 6; $i++ ) {
	$vector[] = STC_Generator::rng_next( $state );
}
// Canonical mulberry32 seeded with 1, verified against the reference
// JavaScript implementation. If this line ever has to be "updated", every
// `hti_stc_seed` already in the database has just stopped meaning anything.
hti_games_check(
	array( 2693262067, 11749833, 2265367787, 4213581821, 4159151403, 1207330352 ) === $vector,
	'mulberry32(1) yields the canonical vector'
);

$a    = STC_Generator::rng_state( 99 );
$b    = STC_Generator::rng_state( 99 );
$same = true;
for ( $i = 0; $i < 50; $i++ ) {
	$same = $same && STC_Generator::rng_next( $a ) === STC_Generator::rng_next( $b );
}
hti_games_check( $same, 'the same seed replays the same stream' );

$c = STC_Generator::rng_state( 100 );
hti_games_check( STC_Generator::rng_next( $a ) !== STC_Generator::rng_next( $c ), 'a neighbouring seed does not' );

$state  = STC_Generator::rng_state( 7 );
$in     = true;
$hi     = false;
$lo     = false;
for ( $i = 0; $i < 4000; $i++ ) {
	$draw = STC_Generator::rng_int( $state, -3, 4 );
	$in   = $in && $draw >= -3 && $draw <= 4;
	$hi   = $hi || 4 === $draw;
	$lo   = $lo || -3 === $draw;
}
hti_games_check( $in, 'rng_int stays inside its bounds' );
hti_games_check( $hi && $lo, 'and reaches both of them' );
hti_games_check( 5 === STC_Generator::rng_int( $state, 5, 5 ), 'a degenerate range returns its only value' );
hti_games_check( 9 === STC_Generator::rng_int( $state, 9, 2 ), 'an inverted range returns the lower bound rather than looping' );

echo "\nA scenario is a function of its class and seed\n";
$first  = STC_Generator::scenario( 'trap', 424242 );
$second = STC_Generator::scenario( 'trap', 424242 );
hti_games_check( $first['candles'] === $second['candles'], 'the same class and seed rebuild the identical series' );
hti_games_check( $first['checksum'] !== STC_Generator::scenario( 'trap', 424243 )['checksum'], 'a different seed builds a different one' );

// The regression lock proper: these three digests are the generated library's
// promise that yesterday's chart is still yesterday's chart.
$locks = array(
	'reasonable' => 'da979b4640a817a452e6949e0355be0e',
	'ambiguous'  => 'cc56c1b829aaec3bd264c7153d2c4096',
	'trap'       => '6b25e2fb2c11bee6b2889ca8b97acc90',
);
foreach ( $locks as $class => $digest ) {
	hti_games_check(
		$digest === STC_Generator::scenario( $class, 424242 )['checksum'],
		"seed 424242 still regenerates the same {$class} chart"
	);
}

hti_games_check( 120 === STC_Generator::LENGTH, 'a scenario is 120 candles' );
hti_games_check( Config::STC_VISIBLE + Config::STC_OUTCOME === STC_Generator::LENGTH, 'which is what Config says it should be' );
hti_games_check( STC_Generator::MAX_ATTEMPTS > 0, 'rejection sampling is bounded, not a while(true)' );

$threw = false;
try {
	STC_Generator::scenario( 'confusing', 1 );
} catch ( \RuntimeException $e ) {
	$threw = true;
}
hti_games_check( $threw, 'an unknown class throws rather than quietly returning something' );

echo "\nEvery candle is a candle\n";
$sample = array();
foreach ( CPT::SCENARIO_CLASSES as $class ) {
	for ( $i = 0; $i < 60; $i++ ) {
		$sample[] = STC_Generator::scenario( $class, 5000 + $i );
	}
}

$bad_length = 0;
$bad_shape  = 0;
$bad_type   = 0;
$bad_price  = 0;
$flat_atr   = 0;
foreach ( $sample as $scenario ) {
	if ( count( $scenario['candles'] ) !== STC_Generator::LENGTH ) {
		++$bad_length;
	}
	if ( $scenario['atr'] <= 0 ) {
		++$flat_atr;
	}
	foreach ( $scenario['candles'] as $candle ) {
		foreach ( array( 'o', 'h', 'l', 'c' ) as $key ) {
			if ( ! is_int( $candle[ $key ] ) ) {
				++$bad_type;
			}
		}
		if ( $candle['h'] < max( $candle['o'], $candle['c'] ) || $candle['l'] > min( $candle['o'], $candle['c'] ) ) {
			++$bad_shape;
		}
		if ( $candle['l'] <= 0 ) {
			++$bad_price;
		}
	}
}
hti_games_check( 0 === $bad_length, sprintf( 'all %d sampled scenarios are the right length', count( $sample ) ) );
hti_games_check( 0 === $bad_type, 'every open, high, low and close is an integer — no float reaches the decision path' );
hti_games_check( 0 === $bad_shape, 'every high covers the body and every low sits under it' );
hti_games_check( 0 === $bad_price, 'no price is zero or negative' );
hti_games_check( 0 === $flat_atr, 'no scenario has a zero ATR, which would leave the day with no risk unit' );

echo "\nThe visible window implies exactly one thing\n";
$up   = array_map( 'hti_gen_candle', range( 100000, 100000 + 20 * 100, 100 ) );
$down = array_map( 'hti_gen_candle', range( 100000, 100000 - 20 * 100, -100 ) );
$flat = array_map( 'hti_gen_candle', array_fill( 0, 21, 100000 ) );
hti_games_check( 'buy' === STC_Generator::implied_direction( $up ), 'a rising window implies a buy' );
hti_games_check( 'sell' === STC_Generator::implied_direction( $down ), 'a falling one implies a sell' );
hti_games_check( '' === STC_Generator::implied_direction( $flat ), 'a window that ends exactly where it started implies nothing, and says so' );
hti_games_check( '' === STC_Generator::implied_direction( array() ), 'and neither does an empty window' );
hti_games_check( 'sell' === STC_Generator::opposite( 'buy' ) && 'buy' === STC_Generator::opposite( 'sell' ), 'the other side of a position is the other side' );

// Only the last IMPLIED_LOOKBACK candles are read: a long decline that turned
// around a fortnight ago is a window implying a buy, and the generator is
// entitled to trap it.
$turn = array_merge(
	array_map( 'hti_gen_candle', range( 100000, 100000 - 60 * 100, -100 ) ),
	array_map( 'hti_gen_candle', range( 94000, 94000 + 14 * 100, 100 ) )
);
hti_games_check( 'buy' === STC_Generator::implied_direction( $turn ), 'only the last fourteen candles are read, not the whole chart' );

echo "\nEvery label is a claim the engine has checked\n";
$by_class = array_fill_keys( CPT::SCENARIO_CLASSES, array() );
foreach ( $sample as $scenario ) {
	$visible = array_slice( $scenario['candles'], 0, Config::STC_VISIBLE );
	$after   = array_slice( $scenario['candles'], Config::STC_VISIBLE );
	$with    = STC_Engine::resolve( $visible, $after, $scenario['implied'], 100, false, Config::CAPITAL_START );
	$against = STC_Engine::resolve( $visible, $after, STC_Generator::opposite( $scenario['implied'] ), 100, false, Config::CAPITAL_START );

	$by_class[ $scenario['class'] ][] = array(
		'scenario' => $scenario,
		'with'     => $with,
		'against'  => $against,
	);
}

$wrong_implied = 0;
foreach ( $sample as $scenario ) {
	$visible = array_slice( $scenario['candles'], 0, Config::STC_VISIBLE );
	if ( STC_Generator::implied_direction( $visible ) !== $scenario['implied'] ) {
		++$wrong_implied;
	}
}
hti_games_check( 0 === $wrong_implied, 'the recorded implied direction is the one the stored candles actually imply' );

$fails = 0;
foreach ( $by_class['reasonable'] as $row ) {
	if ( 'target' !== $row['with']['outcome'] ) {
		++$fails;
	}
}
hti_games_check( 0 === $fails, sprintf( 'all %d reasonable scenarios reach the target in the implied direction', count( $by_class['reasonable'] ) ) );

$fails = 0;
$slow  = 0;
$paid  = 0;
foreach ( $by_class['trap'] as $row ) {
	if ( 'stop' !== $row['with']['outcome'] ) {
		++$fails;
	}
	if ( (int) $row['with']['candle'] > STC_Generator::TRAP_MAX_CANDLE ) {
		++$slow;
	}
	if ( (int) $row['against']['r_bp'] > 0 ) {
		++$paid;
	}
}
hti_games_check( 0 === $fails, sprintf( 'all %d traps stop out the direction the window implied', count( $by_class['trap'] ) ) );
hti_games_check( 0 === $slow, 'and every one of them springs within ' . STC_Generator::TRAP_MAX_CANDLE . ' candles' );
hti_games_check( 0 === $paid, 'and none of them pays the contrarian either — which is what makes passing the right answer' );

$marked = 0;
foreach ( $by_class['trap'] as $row ) {
	if ( true === $row['scenario']['pass_right'] ) {
		++$marked;
	}
}
hti_games_check( count( $by_class['trap'] ) === $marked, 'every trap is stored with pass_right set, computed from the engine and not from the label' );

$fails = 0;
$loud  = 0;
foreach ( $by_class['ambiguous'] as $row ) {
	if ( 'open' !== $row['with']['outcome'] || 'open' !== $row['against']['outcome'] ) {
		++$fails;
	}
	if ( abs( (int) $row['with']['r_bp'] ) > STC_Generator::AMBIGUOUS_MAX_R ) {
		++$loud;
	}
}
hti_games_check( 0 === $fails, sprintf( 'all %d ambiguous scenarios resolve nothing in either direction', count( $by_class['ambiguous'] ) ) );
hti_games_check( 0 === $loud, 'and none of them drifts more than 0.75R from flat — the day answered nothing' );

// The validator is not decorative: hand it a scenario under the wrong label
// and it says no.
$reasonable = $by_class['reasonable'][0]['scenario'];
$visible    = array_slice( $reasonable['candles'], 0, Config::STC_VISIBLE );
$after      = array_slice( $reasonable['candles'], Config::STC_VISIBLE );
$verdict    = STC_Generator::behaviour( $visible, $after, $reasonable['implied'] );
hti_games_check( STC_Generator::behaves_like( 'reasonable', $verdict ), 'the validator accepts a scenario that behaves like its class' );
hti_games_check( ! STC_Generator::behaves_like( 'trap', $verdict ), 'and rejects the same one relabelled as a trap' );
hti_games_check( ! STC_Generator::behaves_like( 'ambiguous', $verdict ), 'and as an ambiguous one' );
hti_games_check( ! STC_Generator::behaves_like( 'reasonable', array( 'with' => array(), 'against' => array() ) ), 'a verdict with nothing in it is never good enough' );

echo "\nA library honours the mix\n";
$library = STC_Generator::batch( 365, 20260830 );
$mix     = STC_Generator::mix( $library );

hti_games_check( 365 === count( $library ), 'a year of scenarios is a year of scenarios' );
hti_games_check( array_sum( $mix ) === count( $library ), 'and every one of them is counted in the mix' );
hti_games_check( array_keys( $mix ) === CPT::SCENARIO_CLASSES, 'the mix always reports all three classes' );

foreach ( STC_Generator::MIX_BP as $class => $target ) {
	$observed = intdiv( $mix[ $class ] * 10000, count( $library ) );
	hti_games_check(
		abs( $observed - $target ) <= 100,
		sprintf( '%s is %s%% of the library, against a target of %s%%', $class, number_format( $observed / 100, 1 ), number_format( $target / 100, 1 ) )
	);
}

$digests = array_map( fn( array $s ): string => (string) $s['checksum'], $library );
hti_games_check( count( array_unique( $digests ) ) === count( $digests ), 'no chart appears twice in the library' );

$seeds = array_map( fn( array $s ): int => (int) $s['seed'], $library );
hti_games_check( count( array_unique( $seeds ) ) === count( $seeds ), 'and no two scenarios share a seed, which is their identity in the database' );

hti_games_check(
	array_map( fn( array $s ): string => (string) $s['checksum'], STC_Generator::batch( 365, 20260830 ) ) === $digests,
	'the same run seed and count rebuild the same library, in the same order'
);
hti_games_check(
	array_map( fn( array $s ): string => (string) $s['checksum'], STC_Generator::batch( 365, 20260831 ) ) !== $digests,
	'a different run seed builds a different one'
);
// The count is part of a library's address, not just its length: the class
// counts come from the mix, so asking for 400 reshuffles the plan and every
// scenario after the first draw is a different chart. Worth knowing before
// anybody runs the command twice with two different --count values and
// wonders why they now have 765 drafts.
hti_games_check(
	array_map( fn( array $s ): string => (string) $s['checksum'], STC_Generator::batch( 12, 20260830 ) ) !== array_slice( $digests, 0, 12 ),
	'a shorter library is not a prefix of a longer one — the count is part of the address'
);
hti_games_check( array() === STC_Generator::batch( 0, 1 ), 'a library of nothing is empty rather than an error' );

echo "\nThe plan behind the mix\n";
$state = STC_Generator::rng_state( 7 );
$plan  = STC_Generator::plan( 100, $state );
$count = array_count_values( $plan );
hti_games_check( 100 === count( $plan ), 'a plan has one entry per scenario' );
hti_games_check( 40 === $count['reasonable'] && 35 === $count['ambiguous'] && 25 === $count['trap'], 'and splits 40/35/25 exactly at a round hundred' );

$state = STC_Generator::rng_state( 7 );
hti_games_check( STC_Generator::plan( 100, $state ) === $plan, 'the same seed plans the same order' );
hti_games_check( 'reasonable' !== implode( '', array_unique( array_slice( $plan, 0, 20 ) ) ), 'the classes are shuffled, not stacked in blocks' );

$state = STC_Generator::rng_state( 3 );
$odd   = STC_Generator::plan( 7, $state );
hti_games_check( 7 === count( $odd ), 'a count that does not divide cleanly still yields exactly that many' );

echo "\nWhat gets stored\n";
$quads = STC_Generator::quads( $first['candles'] );
hti_games_check( count( $quads ) === STC_Generator::LENGTH, 'the stored quads are one per candle' );
hti_games_check( array( 0, 1, 2, 3 ) === array_keys( $quads[0] ) && 4 === count( $quads[0] ), 'each is a bare [o, h, l, c] list' );
hti_games_check(
	$quads[0] === array( $first['candles'][0]['o'], $first['candles'][0]['h'], $first['candles'][0]['l'], $first['candles'][0]['c'] ),
	'in open, high, low, close order — the order class-cpt.php parses back'
);
hti_games_check( Config::TICK_SCALE === $first['scale'], 'and they are declared at the tick scale the rest of the plugin uses' );
hti_games_check( Config::STC_VISIBLE === $first['visible'] && Config::STC_OUTCOME === $first['outcome'], 'with the visible/outcome split Config defines' );
hti_games_check( 1 === preg_match( '/^[a-f0-9]{32}$/', $first['checksum'] ), 'the checksum is a plain hex digest, as class-cpt.php sanitizes it' );

hti_games_done();
