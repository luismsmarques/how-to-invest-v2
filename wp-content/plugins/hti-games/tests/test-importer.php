<?php
/**
 * The import pipeline, all of it pure.
 *
 * A bad candle file is not an abstract risk: a flat stretch, a duplicated
 * bar, a split that halves every price on one day, a feed that emits a low
 * above the open — all of them look like charts, and all of them would be
 * served to a player as though they were a market to read. So the validation
 * is where the importer's real work is, and this file is where it is proven.
 *
 *   php wp-content/plugins/hti-games/tests/test-importer.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-importer.php';

use HTI\Games\Config;
use HTI\Games\Importer;

/**
 * A deterministic candle series: a zigzag with a wandering middle.
 *
 * @param int $n   How many candles.
 * @param int $amp Half-range of a candle, in ticks.
 * @return array<int,array<int,int>> Rows of [ts, o, h, l, c].
 */
function hti_series( int $n, int $amp = 50 ): array {
	$rows = array();
	$base = (int) strtotime( '2020-01-01 00:00:00 UTC' );

	for ( $i = 0; $i < $n; $i++ ) {
		$mid    = 100000 + ( ( $i * 7 ) % 101 ) * $amp;
		$open   = $mid;
		$close  = $mid + ( 0 === $i % 2 ? $amp : -$amp );
		$rows[] = array(
			$base + $i * 3600,
			$open,
			max( $open, $close ) + $amp,
			min( $open, $close ) - $amp,
			$close,
		);
	}

	return $rows;
}

/**
 * Those rows as a CSV file, with a header.
 *
 * @param array<int,array<int,int|string>> $rows Rows.
 */
function hti_csv( array $rows ): string {
	$out = "timestamp,open,high,low,close\n";
	foreach ( $rows as $row ) {
		$out .= implode( ',', $row ) . "\n";
	}

	return $out;
}

$rows = hti_series( 600 );

echo "A clean CSV parses into integer ticks\n";
$parsed = Importer::parse( hti_csv( $rows ), 'csv', 1 );
hti_games_check( array() === $parsed['errors'], 'a clean file produces no errors (' . implode( '; ', $parsed['errors'] ) . ')' );
hti_games_check( 600 === count( $parsed['rows'] ), 'every candle survives, and the header row does not become one' );
hti_games_check( array( 'ts', 'o', 'h', 'l', 'c' ) === array_keys( $parsed['rows'][0] ), 'a row is a named quad plus its timestamp' );
hti_games_check( is_int( $parsed['rows'][0]['o'] ) && is_int( $parsed['rows'][0]['c'] ), 'prices come back as integers, never floats' );

echo "\nThe scale is applied once, at import, and never again\n";
$decimal = "timestamp,open,high,low,close\n";
foreach ( range( 0, 130 ) as $i ) {
	$decimal .= ( 1577836800 + $i * 60 ) . ',1.09120,1.09180,1.09080,1.09150' . "\n";
}
$scaled = Importer::parse( $decimal, 'csv', 100000 );
hti_games_check( array() !== $scaled['rows'] && 109120 === $scaled['rows'][0]['o'], '1.09120 at ×100000 is stored as 109120' );
hti_games_check( 109150 === $scaled['rows'][0]['c'], 'and 1.09150 as 109150 — no float reaches the decision path' );
hti_games_check( in_array( 'no usable scale was declared (7)', Importer::validate( $scaled['rows'], 7 ), true ), 'a scale that is not one of the offered ones is refused: the same file means something 100× different at the wrong one' );

