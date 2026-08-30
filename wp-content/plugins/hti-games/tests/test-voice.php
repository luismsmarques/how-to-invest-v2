<?php
/**
 * One voice sweep over every copy table the plugin ships.
 *
 * The individual tables already guard themselves — test-strings.php checks the
 * UI strings, test-lessons.php checks the lesson library. What none of them
 * covers is the editorial page copy, which is now the largest body of prose in
 * the section (roughly 1,800 words per language on the chart game alone) and
 * the part a stranger reads first.
 *
 * So this file is deliberately not another per-table test. It enumerates every
 * table by asking the classes for them, and fails if a new one appears that it
 * does not know about — because the failure mode worth preventing is not a bad
 * sentence in a table somebody is watching, it is a whole new table nobody is.
 *
 * What it enforces is the house voice from .claude/skills/brand-voice: calm,
 * second person, conditional. No urgency, no promises, no imperatives to act,
 * nothing that reads as an inducement, and — specific to a game about a market
 * — nothing claiming an outcome could have been seen coming.
 *
 *   php wp-content/plugins/hti-games/tests/test-voice.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-lessons.php';
require_once __DIR__ . '/../includes/class-seeder.php';

if ( is_readable( __DIR__ . '/../includes/class-reveal-lessons.php' ) ) {
	require_once __DIR__ . '/../includes/class-reveal-lessons.php';
}

use HTI\Games\Lessons;
use HTI\Games\Seeder;
use HTI\Games\Strings;

/**
 * Every bilingual copy table the plugin ships, as name => rows.
 *
 * @return array<string,array<string,array{en:string,pt:string}>>
 */
function hti_games_copy_tables(): array {
	$tables = array(
		'Strings::all' => Strings::all(),
		'Lessons::all' => hti_games_flatten_lessons( Lessons::all() ),
		'Seeder::copy' => Seeder::copy(),
	);

	if ( class_exists( '\\HTI\\Games\\Reveal_Lessons' ) ) {
		$tables['Reveal_Lessons::all'] = hti_games_flatten_lessons( \HTI\Games\Reveal_Lessons::all() );
	}

	return $tables;
}

/**
 * A lesson library is keyed by class and then listed, so flatten it to the
 * key => { en, pt } shape the rest of this file walks.
 *
 * Accepts a library that is already flat, so it does not care which of the two
 * lesson classes changes shape first.
 *
 * @param array<string,mixed> $library Lesson library.
 * @return array<string,array{en:string,pt:string}>
 */
function hti_games_flatten_lessons( array $library ): array {
	$out = array();

	foreach ( $library as $class => $list ) {
		if ( isset( $list['en'] ) || isset( $list['pt'] ) ) {
			$out[ (string) $class ] = $list;
			continue;
		}
		foreach ( (array) $list as $i => $lesson ) {
			$id         = (string) ( $lesson['id'] ?? $class . '_' . $i );
			$out[ $id ] = $lesson;
		}
	}

	return $out;
}

$tables = hti_games_copy_tables();
$rows   = 0;
foreach ( $tables as $table ) {
	$rows += count( $table );
}

echo "Every copy table is in the sweep\n";
hti_games_check( count( $tables ) >= 3, sprintf( 'found %d copy tables holding %d rows', count( $tables ), $rows ) );

// The guard against a table nobody watches: any class exposing a bilingual
// table has to be listed above. Grep the includes for the shape rather than
// trusting the list to be maintained by hand.
$suspects = array();
foreach ( (array) glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
	$body = (string) file_get_contents( $file );
	// A bilingual table declares rows as array( 'en' => …, 'pt' => … ).
	if ( ! preg_match( "/'en'\s*=>/", $body ) || ! preg_match( "/'pt'\s*=>/", $body ) ) {
		continue;
	}
	$class = str_replace( array( 'class-', '.php' ), '', basename( $file ) );
	$class = implode( '_', array_map( 'ucfirst', explode( '-', $class ) ) );
	$known = false;
	foreach ( array_keys( $tables ) as $name ) {
		if ( str_starts_with( strtolower( $name ), strtolower( $class ) ) ) {
			$known = true;
			break;
		}
	}
	// Exempt for three different reasons, all deliberate. Seed_Cases holds case
	// data rather than site copy and has its own file. Frontend, Case_Admin and
	// the admin classes hold admin-only labels, which no visitor reads. Config's
	// 'en'/'pt' keys are page slugs, not prose — they are shaped like a copy
	// table and are not one.
	if ( ! $known && ! in_array( $class, array( 'Seed_Cases', 'Frontend', 'Case_Admin', 'Cpt', 'Scenario_Admin', 'Importer', 'Settings', 'Installer', 'Schema', 'Config' ), true ) ) {
		$suspects[] = $class;
	}
}
hti_games_check( array() === $suspects, 'no bilingual table is outside the sweep (' . ( $suspects ? implode( ', ', $suspects ) : 'none' ) . ')' );

