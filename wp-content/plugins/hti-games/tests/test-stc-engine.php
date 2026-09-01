<?php
/**
 * Survive the Charts: the engine, and its agreement with the JavaScript.
 *
 * Two halves. The first walks tests/fixtures/parity.json — generated from
 * assets/js/stc-core.js — and asserts the PHP reaches the same numbers, field
 * for field, including a capital large enough to strain a double and a P&L
 * that lands on a negative half-dollar. The second is hand-built candles for
 * the rules a fixture states but does not explain: the same-candle tie, the
 * clamp, the floor, the reset.
 *
 *   php wp-content/plugins/hti-games/tests/test-stc-engine.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';

use HTI\Games\Config;
use HTI\Games\STC_Engine;

/**
 * One candle, in integer ticks.
 *
 * @param int $o Open.
 * @param int $h High.
 * @param int $l Low.
 * @param int $c Close.
 */
function stc_candle( int $o, int $h, int $l, int $c ): array {
	return array(
		'o' => $o,
		'h' => $h,
		'l' => $l,
		'c' => $c,
	);
}

/**
 * A run of identical candles: fourteen of these give an ATR of exactly $range.
 *
 * @param int $n     How many.
 * @param int $close Close price in ticks.
 * @param int $range High-low span in ticks.
 */
function stc_flat( int $n, int $close, int $range ): array {
	$half = intdiv( $range, 2 );
	$out  = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$out[] = stc_candle( $close, $close + $half, $close - $half, $close );
	}
	return $out;
}

/**
 * Recursively key-sort a decoded structure so comparison ignores key order but
 * still catches a missing key, an extra one or a changed type.
 *
 * @param mixed $value Decoded JSON.
 * @return mixed
 */
function stc_norm( $value ) {
	if ( is_array( $value ) ) {
		ksort( $value );
		return array_map( 'stc_norm', $value );
	}
	return $value;
}

$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/parity.json' ), true );

echo "The parity fixture is readable\n";
hti_games_check( is_array( $fixture ), 'tests/fixtures/parity.json exists and decodes' );

echo "\nThe JavaScript's copy of the config table matches Config\n";
$js = $fixture['config']['stc'];
hti_games_check( Config::CAPITAL_START === $js['capital_start'], 'the JS starts a run at the same capital' );
hti_games_check( Config::CAPITAL_FLOOR === $js['capital_floor'], 'and dies at the same floor' );
hti_games_check( Config::TICK_SCALE === $js['tick_scale'], 'prices are scaled the same way on both sides' );
hti_games_check( Config::STC_VISIBLE === $js['visible'] && Config::STC_OUTCOME === $js['outcome'], 'the chart is the same shape in both ports' );
hti_games_check( Config::STC_ATR_PERIOD === $js['atr_period'], 'the ATR window is the same length' );
hti_games_check( Config::STC_TARGET_NUM === $js['target_num'] && Config::STC_TARGET_DEN === $js['target_den'], 'the target fraction is the same 1.5' );
hti_games_check( Config::STC_DOUBLE === $js['double'], 'the double stake doubles on both sides' );
hti_games_check( Config::STC_RISK_BP === $js['risk_bp'], 'the risk tiers are the same six' );

echo "\nRounding: halves go away from zero in both languages\n";
foreach ( $fixture['rounding'] as $case ) {
	$got = STC_Engine::round_half_away_from_zero( (float) $case['v'] );
	hti_games_check( $got === $case['out'], sprintf( 'round(%s) is %d in PHP and in JavaScript', var_export( $case['v'], true ), $case['out'] ) );
}
hti_games_check( -1 === STC_Engine::round_half_away_from_zero( -0.5 ), 'a negative half rounds to -1, where Math.round() would give -0' );
hti_games_check( -3 === STC_Engine::round_half_away_from_zero( -2.5 ), 'and -2.5 rounds to -3, where Math.round() would give -2' );

