<?php
/**
 * Tests for the fallback copy and the LLM-output validator (Criterios §2).
 *
 *   php wp-content/plugins/hti-engine/tests/test-explainer.php
 *
 * Verifies that the curated fallback always validates, and that the validator
 * rejects named instruments, invented percentages, wrong language, missing
 * safety messages, mismatched class keys and schema violations.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Engine;
use HTI\Engine\Config;
use HTI\Engine\Fallback;
use HTI\Engine\Validator;

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

$scoring    = Config::default_scoring();
$archetypes = Config::default_archetypes();

// A4 with crypto granted, plus a trap case for safety-message coverage.
$granted = Engine::recommend(
	array(
		'p1_horizon'        => 'over_15y',
		'p2_goal'           => 'accumulate',
		'p3_drop_reaction'  => 'hold',
		'p4_capacity'       => 'comfortable',
		'p5_experience'     => 'little',
		'p6_emergency_fund' => true,
		'p8_crypto'         => 'yes',
	),
	$scoring,
	$archetypes
);

$trapped = Engine::recommend(
	array(
		'p1_horizon'        => '7_15y',
		'p2_goal'           => 'accumulate',
		'p3_drop_reaction'  => 'hold',
		'p4_capacity'       => 'small',
		'p5_experience'     => 'never',
		'p6_emergency_fund' => false,
	),
	$scoring,
	$archetypes
);

echo "\n=== Fallback validates ===\n";
foreach ( array( 'en', 'pt' ) as $locale ) {
	$fb = Fallback::build( $granted, $locale );
	$errs = Validator::errors( $fb, $granted, $locale );
	check( array() === $errs, "granted/{$locale} fallback is valid (" . implode( '; ', $errs ) . ')' );

	$fb2  = Fallback::build( $trapped, $locale );
	$errs2 = Validator::errors( $fb2, $trapped, $locale );
	check( array() === $errs2, "trapped/{$locale} fallback is valid (" . implode( '; ', $errs2 ) . ')' );
	check( null !== $fb2['safety_message'], "trapped/{$locale} has a safety_message" );
}

echo "\n=== Validator rejects bad output ===\n";
$base = Fallback::build( $granted, 'en' );

// Named instrument.
$bad = $base;
$bad['why_archetype'] = 'A profile like this often considers holding the S&P 500 and some Bitcoin for the long run, illustratively.';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects named instruments (S&P, Bitcoin)' );

// Ticker-like token.
$bad = $base;
$bad['class_notes']['bonds'] = 'A steady class that some access through tickers like VWCE in practice, illustratively speaking here.';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects ticker-like tokens (VWCE)' );

// Invented percentage.
$bad = $base;
$bad['why_archetype'] = 'An example for a profile like this might lean around 73% toward growth assets over the long term here.';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects a percentage not in the allocation (73%)' );

// Allocation percentage is allowed.
$allowed_pct = $granted['allocation'][0]['pct'];
$ok          = $base;
$ok['why_archetype'] = "An example for a profile like this can place roughly {$allowed_pct}% in global shares, illustratively.";
check( Validator::is_valid( $ok, $granted, 'en' ), "allows a percentage that IS in the allocation ({$allowed_pct}%)" );

// Wrong language (English text requested as pt).
check( ! Validator::is_valid( $base, $granted, 'pt' ), 'rejects English text when pt requested' );

// Wrong language (pt text requested as en).
$pt = Fallback::build( $granted, 'pt' );
check( ! Validator::is_valid( $pt, $granted, 'en' ), 'rejects Portuguese text when en requested' );

// Missing safety message when a trap fired.
$bad = Fallback::build( $trapped, 'en' );
$bad['safety_message'] = null;
check( ! Validator::is_valid( $bad, $trapped, 'en' ), 'rejects null safety_message when a trap fired' );

// class_notes keys mismatch.
$bad = $base;
$bad['class_notes']['gold'] = 'An extra class that is not part of the fixed allocation at all here, illustratively.';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects class_notes keys that do not match the allocation' );

// Schema: too-short why_archetype.
$bad = $base;
$bad['why_archetype'] = 'Too short.';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects why_archetype below min length' );

// Schema: unexpected extra key.
$bad           = $base;
$bad['extra']  = 'nope';
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects unexpected top-level keys' );

// Schema: missing key.
$bad = $base;
unset( $bad['safety_message'] );
check( ! Validator::is_valid( $bad, $granted, 'en' ), 'rejects missing required key' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