echo "\nNo urgency, no promise, no inducement\n";
// .claude/skills/brand-voice's avoid list, in both languages. A leaderboard
// game reaches for exactly these words, which is why the rule is a test.
$banned = array(
	'en' => array( 'guaranteed', 'guarantee', 'beat the market', "don't miss", 'do not miss', 'act now', 'hurry', 'limited time', 'you will make', 'you will earn', 'get rich', 'easy money', 'smart money', 'hot tip', 'top picks', 'must buy', 'risk-free', 'no risk', 'sure thing' ),
	'pt' => array( 'garantido', 'garantimos', 'bater o mercado', 'não percas', 'nao percas', 'age já', 'despacha-te', 'tempo limitado', 'vais ganhar', 'ficar rico', 'dinheiro fácil', 'dica quente', 'sem risco', 'aposta certa' ),
);
$offenders = array();
foreach ( $tables as $name => $table ) {
	foreach ( $table as $key => $pair ) {
		foreach ( $banned as $lang => $words ) {
			$text = (string) ( $pair[ $lang ] ?? '' );
			foreach ( $words as $word ) {
				if ( false !== stripos( $text, $word ) ) {
					$offenders[] = "{$name}[{$key}].{$lang}: \"{$word}\"";
				}
			}
		}
	}
}
hti_games_check( array() === $offenders, 'nothing promises or hurries (' . ( $offenders ? implode( '; ', array_slice( $offenders, 0, 5 ) ) : 'clean' ) . ')' );

echo "\nNothing claims the outcome was knowable\n";
// The game is built so direction is often unknowable — that is the point of
// the ambiguous and trap classes. Copy that says otherwise teaches the player
// to look for a signal the engine deliberately did not put there.
$hindsight = array(
	'en' => array( 'you should have seen', 'the signs were there', 'obvious in hindsight', 'clearly heading', 'was always going to', 'anyone could see', 'the chart told you' ),
	'pt' => array( 'devias ter visto', 'os sinais estavam lá', 'óbvio em retrospetiva', 'claramente a caminho', 'ia sempre', 'qualquer um via', 'o gráfico dizia-te' ),
);
$hind = array();
foreach ( $tables as $name => $table ) {
	foreach ( $table as $key => $pair ) {
		foreach ( $hindsight as $lang => $phrases ) {
			$text = (string) ( $pair[ $lang ] ?? '' );
			foreach ( $phrases as $phrase ) {
				if ( false !== stripos( $text, $phrase ) ) {
					$hind[] = "{$name}[{$key}].{$lang}: \"{$phrase}\"";
				}
			}
		}
	}
}
hti_games_check( array() === $hind, 'nothing claims the outcome could have been called (' . ( $hind ? implode( '; ', $hind ) : 'clean' ) . ')' );

echo "\nEvery table is complete in both languages\n";
$gaps = array();
foreach ( $tables as $name => $table ) {
	foreach ( $table as $key => $pair ) {
		foreach ( array( 'en', 'pt' ) as $lang ) {
			if ( ! isset( $pair[ $lang ] ) || '' === trim( (string) $pair[ $lang ] ) ) {
				$gaps[] = "{$name}[{$key}].{$lang}";
			}
		}
	}
}
hti_games_check( array() === $gaps, 'no row is missing a language (' . ( $gaps ? implode( ', ', array_slice( $gaps, 0, 5 ) ) : 'all present' ) . ')' );

echo "\nPlaceholders survive translation\n";
// A %d present in English and dropped in Portuguese is a warning waiting for a
// Portuguese reader, and vsprintf does not care that the sentence is prose.
$mismatched = array();
foreach ( $tables as $name => $table ) {
	foreach ( $table as $key => $pair ) {
		$found = array();
		foreach ( array( 'en', 'pt' ) as $lang ) {
			$m = array();
			preg_match_all( '/%(?!%)[0-9]*\$?[bcdeEfFgGosuxX]/', str_replace( '%%', '', (string) ( $pair[ $lang ] ?? '' ) ), $m );
			sort( $m[0] );
			$found[ $lang ] = $m[0];
		}
		if ( $found['en'] !== $found['pt'] ) {
			$mismatched[] = "{$name}[{$key}]";
		}
	}
}
hti_games_check( array() === $mismatched, 'the same placeholders appear in both languages (' . ( $mismatched ? implode( ', ', $mismatched ) : 'all aligned' ) . ')' );

echo "\nThe sweep can actually find something\n";
// A word list that never matches is indistinguishable from a word list that is
// wrong, so prove the matcher works before trusting a clean result.
hti_games_check(
	false !== stripos( 'This is a risk-free way to beat the market', 'beat the market' )
		&& false !== stripos( 'Os sinais estavam lá desde o início', 'os sinais estavam lá' ),
	'the matcher finds both an inducement and a hindsight claim'
);

hti_games_done();