echo "\nATR, levels and every resolved case agree with the JavaScript\n";
foreach ( $fixture['atr'] as $case ) {
	$got = STC_Engine::atr( $fixture['scenarios'][ $case['scenario'] ]['visible'], $case['period'] );
	hti_games_check( $got === $case['atr'], sprintf( 'ATR(%s, %d) is %d in both ports', $case['scenario'], $case['period'], $case['atr'] ) );
}

foreach ( $fixture['levels'] as $case ) {
	$got = STC_Engine::levels( $case['entry'], $case['atr'], $case['direction'] );
	hti_games_check(
		$got['stop'] === $case['stop'] && $got['target'] === $case['target'],
		sprintf( '%s levels off %d at ATR %d are %d/%d in both ports', $case['direction'], $case['entry'], $case['atr'], $case['stop'], $case['target'] )
	);
}

foreach ( $fixture['stc'] as $case ) {
	$scenario = $fixture['scenarios'][ $case['scenario'] ];
	$label    = sprintf(
		'%s · %s · %d bp%s · $%s',
		$case['scenario'],
		$case['direction'],
		$case['risk_bp'],
		$case['double'] ? ' doubled' : '',
		number_format( $case['capital'] )
	);

	$got = STC_Engine::resolve( $scenario['visible'], $scenario['after'], $case['direction'], $case['risk_bp'], $case['double'], $case['capital'] );
	hti_games_check( stc_norm( $got ) === stc_norm( $case['result'] ), $label . ' resolves identically in both ports' );

	$at_risk = STC_Engine::at_risk( $case['capital'], $case['risk_bp'], $case['double'] ? Config::STC_DOUBLE : 1 );
	hti_games_check( $at_risk === $case['at_risk'], $label . ' puts the same dollars at risk in both ports' );

	$after = STC_Engine::apply( $case['capital'], $got['pnl'] );
	hti_games_check( stc_norm( $after ) === stc_norm( $case['after'] ), $label . ' leaves the account in the same state' );
	// Cast: JSON writes a whole float as `1`, which decodes to an int.
	hti_games_check( STC_Engine::survival( $after['capital'] ) === (float) $case['survival'], $label . ' reports the same survival fraction' );
}

foreach ( $fixture['survival'] as $case ) {
	hti_games_check( STC_Engine::survival( $case['capital'] ) === (float) $case['survival'], sprintf( 'survival at $%d matches the JavaScript', $case['capital'] ) );
}

foreach ( $fixture['apply'] as $case ) {
	$got = STC_Engine::apply( $case['capital'], $case['pnl'] );
	hti_games_check( stc_norm( $got ) === stc_norm( $case['out'] ), sprintf( '$%d %+d books identically in both ports', $case['capital'], $case['pnl'] ) );
}

foreach ( $fixture['ruin'] as $case ) {
	$got = STC_Engine::losses_to_ruin( $case['risk_bp'], $case['double'] );
	hti_games_check( $got === $case['losses'], sprintf( '%d bp%s takes %d losses to ruin in both ports', $case['risk_bp'], $case['double'] ? ' doubled' : '', $case['losses'] ) );
}

/*
 * ---------------------------------------------------------------------------
 * Hand-built: the rules themselves, on candles small enough to check by eye.
 * Fourteen 100-tick candles closing at 100000 give an ATR of exactly 100, so
 * a long stops at 99900 and targets at 100150.
 * ---------------------------------------------------------------------------
 */
$visible = stc_flat( 14, 100000, 100 );

echo "\nATR is the mean range of the last fourteen candles the player could see\n";
hti_games_check( 100 === STC_Engine::atr( $visible, 14 ), 'fourteen 100-tick candles give an ATR of 100' );
hti_games_check( 0 === STC_Engine::atr( stc_flat( 13, 100000, 100 ), 14 ), 'a window short of fourteen candles gives 0 rather than a partial average' );
hti_games_check( 0 === STC_Engine::atr( array(), 14 ), 'and so does no window at all' );
hti_games_check( 0 === STC_Engine::atr( $visible, 0 ), 'a period of zero gives 0 rather than a division by zero' );

