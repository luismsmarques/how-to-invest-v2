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

// Without a token every start() refuses before it reaches the logic under
// test, which would make most of this file pass for the wrong reason.
define( 'HTI_TELEGRAM_BOT_TOKEN', '123456:test-token' );

define( 'HTI_FOREX_PATH', dirname( __DIR__ ) . '/' );
define( 'HTI_FOREX_URL', 'https://howtoinvest.pro/wp-content/plugins/hti-forex/' );

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-bot-math.php';
require_once __DIR__ . '/../includes/class-bot-images.php';
require_once __DIR__ . '/../includes/class-telegram.php';
require_once __DIR__ . '/fixtures/bot-store-stub.php';
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

echo "\n=== A recusa deixa de ser um aviso que desaparece ===\n";

// The reason used to live only in an admin notice, gone on the next reload.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();

check( false === Bot_Broadcast::start( '' ), 'uma mensagem vazia é recusada' );
check( 'empty' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'e a razão fica registada' );
check( ( Bot_Broadcast::log()['refused']['at'] ?? 0 ) > 0, 'com a data' );

check( false === Bot_Broadcast::start( str_repeat( 'a', 2000 ), 'promo' ), 'uma legenda longa demais é recusada' );
check( 'caption-too-long' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'e diz que foi a legenda' );

put_state(
	array(
		'text'     => 'a correr',
		'image'    => '',
		'cursor'   => 0,
		'sent'     => 0,
		'dropped'  => 0,
		'total'    => 10,
		'started'  => time(),
		'updated'  => time(),
		'finished' => 0,
	)
);
wp_schedule_single_event( time() + 1, Bot_Broadcast::HOOK );
check( false === Bot_Broadcast::start( 'outra' ), 'uma segunda difusão em cima de outra é recusada' );
check( 'already-running' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'e diz que já havia uma a correr' );

echo "\n=== Uma confirmação que não se pode falsificar ===\n";

// "Broadcast queued" used to be drawn from a URL parameter, so a restored tab
// or a back button claimed a send had been queued when none had. The screen now
// checks this instead.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_subs']    = array( 7001 );

check( ! Bot_Broadcast::just_started(), 'sem envios, não há nada para confirmar' );

Bot_Broadcast::start( 'a valer' );
check( Bot_Broadcast::just_started(), 'um envio aceite fica registado como acabado de começar' );

Bot_Broadcast::cancel();
check( Bot_Broadcast::just_started(), 'e cancelar não apaga o facto de ter começado' );

// Age it past the window: the same record stops standing as confirmation,
// because a confirmation is about what was just done, not about yesterday.
$log            = get_option( Bot_Broadcast::OPTION_LOG );
$log['started'] = time() - 3600;
update_option( Bot_Broadcast::OPTION_LOG, $log );

check( ! Bot_Broadcast::just_started(), 'um arranque antigo deixa de valer como confirmação' );

$GLOBALS['__hti_options'] = array();
check( false === Bot_Broadcast::start( '' ), 'uma recusa continua a ser recusa' );
check( ! Bot_Broadcast::just_started(), 'e não deixa nada que pareça um envio aceite' );
check( 'empty' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'com a razão registada' );

echo "\n=== A ordem: estado primeiro, confirmação depois ===\n";

// This shipped the other way round. remember_start() ran before the state was
// written, so a failed write left a note saying a broadcast had begun when none
// existed — and just_started(), which the confirmation on screen is checked
// against, believed it. The confirmation moved one step earlier instead of
// becoming true.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_subs']    = array( 8001 );

$GLOBALS['__hti_refuse_write'] = Bot_Broadcast::OPTION;
$ok = Bot_Broadcast::start( 'esta não devia passar' );
unset( $GLOBALS['__hti_refuse_write'] );

check( false === $ok, 'uma gravação recusada faz o start() falhar' );
check( ! Bot_Broadcast::just_started(), 'e não deixa nada a dizer que uma difusão começou' );
check( 'write-failed' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'com a razão registada, para o ecrã a poder dizer' );
check( 0 === Bot_Broadcast::status()['started'], 'e o estado continua sem difusão' );
check( ! Bot_Broadcast::running(), 'logo o compositor continua disponível' );

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
check( true === Bot_Broadcast::start( 'esta passa' ), 'uma gravação que resulta devolve true' );
check( Bot_Broadcast::just_started(), 'e só então fica registada como começada' );
check( Bot_Broadcast::status()['started'] > 0, 'com o estado gravado' );

