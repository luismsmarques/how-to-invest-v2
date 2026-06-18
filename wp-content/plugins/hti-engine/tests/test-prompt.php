<?php
/**
 * Tests for the LLM prompt builder (Prompt_LLM_Schema §3).
 *
 *   php wp-content/plugins/hti-engine/tests/test-prompt.php
 *
 * Verifies the user prompt carries the fixed decision (allocation, archetype,
 * traps), the locale, the user answers and the curated class notes — the exact
 * inputs the model needs to explain without deciding.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Engine;
use HTI\Engine\Config;
use HTI\Engine\Prompt;

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

$answers = array(
	'p1_horizon'        => 'over_15y',
	'p2_goal'           => 'accumulate',
	'p3_drop_reaction'  => 'hold',
	'p4_capacity'       => 'comfortable',
	'p5_experience'     => 'little',
	'p6_emergency_fund' => true,
	'p7_esg'            => 'yes',
	'p8_crypto'         => 'yes',
);
$result  = Engine::recommend( $answers, $scoring, $archetypes );
$label   = $archetypes[ $result['archetype_id'] ]['label']['en'];

echo "\n=== Prompt builder ===\n";

$user = Prompt::build_user( $result, $answers, 'en', $label );

check( str_contains( $user, 'idioma: en' ), 'states the requested locale' );
check( str_contains( $user, '"class":"global_equity"' ) || str_contains( $user, '"class": "global_equity"' ), 'includes the fixed allocation JSON' );
check( str_contains( $user, (string) $result['archetype_id'] ), 'includes the archetype id' );
check( str_contains( $user, $label ), 'includes the archetype label' );
check( str_contains( $user, 'over_15y' ) && str_contains( $user, 'accumulate' ), 'includes the user answers' );
check( str_contains( $user, 'NÃO alterar' ), 'instructs not to change the numbers' );
check( str_contains( $user, 'growth engine of a portfolio' ), 'includes the curated class notes (factual base)' );

// Traps surface in the prompt.
$trapped = Engine::recommend(
	array_merge( $answers, array( 'p6_emergency_fund' => false ) ),
	$scoring,
	$archetypes
);
$user2 = Prompt::build_user( $trapped, $answers, 'pt', $label );
check( str_contains( $user2, 'no_emergency_fund' ), 'surfaces fired safety traps' );
check( str_contains( $user2, 'idioma: pt' ), 'honours the pt locale' );

// The system prompt carries the absolute rules.
check( str_contains( Prompt::SYSTEM, 'REGRAS ABSOLUTAS' ), 'system prompt states the absolute rules' );
check( str_contains( Prompt::SYSTEM, 'NUNCA menciones instrumentos' ), 'system prompt forbids naming instruments' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