$mixed = array_merge( stc_flat( 13, 100000, 100 ), array( stc_candle( 100000, 100150, 99950, 100000 ) ) );
hti_games_check( 107 === STC_Engine::atr( $mixed, 14 ), 'the last candle counts: thirteen 100s and one 200 average to 107 (truncated, not rounded)' );
hti_games_check( 100 === STC_Engine::atr( array_merge( stc_flat( 5, 100000, 900 ), $visible ), 14 ), 'candles before the window do not count' );

echo "\nThe stop is one ATR against the position and the target one and a half with it\n";
$long = STC_Engine::levels( 100000, 100, 'buy' );
hti_games_check( 99900 === $long['stop'], 'a long stops one ATR below entry' );
hti_games_check( 100150 === $long['target'], 'and targets one and a half above' );
$short = STC_Engine::levels( 100000, 100, 'sell' );
hti_games_check( 100100 === $short['stop'], 'a short stops one ATR above entry' );
hti_games_check( 99850 === $short['target'], 'and targets one and a half below' );
hti_games_check( 15000 === STC_Engine::r_target(), 'the payout multiple is the same 1.5 the level is drawn at' );

echo "\nA candle that reaches both levels is a stop\n";
$tie = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100160, 99890, 100000 ) ), 'buy', 100, false, 10000 );
hti_games_check( 'stop' === $tie['outcome'], 'a long candle whose range contains both levels resolves as a stop' );
hti_games_check( -100 === $tie['pnl'], 'and is paid as a full stop, not as a 1.5R win' );
hti_games_check( 1 === $tie['candle'], 'on the candle it happened, not later' );

$tie_short = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100160, 99800, 100000 ) ), 'sell', 100, false, 10000 );
hti_games_check( 'stop' === $tie_short['outcome'], 'the same tie on a short is also a stop — the pessimism is symmetric' );

echo "\nOtherwise, whichever level the candles reach first\n";
$won = STC_Engine::resolve(
	$visible,
	array( stc_candle( 100000, 100040, 99960, 100020 ), stc_candle( 100020, 100150, 99950, 100100 ) ),
	'buy',
	100,
	false,
	10000
);
hti_games_check( 'target' === $won['outcome'] && 2 === $won['candle'], 'a target reached on the second candle is a win on the second candle' );
hti_games_check( 150 === $won['pnl'], 'a 1% target at $10,000 pays $150 — one and a half times the $100 at risk' );
hti_games_check( 100150 === $won['exit'], 'and the trade exits at the target, not at the candle close' );

$lost = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100050, 99900, 99920 ) ), 'buy', 100, false, 10000 );
hti_games_check( 'stop' === $lost['outcome'], 'a low that touches the stop exactly is a stop' );
hti_games_check( -100 === $lost['pnl'], 'and costs exactly the dollars the tier put at risk' );

echo "\nNeither level inside the window: paid at the fraction of R it reached\n";
$mid = STC_Engine::resolve(
	$visible,
	array( stc_candle( 100000, 100040, 99960, 100020 ), stc_candle( 100020, 100090, 99980, 100050 ) ),
	'buy',
	100,
	false,
	10000
);
hti_games_check( 'open' === $mid['outcome'] && 0 === $mid['candle'], 'a walk that touches nothing is still open at the end' );
hti_games_check( 5000 === $mid['r_bp'], 'half an ATR above entry is +0.5R' );
hti_games_check( 50 === $mid['pnl'], 'which pays half the $100 at risk' );

$mirror = STC_Engine::resolve(
	$visible,
	array( stc_candle( 100000, 100040, 99960, 100020 ), stc_candle( 100020, 100090, 99980, 100050 ) ),
	'sell',
	100,
	false,
	10000
);
hti_games_check( -5000 === $mirror['r_bp'] && -50 === $mirror['pnl'], 'the same drift sold is the mirror loss' );

