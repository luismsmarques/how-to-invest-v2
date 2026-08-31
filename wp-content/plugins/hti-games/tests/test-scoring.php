<?php
/**
 * What the run rows say about a player.
 *
 * The block worth reading is the last one. The daily board ranks by a
 * risk-normalised score rather than by raw P&L, and these assertions are the
 * executable version of why: two players who read the same chart the same way
 * score the same, whatever they staked. If someone ever "simplifies"
 * board_score() back to returning the P&L, the assertion that says so fails by
 * name.
 *
 *   php wp-content/plugins/hti-games/tests/test-scoring.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-scoring.php';

use HTI\Games\Config;
use HTI\Games\Scoring;

/**
 * A day key N days after the base date.
 *
 * @param int $offset Days from 2026-08-01.
 */
function on_day( int $offset ): string {
	return gmdate( 'Y-m-d', (int) strtotime( '2026-08-01 00:00:00 UTC' ) + ( $offset * 86400 ) );
}

/**
 * One run row.
 *
 * @param int    $offset   Days from 2026-08-01.
 * @param string $decision 'buy', 'sell', 'pass' or 'invest'.
 * @param int    $risk_bp  Risk tier in basis points.
 * @param int    $pnl      Whole dollars, signed.
 * @param bool   $died     Whether this row blew the account.
 */
function run_row( int $offset, string $decision, int $risk_bp, int $pnl, bool $died = false ): array {
	return array(
		'day'      => on_day( $offset ),
		'decision' => $decision,
		'outcome'  => $pnl > 0 ? 'target' : ( $pnl < 0 ? 'stop' : 'open' ),
		'risk_bp'  => $risk_bp,
		'pnl'      => $pnl,
		'died'     => $died,
	);
}

echo "A streak is consecutive days, and a pass is one of them\n";
$played = array( run_row( 0, 'buy', 100, 150 ), run_row( 1, 'pass', 0, 0 ), run_row( 2, 'sell', 100, -100 ) );
hti_games_check( 3 === Scoring::streak_from( $played ), 'three days in a row is a streak of three' );
hti_games_check( 3 === Scoring::streak_from( array_reverse( $played ) ), 'and the rows do not have to arrive in order' );
hti_games_check( 0 === Scoring::streak_from( array() ), 'a player who has never played has no streak' );
hti_games_check( 1 === Scoring::streak_from( array( run_row( 0, 'pass', 0, 0 ) ) ), 'a single pass still starts a streak — the streak measures showing up, not trading' );

$gapped = array( run_row( 0, 'buy', 100, 150 ), run_row( 1, 'buy', 100, 150 ), run_row( 4, 'buy', 100, 150 ) );
hti_games_check( 1 === Scoring::streak_from( $gapped ), 'a missed day ends the streak — consecutive means consecutive' );

$blown = array( run_row( 0, 'buy', 100, 150 ), run_row( 1, 'buy', 2500, -9000, true ), run_row( 2, 'buy', 100, 150 ) );
hti_games_check( 1 === Scoring::streak_from( $blown ), 'a death ends the streak, and the day after it starts a new one' );
hti_games_check( 0 === Scoring::streak_from( array( run_row( 0, 'buy', 100, 150 ), run_row( 1, 'buy', 2500, -9000, true ) ) ), 'the run that died is not counted — the streak belonged to that account' );

echo "\nAverage risk describes sizing, not attendance\n";
hti_games_check( 100 === Scoring::average_risk_bp( array( run_row( 0, 'buy', 50, 0 ), run_row( 1, 'buy', 150, 0 ) ) ), 'two positions at 0.5% and 1.5% average to 1%' );
hti_games_check( 0 === Scoring::average_risk_bp( array() ), 'a player who has staked nothing has no average' );
hti_games_check( 0 === Scoring::average_risk_bp( array( run_row( 0, 'pass', 0, 0 ) ) ), 'and neither does one who has only passed' );

$one_big = array( run_row( 0, 'buy', 2500, -2500 ) );
for ( $i = 1; $i < 10; $i++ ) {
	$one_big[] = run_row( $i, 'pass', 0, 0 );
}
hti_games_check( 2500 === Scoring::average_risk_bp( $one_big ), 'nine passes do not dilute one 25% position down to 250 bp — passes are not small positions' );
hti_games_check( 133 === Scoring::average_risk_bp( array( run_row( 0, 'buy', 100, 0 ), run_row( 1, 'buy', 100, 0 ), run_row( 2, 'buy', 200, 0 ) ) ), 'the average rounds rather than truncating' );

