<?php
/**
 * Test for the LLM transport helper (code-fence stripping).
 *
 *   php wp-content/plugins/hti-engine/tests/test-llm.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\LLM;

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

echo "\n=== strip_fences ===\n";

$json = '{"why_archetype":"x"}';

check( $json === LLM::strip_fences( $json ), 'plain JSON is unchanged' );
check( $json === LLM::strip_fences( "```json\n" . $json . "\n```" ), 'strips a ```json fence' );
check( $json === LLM::strip_fences( "```\n" . $json . "\n```" ), 'strips a bare ``` fence' );
check( $json === LLM::strip_fences( "  " . $json . "  " ), 'trims surrounding whitespace' );

$decoded = json_decode( LLM::strip_fences( "```json\n" . $json . "\n```" ), true );
check( is_array( $decoded ) && 'x' === $decoded['why_archetype'], 'result decodes to the expected object' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
