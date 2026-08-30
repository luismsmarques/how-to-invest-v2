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
require_once __DIR__ . '/../includes/class-rates.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-bot-images.php';
require_once __DIR__ . '/fixtures/bot-store-stub.php';
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

/**
 * Post a text message from a chat and hand back the response body.
 *
 * @param int    $chat_id Chat.
 * @param string $text    Message text.
 * @return array<string,mixed>
 */
function say( int $chat_id, string $text ): array {
	$request = new WP_REST_Request(
		array( 'x_telegram_bot_api_secret_token' => Telegram::secret() ),
		array( 'message' => array( 'chat' => array( 'id' => $chat_id ), 'text' => $text ) )
	);
	return (array) Bot::receive( $request )->get_data();
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

echo "\n=== A resposta viaja na resposta ao webhook ===\n";

// This is the change that matters for the server: what used to be "answer 200,
// then open a socket to Telegram and wait up to ten seconds" is now one request
// that ends where it started. The assertion that proves it is not the body —
// it is that nothing went out.
$GLOBALS['__hti_subs']     = array();
$GLOBALS['__hti_http']     = array();
$GLOBALS['__hti_http_log'] = array();

$reply = say( 4242, '/help' );

check( 'sendMessage' === ( $reply['method'] ?? '' ), '/help responde com um sendMessage no corpo' );
check( 4242 === ( $reply['chat_id'] ?? 0 ), 'endereçado a quem escreveu' );
check( 'HTML' === ( $reply['parse_mode'] ?? '' ), 'em HTML, como o Telegram espera' );
check( array() === $GLOBALS['__hti_http_log'], 'e sem uma única chamada de saída' );

$reply = say( 4242, 'isto não é um número' );
check( 'sendMessage' === ( $reply['method'] ?? '' ), 'texto que não é um saldo também responde no corpo' );
check( array() === $GLOBALS['__hti_http_log'], 'ainda sem chamadas de saída' );

$reply = say( 4242, '50000' );
check( 'sendMessage' === ( $reply['method'] ?? '' ), 'um saldo é respondido no corpo' );
check( str_contains( (string) ( $reply['text'] ?? '' ), '₹' ), 'com a conta em rupias' );
check( isset( $reply['reply_markup']['inline_keyboard'] ), 'e com os botões agarrados' );
check( array() === $GLOBALS['__hti_http_log'], 'sem chamadas de saída' );

$reply = say( 4242, '/stop' );
check( 'sendMessage' === ( $reply['method'] ?? '' ), '/stop também' );
check( ! in_array( 4242, $GLOBALS['__hti_subs'], true ), 'e a pessoa sai mesmo da lista' );

echo "\n=== O botão deixa de custar duas chamadas ===\n";

$GLOBALS['__hti_http']     = array();
$GLOBALS['__hti_http_log'] = array();

$request = new WP_REST_Request(
	array( 'x_telegram_bot_api_secret_token' => Telegram::secret() ),
	array(
		'callback_query' => array(
			'id'      => 'cb1',
			'data'    => 'p:GBPUSD:50000',
			'message' => array( 'chat' => array( 'id' => 4242 ) ),
		),
	)
);
$reply = (array) Bot::receive( $request )->get_data();

check( 'sendMessage' === ( $reply['method'] ?? '' ), 'a nova resposta vem no corpo' );
check( str_contains( (string) ( $reply['text'] ?? '' ), 'GBP/USD' ), 'já com o par que foi escolhido' );
check( 1 === count( $GLOBALS['__hti_http_log'] ), 'resta uma só chamada, não duas' );
check(
	str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['url'] ?? '' ), 'answerCallbackQuery' ),
	'e é a que limpa o indicador do botão'
);

echo "\n=== O que o Telegram diz sobre o webhook ===\n";

// The last delivery error is the one diagnostic this side cannot produce for
// itself: if our endpoint fails, updates simply stop arriving.
$GLOBALS['__hti_http'] = array(
	array(
		'body' => array(
			'ok'     => true,
			'result' => array(
				'url'                  => Telegram::webhook_url(),
				'pending_update_count' => 3,
				'last_error_date'      => 1756000000,
				'last_error_message'   => 'Wrong response from the webhook: 500 Internal Server Error',
			),
		),
		'code' => 200,
	),
);

$info = Telegram::webhook_info();

check( true === $info['ok'], 'uma resposta boa é lida' );
check( Telegram::webhook_url() === $info['url'], 'o URL registado vem de lá' );
check( 3 === $info['pending'], 'e as atualizações à espera' );
check( str_contains( $info['error'], '500' ), 'e o último erro de entrega' );
check( 1756000000 === $info['error_at'], 'com a data do erro' );

$GLOBALS['__hti_http'] = array(
	array( 'body' => array( 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ), 'code' => 401 ),
);
$info = Telegram::webhook_info();

