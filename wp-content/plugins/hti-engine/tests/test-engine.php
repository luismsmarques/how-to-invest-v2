<?php
/**
 * Repeatable test matrix for the deterministic engine (Criterios §1).
 *
 * Runs as a plain CLI script — no WordPress, no PHPUnit:
 *   php wp-content/plugins/hti-engine/tests/test-engine.php
 *
 * Covers: one profile per archetype (5), one per safety trap (3), crypto
 * granted, crypto blocked (two ways), ESG-is-a-lens, threshold boundaries,
 * and determinism. Exits non-zero on any failure.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Engine;
use HTI\Engine\Config;

$failures = 0;
$passes   = 0;

/**
 * Assert a condition, recording the outcome.
 *
 * @param bool   $cond  Condition.
 * @param string $label Description.
 */
function check( bool $cond, string $label ): void {
	global $failures, $passes;
	if ( $cond ) {
		++$passes;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}

/**
 * Shared invariants every result must satisfy.
 *
 * @param array<string,mixed> $result     Engine result.
 * @param array<int,mixed>    $archetypes Archetype config.
 */
function assert_invariants( array $result, array $archetypes ): void {
	$alloc = $result['allocation'];
	$sum   = array_sum( array_column( $alloc, 'pct' ) );
	check( 100 === $sum, "allocation sums to 100 (got {$sum})" );

	$classes = array_column( $alloc, 'class' );
	check( $classes === array_values( array_unique( $classes ) ), 'no duplicate classes' );
	check( array() === array_diff( $classes, Engine::CLASSES ), 'only known asset classes' );

	$ranges = $archetypes[ $result['archetype_id'] ]['ranges'];
	$ok     = true;
	foreach ( $alloc as $slice ) {
		$r = $ranges[ $slice['class'] ];
		if ( $slice['pct'] < $r[0] || $slice['pct'] > $r[1] ) {
			$ok = false;
		}
	}
	check( $ok, 'every slice within its curated range' );

	// Crypto rule: only present if archetype >= 3 and no emergency-fund trap.
	$has_crypto = in_array( 'crypto', $classes, true );
	if ( $has_crypto ) {
		$valid = $result['archetype_id'] >= 3 && ! in_array( 'no_emergency_fund', $result['safety_flags'], true );
		check( $valid, 'crypto only when archetype >= 3 and emergency fund present' );
	}
}

/**
 * Render an allocation compactly for the log.
 *
 * @param list<array{class:string,pct:int}> $alloc Allocation.
 */
function fmt( array $alloc ): string {
	return implode( ' ', array_map( static fn( $s ) => "{$s['class']}:{$s['pct']}", $alloc ) );
}

$archetypes = Config::default_archetypes();
$scoring    = Config::default_scoring();

/**
 * Base answer set; override per scenario.
 *
 * @return array<string,mixed>
 */
function base(): array {
	return array(
		'p1_horizon'        => '7_15y',
		'p2_goal'           => 'accumulate',
		'p3_drop_reaction'  => 'hold',
		'p4_capacity'       => 'comfortable',
		'p5_experience'     => 'some',
		'p6_emergency_fund' => true,
		'p7_esg'            => 'no',
		'p8_crypto'         => 'no',
	);
}

// name => [answers, expected_archetype, expected_flags (sorted)].
$scenarios = array(
	'A1 Preservation (low score)' => array(
		array( 'p1_horizon' => '3_7y', 'p2_goal' => 'protect', 'p3_drop_reaction' => 'sell_all', 'p4_capacity' => 'almost_none', 'p5_experience' => 'never' ),
		1,
		array(),
	),
	'A2 Balanced income' => array(
		array( 'p1_horizon' => '7_15y', 'p2_goal' => 'grow', 'p3_drop_reaction' => 'sell_part', 'p4_capacity' => 'almost_none', 'p5_experience' => 'never' ),
		2,
		array(),
	),
	'A3 Balanced' => array(
		array( 'p1_horizon' => '7_15y', 'p2_goal' => 'accumulate', 'p3_drop_reaction' => 'hold', 'p4_capacity' => 'small', 'p5_experience' => 'never' ),
		3,
		array(),
	),
	'A4 Growth' => array(
		array( 'p1_horizon' => 'over_15y', 'p2_goal' => 'accumulate', 'p3_drop_reaction' => 'hold', 'p4_capacity' => 'comfortable', 'p5_experience' => 'little' ),
		4,
		array(),
	),
	'A5 Aggressive growth (max score)' => array(
		array( 'p1_horizon' => 'over_15y', 'p2_goal' => 'maximize', 'p3_drop_reaction' => 'buy_more', 'p4_capacity' => 'significant', 'p5_experience' => 'confident' ),
		5,
		array(),
	),
	'Trap 1 — no emergency fund' => array(
		array( 'p1_horizon' => '7_15y', 'p2_goal' => 'accumulate', 'p3_drop_reaction' => 'hold', 'p4_capacity' => 'small', 'p5_experience' => 'never', 'p6_emergency_fund' => false ),
		3,
		array( 'no_emergency_fund' ),
	),
	'Trap 2 — horizon override (high score, 3y)' => array(
		array( 'p1_horizon' => '3y', 'p2_goal' => 'maximize', 'p3_drop_reaction' => 'buy_more', 'p4_capacity' => 'significant', 'p5_experience' => 'confident' ),
		2,
		array( 'horizon_override' ),
	),
	'Trap 3 — crypto blocked (low archetype requests crypto)' => array(
		array( 'p1_horizon' => '7_15y', 'p2_goal' => 'grow', 'p3_drop_reaction' => 'sell_part', 'p4_capacity' => 'almost_none', 'p5_experience' => 'never', 'p8_crypto' => 'yes' ),
		2,
		array( 'crypto_blocked' ),
	),
	'Crypto granted (A4, has EF)' => array(
		array( 'p1_horizon' => 'over_15y', 'p2_goal' => 'accumulate', 'p3_drop_reaction' => 'hold', 'p4_capacity' => 'comfortable', 'p5_experience' => 'little', 'p8_crypto' => 'yes' ),
		4,
		array(),
	),
	'Crypto granted (A3, has EF)' => array(
		array( 'p1_horizon' => '7_15y', 'p2_goal' => 'accumulate', 'p3_drop_reaction' => 'hold', 'p4_capacity' => 'small', 'p5_experience' => 'never', 'p8_crypto' => 'yes' ),
		3,
		array(),
	),
	'Crypto blocked via Trap 1 (A5 raw, no EF)' => array(
		array( 'p1_horizon' => 'over_15y', 'p2_goal' => 'maximize', 'p3_drop_reaction' => 'buy_more', 'p4_capacity' => 'significant', 'p5_experience' => 'confident', 'p6_emergency_fund' => false, 'p8_crypto' => 'yes' ),
		5,
		array( 'no_emergency_fund', 'crypto_blocked' ),
	),
);

echo "\n=== Engine test matrix (rules v" . Engine::VERSION . ") ===\n";

foreach ( $scenarios as $name => $spec ) {
	list( $override, $expected_archetype, $expected_flags ) = $spec;
	$answers = array_merge( base(), $override );
	$result  = Engine::recommend( $answers, $scoring, $archetypes );

	echo "\n• {$name}  →  score {$result['score']}, archetype {$result['archetype_id']}, "
		. 'flags [' . implode( ',', $result['safety_flags'] ) . ']'
		. "\n    alloc: " . fmt( $result['allocation'] ) . "\n";

	check( $result['archetype_id'] === $expected_archetype, "archetype is {$expected_archetype}" );

	$got_flags = $result['safety_flags'];
	sort( $got_flags );
	$want_flags = $expected_flags;
	sort( $want_flags );
	check( $got_flags === $want_flags, 'flags are [' . implode( ',', $expected_flags ) . ']' );

	$has_crypto = in_array( 'crypto', array_column( $result['allocation'], 'class' ), true );
	if ( str_contains( $name, 'Crypto granted' ) ) {
		check( $has_crypto, 'crypto slice present' );
	}
	if ( str_contains( $name, 'blocked' ) ) {
		check( ! $has_crypto, 'crypto slice absent' );
	}

	assert_invariants( $result, $archetypes );
}

// ESG is a lens: toggling p7 must not change the decision.
echo "\n• ESG is a lens (p7 must not change the result)\n";
$a            = array_merge( base(), array( 'p7_esg' => 'no' ) );
$b            = array_merge( base(), array( 'p7_esg' => 'yes' ) );
$ra           = Engine::recommend( $a, $scoring, $archetypes );
$rb           = Engine::recommend( $b, $scoring, $archetypes );
check( $ra === $rb, 'identical result regardless of ESG answer' );

// Determinism: same input twice → identical output.
echo "\n• Determinism (same input twice)\n";
$max  = array_merge( base(), array( 'p1_horizon' => 'over_15y', 'p2_goal' => 'maximize', 'p3_drop_reaction' => 'buy_more', 'p4_capacity' => 'significant', 'p5_experience' => 'confident' ) );
check( Engine::recommend( $max, $scoring, $archetypes ) === Engine::recommend( $max, $scoring, $archetypes ), 'repeatable output' );

// Threshold boundaries map to the right archetype.
echo "\n• Threshold boundaries (0,5,6,11,12,17,18,23,24,27)\n";
$boundaries = array(
	0  => 1,
	5  => 1,
	6  => 2,
	11 => 2,
	12 => 3,
	17 => 3,
	18 => 4,
	23 => 4,
	24 => 5,
	27 => 5,
);
foreach ( $boundaries as $score => $expected ) {
	$got = Engine::archetype_for_score( $score, $scoring['thresholds'] );
	check( $got === $expected, "score {$score} → archetype {$expected}" );
}

// Invalid input is rejected (REST will map this to 422).
echo "\n• Invalid input rejected\n";
$threw = false;
try {
	Engine::recommend( array_merge( base(), array( 'p1_horizon' => 'bogus' ) ), $scoring, $archetypes );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
check( $threw, 'invalid answer throws InvalidArgumentException' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