echo "\nJSON says the same thing as CSV\n";
$json = array();
foreach ( array_slice( $rows, 0, 130 ) as $row ) {
	$json[] = array(
		'timestamp' => $row[0],
		'open'      => $row[1],
		'high'      => $row[2],
		'low'       => $row[3],
		'close'     => $row[4],
	);
}
$from_json = Importer::parse( (string) wp_json_encode( $json ), 'json', 1 );
$from_csv  = Importer::parse( hti_csv( array_slice( $rows, 0, 130 ) ), 'csv', 1 );
hti_games_check( $from_json['rows'] === $from_csv['rows'], 'the same candles parse identically from either format' );
hti_games_check( array() !== Importer::parse( '{not json', 'json', 1 )['errors'], 'a file that is not JSON says so rather than importing nothing quietly' );

echo "\nA broken file is refused, and the reason names the row\n";
$short = Importer::parse( hti_csv( hti_series( 119 ) ), 'csv', 1 );
hti_games_check( array() !== $short['errors'], '119 candles is not a scenario' );
hti_games_check( str_contains( implode( ' ', $short['errors'] ), (string) Importer::WINDOW ), 'and the message says how many are needed' );
hti_games_check( Importer::WINDOW === Config::STC_VISIBLE + Config::STC_OUTCOME, 'the window is exactly what the game shows plus what it plays out' );

$dupe    = hti_series( 130 );
$dupe[5] = $dupe[4];
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $dupe ), 'csv', 1 )['errors'] ), 'duplicate timestamp' ), 'a duplicated bar is caught' );

$back     = hti_series( 130 );
$back[60] = array( $back[60][0] - 999999, $back[60][1], $back[60][2], $back[60][3], $back[60][4] );
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $back ), 'csv', 1 )['errors'] ), 'backwards' ), 'so is a series that goes backwards in time' );

$bad_low     = hti_series( 130 );
$bad_low[7]  = array( $bad_low[7][0], 100, 200, 150, 120 );
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $bad_low ), 'csv', 1 )['errors'] ), 'low is above' ), 'a low above the open is a broken feed, not a candle' );

$bad_high    = hti_series( 130 );
$bad_high[8] = array( $bad_high[8][0], 100, 110, 90, 200 );
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $bad_high ), 'csv', 1 )['errors'] ), 'high is below' ), 'and so is a high below the close' );

$zero    = hti_series( 130 );
$zero[9] = array( $zero[9][0], 0, 10, 0, 5 );
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $zero ), 'csv', 1 )['errors'] ), 'non-positive' ), 'a zero price is refused' );

$text     = hti_series( 130 );
$text[10] = array( $text[10][0], 'n/a', 10, 1, 5 );
hti_games_check( str_contains( implode( ' ', Importer::parse( hti_csv( $text ), 'csv', 1 )['errors'] ), 'non-numeric' ), 'so is "n/a", which is how a data provider writes a holiday' );

echo "\nSlicing is a pure cut, and the checksum is of the candles\n";
$windows = Importer::slice( $parsed['rows'], Importer::WINDOW, Importer::STRIDE );
hti_games_check( 13 === count( $windows ), '600 candles at a stride of 40 give 13 overlapping windows' );
hti_games_check( Importer::WINDOW === count( $windows[0]['rows'] ), 'each window is exactly one scenario long' );
hti_games_check( 0 === $windows[0]['start'] && 40 === $windows[1]['start'], 'windows start one stride apart' );
hti_games_check( array() === Importer::slice( array_slice( $parsed['rows'], 0, 119 ), Importer::WINDOW, Importer::STRIDE ), 'a series shorter than a window yields nothing rather than a short scenario' );
hti_games_check( array() !== Importer::slice( $parsed['rows'], Importer::WINDOW, 0 ), 'a stride of zero is clamped rather than looping forever' );

hti_games_check( $windows[0]['checksum'] === Importer::checksum( $windows[0]['rows'] ), 'the checksum is a function of the candles alone' );
hti_games_check( $windows[0]['checksum'] !== $windows[1]['checksum'], 'two different windows fingerprint differently' );
$again = Importer::slice( Importer::parse( hti_csv( $rows ), 'csv', 1 )['rows'], Importer::WINDOW, Importer::STRIDE );
hti_games_check( $again[3]['checksum'] === $windows[3]['checksum'], 'and re-importing the same file gives the same fingerprints — which is what makes the import idempotent' );

