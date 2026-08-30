<?php
/**
 * The broadcast state machine.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-broadcast.php
 *
 * This file exists because of a bug that reached production: `status()` built
 * the state array key by key and one key `run()` reads — the image slug — was
 * missing from it. The result was not a message without a picture. It was a
 * TypeError one recipient into the first batch, which killed the cron before
 * it could save progress or queue the next tick, so nothing was ever sent and
 * the state read "sending" for ever, refusing every later broadcast.
 *
 * The first test below is the one that would have caught it, and will catch
 * the next one of its kind: every key the class touches has to be a key
 * `status()` returns.
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';

define( 'HTI_FOREX_PATH', dirname( __DIR__ ) . '/' );
define( 'HTI_FOREX_URL', 'https://howtoinvest.pro/wp-content/plugins/hti-forex/' );

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-bot-math.php';
require_once __DIR__ . '/../includes/class-bot-images.php';
require_once __DIR__ . '/../includes/class-telegram.php';
require_once __DIR__ . '/../includes/class-bot-broadcast.php';

use HTI\Forex\Bot_Broadcast;

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
 * Put a state straight into the option, as a half-finished send would leave it.
 *
 * @param array<string,mixed> $state State.
 */
function put_state( array $state ): void {
	update_option( Bot_Broadcast::OPTION, $state );
}

echo "\n=== Todas as chaves usadas são chaves declaradas ===\n";

// The structural test. Anything the class reads or writes on the state has to
// come back from status(), because status() is the only way run() sees it.
$source = (string) file_get_contents( __DIR__ . '/../includes/class-bot-broadcast.php' );
preg_match_all( "/\\\$state\\['([a-z_]+)'\\]/", $source, $found );
$touched = array_values( array_unique( $found[1] ) );
$declared = array_keys( Bot_Broadcast::status() );

check( count( $touched ) > 5, 'o ficheiro foi lido e as chaves extraídas' );

foreach ( $touched as $key ) {
	check( in_array( $key, $declared, true ), "a chave '{$key}' é devolvida por status()" );
}

echo "\n=== Os valores por omissão ===\n";

delete_option( Bot_Broadcast::OPTION );
$fresh = Bot_Broadcast::status();

check( '' === $fresh['text'], 'sem estado, o texto é vazio' );
check( '' === $fresh['image'], 'sem estado, a imagem é vazia (string, não null)' );
check( 0 === $fresh['started'], 'sem estado, não começou' );
check( ! Bot_Broadcast::running(), 'sem estado, não está a enviar' );
check( ! Bot_Broadcast::stalled(), 'sem estado, não está encravado' );

echo "\n=== A imagem sobrevive à leitura do estado ===\n";

put_state(
	array(
		'text'     => 'olá',
		'image'    => 'promo',
		'cursor'   => 0,
		'sent'     => 0,
		'dropped'  => 0,
		'total'    => 10,
		'started'  => time(),
		'updated'  => time(),
		'finished' => 0,
	)
);

check( 'promo' === Bot_Broadcast::status()['image'], 'o slug da imagem chega a quem envia' );
check( is_string( Bot_Broadcast::status()['image'] ), 'e chega como string' );

echo "\n=== Um envio vivo não é dado como morto ===\n";

$GLOBALS['__hti_cron'] = array();
wp_schedule_single_event( time() + 1, Bot_Broadcast::HOOK );

check( Bot_Broadcast::running(), 'com tick agendado, está a enviar' );
check( ! Bot_Broadcast::stalled(), 'com tick agendado, não está encravado' );

// The gap between a cron firing and run() re-arming it: no tick, but recent.
$GLOBALS['__hti_cron'] = array();
check( ! Bot_Broadcast::stalled(), 'sem tick mas com sinal de vida recente, ainda não está encravado' );
check( Bot_Broadcast::running(), 'e continua a contar como a enviar' );

echo "\n=== Um envio morto liberta o compositor ===\n";

put_state(
	array(
		'text'     => 'olá',
		'image'    => 'promo',
		'cursor'   => 0,
		'sent'     => 0,
		'dropped'  => 0,
		'total'    => 915,
		'started'  => time() - 86400,
		'updated'  => time() - 86400,
		'finished' => 0,
	)
);
$GLOBALS['__hti_cron'] = array();

check( Bot_Broadcast::stalled(), 'sem tick e sem sinal de vida há muito → encravado' );
check( ! Bot_Broadcast::running(), 'e deixa de bloquear o compositor' );

echo "\n=== O estado partido que ficou em produção ===\n";

// Exactly what the old code left behind: no 'image', no 'updated', started
// long ago, no tick. It has to be recognised and released on sight.
put_state(
	array(
		'text'     => 'a mensagem que nunca saiu',
		'cursor'   => 0,
		'sent'     => 0,
		'dropped'  => 0,
		'total'    => 915,
		'started'  => time() - 7200,
		'finished' => 0,
	)
);
$GLOBALS['__hti_cron'] = array();

check( '' === Bot_Broadcast::status()['image'], 'um estado antigo sem imagem lê-se como sem imagem' );
check( time() - 7200 === Bot_Broadcast::status()['updated'], 'e o início serve de último sinal de vida' );
check( Bot_Broadcast::stalled(), 'é reconhecido como encravado' );
check( ! Bot_Broadcast::running(), 'e a difusão seguinte deixa de ser recusada' );

echo "\n=== Cancelar ===\n";

put_state(
	array(
		'text'     => 'olá',
		'image'    => '',
		'cursor'   => 100,
		'sent'     => 100,
		'dropped'  => 2,
		'total'    => 915,
		'started'  => time() - 60,
		'updated'  => time() - 10,
		'finished' => 0,
	)
);
wp_schedule_single_event( time() + 1, Bot_Broadcast::HOOK );

Bot_Broadcast::cancel();
$after = Bot_Broadcast::status();

check( $after['finished'] > 0, 'cancelar termina o envio' );
check( ! Bot_Broadcast::running(), 'e deixa de estar a enviar' );
check( ! Bot_Broadcast::stalled(), 'um envio terminado não é um envio encravado' );
check( 100 === $after['sent'], 'o que já tinha saído mantém-se contado' );
check( false === wp_next_scheduled( Bot_Broadcast::HOOK ), 'e o tick agendado é limpo' );

echo "\n=== O limite da legenda ===\n";

check( Bot_Broadcast::fits_caption( str_repeat( 'a', 5000 ), '' ), 'sem imagem, o limite da legenda não se aplica' );
check( ! Bot_Broadcast::fits_caption( str_repeat( 'a', 1024 ), 'promo' ), 'com imagem, o rodapé conta para o limite' );
check( Bot_Broadcast::fits_caption( 'curta', 'promo' ), 'uma mensagem curta cabe na legenda' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
