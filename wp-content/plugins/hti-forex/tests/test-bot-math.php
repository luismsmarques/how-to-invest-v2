<?php
/**
 * Bot arithmetic: JS↔PHP parity, the amount parser, and the account picture.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-math.php
 *
 * The parity block is the important one. The website computes these numbers
 * in JavaScript and the bot computes them in PHP; if the two ever disagree we
 * lose the only thing that makes this project worth trusting. Both suites
 * assert against tests/fixtures/parity.json, so drift on either side goes red.
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-bot-math.php';

use HTI\Forex\Bot_Math;

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
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗\033[0m {$label}\n";
	}
}

/**
 * Float comparison that tolerates only representation noise, not drift.
 *
 * @param float  $got   Value.
 * @param float  $want  Expected.
 * @param string $label Label.
 */
function near( float $got, float $want, string $label ): void {
	$tolerance = max( 1e-9, abs( $want ) * 1e-9 );
	check( abs( $got - $want ) <= $tolerance, $label . sprintf( ' (got %.10g, want %.10g)', $got, $want ) );
}

echo "\n=== Paridade JS↔PHP (tests/fixtures/parity.json) ===\n";

$fixture_path = __DIR__ . '/fixtures/parity.json';
$fixture      = is_file( $fixture_path )
	? json_decode( (string) file_get_contents( $fixture_path ), true )
	: null;

check( is_array( $fixture ), 'o ficheiro de referência existe e lê-se' );

if ( is_array( $fixture ) ) {
	$rates = $fixture['rates'];

	foreach ( $fixture['pip_value'] as $case ) {
		$got   = Bot_Math::pip_value( $case['pair'], (float) $case['lots'], $rates );
		$label = sprintf( 'pip %s × %s', $case['pair'], $case['lots'] );

		if ( ! is_array( $got ) ) {
			check( false, $label . ' — devolveu null' );
			continue;
		}
		near( $got['quote'], (float) $case['quote'], $label . ' · quote' );
		near( $got['usd'], (float) $case['usd'], $label . ' · usd' );
		near( $got['inr'], (float) $case['inr'], $label . ' · inr' );
	}

	foreach ( $fixture['margin_required'] as $case ) {
		$got   = Bot_Math::margin_required(
			$case['pair'],
			(float) $case['lots'],
			(float) $case['price'],
			(float) $case['leverage'],
			$rates
		);
		$label = sprintf( 'margem %s × %s @ 1:%s', $case['pair'], $case['lots'], $case['leverage'] );

		if ( ! is_array( $got ) ) {
			check( false, $label . ' — devolveu null' );
			continue;
		}
		near( $got['notional_usd'], (float) $case['notional_usd'], $label . ' · nocional' );
		near( $got['margin_usd'], (float) $case['margin_usd'], $label . ' · USD' );
		near( $got['margin_inr'], (float) $case['margin_inr'], $label . ' · INR' );
	}
}

echo "\n=== Rejeições da matemática ===\n";

$rates = array(
	'USDINR' => 83.0,
	'USDJPY' => 147.0,
);

check( null === Bot_Math::pip_value( 'NOPE', 1, $rates ), 'par desconhecido → null' );
check( null === Bot_Math::pip_value( 'EURUSD', 0, $rates ), 'zero lotes → null' );
check( null === Bot_Math::pip_value( 'EURUSD', -1, $rates ), 'lotes negativos → null' );
check( null === Bot_Math::pip_value( 'EURUSD', 1, array( 'USDINR' => 0 ) ), 'sem USDINR → null' );
check( null === Bot_Math::pip_value( 'USDJPY', 1, array( 'USDINR' => 83 ) ), 'iene sem USDJPY → null' );
check( null === Bot_Math::margin_required( 'EURUSD', 0.01, 0, 500, $rates ), 'par não-USD sem preço → null' );
check( null === Bot_Math::margin_required( 'EURUSD', 0.01, 1.165, 0, $rates ), 'alavancagem zero → null' );
check( is_array( Bot_Math::margin_required( 'USDJPY', 0.01, 0, 500, $rates ) ), 'par com base USD dispensa preço' );

echo "\n=== price_for() ===\n";