echo "\n=== A opção do estado tem nome próprio ===\n";

// The old name became unwritable on the live site: the row was gone from the
// database while WordPress still believed the option existed, so every write
// took the UPDATE path, matched no row, and failed — permanently, and looking
// exactly like a message that had gone out. Going back to that name would walk
// into the same wall.
check( 'hti_forex_bot_broadcast' !== Bot_Broadcast::OPTION, 'não voltámos ao nome que ficou inutilizável' );
check( Bot_Broadcast::OPTION !== Bot_Broadcast::OPTION_LOG, 'o estado e o registo são opções distintas' );
check( Bot_Broadcast::HOOK !== Bot_Broadcast::OPTION, 'e o hook do cron não se confunde com a opção' );

echo "\n=== Uma cache velha não pode travar isto para sempre ===\n";

// add_option() asks whether the option exists; a stale object cache answers yes
// with a value the database no longer holds, and it refuses — not once, but
// every time. One retry with the entry dropped is what tells that apart from a
// database that genuinely will not take the write.
$GLOBALS['__hti_options']       = array();
$GLOBALS['__hti_cron']          = array();
$GLOBALS['__hti_subs']          = array( 9001 );
$GLOBALS['__hti_cache_deleted'] = array();

$GLOBALS['__hti_refuse_until_flush'] = Bot_Broadcast::OPTION;
$ok = Bot_Broadcast::start( 'esta passa à segunda' );

check( true === $ok, 'uma escrita recusada é tentada outra vez e passa' );
check( in_array( Bot_Broadcast::OPTION, $GLOBALS['__hti_cache_deleted'], true ), 'depois de a entrada em cache ser largada' );
check( Bot_Broadcast::status()['started'] > 0, 'e o estado fica mesmo gravado' );
check( Bot_Broadcast::running(), 'logo a difusão conta como a correr' );
check( array() === Bot_Broadcast::log()['refused'], 'e não fica registada recusa nenhuma' );

// A database that refuses regardless is still reported, not retried for ever.
$GLOBALS['__hti_options']      = array();
$GLOBALS['__hti_cron']         = array();
$GLOBALS['__hti_refuse_write'] = Bot_Broadcast::OPTION;
$GLOBALS['wpdb']->last_error   = 'Disk full at option write';
$ok = Bot_Broadcast::start( 'esta não passa de todo' );
unset( $GLOBALS['__hti_refuse_write'] );
$GLOBALS['wpdb']->last_error = '';

check( false === $ok, 'uma recusa que persiste continua a falhar' );
check( 'write-failed' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'e é reportada, não escondida pela repetição' );

// Five guesses at this failure from the outside were five wrong ones. MySQL
// knows what it objected to, and until now nothing carried its answer up.
check( 'Disk full at option write' === ( Bot_Broadcast::log()['refused']['detail'] ?? '' ), 'e traz consigo o que a base de dados disse' );

// The cause that actually happened, and the one worth naming: an emoji is four
// bytes, and a column still on three-byte utf8 refuses the whole write.
// WordPress calls that "invalid data", which reads like a fault in the message
// rather than a limit of the table it is being written to.
check( Bot_Broadcast::has_four_byte_characters( 'Abre conta 📊 hoje' ), 'um emoji é reconhecido como caractere de 4 bytes' );
check( ! Bot_Broadcast::has_four_byte_characters( 'Abre conta hoje' ), 'texto simples não é' );
check( ! Bot_Broadcast::has_four_byte_characters( 'acentuação, ç, €, ₹' ), 'nem acentos, o euro ou a rupia — esses cabem em três bytes' );

$GLOBALS['__hti_options']      = array();
$GLOBALS['__hti_refuse_write'] = Bot_Broadcast::OPTION;
Bot_Broadcast::start( 'Abre conta 📊 hoje' );
unset( $GLOBALS['__hti_refuse_write'] );

check( 'emoji-unsupported' === ( Bot_Broadcast::log()['refused']['reason'] ?? '' ), 'e a recusa diz que foi o emoji, não "problema de servidor"' );

echo "\n=== O histórico ===\n";

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();

check( array() === Bot_Broadcast::log()['history'], 'sem difusões, o histórico está vazio' );

