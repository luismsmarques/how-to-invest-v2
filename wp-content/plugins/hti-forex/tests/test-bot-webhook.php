<?php
/**
 * The webhook's front door.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-webhook.php
 *
 * This endpoint has to be public — Telegram cannot send a nonce — so the
 * secret header is the only thing standing between it and anyone who guesses
 * the URL. It is worth a test of its own.
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';

// A token has to exist before Telegram::configured() will let anything past.
define( 'HTI_TELEGRAM_BOT_TOKEN', '123456:test-token' );

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-bot-math.php';
require_once __DIR__ . '/../includes/class-telegram.php';
require_once __DIR__ . '/../includes/class-bot.php';

use HTI\Forex\Bot;
use HTI\Forex\Telegram;

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
 * Post an update at the endpoint.
 *
 * @param string|null              $secret Header value, or null to omit it.
 * @param array<string,mixed>|null $body   JSON body.
 * @return int HTTP status.
 */
function post( ?string $secret, $body = array() ): int {
	$headers = null === $secret ? array() : array( 'x_telegram_bot_api_secret_token' => $secret );
	return Bot::receive( new WP_REST_Request( $headers, $body ) )->get_status();
}

echo "\n=== O segredo do webhook ===\n";

$secret = Telegram::secret();
check( strlen( $secret ) >= 32, 'o segredo é gerado com comprimento suficiente' );
check( $secret === Telegram::secret(), 'e é estável entre chamadas' );

echo "\n=== Quem entra e quem não entra ===\n";

check( 403 === post( null ), 'sem o cabeçalho → 403' );
check( 403 === post( '' ), 'cabeçalho vazio → 403' );
check( 403 === post( 'wrong-secret' ), 'segredo errado → 403' );
check( 403 === post( strtoupper( $secret ) ), 'segredo com outra capitalização → 403' );
check( 403 === post( substr( $secret, 0, -1 ) ), 'segredo truncado → 403' );
check( 403 === post( $secret . 'x' ), 'segredo com sufixo → 403' );

check( 200 === post( $secret ), 'segredo correto → 200' );

echo "\n=== Corpos que não deviam derrubar nada ===\n";

// Um não-200 faz o Telegram repetir a mesma atualização, e uma atualização que
// não se percebeu não se vai perceber à segunda. Autenticado responde sempre 200.
check( 200 === post( $secret, array() ), 'corpo vazio → 200' );
check( 200 === post( $secret, null ), 'corpo não-JSON → 200' );
check( 200 === post( $secret, array( 'update_id' => 1 ) ), 'atualização sem mensagem → 200' );
check( 200 === post( $secret, array( 'message' => 'not-an-array' ) ), 'mensagem com o tipo errado → 200' );
check( 200 === post( $secret, array( 'message' => array( 'text' => 'olá' ) ) ), 'mensagem sem chat → 200' );
check( 200 === post( $secret, array( 'message' => array( 'chat' => array( 'id' => 5 ) ) ) ), 'mensagem sem texto → 200' );
check( 200 === post( $secret, array( 'message' => array( 'chat' => array( 'id' => 0 ), 'text' => 'olá' ) ) ), 'chat id zero → 200' );
check( 200 === post( $secret, array( 'message' => array( 'chat' => array( 'id' => 5 ), 'text' => '   ' ) ) ), 'texto só com espaços → 200' );

echo "\n=== O URL do webhook ===\n";

check( str_contains( Telegram::webhook_url(), 'htinvest/v1/forex/telegram' ), 'o URL aponta para a rota REST registada' );
check( 30 === Telegram::RATE_PER_SECOND, 'o tecto gratuito do Telegram está registado no código' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