check( 0.0 === Bot_Math::price_for( 'USDJPY', $rates ), 'base USD → 0, sem preço necessário' );
check( -1.0 === Bot_Math::price_for( 'EURUSD', $rates ), 'sem preço nas taxas → -1' );
check( 1.165 === Bot_Math::price_for( 'EURUSD', array( 'USDINR' => 83, 'EURUSD' => 1.165 ) ), 'preço vindo das taxas' );

echo "\n=== O parser de valores ===\n";

$usd_inr = 83.0;

/**
 * Parser assertion.
 *
 * @param string     $input Raw input.
 * @param float|null $want  Expected rupee amount, or null for a rejection.
 */
function parses( string $input, ?float $want ): void {
	$got = Bot_Math::parse_amount( $input, 83.0 );

	if ( null === $want ) {
		check( null === $got, sprintf( '"%s" → recusado', $input ) );
		return;
	}
	if ( ! is_array( $got ) ) {
		check( false, sprintf( '"%s" → esperava %.2f, devolveu null', $input, $want ) );
		return;
	}
	near( $got['inr'], $want, sprintf( '"%s"', $input ) );
}

parses( '5000', 5000.0 );
parses( '  5000  ', 5000.0 );
parses( '₹5,000', 5000.0 );
parses( 'Rs 5000', 5000.0 );
parses( 'rs.5000', 5000.0 );
parses( 'Rs. 5,000', 5000.0 );
parses( 'inr.2500.50', 2500.50 );
parses( '5000 INR', 5000.0 );
parses( '1,00,000', 100000.0 );          // Agrupamento indiano.
parses( '100000', 100000.0 );
parses( '50k', 50000.0 );
parses( '50 k', 50000.0 );
parses( '2 lakh', 200000.0 );
parses( '2lakh', 200000.0 );
parses( '1.5 lakh', 150000.0 );
parses( '1 crore', 10000000.0 );
parses( '2500.50', 2500.50 );

// Dólares — metade deste público pensa em dólares por causa da página de $100.
parses( '$100', 8300.0 );
parses( '100 usd', 8300.0 );
parses( '100 dollars', 8300.0 );
parses( '$1,000', 83000.0 );

// Recusas.
parses( 'abc', null );
parses( '', null );
parses( '   ', null );
parses( '0', null );
parses( '-5000', 5000.0 );               // O sinal cai; um saldo negativo não é uma pergunta.
parses( '999999999999', null );          // Acima do tecto de sanidade.
parses( str_repeat( '9', 60 ), null );   // Comprimento absurdo.

echo "\n=== O código de campanha do deep link ===\n";

/**
 * Campaign-code assertion.
 *
 * @param string $input Raw payload.
 * @param string $want  Expected code.
 */
function code( string $input, string $want ): void {
	$got = Bot_Math::source_code( $input );
	check( $want === $got, sprintf( '"%s" → "%s"%s', $input, $got, $want === $got ? '' : ' (esperava "' . $want . '")' ) );
}

code( 'px_a1', 'px_a1' );
code( 'PX_A1', 'px_a1' );                        // A capitalização não cria uma segunda linha.
code( '  px_a1  ', 'px_a1' );
code( 'tg-mini-b2', 'tg-mini-b2' );
code( 'channel', 'channel' );
code( str_repeat( 'a', 32 ), str_repeat( 'a', 32 ) );

// Recusas: isto chega da web aberta e vira chave de um contador.
code( '', '' );
code( str_repeat( 'a', 33 ), '' );               // Acima dos 32.
code( 'px a1', '' );                             // Espaço a meio.
code( 'px.a1', '' );
code( 'px/a1', '' );
code( '<script>', '' );
code( 'código', '' );                            // Fora de [a-z0-9_-].
code( '../../etc', '' );

check(
	'' === Bot_Math::source_code( "px_a1\nmais" ),
	'nada de várias linhas — uma quebra não passa a fronteira'
);

echo "\n=== Baldes de saldo (o estudo de audiência) ===\n";

