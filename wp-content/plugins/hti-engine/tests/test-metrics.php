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

// --- broker events (broker-affiliate section) ------------------------------
$GLOBALS['__hti_options'] = array();

Metrics::bump( 'broker_click', array( 'broker' => 'xtb', 'location' => 'review' ) );
Metrics::bump( 'broker_click', array( 'broker' => 'xtb', 'location' => 'compare' ) );
Metrics::bump( 'broker_click', array( 'broker' => 'lightyear', 'location' => 'review' ) );
Metrics::bump( 'result_broker_view', array( 'archetype' => 3 ) );
Metrics::bump( 'broker_compare_view' );
Metrics::bump( 'bogus_broker_event' ); // not allowlisted → ignored.

$day  = gmdate( 'Y-m-d' );
$data = $GLOBALS['__hti_options']['hti_metrics'][ $day ] ?? array();

check( 3 === (int) ( $data['e']['broker_click'] ?? 0 ), 'broker_click counted' );
check( 2 === (int) ( $data['bkr']['xtb'] ?? 0 ), 'per-broker breakdown counted (xtb)' );
check( 1 === (int) ( $data['bkr']['lightyear'] ?? 0 ), 'per-broker breakdown counted (lightyear)' );
check( 2 === (int) ( $data['bkr_loc']['review'] ?? 0 ), 'per-location breakdown counted' );
check( 1 === (int) ( $data['e']['result_broker_view'] ?? 0 ), 'result_broker_view counted' );
check( 1 === (int) ( $data['bkr_arch'][3] ?? 0 ), 'partner-module archetype breakdown counted' );
check( 1 === (int) ( $data['e']['broker_compare_view'] ?? 0 ), 'broker_compare_view counted' );
check( ! isset( $data['e']['bogus_broker_event'] ), 'unknown event ignored' );

// --- Campaign attribution (paid traffic the GA reports cannot see) ----------
$norm = new ReflectionMethod( 'HTI\\Engine\\Metrics', 'norm_campaign' );
$norm->setAccessible( true );

check( 'propeller_fx' === $norm->invoke( null, 'Propeller_FX' ), 'campaign is lowercased' );
check( 'promo-2026' === $norm->invoke( null, 'promo-2026' ), 'hyphens and digits survive' );
check( 'abc' === $norm->invoke( null, 'a b/c' ), 'separators are stripped' );
check( 32 === strlen( $norm->invoke( null, str_repeat( 'a', 80 ) ) ), 'campaign is capped at 32 characters' );
check( 'script' === $norm->invoke( null, '<script>' ), 'markup characters are stripped' );
check( 0 === preg_match( '/[^a-z0-9_\-]/', $norm->invoke( null, '"><img src=x>' ) ), 'a hostile value leaves only safe characters' );

Metrics::bump( 'page_view', array( 'path' => '/forex/', 'campaign' => 'propeller_fx' ) );
Metrics::bump( 'page_view', array( 'path' => '/forex/', 'campaign' => 'propeller_fx' ) );
Metrics::bump( 'page_view', array( 'path' => '/learn/', 'campaign' => 'newsletter' ) );
Metrics::bump( 'page_view', array( 'path' => '/learn/' ) ); // organic: no campaign.

$day  = gmdate( 'Y-m-d' );
$data = $GLOBALS['__hti_options']['hti_metrics'][ $day ] ?? array();

check( 2 === (int) ( $data['camp']['propeller_fx'] ?? 0 ), 'campaign page views aggregate per campaign' );
check( 1 === (int) ( $data['camp']['newsletter'] ?? 0 ), 'a second campaign is counted separately' );
check( 3 === array_sum( $data['camp'] ?? array() ), 'page views without a campaign are not attributed' );

$totals = Metrics::totals( 30 );
check( 2 === (int) ( $totals['camp']['propeller_fx'] ?? 0 ), 'campaigns survive the totals aggregation' );

// --- Custom date range ------------------------------------------------------
// Seed three distinct days directly, so the range filter is tested against
// known data rather than whatever "today" happens to hold.
$d1 = gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS );
$d2 = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
$d3 = gmdate( 'Y-m-d', time() - 1 * DAY_IN_SECONDS );

$GLOBALS['__hti_options']['hti_metrics'] = array(
	$d1 => array( 'e' => array( 'page_view' => 5 ), 'camp' => array( 'propeller_fx' => 5 ) ),
	$d2 => array( 'e' => array( 'page_view' => 7 ), 'camp' => array( 'propeller_fx' => 7 ) ),
	$d3 => array( 'e' => array( 'page_view' => 9 ), 'camp' => array( 'newsletter' => 9 ) ),
);

$r = Metrics::totals_between( $d1, $d3 );
check( 21 === (int) ( $r['e']['page_view'] ?? 0 ), 'a full range sums every day in it' );

$r = Metrics::totals_between( $d2, $d2 );
check( 7 === (int) ( $r['e']['page_view'] ?? 0 ), 'a single day reports only that day' );

$r = Metrics::totals_between( $d1, $d2 );
check( 12 === (int) ( $r['e']['page_view'] ?? 0 ), 'both bounds are inclusive' );
check( 12 === (int) ( $r['camp']['propeller_fx'] ?? 0 ), 'campaign breakdown respects the range' );
check( ! isset( $r['camp']['newsletter'] ), 'a day outside the range is excluded' );

$r = Metrics::totals_between( $d3, $d1 );
check( 21 === (int) ( $r['e']['page_view'] ?? 0 ), 'a reversed range is swapped, not empty' );

$fallback = Metrics::totals_between( 'not-a-date', $d3 );
check( is_array( $fallback ) && isset( $fallback['e'] ), 'an invalid date falls back to the default window instead of failing' );

$norm_date = new ReflectionMethod( 'HTI\\Engine\\Metrics', 'norm_date' );
$norm_date->setAccessible( true );
check( '2026-02-28' === $norm_date->invoke( null, '2026-02-28' ), 'a real date is accepted' );
check( '' === $norm_date->invoke( null, '2026-02-31' ), 'an impossible calendar day is rejected' );
check( '' === $norm_date->invoke( null, '2026-2-8' ), 'a malformed date is rejected' );
check( '' === $norm_date->invoke( null, "2026-01-01' OR 1=1" ), 'an injection attempt is rejected' );

echo "\n";
if ( $failures ) {
	echo "\033[31mFAILED\033[0m {$passes} passed, {$failures} failed\n";
	exit( 1 );
}
echo "\033[32mPASSED\033[0m {$passes} checks\n";
exit( 0 );
