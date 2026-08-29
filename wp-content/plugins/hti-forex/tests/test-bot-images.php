<?php
/**
 * The bot's images: the registry, the file_id cache, and the caption limit.
 *
 *   php wp-content/plugins/hti-forex/tests/test-bot-images.php
 *
 * The cache is the part worth testing. A stale file_id would make the bot
 * send yesterday's picture for ever, and a cache that never hits would make
 * Telegram pull a 250 KB PNG off a shared host once per person per message.
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
require_once __DIR__ . '/../includes/class-bot.php';
require_once __DIR__ . '/../includes/class-bot-broadcast.php';

use HTI\Forex\Bot;
use HTI\Forex\Bot_Broadcast;
use HTI\Forex\Bot_Images;
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

echo "\n=== Os ficheiros estão lá ===\n";

foreach ( Bot_Images::files() as $slug => $file ) {
	check( Bot_Images::exists( $slug ), sprintf( '%s → %s existe e lê-se', $slug, $file ) );
	check( str_ends_with( Bot_Images::url( $slug ), '/assets/brand/' . $file ), sprintf( '%s tem URL público', $slug ) );
}

check( '' === Bot_Images::path( 'nope' ), 'slug desconhecido não devolve caminho' );
check( '' === Bot_Images::url( 'nope' ), 'nem URL' );
check( false === Bot_Images::exists( 'nope' ), 'nem existe' );
check( '' === Bot_Images::photo( 'nope' ), 'e não se envia' );

echo "\n=== São PNG e cabem no que o Telegram aceita ===\n";

foreach ( array_keys( Bot_Images::files() ) as $slug ) {
	$path = Bot_Images::path( $slug );
	$head = (string) file_get_contents( $path, false, null, 0, 24 );

	check( str_starts_with( $head, "\x89PNG\r\n\x1a\n" ), sprintf( '%s é PNG a sério', $slug ) );

	$dim = unpack( 'Nw/Nh', substr( $head, 16, 8 ) );
	check( $dim['w'] + $dim['h'] <= 10000, sprintf( '%s: %dx%d dentro do limite de soma do Telegram', $slug, $dim['w'], $dim['h'] ) );
	check( filesize( $path ) <= 10 * 1024 * 1024, sprintf( '%s pesa %d KB, abaixo dos 10 MB', $slug, filesize( $path ) / 1024 ) );

	// A 360px de largura num telemóvel, uma tela de 2560 encolhe para 14%.
	check( $dim['w'] >= 1280, sprintf( '%s tem resolução para não ficar mole em ecrãs densos', $slug ) );
}

echo "\n=== A cache de file_id ===\n";

$GLOBALS['__hti_options'] = array();

check( Bot_Images::url( 'start' ) === Bot_Images::photo( 'start' ), 'sem cache, envia-se o URL para o Telegram ir buscar' );

Bot_Images::remember( 'start', 'AgACAgQAAx0-EXEMPLO' );
check( 'AgACAgQAAx0-EXEMPLO' === Bot_Images::photo( 'start' ), 'depois de guardado, envia-se o id' );

// Um ficheiro trocado no servidor tem de invalidar o id sozinho — ninguém se
// vai lembrar de limpar uma cache depois de um deploy.
$cache = get_option( 'hti_forex_bot_images', array() );
$cache['start']['fingerprint'] = 'outra-coisa';
update_option( 'hti_forex_bot_images', $cache );
check( Bot_Images::url( 'start' ) === Bot_Images::photo( 'start' ), 'ficheiro alterado → o id cai e volta-se ao URL' );

Bot_Images::remember( 'nope', 'x' );
check( ! isset( get_option( 'hti_forex_bot_images', array() )['nope'] ), 'não se guarda id para um slug que não existe' );

Bot_Images::remember( 'pip', '' );
check( ! isset( get_option( 'hti_forex_bot_images', array() )['pip'] ), 'nem um id vazio' );

echo "\n=== Ler o file_id da resposta do Telegram ===\n";

$reply = array(
	'photo' => array(
		array( 'file_id' => 'pequeno', 'width' => 90 ),
		array( 'file_id' => 'medio', 'width' => 320 ),
		array( 'file_id' => 'grande', 'width' => 1280 ),
	),
);
check( 'grande' === Bot_Images::file_id_from( $reply ), 'fica-se com a maior das versões' );
check( '' === Bot_Images::file_id_from( array() ), 'resposta sem fotos → sem id' );
check( '' === Bot_Images::file_id_from( null ), 'resposta nula → sem id' );
check( '' === Bot_Images::file_id_from( array( 'photo' => 'lixo' ) ), 'resposta malformada → sem id' );

echo "\n=== As legendas cabem no limite ===\n";

// Uma legenda longa de mais não trunca: falha o envio, e falha-o para toda a
// gente de uma vez. Por isso é um teste e não uma esperança.
$captions = array(
	'/start'        => Bot::start_text(),
	'pip explainer' => Bot::pip_explainer(),
);
foreach ( $captions as $name => $text ) {
	$n = mb_strlen( $text );
	check( $n <= Telegram::CAPTION_MAX, sprintf( '%s: %d de %d caracteres', $name, $n, Telegram::CAPTION_MAX ) );
}

check( true === Bot_Broadcast::fits_caption( str_repeat( 'a', 4000 ), '' ), 'sem imagem, o limite de legenda não se aplica' );
check( true === Bot_Broadcast::fits_caption( 'curta', 'promo' ), 'com imagem, uma mensagem curta passa' );
check( false === Bot_Broadcast::fits_caption( str_repeat( 'a', 1024 ), 'promo' ), 'com imagem, o rodapé /stop faz uma de 1024 estourar' );
check( false === Bot_Broadcast::fits_caption( str_repeat( 'a', 2000 ), 'promo' ), 'e uma claramente longa também' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
