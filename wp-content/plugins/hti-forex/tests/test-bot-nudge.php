<?php
/**
 * The follow-up nudge.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-nudge.php
 *
 * This is a feature that messages people automatically, which the bot had
 * deliberately never done. Everything that keeps that safe is a condition
 * somewhere — armed only for someone new, spent by answering, claimed before
 * sending, never sent twice, never sent to a backlog. A condition nobody
 * asserts is a condition that quietly stops holding, so each one has a test
 * here and the file is the contract.
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';

define( 'HTI_TELEGRAM_BOT_TOKEN', '123456:test-token' );
define( 'HTI_FOREX_PATH', dirname( __DIR__ ) . '/' );
define( 'HTI_FOREX_URL', 'https://howtoinvest.pro/wp-content/plugins/hti-forex/' );

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-telegram.php';
require_once __DIR__ . '/fixtures/bot-store-stub.php';
require_once __DIR__ . '/../includes/class-bot-nudge.php';

use HTI\Forex\Bot_Nudge;
use HTI\Forex\Bot_Store;
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
		echo "  \033[31m✗\033[0m {$label}\n";
	}
}

/**
 * Reset every bit of shared state between scenarios.
 *
 * @param bool $enabled Whether the feature is switched on.
 */
function reset_world( bool $enabled = true ): void {
	$GLOBALS['__hti_subs']     = array();
	$GLOBALS['__hti_nudges']   = array();
	$GLOBALS['__hti_cron']     = array();
	$GLOBALS['__hti_http']     = array();
	$GLOBALS['__hti_http_log'] = array();

	update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'bot_nudge_enabled' => $enabled ) ) );
}

/**
 * Put someone in the table with a nudge already due.
 *
 * @param int $chat_id Chat id.
 * @param int $ago     How many seconds ago it came due.
 */
function due_since( int $chat_id, int $ago ): void {
	Bot_Store::remember( $chat_id );
	$GLOBALS['__hti_nudges'][ $chat_id ] = array(
		'due'    => time() - $ago,
		'nudged' => false,
	);
}

echo "\n=== Off por omissão, e o interruptor manda ===\n";

check( false === Settings::defaults()['bot_nudge_enabled'], 'o default é desligado — implantar não arma ninguém' );

reset_world( false );
Bot_Store::remember( 10 );
Bot_Nudge::arm( 10 );
check( array() === ( $GLOBALS['__hti_nudges'] ?? array() ), 'com o interruptor off, /start não arma nada' );
check( false === wp_next_scheduled( Bot_Nudge::HOOK ), 'e não agenda cron nenhum' );

reset_world( false );
due_since( 11, 60 );
Bot_Nudge::run();
check( array() === $GLOBALS['__hti_http_log'], 'desligar é kill-switch: nem os que já estavam pendentes saem' );

echo "\n=== Armar acontece uma vez, e não se pode mover ===\n";

reset_world();
Bot_Store::remember( 20 );
Bot_Nudge::arm( 20 );
$first = $GLOBALS['__hti_nudges'][20]['due'];
check( null !== $first, 'um /start novo arma o seguimento' );
check( false !== wp_next_scheduled( Bot_Nudge::HOOK ), 'e agenda o tique que o vai enviar' );

Bot_Nudge::arm( 20 );
check( $GLOBALS['__hti_nudges'][20]['due'] === $first, 'armar outra vez não empurra a data para a frente' );

echo "\n=== Responder a um saldo gasta o seguimento, para sempre ===\n";

reset_world();
Bot_Store::remember( 30 );
Bot_Nudge::arm( 30 );
Bot_Store::disarm_nudge( 30 );
check( true === $GLOBALS['__hti_nudges'][30]['nudged'], 'quem usa o bot deixa de estar pendente' );

Bot_Nudge::arm( 30 );
check( null === $GLOBALS['__hti_nudges'][30]['due'], 'e um /start posterior não o volta a armar' );

$GLOBALS['__hti_http_log'] = array();
Bot_Nudge::run();
check( array() === $GLOBALS['__hti_http_log'], 'não recebe mensagem nenhuma' );

echo "\n=== Envia uma vez, e só uma ===\n";

reset_world();
due_since( 40, 60 );
Bot_Nudge::run();
check( count( $GLOBALS['__hti_http_log'] ) === 1, 'um envio para quem estava devido' );
check( str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['url'] ?? '' ), 'sendMessage' ), 'como sendMessage' );

$body = (string) ( $GLOBALS['__hti_http_log'][0]['body']['text'] ?? '' );
check( str_contains( $body, 'one number' ), 'com o pedido repetido' );
check( str_contains( $body, '/stop' ), 'e a saída colada, como numa difusão' );
check( ! str_contains( strtolower( $body ), 'xm' ), 'sem corretora — o seguimento não vende nada' );

$GLOBALS['__hti_http_log'] = array();
Bot_Nudge::run();
check( array() === $GLOBALS['__hti_http_log'], 'correr outra vez não reenvia — o at-most-one é do estado, não do acaso' );

echo "\n=== Duas corridas sobrepostas não duplicam ===\n";

reset_world();
due_since( 50, 60 );
check( true === Bot_Store::claim_nudge( 1 ), 'a primeira corrida fica com a linha' );
check( false === Bot_Store::claim_nudge( 1 ), 'a segunda encontra-a gasta e desiste' );

echo "\n=== Bloqueado sai da tabela ===\n";

reset_world();
due_since( 60, 60 );
$GLOBALS['__hti_http'] = array(
	array(
		'code' => 403,
		'body' => array(
			'ok'          => false,
			'error_code'  => 403,
			'description' => 'Forbidden: bot was blocked by the user',
		),
	),
);
Bot_Nudge::run();
check( ! in_array( 60, $GLOBALS['__hti_subs'], true ), 'quem bloqueou é apagado, como na difusão' );

echo "\n=== Um atraso longo não vira mailing list ===\n";

reset_world();
due_since( 70, 10 * DAY_IN_SECONDS );
Bot_Nudge::run();
check( array() === $GLOBALS['__hti_http_log'], 'devido há dez dias já não é enviado' );

reset_world();
due_since( 71, 60 );
due_since( 72, 10 * DAY_IN_SECONDS );
Bot_Nudge::run();
check( count( $GLOBALS['__hti_http_log'] ) === 1, 'e o velho não impede o recente de sair' );

echo "\n=== O que ainda não venceu espera ===\n";

reset_world();
Bot_Store::remember( 80 );
Bot_Nudge::arm( 80 );
$GLOBALS['__hti_http_log'] = array();
Bot_Nudge::run();
check( array() === $GLOBALS['__hti_http_log'], 'trinta minutos antes, ninguém é incomodado' );
check( false === $GLOBALS['__hti_nudges'][80]['nudged'], 'e continua pendente' );

echo "\n=== O texto ===\n";

$text = Bot_Nudge::text();
check( mb_strlen( $text ) < 1024, 'cabe numa legenda, caso alguma vez leve imagem' );
check( str_contains( $text, '5000' ), 'mostra exemplos de como escrever o número' );
check( ! str_contains( $text, 'http' ), 'não leva link nenhum' );

echo "\n=== " . ( $failures > 0 ? "\033[31m" : "\033[32m" ) . "{$passes} passed, {$failures} failed\033[0m ===\n";

exit( $failures > 0 ? 1 : 0 );
