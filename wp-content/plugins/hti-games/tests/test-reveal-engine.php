<?php
/**
 * The Reveal: the engine, and its agreement with the JavaScript.
 *
 * The same two halves as test-stc-engine.php — the shared parity fixture
 * first, then hand-built cases for the rules behind it. The one worth reading
 * is the last block: The Reveal does not decide death itself, it hands its
 * capital to the same STC_Engine::apply() the chart game uses, and these tests
 * assert that the floor really is one rule and not two copies of one.
 *
 *   php wp-content/plugins/hti-games/tests/test-reveal-engine.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-reveal-engine.php';

use HTI\Games\Config;
use HTI\Games\Reveal_Engine;
use HTI\Games\STC_Engine;

/**
 * Recursively key-sort a decoded structure so comparison ignores key order but
 * still catches a missing key, an extra one or a changed type.
 *
 * @param mixed $value Decoded JSON.
 * @return mixed
 */
function reveal_norm( $value ) {
	if ( is_array( $value ) ) {
		ksort( $value );
		return array_map( 'reveal_norm', $value );
	}
	return $value;
}

$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/parity.json' ), true );

echo "The parity fixture is readable\n";
hti_games_check( is_array( $fixture ), 'tests/fixtures/parity.json exists and decodes' );

echo "\nThe JavaScript's copy of the config table matches Config\n";
$js = $fixture['config']['reveal'];
hti_games_check( Config::CAPITAL_START === $js['capital_start'], 'the JS starts a run at the same capital' );
hti_games_check( Config::CAPITAL_FLOOR === $js['capital_floor'], 'and dies at the same floor' );
hti_games_check( Config::REVEAL_INDEX_STEP_BP === $js['index_step_bp'], 'the index compounds by the same step' );
hti_games_check( Config::REVEAL_SIZES === $js['sizes'], 'the offered sizes are the same four' );
hti_games_check( Config::REVEAL_MIN_AGE_YEARS === $js['min_age_years'], 'a named company must be the same number of years in the past' );

echo "\nEvery P&L agrees with the JavaScript\n";
foreach ( $fixture['reveal_pnl'] as $case ) {
	$label = sprintf( '$%s at %d%% against %+.2f%%', number_format( $case['capital'] ), $case['size_pct'], $case['r_bp'] / 100 );
	hti_games_check( Reveal_Engine::committed( $case['capital'], $case['size_pct'] ) === $case['committed'], $label . ' commits the same dollars in both ports' );
	hti_games_check( Reveal_Engine::pnl( $case['capital'], $case['size_pct'], $case['r_bp'] ) === $case['pnl'], $label . ' pays the same in both ports' );
}

foreach ( $fixture['reveal_index'] as $case ) {
	$label = sprintf( 'an index at $%s against %+.2f%%', number_format( $case['index_cap'] ), $case['r_idx_bp'] / 100 );
	hti_games_check( Reveal_Engine::index_pnl( $case['index_cap'], $case['r_idx_bp'] ) === $case['index_pnl'], $label . ' moves the same in both ports' );
	hti_games_check( Reveal_Engine::index_step( $case['index_cap'], $case['r_idx_bp'] ) === $case['index_step'], $label . ' compounds to the same balance' );
}

foreach ( $fixture['reveal'] as $case ) {
	$got   = Reveal_Engine::resolve( $case['r_bp'], $case['r_idx_bp'], $case['decision'], $case['size_pct'], $case['capital'], $case['index_cap'] );
	$label = sprintf( '%s %d%% of $%s against %+.2f%%', $case['decision'], $case['size_pct'], number_format( $case['capital'] ), $case['r_bp'] / 100 );
	hti_games_check( reveal_norm( $got ) === reveal_norm( $case['result'] ), $label . ' resolves identically in both ports' );
}

/*
 * ---------------------------------------------------------------------------
 * Hand-built: the rules themselves.
 * ---------------------------------------------------------------------------
 */
echo "\nThe P&L is the real return applied to what was really committed\n";
hti_games_check( 1820 === Reveal_Engine::pnl( 10000, 10, 18200 ), '10% of $10,000 into a company that returned 182% makes $1,820' );
hti_games_check( -5000 === Reveal_Engine::pnl( 10000, 50, -10000 ), 'half the account into a company that went to zero loses half the account' );
hti_games_check( 0 === Reveal_Engine::pnl( 10000, 25, 0 ), 'a company that went nowhere pays nothing' );
hti_games_check( 0 === Reveal_Engine::pnl( 10000, 0, 18200 ), 'nothing committed makes nothing, however well the company did' );
hti_games_check( 0 === Reveal_Engine::pnl( 10000, -25, 18200 ), 'and a negative size commits nothing rather than shorting the case' );
hti_games_check( 2500 === Reveal_Engine::committed( 10000, 25 ), 'a quarter of $10,000 is $2,500 on the table' );
hti_games_check( 205 === Reveal_Engine::committed( 4100, 5 ), 'the committed amount truncates, so it is never more than the share chosen' );