check( 'under_2k' === Bot_Math::bucket( 1500 ), '₹1.500 → under_2k' );
check( '2k_5k' === Bot_Math::bucket( 2000 ), '₹2.000 → 2k_5k (fronteira inferior inclusiva)' );
check( '2k_5k' === Bot_Math::bucket( 4999 ), '₹4.999 → 2k_5k' );
check( '5k_10k' === Bot_Math::bucket( 5000 ), '₹5.000 → 5k_10k' );
check( '1l_5l' === Bot_Math::bucket( 100000 ), '₹1 lakh → 1l_5l' );
check( 'over_5l' === Bot_Math::bucket( 500000 ), '₹5 lakh → over_5l' );
check( 'over_5l' === Bot_Math::bucket( 99000000 ), 'muito grande → over_5l' );

$keys = array_column( Bot_Math::buckets(), 'key' );
check( count( $keys ) === count( array_unique( $keys ) ), 'chaves dos baldes sem repetições' );

echo "\n=== Formatação indiana ===\n";

check( '5,000' === Bot_Math::inr( 5000 ), '5000 → 5,000' );
check( '1,00,000' === Bot_Math::inr( 100000 ), '100000 → 1,00,000 (lakh)' );
check( '10,00,000' === Bot_Math::inr( 1000000 ), '1000000 → 10,00,000' );
check( '1,00,00,000' === Bot_Math::inr( 10000000 ), '10000000 → 1,00,00,000 (crore)' );
check( '999' === Bot_Math::inr( 999 ), '999 sem separador' );
check( '9.55' === Bot_Math::inr( 9.55, 2 ), 'casas decimais preservadas' );
check( '1,000' === Bot_Math::plain( 1000 ), 'formatação simples para dólares' );

echo "\n=== O retrato da conta ===\n";

$rates = array(
	'USDINR' => 95.5,
	'USDJPY' => 159.0,
	'EURUSD' => 1.165,
);

$p = Bot_Math::picture( 5000, 'EURUSD', 500, $rates );

check( is_array( $p ), '₹5.000 em EUR/USD devolve um retrato' );
near( $p['pip_inr'], 9.55, 'um pip num micro lote ≈ ₹9,55 (o número do cheat sheet)' );
near( $p['stops'][0]['cost'], 191.0, 'stop de 20 pips ≈ ₹191' );
near( $p['stops'][0]['percent'], 3.82, 'e isso é 3,82% de ₹5.000' );
near( $p['margin_inr'], 222.515, 'margem de 0,01 lotes a 1:500' );
check( 1000 === $p['units'], '0,01 lotes = 1.000 unidades' );
check( true === $p['tight'], '₹5.000: o lote mínimo já arrisca mais de 2%' );
near( $p['room'][0]['pips'], 50 / 9.55, 'com 1% de risco (₹50) o stop cabe em ~5 pips' );

$p = Bot_Math::picture( 100000, 'EURUSD', 500, $rates );
check( false === $p['tight'], '₹1 lakh: o lote mínimo já não é apertado' );
near( $p['stops'][0]['percent'], 0.191, 'stop de 20 pips = 0,19% de ₹1 lakh' );

// O limiar do cheat sheet: ₹19.100 é onde 1% cobre exatamente um micro lote
// com stop de 20 pips. O retrato tem de concordar com o PDF fixado no canal.
$p = Bot_Math::picture( 19100, 'EURUSD', 500, $rates );
near( $p['stops'][0]['percent'], 1.0, '₹19.100: o stop de 20 pips é exatamente 1% — o número do PDF' );

$p = Bot_Math::picture( 5000, 'USDJPY', 500, $rates );
check( is_array( $p ) && null !== $p['margin_inr'], 'USD/JPY tem margem sem precisar de preço' );

check( null === Bot_Math::picture( 5000, 'XAUUSD', 500, $rates ), 'ouro está fora do âmbito do bot' );
check( null === Bot_Math::picture( 0, 'EURUSD', 500, $rates ), 'saldo zero → null' );
check( null === Bot_Math::picture( 5000, 'NOPE', 500, $rates ), 'par desconhecido → null' );

// Sem preço EUR/USD nas taxas o retrato ainda responde, só sem a linha da
// margem — degradação suave em vez de erro.
$p = Bot_Math::picture( 5000, 'EURUSD', 500, array( 'USDINR' => 95.5 ) );
check( is_array( $p ) && null === $p['margin_inr'], 'sem preço, retrato sem margem em vez de falhar' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
