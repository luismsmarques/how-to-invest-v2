<?php
/**
 * Telegram conversion block: settings validation and which block renders.
 *
 * The /forex/ pages carry one conversion slot after the calculator, and which
 * block lands in it is a live experiment driven by a setting. Two things have
 * to hold for that experiment to be safe: a bad URL must never reach the page
 * (it is where paid traffic is sent), and no combination of settings may leave
 * the page with nothing in the slot — which would quietly delete the section's
 * only conversion path.
 *
 *   php wp-content/plugins/hti-forex/tests/test-telegram.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-settings.php';

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
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}

echo "\n=== URL do canal: o que é aceite ===\n";
$ok_urls = array(
	'https://t.me/howtoinvestpro',
	'https://t.me/howtoinvestpro/',
	'https://t.me/+AbCdEf123456',
	'https://telegram.me/howtoinvestpro',
	'HTTPS://T.ME/howtoinvestpro',
);
foreach ( $ok_urls as $url ) {
	check( '' !== Settings::normalize_telegram_url( $url ), 'aceita ' . $url );
}

echo "\n=== …e o que é recusado ===\n";
$bad_urls = array(
	''                                  => 'vazio',
	'http://t.me/howtoinvestpro'        => 'http em vez de https',
	'//t.me/howtoinvestpro'             => 'sem esquema',
	'https://t.me'                      => 'host sem canal',
	'https://t.me/'                     => 'host sem canal, com barra',
	'https://telegram.org/howtoinvest'  => 'telegram.org não é um link de canal',
	'https://evil.example/t.me/x'       => 'outro host com t.me no caminho',
	'https://t.me.evil.example/x'       => 'host que apenas começa por t.me',
	'javascript:alert(1)'               => 'javascript:',
	'not a url at all'                  => 'lixo',
);
foreach ( $bad_urls as $url => $why ) {
	check( '' === Settings::normalize_telegram_url( (string) $url ), 'recusa ' . $why );
}

echo "\n=== Modo do bloco de conversão ===\n";
foreach ( array( 'telegram', 'email', 'both' ) as $mode ) {
	check( $mode === Settings::normalize_conversion_block( $mode ), 'mantém ' . $mode );
}
check( 'both' === Settings::normalize_conversion_block( '  BOTH ' ), 'normaliza espaços e maiúsculas' );
check( 'telegram' === Settings::normalize_conversion_block( '' ), 'vazio cai em telegram' );
check( 'telegram' === Settings::normalize_conversion_block( 'inventado' ), 'valor desconhecido cai em telegram' );

echo "\n=== Que blocos renderizam ===\n";
$url = 'https://t.me/howtoinvestpro';

$cases = array(
	'telegram, com URL'          => array( array( 'conversion_block' => 'telegram', 'telegram_url' => $url, 'email_enabled' => true ), true, false ),
	'email, com URL'             => array( array( 'conversion_block' => 'email', 'telegram_url' => $url, 'email_enabled' => true ), false, true ),
	'both, com URL'              => array( array( 'conversion_block' => 'both', 'telegram_url' => $url, 'email_enabled' => true ), true, true ),
	'telegram, sem URL'          => array( array( 'conversion_block' => 'telegram', 'telegram_url' => '', 'email_enabled' => true ), false, true ),
	'both, sem URL'              => array( array( 'conversion_block' => 'both', 'telegram_url' => '', 'email_enabled' => true ), false, true ),
	'telegram, URL inválido'     => array( array( 'conversion_block' => 'telegram', 'telegram_url' => 'http://t.me/x', 'email_enabled' => true ), false, true ),
	'telegram, email desligado'  => array( array( 'conversion_block' => 'telegram', 'telegram_url' => $url, 'email_enabled' => false ), true, false ),
	'email, email desligado'     => array( array( 'conversion_block' => 'email', 'telegram_url' => $url, 'email_enabled' => false ), false, false ),
	'definições vazias'          => array( array(), false, true ),
);

foreach ( $cases as $label => $case ) {
	[ $settings, $want_tg, $want_email ] = $case;
	$got = Settings::conversion_blocks( $settings );
	check(
		$want_tg === $got['telegram'] && $want_email === $got['email'],
		sprintf(
			'%s → telegram=%s email=%s',
			$label,
			$got['telegram'] ? 'sim' : 'não',
			$got['email'] ? 'sim' : 'não'
		)
	);
}

echo "\n=== A página nunca fica sem bloco de conversão ===\n";
// A não ser que o dono desligue explicitamente o email E não haja canal.
$orphans = array();
foreach ( array( 'telegram', 'email', 'both', '', 'lixo' ) as $mode ) {
	foreach ( array( $url, '', 'http://t.me/x' ) as $candidate ) {
		$got = Settings::conversion_blocks(
			array( 'conversion_block' => $mode, 'telegram_url' => $candidate, 'email_enabled' => true )
		);
		if ( ! $got['telegram'] && ! $got['email'] ) {
			$orphans[] = "{$mode}/{$candidate}";
		}
	}
}
check( array() === $orphans, 'com o email permitido, há sempre um bloco (órfãos: ' . ( $orphans ? implode( ', ', $orphans ) : 'nenhum' ) . ')' );

echo "\n=== Vocabulário fechado das chaves de medição ===\n";
// O mapa `cta` do funil é a única quebra sem teto de cardinalidade, por isso
// estas chaves têm de vir da lista de ferramentas e nunca do visitante.
$locations = array( 'forex_telegram_hub' );
foreach ( Settings::TOOLS as $tool ) {
	$locations[] = 'forex_telegram_' . $tool;
}
check( 5 === count( $locations ), 'cinco chaves: hub mais uma por ferramenta' );
check( count( array_unique( $locations ) ) === count( $locations ), 'sem repetições' );
$bad = array_filter( $locations, static fn( string $l ): bool => 1 !== preg_match( '/^forex_telegram_[a-z_]+$/', $l ) );
check( array() === $bad, 'todas em minúsculas e sublinhados' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