$renamed = $windows[0]['rows'];
$renamed[0]['c'] += 1;
hti_games_check( Importer::checksum( $renamed ) !== $windows[0]['checksum'], 'one tick of difference is a different chart' );

echo "\nThe candles go out as the integer quads the meta key stores\n";
$quads = Importer::quads( $windows[0]['rows'] );
hti_games_check( Importer::WINDOW === count( $quads ) && 4 === count( $quads[0] ), 'one four-element quad per candle' );
hti_games_check( $quads[0] === array( $windows[0]['rows'][0]['o'], $windows[0]['rows'][0]['h'], $windows[0]['rows'][0]['l'], $windows[0]['rows'][0]['c'] ), 'in open, high, low, close order' );

echo "\nATR and the median\n";
hti_games_check( 0 === Importer::atr( array(), 14 ), 'an empty series has no range' );
$flat = array();
foreach ( range( 0, 130 ) as $i ) {
	$flat[] = array(
		'ts' => 1577836800 + $i * 60,
		'o'  => 1000,
		'h'  => 1000,
		'l'  => 1000,
		'c'  => 1000,
	);
}
hti_games_check( 0 === Importer::atr( $flat, 14 ), 'a flat line has an ATR of zero — it is a holiday, not a market' );
hti_games_check( Importer::atr( $parsed['rows'], 14 ) > 0, 'a real series does not' );
hti_games_check( 3 === Importer::median( array( 5, 1, 3 ) ), 'the median of three' );
hti_games_check( 3 === Importer::median( array( 1, 2, 4, 8 ) ), 'and of four, rounded down in integer arithmetic' );
hti_games_check( 0 === Importer::median( array() ), 'and of nothing' );
hti_games_check( 2 === Importer::median( array( 1000000, 2, 1 ) ), 'the median ignores an outlier, which is why the ATR ceiling is built on it and not on a mean' );

echo "\nScreening drops what cannot be traded or should not be read\n";
$flat_windows = Importer::slice( $flat, 120, 40 );
$screened     = Importer::screen( $flat_windows );
hti_games_check( array() === $screened['keep'] && 1 === count( $screened['dropped'] ), 'every flat window is dropped' );
hti_games_check( str_contains( $screened['dropped'][0]['reason'], 'ATR is zero' ), 'and the reason says why' );

$spiked      = hti_series( 600 );
$spiked[300] = array( $spiked[300][0], 100000, 2000000, 99999, 100000 );
$spike_rows  = Importer::parse( hti_csv( $spiked ), 'csv', 1 );
$spike_out   = Importer::screen( Importer::slice( $spike_rows['rows'], Importer::WINDOW, Importer::STRIDE ) );
hti_games_check( array() === $spike_rows['errors'], 'the spiked candle is structurally valid — nothing in the row rules can catch it' );
hti_games_check( count( $spike_out['dropped'] ) >= 1, 'but the windows containing it are dropped: a split or a bad tick looks like a signal, which is the problem' );
hti_games_check( count( $spike_out['keep'] ) >= 5, 'while the rest of the file is still usable' );
hti_games_check( str_contains( $spike_out['dropped'][0]['reason'], 'median' ), 'the reason names the comparison that failed' );
hti_games_check( 13 === count( $spike_out['keep'] ) + count( $spike_out['dropped'] ), 'nothing is silently lost between slicing and screening' );

$clean = Importer::screen( $windows );
hti_games_check( 13 === count( $clean['keep'] ) && array() === $clean['dropped'], 'a well-behaved file keeps everything' );
hti_games_check( array( 'keep', 'dropped' ) === array_keys( Importer::screen( array() ) ), 'screening nothing is an empty answer, not a warning' );

hti_games_done();
