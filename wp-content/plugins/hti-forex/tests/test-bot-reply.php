<?php
/**
 * What the bot actually sends back: the answer text, the buttons, and the
 * state machine behind a broadcast.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-reply.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-bot-math.php';
require_once __DIR__ . '/../includes/class-telegram.php';
require_once __DIR__ . '/../includes/class-bot.php';
require_once __DIR__ . '/../includes/class-bot-broadcast.php';

use HTI\Forex\Bot;
use HTI\Forex\Bot_Broadcast;
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

$rates = array(
	'USDINR' => 95.5,
	'USDJPY' => 159.0,
	'EURUSD' => 1.165,
);

echo "\n=== O texto da resposta ===\n";

$small = Bot_Math::picture( 5000, 'EURUSD', 500, $rates );
$text  = Bot::reply_text( $small );

check( str_contains( $text, '<b>₹5,000 · EUR/USD · 1:500</b>' ), 'cabeçalho com saldo, par e alavancagem' );
check( str_contains( $text, '₹9.55' ), 'o valor do pip aparece com cêntimos' );
check( str_contains( $text, '₹191' ), 'o custo do stop de 20 pips aparece' );
check( str_contains( $text, '(3.8%)' ), 'e a percentagem da conta ao lado' );
check( str_contains( $text, '₹223' ), 'a margem aparece arredondada' );
check( str_contains( $text, '<pre>' ) && str_contains( $text, '</pre>' ), 'a tabela vai em <pre> para alinhar no telemóvel' );
check( str_contains( $text, 'Risking 1% (₹50)' ), 'espaço de manobra a 1%' );
check( str_contains( $text, 'Risking 2% (₹100)' ), 'espaço de manobra a 2%' );
check( ! str_contains( $text, 'RBI Alert List' ), 'o rodapé não repete a Alert List — vive no conteúdo do canal e no PDF' );
check( str_contains( $text, 'most retail accounts lose money' ), 'o aviso de risco está sempre lá' );

echo "\n=== O aviso honesto para contas pequenas ===\n";

check( str_contains( $text, '⚠️' ), '₹5.000 leva o aviso de que o lote mínimo já arrisca demais' );
check(
	str_contains( $text, 'not a reason to trade larger' ),
	'e o aviso é enquadrado como aritmética, não como incentivo'
);

$big = Bot_Math::picture( 500000, 'EURUSD', 500, $rates );
check( ! str_contains( Bot::reply_text( $big ), '⚠️' ), '₹5 lakh não leva aviso — não é apertada' );

echo "\n=== Sem imperativos nem promessas ===\n";

$all = implode(
	"\n",
	array(
		$text,
		Bot::reply_text( $big ),
		Bot::start_text(),
		Bot::help_text(),
		Bot::stop_text(),
		Bot::confused_text(),
		Bot::pip_explainer(),
	)
);

foreach ( array( 'you should', 'you must', 'guaranteed', 'best broker', 'act now', 'sure shot', 'win rate' ) as $banned ) {
	check( ! str_contains( strtolower( $all ), $banned ), sprintf( '"%s" não aparece em lado nenhum', $banned ) );
}

check( str_contains( Bot::start_text(), 'signals, trade calls or tips' ), '/start promete explicitamente que não há sinais' );
check( str_contains( Bot::start_text(), '/stop' ), '/start diz como sair' );

echo "\n=== HTML válido para o modo HTML do Telegram ===\n";

// O Telegram aceita um conjunto pequeno de etiquetas; qualquer outra faz a
// API rejeitar a mensagem — para todos os destinatários, não só para um.
$allowed = array( 'b', 'i', 'u', 's', 'code', 'pre', 'a' );
preg_match_all( '/<\s*\/?\s*([a-z0-9]+)/i', $all, $found );
$used = array_unique( array_map( 'strtolower', $found[1] ) );
$bad  = array_diff( $used, $allowed );

check( array() === $bad, 'só etiquetas suportadas (encontradas: ' . implode( ', ', $used ) . ')' );

$opens  = substr_count( $text, '<pre>' );
$closes = substr_count( $text, '</pre>' );
check( $opens === $closes && 1 === $opens, '<pre> abre e fecha uma vez' );

echo "\n=== A linha do parceiro ===\n";

$with_ad = Bot::reply_text( $small, '<i>Partner · Ad</i> — <a href="https://example.test/">demo</a>' );

check( str_contains( $with_ad, 'Partner · Ad' ), 'a linha do parceiro entra quando existe' );
check( ! str_contains( $text, 'Partner · Ad' ), 'e não entra quando está desligada' );

$ad_pos     = strpos( $with_ad, 'Partner · Ad' );
$table_pos  = strpos( $with_ad, '</pre>' );
$room_pos   = strpos( $with_ad, 'Risking 2%' );
check( $ad_pos > $table_pos, 'a publicidade vem depois da tabela, nunca no meio dela' );
check( $ad_pos > $room_pos, 'e depois de toda a resposta que a pessoa pediu' );

echo "\n=== Os botões ===\n";

$keyboard = Bot::keyboard( $small );
$flat     = array_merge( ...$keyboard );
$labels   = array_column( $flat, 'text' );
$data     = array_column( $flat, 'callback_data' );

check( ! in_array( 'EUR/USD', $labels, true ), 'o par atual não aparece como botão' );
check( in_array( 'Gold (XAU/USD)', $labels, true ) === false, 'o ouro não aparece — está fora do âmbito' );
check( ! in_array( '1:500', $labels, true ), 'a alavancagem atual não aparece como botão' );
check( in_array( '1:100', $labels, true ) && in_array( '1:200', $labels, true ), 'as outras alavancagens aparecem' );

$carried = array_filter( $data, static fn( string $d ): bool => str_ends_with( $d, ':5000' ) );
check( count( $carried ) === count( Bot_Math::PAIRS ) - 1 + count( Bot_Math::LEVERAGES ) - 1, 'o saldo viaja em todos os botões que recalculam' );
check( in_array( 'x:pip', $data, true ), 'o botão do jargão não carrega saldo nenhum' );

foreach ( $data as $d ) {
	check( strlen( $d ) <= 64, sprintf( 'callback_data "%s" cabe nos 64 bytes do Telegram', $d ) );
}

echo "\n=== A máquina de estados da difusão ===\n";

$GLOBALS['__hti_options'] = array();

$fresh = Bot_Broadcast::status();
check( 0 === $fresh['started'] && 0 === $fresh['sent'], 'sem difusão, o estado vem a zeros' );
check( false === Bot_Broadcast::running(), 'e nada está a correr' );

update_option(
	Bot_Broadcast::OPTION,
	array(
		'text'     => 'olá',
		'cursor'   => 12,
		'sent'     => 40,
		'dropped'  => 2,
		'total'    => 160,
		'started'  => 1000,
		'finished' => 0,
	)
);
check( true === Bot_Broadcast::running(), 'iniciada e não terminada → a correr' );

Bot_Broadcast::cancel();
check( false === Bot_Broadcast::running(), 'cancelar marca como terminada' );
check( 40 === Bot_Broadcast::status()['sent'], 'e preserva o que já tinha sido enviado' );

$GLOBALS['__hti_options'] = array();
check( false === Bot_Broadcast::start( '   ' ), 'mensagem vazia não arranca nada' );
check( false === Bot_Broadcast::start( 'olá' ), 'sem token do bot não arranca nada' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