echo "\nRisk per week is the learning metric: it should trend down\n";
$learning = array();
for ( $i = 0; $i < 7; $i++ ) {
	$learning[] = run_row( $i, 'buy', 1000, -1000 );
}
for ( $i = 7; $i < 14; $i++ ) {
	$learning[] = run_row( $i, 'buy', 100, -100 );
}
$weeks = Scoring::risk_by_week( $learning, 2 );
hti_games_check( 2 === count( $weeks ), 'two weeks asked for, two weeks returned' );
hti_games_check( 1000 === $weeks[0]['average_bp'], 'the older week averaged 10%' );
hti_games_check( 100 === $weeks[1]['average_bp'], 'the newer week averaged 1%' );
hti_games_check( $weeks[1]['average_bp'] < $weeks[0]['average_bp'], 'oldest first, so the chart reads left to right and the learning is visible' );
hti_games_check( 7 === $weeks[0]['runs'] && 7 === $weeks[1]['runs'], 'each week counts its own positions' );
hti_games_check( on_day( 13 ) === $weeks[1]['to'], 'the most recent bucket ends on the most recent row' );
hti_games_check( on_day( 7 ) === $weeks[1]['from'], 'and covers the seven days up to it' );

$sparse = Scoring::risk_by_week( array( run_row( 13, 'buy', 100, 0 ) ), 2 );
hti_games_check( 0 === $sparse[0]['runs'] && 0 === $sparse[0]['average_bp'], 'a week with no positions reports zero runs, so the chart can skip it rather than draw a collapse in risk' );
hti_games_check( array() === Scoring::risk_by_week( array(), 4 ), 'with no rows there is nothing to anchor weeks to' );

echo "\nThe calendar is one cell per day, including the days nobody played\n";
$rows = array(
	run_row( 25, 'buy', 100, 150 ),
	run_row( 26, 'buy', 100, -100 ),
	run_row( 27, 'pass', 0, 0 ),
	run_row( 28, 'buy', 100, 0 ),
	run_row( 29, 'buy', 2500, -9000, true ),
);
$grid = Scoring::calendar( $rows, on_day( 29 ), Scoring::MONTH );
hti_games_check( 28 === count( $grid ), 'twenty-eight days asked for, twenty-eight cells returned' );
hti_games_check( on_day( 29 ) === $grid[27]['day'], 'the last cell is today' );
hti_games_check( on_day( 2 ) === $grid[0]['day'], 'and the first is twenty-seven days before it' );
hti_games_check( 'missed' === $grid[0]['state'], 'a day with no row at all is missed — a habit is visible in the gaps' );
hti_games_check( 'won' === $grid[23]['state'], 'a profitable day is won' );
hti_games_check( 'lost' === $grid[24]['state'], 'a losing day is lost' );
hti_games_check( 'passed' === $grid[25]['state'], 'a pass is its own state, not a loss' );
hti_games_check( 'flat' === $grid[26]['state'], 'and a position that rounds to exactly zero dollars is flat, because calling it a loss would be a small lie repeated daily' );
hti_games_check( true === $grid[27]['died'], 'the day the account died is marked as such' );
hti_games_check( 'lost' === $grid[27]['state'] && -9000 === $grid[27]['pnl'], 'and still carries its state and its damage' );
hti_games_check( 0 === $grid[0]['pnl'] && false === $grid[0]['died'], 'a missed day is neutral rather than absent' );
hti_games_check( array() === Scoring::calendar( $rows, '2026-02-30', 28 ), 'a day key that is not a real date yields no grid rather than a guess' );
hti_games_check( 7 === count( Scoring::calendar( $rows, on_day( 29 ), 7 ) ), 'the window length is the caller\'s choice' );

echo "\nBadges reward showing up, passing and sizing down — never a big number\n";
$badges = array_column( Scoring::badges( $played, array( 'capital' => 10000 ) ), null, 'key' );
hti_games_check( isset( $badges['first_chart'], $badges['week'], $badges['month'], $badges['patience'], $badges['small_size'], $badges['de_risked'], $badges['blown'], $badges['survivor'] ), 'the whole table is returned, earned or not, so the profile can show what is still ahead' );
hti_games_check( true === $badges['first_chart']['earned'], 'playing once earns the first badge' );
hti_games_check( false === $badges['week']['earned'], 'three days is not a week' );
hti_games_check( 3 === $badges['week']['progress'] && 7 === $badges['week']['target'], 'and the progress towards it is reported' );