$none = STC_Engine::resolve( $visible, array(), 'buy', 200, false, 10000 );
hti_games_check( 'open' === $none['outcome'] && 0 === $none['pnl'], 'a scenario with no outcome candles costs nothing' );
hti_games_check( 100000 === $none['exit'], 'and exits at the entry it never left' );

// The clamp cannot be reached by real candles — a close beyond a level implies
// a high or low beyond it, which would have touched. Only malformed data gets
// here, which is exactly what the clamp is for.
$broken_up = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100100, 99950, 100400 ) ), 'buy', 100, false, 10000 );
hti_games_check( 15000 === $broken_up['r_bp'], 'a malformed candle closing four ATRs up is clamped to +1.5R' );
hti_games_check( 150 === $broken_up['pnl'], 'so a broken scenario can never pay more than a winning trade' );

$broken_down = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100050, 99950, 99000 ) ), 'buy', 100, false, 10000 );
hti_games_check( -15000 === $broken_down['r_bp'] && -150 === $broken_down['pnl'], 'and is clamped to -1.5R on the way down' );

$flat = STC_Engine::resolve( stc_flat( 14, 100000, 0 ), array( stc_candle( 100000, 100000, 100000, 100000 ) ), 'buy', 100, false, 10000 );
hti_games_check( 0 === $flat['atr'] && 0 === $flat['pnl'], 'a chart with no range at all resolves flat rather than stopping instantly' );

echo "\nPassing costs nothing and still says what would have happened\n";
$passed = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100040, 99960, 100050 ) ), 'pass', 100, false, 10000 );
hti_games_check( 'pass' === $passed['outcome'] && 0 === $passed['pnl'], 'a pass books nothing' );
hti_games_check( 0 === $passed['stop'] && 0 === $passed['target'], 'and has no levels, because there is no position' );
hti_games_check( is_array( $passed['would'] ), 'the result still reports what would have happened' );
hti_games_check( 50 === $passed['would']['buy']['pnl'], 'a buy would have made $50 here' );
hti_games_check( -50 === $passed['would']['sell']['pnl'], 'and a sell would have lost $50' );
hti_games_check( null === $won['would'], 'a decision that was taken reports no counterfactual' );

$garbage = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100040, 99960, 100050 ) ), "buy' OR 1=1", 100, false, 10000 );
hti_games_check( 'pass' === $garbage['direction'], 'a direction that is not one of the three is read as a pass' );

echo "\nThe multiplier doubles both sides, and nothing else\n";
$win_x1 = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100150, 99950, 100100 ) ), 'buy', 100, false, 10000 );
$win_x2 = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100150, 99950, 100100 ) ), 'buy', 100, true, 10000 );
hti_games_check( 150 === $win_x1['pnl'] && 300 === $win_x2['pnl'], 'a doubled win pays twice as much' );
$loss_x1 = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100050, 99900, 99920 ) ), 'buy', 100, false, 10000 );
$loss_x2 = STC_Engine::resolve( $visible, array( stc_candle( 100000, 100050, 99900, 99920 ) ), 'buy', 100, true, 10000 );
hti_games_check( -100 === $loss_x1['pnl'] && -200 === $loss_x2['pnl'], 'and a doubled loss costs twice as much' );
hti_games_check( $win_x2['stop'] === $win_x1['stop'] && $win_x2['target'] === $win_x1['target'], 'the multiplier moves no level — it is stake, not leverage' );

echo "\nThe stake is exactly the figure the risk screen showed\n";
hti_games_check( 100 === STC_Engine::at_risk( 10000, 100, 1 ), '1% of $10,000 is $100 at risk' );
hti_games_check( 500 === STC_Engine::at_risk( 10000, 250, 2 ), 'and a doubled 2.5% is $500' );
hti_games_check( 20 === STC_Engine::at_risk( 4100, 50, 1 ), 'the amount at risk truncates, so it is never more than the tier chosen' );
hti_games_check( -20 === STC_Engine::cash( 4100, 50, 1, STC_Engine::R_STOP ), 'and a stop costs precisely that, to the dollar' );
hti_games_check( 0 === STC_Engine::at_risk( 10000, -100, 1 ), 'a negative tier risks nothing rather than inverting the game' );

