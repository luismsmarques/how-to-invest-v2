<?php
/**
 * Test for the retention cutoff used by the profile-pruning cron (L1).
 *
 *   php wp-content/plugins/hti-engine/tests/test-cron.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Cron;

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

echo "\n=== Retention cutoff ===\n";

$now = gmmktime( 12, 0, 0, 6, 18, 2026 ); // 2026-06-18 12:00:00 UTC.

check( '2026-03-20 12:00:00' === Cron::cutoff_gmt( 90, $now ), '90-day cutoff is 90 days earlier' );
check( '2026-06-17 12:00:00' === Cron::cutoff_gmt( 1, $now ), '1-day cutoff is one day earlier' );
check( Cron::cutoff_gmt( 30, $now ) < gmdate( 'Y-m-d H:i:s', $now ), 'cutoff is in the past' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