check( false === $info['ok'], 'um token recusado não é uma resposta boa' );
check( 'Unauthorized' === $info['description'], 'e diz-se porquê' );
check( '' === $info['url'] && 0 === $info['pending'], 'sem inventar valores' );

$GLOBALS['__hti_http'] = array(
	array( 'body' => 'não é json', 'code' => 200 ),
);
$info = Telegram::webhook_info();
check( false === $info['ok'] && '' !== $info['description'], 'uma resposta ilegível não rebenta nada' );

echo "\n=== O estado do bot, em cache ===\n";

delete_transient( Telegram::TRANSIENT_HEALTH );
$GLOBALS['__hti_http']     = array(
	array( 'body' => array( 'ok' => true, 'result' => array( 'url' => Telegram::webhook_url(), 'pending_update_count' => 0 ) ), 'code' => 200 ),
	array( 'body' => array( 'ok' => true, 'result' => array( 'username' => 'HowToInvestForexBot' ) ), 'code' => 200 ),
);
$GLOBALS['__hti_http_log'] = array();

$health = Telegram::health();

check( 'HowToInvestForexBot' === $health['username'], 'o bot identifica-se' );
check( true === $health['ours'], 'e o webhook registado é o nosso' );
check( 2 === count( $GLOBALS['__hti_http_log'] ), 'ao custo de duas chamadas' );

$GLOBALS['__hti_http_log'] = array();
$again = Telegram::health();
check( $again === $health, 'a segunda leitura devolve o mesmo' );
check( array() === $GLOBALS['__hti_http_log'], 'sem voltar a falar com o Telegram' );

// A webhook pointing elsewhere is invisible from every other angle: Telegram
// allows one per bot, so whoever registered last is receiving the messages.
delete_transient( Telegram::TRANSIENT_HEALTH );
$GLOBALS['__hti_http'] = array(
	array( 'body' => array( 'ok' => true, 'result' => array( 'url' => 'https://staging.example.com/wp-json/htinvest/v1/forex/telegram' ) ), 'code' => 200 ),
	array( 'body' => array( 'ok' => true, 'result' => array( 'username' => 'HowToInvestForexBot' ) ), 'code' => 200 ),
);
$stolen = Telegram::health();

check( false === $stolen['ours'], 'um webhook roubado por outro site é detetado' );
check( str_contains( $stolen['webhook']['url'], 'staging' ), 'e diz-se quem o tem' );

echo "\n=== Do anúncio à conta: a etiqueta atravessa o bot ===\n";

// A prova ponta a ponta do que os anúncios pagaram: alguém entra por um
// deep-link de campanha, pergunta um saldo dias depois, e o link que sai para
// a corretora ainda leva a campanha colada.
// /start desenha uma imagem, e Bot_Images resolve o caminho do plugin.
if ( ! defined( 'HTI_FOREX_PATH' ) ) {
	define( 'HTI_FOREX_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'HTI_FOREX_URL' ) ) {
	define( 'HTI_FOREX_URL', 'https://howtoinvest.pro/wp-content/plugins/hti-forex/' );
}

$GLOBALS['__hti_chat_sources'] = array();
$GLOBALS['__hti_options'][ HTI\Forex\Settings::OPTION ] = array_merge(
	HTI\Forex\Settings::defaults(),
	array(
		'cta_enabled'    => true,
		'bot_ad_enabled' => true,
	)
);

say( 90001, '/start b2' );
check( 'b2' === HTI\Forex\Bot_Store::source( 90001 ), 'o /start com campanha fica gravado na linha do chat' );

say( 90001, '/start c1' );
check( 'b2' === HTI\Forex\Bot_Store::source( 90001 ), 'um segundo anúncio não rouba a atribuição ao primeiro' );

$answer = say( 90001, '50000' );
check( str_contains( (string) ( $answer['text'] ?? '' ), 'cid=b2' ), 'e a resposta seguinte leva a campanha no link do parceiro' );

say( 90002, '/start' );
check( '' === HTI\Forex\Bot_Store::source( 90002 ), 'quem entra sem campanha não ganha etiqueta nenhuma' );
$plain = say( 90002, '50000' );
check( ! str_contains( (string) ( $plain['text'] ?? '' ), 'cid=' ), 'e o seu link sai sem etiqueta' );

echo "\n=== Mexer no webhook limpa o que estava em cache ===\n";

$GLOBALS['__hti_http'] = array( array( 'body' => array( 'ok' => true, 'result' => true ), 'code' => 200 ) );
Telegram::register_webhook();
check( false === get_transient( Telegram::TRANSIENT_HEALTH ), 'registar o webhook invalida o estado guardado' );

set_transient( Telegram::TRANSIENT_HEALTH, array( 'stale' => true ), 300 );
$GLOBALS['__hti_http'] = array( array( 'body' => array( 'ok' => true, 'result' => true ), 'code' => 200 ) );
Telegram::remove_webhook();
check( false === get_transient( Telegram::TRANSIENT_HEALTH ), 'e removê-lo também' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