// A send that died: start() is the last moment its state exists, so that is
// where it gets written down.
put_state(
	array(
		'text'     => 'a que morreu a meio',
		'image'    => 'promo',
		'cursor'   => 40,
		'sent'     => 40,
		'dropped'  => 1,
		'total'    => 917,
		'started'  => time() - 86400,
		'updated'  => time() - 86400,
		'finished' => 0,
	)
);
Bot_Broadcast::start( 'a seguinte' );

$history = Bot_Broadcast::log()['history'];
check( 1 === count( $history ), 'a difusão morta é arquivada quando a seguinte começa' );
check( 'stalled' === $history[0]['how'], 'marcada como morta a meio' );
check( 40 === $history[0]['sent'], 'com o que chegou a sair' );
check( 917 === $history[0]['total'], 'e quantos havia na altura' );
check( 'a que morreu a meio' === $history[0]['excerpt'], 'e o texto, para se reconhecer qual era' );
check( 'promo' === $history[0]['image'], 'e a imagem que levava' );

check( array() === Bot_Broadcast::log()['refused'], 'e a recusa anterior é esquecida quando uma difusão arranca' );

Bot_Broadcast::cancel();
$history = Bot_Broadcast::log()['history'];
check( 2 === count( $history ), 'cancelar arquiva também' );
check( 'cancelled' === $history[0]['how'], 'marcada como parada à mão' );
check( 'a seguinte' === $history[0]['excerpt'], 'e é a mais recente que vem primeiro' );

// Bounded: ten is memory, not a log file.
for ( $i = 0; $i < 15; $i++ ) {
	Bot_Broadcast::start( "mensagem {$i}" );
	Bot_Broadcast::cancel();
}
$history = Bot_Broadcast::log()['history'];
check( 10 === count( $history ), 'o histórico pára nas dez' );
check( 'mensagem 14' === $history[0]['excerpt'], 'e a mais recente está no topo' );

echo "\n=== Uma difusão inteira, do princípio ao fim ===\n";

// The path that was fatally broken: the loop that actually sends. With a
// Telegram that answers, it can be walked end to end.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_http']    = array();
$GLOBALS['__hti_subs']    = range( 1001, 1010 );

check( true === Bot_Broadcast::start( 'olá a todos' ), 'a difusão é aceite' );
check( Bot_Broadcast::running(), 'e fica a correr' );

Bot_Broadcast::run();
$after = Bot_Broadcast::status();

check( 10 === $after['sent'], 'toda a gente recebeu' );
check( 0 === $after['dropped'], 'e ninguém foi descartado' );
check( 10 === $after['cursor'], 'o cursor parou no último' );
check( count( $GLOBALS['__hti_http_log'] ) === 10, 'houve uma chamada ao Telegram por pessoa' );
check( str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['url'] ?? '' ), 'sendMessage' ), 'como sendMessage' );
check( str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['body']['text'] ?? '' ), 'olá a todos' ), 'com o texto escrito' );
check( str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['body']['text'] ?? '' ), '/stop' ), 'e o rodapé de saída colado' );

Bot_Broadcast::run();
check( Bot_Broadcast::status()['finished'] > 0, 'o tique seguinte não encontra ninguém e fecha' );
check( 'finished' === ( Bot_Broadcast::log()['history'][0]['how'] ?? '' ), 'e fica arquivada como terminada' );

echo "\n=== Com imagem ===\n";

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_http']     = array();
$GLOBALS['__hti_http_log'] = array();
$GLOBALS['__hti_subs']     = array( 2001 );

Bot_Broadcast::start( 'com fotografia', 'promo' );
Bot_Broadcast::run();

check( 1 === Bot_Broadcast::status()['sent'], 'a mensagem com imagem sai' );
check( str_contains( (string) ( $GLOBALS['__hti_http_log'][0]['url'] ?? '' ), 'sendPhoto' ), 'como sendPhoto, não sendMessage' );

echo "\n=== Quem bloqueou, e quem falhou ===\n";

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_http_log'] = array();
$GLOBALS['__hti_subs']     = array( 3001, 3002, 3003 );

