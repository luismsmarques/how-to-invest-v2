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

echo "\n=== A corrida cai sempre depois do BCE publicar ===\n";

// O defeito que isto tranca era sazonal: com `twicedaily` ancorado na hora de
// ativação (02:39 e 14:39 UTC em produção), a corrida da tarde limpava a
// publicação por 39 minutos no verão e falhava-a por 21 no inverno. A partir
// da mudança de hora de outubro o site ficaria um dia atrás, todos os dias, e
// nada o diria. A série ancorada em 16:00 UTC não depende da estação.
$slots = array();
$t     = strtotime( '2026-11-02 00:00:00 UTC' );
for ( $i = 0; $i < 8; $i++ ) {
	$t = Rates::next_slot( $t );
	$slots[] = gmdate( 'H:i', $t );
}
check( array( '04:00', '10:00', '16:00', '22:00' ) === array_values( array_unique( $slots ) ), 'a série é 04/10/16/22 UTC' );

// A publicação do BCE ronda as 16:00 de Frankfurt: ~14:00 UTC no verão
// (CEST) e ~15:00 UTC no inverno (CET). Estas duas asserções são a razão de
// a âncora ser 16:00 e não 14:00.
$after_publication = static function ( int $from, int $publish_hour ): bool {
	$slot = Rates::next_slot( $from );
	// Alguma corrida do dia tem de cair depois da publicação e antes da meia-noite.
	for ( $i = 0; $i < 4; $i++ ) {
		if ( (int) gmdate( 'H', $slot ) >= $publish_hour && (int) gmdate( 'H', $slot ) < 24 ) {
			return true;
		}
		$slot = Rates::next_slot( $slot );
	}
	return false;
};
check( $after_publication( strtotime( '2026-07-15 00:00:00 UTC' ), 14 ), 'verão: há corrida depois das 14:00 UTC' );
check( $after_publication( strtotime( '2026-12-15 00:00:00 UTC' ), 15 ), 'inverno: há corrida depois das 15:00 UTC' );

check( Rates::next_slot( strtotime( '2026-08-31 16:00:00 UTC' ) ) === strtotime( '2026-08-31 22:00:00 UTC' ), 'em cima da hora avança para a seguinte, não repete' );
check( Rates::next_slot( strtotime( '2026-08-31 15:59:00 UTC' ) ) === strtotime( '2026-08-31 16:00:00 UTC' ), 'um minuto antes, a próxima é a das 16:00' );
check( ( Rates::next_slot( strtotime( '2026-08-31 09:00:00 UTC' ) ) - strtotime( '2026-08-31 09:00:00 UTC' ) ) <= 6 * HOUR_IN_SECONDS, 'a primeira corrida após um deploy nunca está a mais de seis horas' );

echo "\n=== Uma cotação de sexta lida à segunda não está atrasada ===\n";

// Sem isto, o painel gritaria todos os fins de semana e ninguém voltaria a
// olhar para o aviso quando ele significasse alguma coisa.
check( 0 === Rates::weekdays_behind( '2026-08-28', strtotime( '2026-08-31 06:00:00 UTC' ) ), 'segunda a ler sexta: em dia' );
check( 0 === Rates::weekdays_behind( '2026-08-28', strtotime( '2026-08-29 06:00:00 UTC' ) ), 'sábado a ler sexta: em dia' );
check( 0 === Rates::weekdays_behind( '2026-08-28', strtotime( '2026-08-30 06:00:00 UTC' ) ), 'domingo a ler sexta: em dia' );
check( 1 === Rates::weekdays_behind( '2026-08-28', strtotime( '2026-09-01 06:00:00 UTC' ) ), 'terça a ler sexta: um dia útil atrás' );
check( 2 === Rates::weekdays_behind( '2026-08-28', strtotime( '2026-09-02 06:00:00 UTC' ) ), 'quarta a ler sexta: dois — é aqui que o painel avisa' );
check( 0 === Rates::weekdays_behind( '', strtotime( '2026-09-02 06:00:00 UTC' ) ), 'sem data guardada não inventa atraso' );
check( 0 === Rates::weekdays_behind( 'nonsense', strtotime( '2026-09-02 06:00:00 UTC' ) ), 'lixo no campo da data também não' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
