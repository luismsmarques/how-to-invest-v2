<?php
/**
 * Tests for the /recommend KPI metrics — engine-success-rate (llm vs fallback
 * vs error) and time-to-result p95 (PRD §7). Covers Metrics::record_recommend,
 * the rec/lat aggregation in totals(), and the p95 histogram estimate.
 *
 *   php wp-content/plugins/hti-engine/tests/test-metrics.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// In-memory option store (bootstrap only ships transient shims).
$GLOBALS['__hti_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['__hti_options'][ $key ] ?? $default;
	}
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $key, $value ) {
		$GLOBALS['__hti_options'][ $key ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../includes/class-metrics.php';

use HTI\Engine\Metrics;

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

echo "\nMetrics — KPI instrumentation\n";

// --- latency_p95 -----------------------------------------------------------
// total 100, 95th percentile falls in the '1-2' band (cumulative 90 → 96).
check( '1-2' === Metrics::latency_p95( array( '0-1' => 90, '1-2' => 6, '2-4' => 3, '4-8' => 1 ) ), 'p95 picks the 95th-percentile band' );
check( null === Metrics::latency_p95( array() ), 'p95 is null with no data' );
check( '16+' === Metrics::latency_p95( array( '16+' => 5 ) ), 'p95 handles a single top bucket' );
check( '0-1' === Metrics::latency_p95( array( '0-1' => 100 ) ), 'p95 is the fast bucket when all fast' );

// --- record_recommend + totals aggregation ---------------------------------
$GLOBALS['__hti_options'] = array();
Metrics::record_recommend( 'ok_llm', 500 );        // 0-1
Metrics::record_recommend( 'ok_llm', 1500 );       // 1-2
Metrics::record_recommend( 'ok_fallback', 3000 );  // 2-4
Metrics::record_recommend( 'error', 20000 );       // 16+
Metrics::record_recommend( 'bogus', 100 );         // coerced → error, 0-1

$t = Metrics::totals( 7 );

check( 2 === (int) ( $t['rec']['ok_llm'] ?? 0 ), 'ok_llm counted' );
check( 1 === (int) ( $t['rec']['ok_fallback'] ?? 0 ), 'ok_fallback counted' );
check( 2 === (int) ( $t['rec']['error'] ?? 0 ), 'error counted (invalid outcome coerced to error)' );
check( 2 === (int) ( $t['lat']['0-1'] ?? 0 ), 'latency bucket 0-1 counted' );
check( 1 === (int) ( $t['lat']['1-2'] ?? 0 ), 'latency bucket 1-2 counted' );
check( 1 === (int) ( $t['lat']['2-4'] ?? 0 ), 'latency bucket 2-4 counted' );
check( 1 === (int) ( $t['lat']['16+'] ?? 0 ), 'latency bucket 16+ counted' );

// engine-success-rate (flow) = ok / (ok + error) = 3 / 5 = 60%.
$ok    = (int) ( $t['rec']['ok_llm'] ?? 0 ) + (int) ( $t['rec']['ok_fallback'] ?? 0 );
$total = $ok + (int) ( $t['rec']['error'] ?? 0 );
check( 5 === $total && 60.0 === round( $ok / $total * 100, 1 ), 'flow success-rate derives from rec buckets' );

// LLM-explained rate = ok_llm / ok = 2 / 3 ≈ 66.7%.
check( 66.7 === round( 2 / $ok * 100, 1 ), 'LLM-explained rate derives from rec buckets' );

echo "\n";
if ( $failures ) {
	echo "\033[31mFAILED\033[0m {$passes} passed, {$failures} failed\n";
	exit( 1 );
}
echo "\033[32mPASSED\033[0m {$passes} checks\n";
exit( 0 );
