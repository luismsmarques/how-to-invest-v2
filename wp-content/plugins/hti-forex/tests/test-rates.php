<?php
/**
 * Rates layer tests: accept() validation and effective() precedence.
 *
 *   php wp-content/plugins/hti-forex/tests/test-rates.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-rates.php';

use HTI\Forex\Rates;
use HTI\Forex\Settings;

$passes   = 0;
$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Label.
 */
function check( bool $cond, string $label ): void {
	global $passes, $failures;
	if ( $cond ) {
		++$passes;
		echo "\033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "\033[31m✗\033[0m {$label}\n";
	}
}

$now  = 1_756_000_000; // Fixed "now" for determinism.
$prev = array(
	'rates'      => array(
		'USDINR' => 87.5,
		'USDJPY' => 148.0,
	),
	'date'       => '2026-08-01',
	'fetched_at' => $now - 3600,
	'source'     => 'frankfurter',
);

// --- accept() ---------------------------------------------------------------
$api = array(
	'date'  => '2026-08-25',
	'rates' => array(
		'INR' => 88.1234,
		'JPY' => 147.31,
	),
);
$r   = Rates::accept( $api, $prev, $now );
check( 88.1234 === $r['rates']['USDINR'], 'valid payload: USDINR stored' );
check( 147.31 === $r['rates']['USDJPY'], 'valid payload: USDJPY stored' );
check( '2026-08-25' === $r['date'], 'valid payload: API date kept' );
check( $now === $r['fetched_at'], 'valid payload: fetched_at stamped' );
check( 'frankfurter' === $r['source'], 'valid payload: source recorded' );

check( $prev === Rates::accept( array(), $prev, $now ), 'empty payload keeps previous state' );
check( $prev === Rates::accept( array( 'rates' => array( 'INR' => 88 ) ), $prev, $now ), 'missing JPY keeps previous state' );
check( $prev === Rates::accept( array( 'rates' => array( 'INR' => 'abc', 'JPY' => 147 ) ), $prev, $now ), 'non-numeric rate keeps previous state' );
check( $prev === Rates::accept( array( 'rates' => array( 'INR' => 8.3, 'JPY' => 147 ) ), $prev, $now ), 'implausible USDINR (8.3) keeps previous state' );
check( $prev === Rates::accept( array( 'rates' => array( 'INR' => 88, 'JPY' => 9000 ) ), $prev, $now ), 'implausible USDJPY keeps previous state' );

$r = Rates::accept( array( 'date' => 'yesterday', 'rates' => array( 'INR' => 88, 'JPY' => 147 ) ), $prev, $now );
check( gmdate( 'Y-m-d', $now ) === $r['date'], 'malformed API date falls back to today (UTC)' );

// --- effective(): fallback when nothing stored ------------------------------
$GLOBALS['__hti_options'] = array();
$e                        = Rates::effective( $now );
check( 'fallback' === $e['source'], 'no stored option → shipped fallback' );
check( true === $e['stale'], 'fallback is always marked stale' );
check( $e['rates']['USDINR'] > 0 && $e['rates']['USDJPY'] > 0, 'fallback rates are usable' );

// --- effective(): fetched ---------------------------------------------------
update_option( Rates::OPTION, $prev );
$e = Rates::effective( $now );
check( 87.5 === $e['rates']['USDINR'], 'fetched rate is used' );
check( false === $e['stale'], 'hour-old fetch is not stale' );
check( 'frankfurter' === $e['source'], 'source reports frankfurter' );

$old               = $prev;
$old['fetched_at'] = $now - 8 * DAY_IN_SECONDS;
update_option( Rates::OPTION, $old );
$e = Rates::effective( $now );
check( true === $e['stale'], 'week-old fetch is marked stale' );

// --- effective(): override wins ---------------------------------------------
update_option( Rates::OPTION, $prev );
update_option( Settings::OPTION, array( 'rate_override_usdinr' => 90.5 ) );
$e = Rates::effective( $now );
check( 90.5 === $e['rates']['USDINR'], 'admin override beats the fetched rate' );
check( 148.0 === $e['rates']['USDJPY'], 'non-overridden symbol keeps the fetched rate' );
check( 'override' === $e['source'], 'override reports its source' );
check( false === $e['stale'], 'override is never stale' );

update_option( Settings::OPTION, array() );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