// One blocked the bot, one failed for a reason we can neither retry nor
// explain away, one went out.
$GLOBALS['__hti_http'] = array(
	array( 'body' => array( 'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user' ), 'code' => 403 ),
	array( 'body' => array( 'ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities" ), 'code' => 400 ),
	array( 'body' => array( 'ok' => true, 'result' => array() ), 'code' => 200 ),
);

Bot_Broadcast::start( 'mistura' );
Bot_Broadcast::run();
$after = Bot_Broadcast::status();

check( 1 === $after['sent'], 'só um foi entregue' );
check( 2 === $after['dropped'], 'os dois recusados foram descartados' );
check( 1 === count( $GLOBALS['__hti_subs'] ), 'e as linhas mortas saíram da tabela' );

echo "\n=== Uma falha que não é nem bloqueio nem excesso ===\n";

// A revoked token, Telegram down, a tag it will not parse: not a dead chat and
// not a rate limit. This used to move the cursor on and leave no trace.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_subs']    = array( 5001, 5002 );
$GLOBALS['__hti_http']    = array(
	array( 'body' => array( 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ), 'code' => 401 ),
	array( 'body' => array( 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ), 'code' => 401 ),
);

Bot_Broadcast::start( 'com token revogado' );
Bot_Broadcast::run();
$after  = Bot_Broadcast::status();
$errors = Bot_Broadcast::log()['errors'];

check( 0 === $after['sent'], 'nada foi entregue' );
check( 0 === $after['dropped'], 'e ninguém foi descartado — não é culpa deles' );
check( 2 === count( $GLOBALS['__hti_subs'] ), 'a tabela fica intacta' );
check( isset( $errors['401'] ), 'a falha fica registada pelo código do Telegram' );
check( 'Unauthorized' === ( $errors['401']['description'] ?? '' ), 'com o que o Telegram disse' );
check( 2 === ( $errors['401']['count'] ?? 0 ), 'e a mesma falha é contada, não repetida' );

// An error code is a numeric key, and PHP reindexes those — so evicting the
// oldest with array_shift would renumber every code and silently destroy the
// grouping the log exists for.
$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_subs']    = array( 6001 );

for ( $code = 500; $code < 512; $code++ ) {
	$GLOBALS['__hti_http'] = array(
		array( 'body' => array( 'ok' => false, 'error_code' => $code, 'description' => "erro {$code}" ), 'code' => $code ),
	);
	Bot_Broadcast::start( "falha {$code}" );
	Bot_Broadcast::run();
	Bot_Broadcast::cancel();
}

$errors = Bot_Broadcast::log()['errors'];

check( 10 === count( $errors ), 'o registo de falhas pára nas dez' );
check( isset( $errors['511'] ), 'a mais recente está lá, pelo seu código' );
check( 511 === ( $errors['511']['code'] ?? 0 ), 'com o código intacto' );
check( ! isset( $errors['500'] ), 'e a mais antiga saiu' );

$keys = array_keys( $errors );
$ok   = true;
foreach ( $keys as $k ) {
	if ( (int) $k !== (int) ( $errors[ $k ]['code'] ?? -1 ) ) {
		$ok = false;
	}
}
check( $ok, 'nenhuma chave foi renumerada — cada uma continua a ser o seu código' );

echo "\n=== Quando o Telegram manda abrandar ===\n";

$GLOBALS['__hti_options'] = array();
$GLOBALS['__hti_cron']    = array();
$GLOBALS['__hti_subs']    = array( 4001, 4002, 4003 );
$GLOBALS['__hti_http']    = array(
	array( 'body' => array( 'ok' => true, 'result' => array() ), 'code' => 200 ),
	array( 'body' => array( 'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => array( 'retry_after' => 7 ) ), 'code' => 429 ),
);

Bot_Broadcast::start( 'devagar' );
Bot_Broadcast::run();
$after = Bot_Broadcast::status();

check( 1 === $after['sent'], 'o lote pára no 429' );
check( 1 === $after['cursor'], 'e o cursor não passa por cima de quem não recebeu' );
check( 0 === $after['finished'], 'a difusão não é dada como terminada' );
check( false !== wp_next_scheduled( Bot_Broadcast::HOOK ), 'e fica um tique agendado para retomar' );

echo "\n=== O limite da legenda ===\n";

check( Bot_Broadcast::fits_caption( str_repeat( 'a', 5000 ), '' ), 'sem imagem, o limite da legenda não se aplica' );
check( ! Bot_Broadcast::fits_caption( str_repeat( 'a', 1024 ), 'promo' ), 'com imagem, o rodapé conta para o limite' );
check( Bot_Broadcast::fits_caption( 'curta', 'promo' ), 'uma mensagem curta cabe na legenda' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
