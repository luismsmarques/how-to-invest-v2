<?php
/**
 * Session-window tests: IST conversions across DST transitions, plus the
 * PHP-side pair-table lock (the JS side is locked in test-forex-core.mjs).
 *
 *   php wp-content/plugins/hti-forex/tests/test-sessions.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Forex\Config;

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

/**
 * Session windows keyed by id for a UTC date string.
 *
 * @param string $iso UTC instant.
 * @return array<string,array<string,mixed>>
 */
function windows_at( string $iso ): array {
	$day = new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
	$out = array();
	foreach ( Config::session_windows_ist( $day ) as $w ) {
		$out[ $w['id'] ] = $w;
	}
	return $out;
}

// --- Pair table lock (mirrors forex-core.js PAIRS) --------------------------
$pairs = Config::pairs();
check( array( 'EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'USDINR' ) === array_keys( $pairs ), 'pairs(): expected symbols in order' );
check( 0.0001 === $pairs['EURUSD']['pip_size'] && 100000 === $pairs['EURUSD']['contract_size'] && 'USD' === $pairs['EURUSD']['quote'], 'EURUSD spec' );
check( 0.01 === $pairs['USDJPY']['pip_size'] && 'JPY' === $pairs['USDJPY']['quote'], 'USDJPY spec (JPY quote, 0.01 pip)' );
check( 0.10 === $pairs['XAUUSD']['pip_size'] && 100 === $pairs['XAUUSD']['contract_size'], 'XAUUSD spec ($0.10 pip on 100oz)' );
check( 0.0025 === $pairs['USDINR']['pip_size'] && 'INR' === $pairs['USDINR']['quote'], 'USDINR spec (0.0025 tick, INR quote)' );

// --- Winter (both hemispheres on standard time) -----------------------------
$w = windows_at( '2026-01-15 12:00' );
check( '13:30' === $w['london']['open_ist'], 'winter: London opens 13:30 IST' );
check( '22:30' === $w['london']['close_ist'], 'winter: London closes 22:30 IST' );
check( '18:30' === $w['new_york']['open_ist'], 'winter: New York opens 18:30 IST' );
check( '03:30' === $w['new_york']['close_ist'] && $w['new_york']['closes_next_day'], 'winter: New York closes 03:30 IST next day' );
check( '05:30' === $w['tokyo']['open_ist'] && '14:30' === $w['tokyo']['close_ist'], 'Tokyo 05:30–14:30 IST (no DST in Japan)' );

$o = Config::overlap_london_ny_ist( new DateTimeImmutable( '2026-01-15 12:00', new DateTimeZone( 'UTC' ) ) );
check( '18:30' === $o['start_ist'] && '22:30' === $o['end_ist'], 'winter overlap 18:30–22:30 IST' );

// --- Summer (both on DST) ---------------------------------------------------
$w = windows_at( '2026-07-15 12:00' );
check( '12:30' === $w['london']['open_ist'], 'summer: London opens 12:30 IST' );
$o = Config::overlap_london_ny_ist( new DateTimeImmutable( '2026-07-15 12:00', new DateTimeZone( 'UTC' ) ) );
check( '17:30' === $o['start_ist'] && '21:30' === $o['end_ist'], 'summer overlap 17:30–21:30 IST' );

// --- March desync (US on DST since Mar 8; UK still on GMT until Mar 29) -----
$o = Config::overlap_london_ny_ist( new DateTimeImmutable( '2026-03-10 12:00', new DateTimeZone( 'UTC' ) ) );
check( '17:30' === $o['start_ist'] && '22:30' === $o['end_ist'], 'March desync overlap 17:30–22:30 IST' );

// --- October desync (UK back on GMT Oct 25; US on DST until Nov 1) ----------
$o = Config::overlap_london_ny_ist( new DateTimeImmutable( '2026-10-28 12:00', new DateTimeZone( 'UTC' ) ) );
check( '17:30' === $o['start_ist'] && '22:30' === $o['end_ist'], 'October desync overlap 17:30–22:30 IST' );

// --- FAQ config sanity ------------------------------------------------------
foreach ( array( 'hub', 'position_size', 'pip_value', 'sessions', 'profit_loss', 'xauusd', 'small_account', 'leverage' ) as $page ) {
	$faqs = Config::faqs( $page );
	check( count( $faqs ) >= 3, "faqs('{$page}') has at least 3 entries" );
	$well_formed = true;
	foreach ( $faqs as $faq ) {
		if ( empty( $faq['q'] ) || empty( $faq['a'] ) || false !== strpos( $faq['a'], '<' ) ) {
			$well_formed = false;
		}
	}
	check( $well_formed, "faqs('{$page}') entries are plain-text q/a pairs" );
}
check( array() === Config::faqs( 'nope' ), 'unknown page yields no FAQs' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