echo "\nA negative half-dollar rounds away from zero, end to end\n";
$half = STC_Engine::resolve(
	$visible,
	array( stc_candle( 100000, 100010, 99930, 99960 ), stc_candle( 99960, 99980, 99920, 99935 ) ),
	'buy',
	50,
	false,
	2000
);
hti_games_check( -6500 === $half['r_bp'], '65 ticks against a 100-tick ATR is -0.65R' );
hti_games_check( -7 === $half['pnl'], '-0.65R on $10 at risk is -$6.50, which books as -$7 — Math.round() would book -$6' );

echo "\nThe floor is <=, and hitting it resets the run\n";
hti_games_check( array( 'capital' => 10000, 'died' => true ) === STC_Engine::apply( 1300, -300 ), 'landing exactly on $1,000 is a blown account' );
hti_games_check( array( 'capital' => 1001, 'died' => false ) === STC_Engine::apply( 1301, -300 ), 'and $1,001 survives' );
hti_games_check( true === STC_Engine::apply( 500, -100 )['died'], 'so does anything below it' );
hti_games_check( Config::CAPITAL_START === STC_Engine::apply( 1200, -500 )['capital'], 'a dead run resets to the starting capital, ready for tomorrow' );
hti_games_check( 10150 === STC_Engine::apply( 10000, 150 )['capital'], 'a live run just carries its balance forward' );

echo "\nThe survival bar is a fraction of the nine thousand dollars a run can lose\n";
hti_games_check( 0.0 === STC_Engine::survival( 1000 ), 'at the floor the bar is empty' );
hti_games_check( 0.0 === STC_Engine::survival( 500 ), 'and it does not go negative below it' );
hti_games_check( 1.0 === STC_Engine::survival( 10000 ), 'at the starting capital it is full' );
hti_games_check( 1.0 === STC_Engine::survival( 20000 ), 'and it does not overflow above it' );
hti_games_check( 0.5 === STC_Engine::survival( 5500 ), 'halfway down is half a bar' );
hti_games_check( STC_Engine::survival( 1001 ) > 0.0, 'a dollar above the floor is still alive' );

echo "\nHow many losses in a row it takes, compounding\n";
hti_games_check( 460 === STC_Engine::losses_to_ruin( 50 ), 'at 0.5% it takes 460 losses, not the linear 180' );
hti_games_check( 230 === STC_Engine::losses_to_ruin( 100 ), 'at 1% it takes 230, not 90' );
hti_games_check( 114 === STC_Engine::losses_to_ruin( 200 ), 'at 2% it takes 114, not the 45 the prototype claimed' );
hti_games_check( 45 === STC_Engine::losses_to_ruin( 500 ), 'at 5% it takes 45' );
hti_games_check( 22 === STC_Engine::losses_to_ruin( 1000 ), 'at 10% it takes 22, not the nine the prototype claimed' );
hti_games_check( 9 === STC_Engine::losses_to_ruin( 2500 ), 'at 25% it takes 9 — doubling the risk from 10% quarters the runway' );
hti_games_check( 11 === STC_Engine::losses_to_ruin( 1000, true ), 'a doubled 10% tier is a 20% tier: 11 losses' );
hti_games_check( 4 === STC_Engine::losses_to_ruin( 2500, true ), 'and a doubled 25% is half the account a day: 4' );
hti_games_check( 0 === STC_Engine::losses_to_ruin( 0 ), 'risking nothing never blows up' );
hti_games_check( 1 === STC_Engine::losses_to_ruin( 10000 ), 'risking everything blows up on the first loss' );
hti_games_check( 1 === STC_Engine::losses_to_ruin( 5000, true ), 'and so does a doubled 50%' );

hti_games_done();
