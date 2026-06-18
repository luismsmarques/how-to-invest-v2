<?php
/**
 * Tests for the settings normalizers (req. 6.7) — admins can edit scoring and
 * allocation ranges, but invalid input is rejected so the engine can always
 * produce a valid 100% allocation.
 *
 *   php wp-content/plugins/hti-engine/tests/test-settings.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Engine;
use HTI\Engine\Config;
use HTI\Engine\Settings;

$failures = 0;
$passes   = 0;

/**
 * Assert helper.
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

$scoring_def    = Config::default_scoring();
$archetypes_def = Config::default_archetypes();

echo "\n=== Scoring normalizer ===\n";

// Empty input → defaults, no errors.
$r = Settings::normalize_scoring( array(), $scoring_def );
check( array() === $r['errors'], 'empty input yields no errors' );
check( $r['value']['thresholds'] === $scoring_def['thresholds'], 'thresholds default through' );
check( $r['value']['weights'] === $scoring_def['weights'], 'weights default through' );

// Valid edit: nudge a weight, keep thresholds covering the new max.
$input = array(
	'weights'    => $scoring_def['weights'],
	'thresholds' => $scoring_def['thresholds'],
);
$r = Settings::normalize_scoring( $input, $scoring_def );
check( array() === $r['errors'], 'mirrored input is accepted' );

// Invalid thresholds (non-contiguous) → reverted + error.
$bad                       = $input;
$bad['thresholds'][3]      = array( 12, 14 ); // leaves a gap before archetype 4.
$r                         = Settings::normalize_scoring( $bad, $scoring_def );
check( ! empty( $r['errors'] ), 'non-contiguous thresholds raise an error' );
check( $r['value']['thresholds'] === $scoring_def['thresholds'], 'bad thresholds revert to defaults' );

// Weights clamp to 0..20.
$bad                              = $input;
$bad['weights']['p1_horizon']['3y'] = 999;
$r                                = Settings::normalize_scoring( $bad, $scoring_def );
check( 20 === $r['value']['weights']['p1_horizon']['3y'], 'weights clamp to max 20' );

echo "\n=== Archetypes normalizer ===\n";

// Empty input → defaults.
$r = Settings::normalize_archetypes( array(), $archetypes_def );
check( array() === $r['errors'], 'empty input yields no errors' );
check( $r['value'] === $archetypes_def, 'archetypes default through' );

// Crypto min is forced to 0.
$input                                      = $archetypes_def;
$input[3]['ranges']['crypto']               = array( 2, 3 );
$r                                          = Settings::normalize_archetypes( $input, $archetypes_def );
check( 0 === $r['value'][3]['ranges']['crypto'][0], 'crypto min is forced to 0' );

// Impossible ranges (mins exceed 100) → reverted + error.
$input                                  = $archetypes_def;
$input[3]['ranges']['global_equity']    = array( 90, 95 );
$input[3]['ranges']['bonds']            = array( 90, 95 );
$r                                      = Settings::normalize_archetypes( $input, $archetypes_def );
check( ! empty( $r['errors'] ), 'unsatisfiable ranges raise an error' );
check( $r['value'][3]['ranges'] === $archetypes_def[3]['ranges'], 'bad ranges revert to defaults' );

// Empty label falls back to the default label.
$input                          = $archetypes_def;
$input[1]['label']['en']        = '   ';
$r                              = Settings::normalize_archetypes( $input, $archetypes_def );
check( $r['value'][1]['label']['en'] === $archetypes_def[1]['label']['en'], 'empty label falls back to default' );

// A custom label is kept (and stripped of tags).
$input                   = $archetypes_def;
$input[1]['label']['en'] = '<b>Capital guard</b>';
$r                       = Settings::normalize_archetypes( $input, $archetypes_def );
check( 'Capital guard' === $r['value'][1]['label']['en'], 'custom label kept and tag-stripped' );

echo "\n=== Normalized config still drives a valid engine ===\n";
$norm_scoring    = Settings::normalize_scoring( array(), $scoring_def )['value'];
$norm_archetypes = Settings::normalize_archetypes( array(), $archetypes_def )['value'];
$answers         = array(
	'p1_horizon'        => 'over_15y',
	'p2_goal'           => 'maximize',
	'p3_drop_reaction'  => 'buy_more',
	'p4_capacity'       => 'significant',
	'p5_experience'     => 'confident',
	'p6_emergency_fund' => true,
	'p8_crypto'         => 'yes',
);
$result = Engine::recommend( $answers, $norm_scoring, $norm_archetypes );
$sum    = array_sum( array_column( $result['allocation'], 'pct' ) );
check( 100 === $sum, 'engine allocation from normalized config sums to 100' );
check( 5 === $result['archetype_id'], 'engine still maps max score to archetype 5' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