$month = array();
for ( $i = 0; $i < 28; $i++ ) {
	$month[] = run_row( $i, 'buy', 50, 25 );
}
$earned = array_column( Scoring::badges( $month, array( 'capital' => 10700 ) ), null, 'key' );
hti_games_check( true === $earned['week']['earned'] && true === $earned['month']['earned'], 'twenty-eight consecutive days earns both streak badges' );
hti_games_check( true === $earned['small_size']['earned'], 'twenty-eight positions at 0.5% earns the restraint badge' );
hti_games_check( true === $earned['survivor']['earned'], 'and finishing above the starting capital earns survivor' );
hti_games_check( false === $earned['blown']['earned'], 'a player who has never died has not earned the blown badge' );

$died = array_column( Scoring::badges( $blown, array( 'capital' => 10150 ) ), null, 'key' );
hti_games_check( true === $died['blown']['earned'], 'blowing the account earns a badge — the game marks the lesson rather than hiding it' );

$patient = array();
for ( $i = 0; $i < 5; $i++ ) {
	$patient[] = run_row( $i, 'pass', 0, 0 );
}
$quiet = array_column( Scoring::badges( $patient, array( 'capital' => 10000 ) ), null, 'key' );
hti_games_check( true === $quiet['patience']['earned'], 'five passes earn patience — declining to trade is a decision the game rewards' );
hti_games_check( false === $quiet['small_size']['earned'], 'but passing is not sizing, so it earns nothing for restraint' );

$calmer = array_column( Scoring::badges( $learning, array( 'capital' => 3000 ) ), null, 'key' );
hti_games_check( true === $calmer['de_risked']['earned'], 'sizing smaller this week than last earns the badge the whole game is about' );
$louder = array_column( Scoring::badges( array_reverse( $learning ), array( 'capital' => 3000 ) ), null, 'key' );
hti_games_check( true === $louder['de_risked']['earned'], 'and the rows do not have to arrive in order for it to be seen' );

$oversized = array();
for ( $i = 0; $i < 14; $i++ ) {
	$oversized[] = run_row( $i, 'buy', $i < 7 ? 100 : 1000, -100 );
}
$rising = array_column( Scoring::badges( $oversized, array( 'capital' => 3000 ) ), null, 'key' );
hti_games_check( false === $rising['de_risked']['earned'], 'sizing up does not' );

$mixed = array( run_row( 0, 'buy', 100, 0 ), run_row( 1, 'pass', 0, 0 ), run_row( 2, 'buy', 50, 0 ) );
$tail  = array_column( Scoring::badges( $mixed, array( 'capital' => 10000 ) ), null, 'key' );
hti_games_check( 2 === $tail['small_size']['progress'], 'a pass in the middle neither earns restraint nor costs it' );

echo "\nThe board ranks by what a decision was worth per unit of risk\n";
hti_games_check( 300 === Scoring::board_score( 300, 100 ), 'at the 1% tier the score is simply the P&L' );
hti_games_check( 150 === Scoring::board_score( 300, 200 ), 'the same dollars at twice the size score half as much' );
hti_games_check( 300 === Scoring::board_score( 7500, 2500 ), 'and a 25% position that made $7,500 scores exactly what a 1% position that read the chart the same way did' );
hti_games_check(
	Scoring::board_score( 150, 100 ) === Scoring::board_score( 3750, 2500 ),
	'two players who read the same chart the same way score the same, whatever they staked — which is the entire reason this is not raw P&L'
);
hti_games_check( Scoring::board_score( 3750, 2500 ) < 3750, 'so betting the account buys no position on the board, only the drawdown' );
hti_games_check( -150 === Scoring::board_score( -300, 200 ), 'losses normalise the same way' );
hti_games_check( 0 === Scoring::board_score( 0, 0 ), 'a pass scores zero' );
hti_games_check( 0 === Scoring::board_score( 500, 0 ), 'and so does anything with no risk to divide by, rather than a division by zero' );
hti_games_check( -13 === Scoring::board_score( -25, 200 ), 'a half-point score rounds away from zero, as every other number here does' );
hti_games_check( 13 === Scoring::board_score( 25, 200 ), 'symmetrically' );
hti_games_check( Config::STC_RISK_BP[1] === Scoring::SMALL_BP, 'the normalising tier is one the game actually offers' );

hti_games_done();
