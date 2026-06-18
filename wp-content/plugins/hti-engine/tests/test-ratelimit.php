<?php
/**
 * Tests for the per-IP rate limiter (security hardening, M1).
 *
 *   php wp-content/plugins/hti-engine/tests/test-ratelimit.php
 *
 * Uses the in-memory transient shims from bootstrap.php.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\RateLimit;

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

// Tighten the limits for a deterministic test (via the filter registry).
add_filter(
	'hti_rate_limits',
	static function () {
		return array(
			'login'    => array( 3, 900 ),
			'register' => array( 2, 3600 ),
		);
	}
);

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

echo "\n=== Rate limiter ===\n";

// login: allow 3, block the 4th.
$results = array();
for ( $i = 0; $i < 4; $i++ ) {
	$results[] = RateLimit::exceeded( 'login' );
}
check( array( false, false, false, true ) === $results, 'login allows 3 then blocks the 4th' );

// A different IP has its own bucket.
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
check( false === RateLimit::exceeded( 'login' ), 'a different IP is not blocked' );

// register: independent action bucket (limit 2).
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check( false === RateLimit::exceeded( 'register' ), 'register attempt 1 allowed' );
check( false === RateLimit::exceeded( 'register' ), 'register attempt 2 allowed' );
check( true === RateLimit::exceeded( 'register' ), 'register attempt 3 blocked' );
// login for that IP is still blocked (separate bucket, already over).
check( true === RateLimit::exceeded( 'login' ), 'login bucket unaffected by register' );

// Unknown action is never limited.
check( false === RateLimit::exceeded( 'nope' ), 'unknown action is not limited' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