echo "\nA negative half-dollar rounds away from zero here too\n";
hti_games_check( 50 === Reveal_Engine::committed( 1010, 5 ), '5% of $1,010 is $50 committed' );
hti_games_check( -7 === Reveal_Engine::pnl( 1010, 5, -1300 ), '$50 against -13% is -$6.50, which books as -$7 — Math.round() would book -$6' );
hti_games_check( -66 === Reveal_Engine::index_pnl( 1005, -6550 ), 'and the index rounds the same way, at -$65.50' );

echo "\nThe index player compounds whatever the player does\n";
hti_games_check( 600 === Reveal_Engine::index_pnl( 10000, 6000 ), 'a tenth of a 60% index return is $600 on $10,000' );
hti_games_check( 10600 === Reveal_Engine::index_step( 10000, 6000 ), 'which compounds the index balance to $10,600' );
hti_games_check( 9700 === Reveal_Engine::index_step( 10000, -3000 ), 'and a losing period compounds it down' );
hti_games_check( 10000 === Reveal_Engine::index_step( 10000, 0 ), 'a flat index moves nothing' );

$flat = Reveal_Engine::index_step( Reveal_Engine::index_step( Reveal_Engine::index_step( 10000, 1000 ), 1000 ), 1000 );
hti_games_check( 10303 === $flat, 'three steps compound rather than adding — 10,100 then 10,201 then 10,303' );

echo "\nPassing is a real answer and is priced as one\n";
$passed = Reveal_Engine::resolve( 18200, 6000, 'pass', 25, 10000, 10000 );
hti_games_check( 'pass' === $passed['decision'] && 0 === $passed['pnl'], 'a pass books nothing' );
hti_games_check( 0 === $passed['committed'] && 0 === $passed['size_pct'], 'and commits nothing' );
hti_games_check( 10000 === $passed['capital'], 'so the account is where it was' );
hti_games_check( 10600 === $passed['index_cap'], 'but the index moved anyway — a pass is not measured against zero' );

$empty = Reveal_Engine::resolve( 18200, 6000, 'invest', 0, 10000, 10000 );
hti_games_check( 'pass' === $empty['decision'], 'an "invest" with no size behind it is a pass, whatever it calls itself' );

$garbage = Reveal_Engine::resolve( 18200, 6000, "invest' OR 1=1", 25, 10000, 10000 );
hti_games_check( 'invest' === $garbage['decision'] && 2500 === $garbage['committed'], 'an unrecognised decision with a real size is still a commitment, not a silent free bet' );

echo "\nThe three lines\n";
$won  = Reveal_Engine::resolve( 18200, 6000, 'invest', 25, 10000, 10000 );
$keys = array_column( $won['lines'], 'key' );
hti_games_check( array( 'you', 'pass', 'index' ) === $keys, 'the result always carries all three lines, in the same order' );
hti_games_check( 4550 === $won['lines'][0]['pnl'], 'the first line is what the player did' );
hti_games_check( 0 === $won['lines'][1]['pnl'], 'the second is always zero, and is shown anyway' );
hti_games_check( 600 === $won['lines'][2]['pnl'], 'the third is what the index did over the same period' );
hti_games_check( array( array( 'key' => 'you', 'pnl' => -50 ), array( 'key' => 'pass', 'pnl' => 0 ), array( 'key' => 'index', 'pnl' => 120 ) ) === Reveal_Engine::three_lines( -50, 120 ), 'the lines are keys, not sentences — the wording is bilingual and lives elsewhere' );

echo "\nDeath is the same rule as the chart game, because it is the same function\n";
$ruinous = Reveal_Engine::resolve( -10000, 6000, 'invest', 50, 2000, 10000 );
hti_games_check( -1000 === $ruinous['pnl'] && 1000 === $ruinous['capital'], 'half of $2,000 into a company that went to zero leaves $1,000' );
hti_games_check( true === STC_Engine::apply( 2000, $ruinous['pnl'] )['died'], 'and $1,000 is death here exactly as it is on the charts' );
hti_games_check( Config::CAPITAL_START === STC_Engine::apply( 2000, $ruinous['pnl'] )['capital'], 'the run resets to the starting capital' );

$survived = Reveal_Engine::resolve( -10000, 6000, 'invest', 50, 2002, 10000 );
hti_games_check( 1001 === $survived['capital'], 'a dollar more in the account and the same case leaves $1,001' );
hti_games_check( false === STC_Engine::apply( 2002, $survived['pnl'] )['died'], 'which survives, on the same <= floor' );

hti_games_check( ! method_exists( Reveal_Engine::class, 'apply' ), 'The Reveal has no floor of its own to drift out of step with' );

hti_games_done();
