<?php
/**
 * The lesson library: complete in both languages, and saying the right thing.
 *
 * Two failures this file exists to catch.
 *
 * The bilingual one is the same trap Strings guards: the site runs pt_PT_ao90
 * against pt_PT translation files, WordPress does not fall back between them,
 * and a missing Portuguese lesson would not raise anything — it would just show
 * a Portuguese player an English sentence under their chart. So both languages
 * are asserted present, distinct and non-trivial for every single lesson.
 *
 * The editorial one is harder and matters more. A lesson is written after the
 * outcome is known, which is exactly the moment it is easiest to write
 * something that teaches the player to predict direction next time, or to
 * congratulate a win that was really a heavy tier getting away with it. The
 * vocabulary sweep below is a blunt instrument against a real risk: no
 * imperatives, no promises, no urgency, nothing about predicting or beating
 * anything. See .claude/skills/brand-voice/SKILL.md.
 *
 *   php wp-content/plugins/hti-games/tests/test-lessons.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-lessons.php';

use HTI\Games\CPT;
use HTI\Games\Lessons;
use HTI\Games\Strings;

$all   = Lessons::all();
$flat  = array();
foreach ( $all as $class => $list ) {
	foreach ( $list as $lesson ) {
		$flat[] = $lesson + array( 'class' => $class );
	}
}

echo "The library covers every class the generator can produce\n";
hti_games_check( array_keys( $all ) === CPT::SCENARIO_CLASSES, 'there is a set of lessons for each of the three scenario classes' );
hti_games_check( Lessons::LANGS === Strings::LANGS, 'and it speaks the same languages as the rest of the copy table' );
hti_games_check( in_array( Lessons::FALLBACK_CLASS, CPT::SCENARIO_CLASSES, true ), 'the fallback class is a real class' );

foreach ( $all as $class => $list ) {
	hti_games_check( count( $list ) >= 8, sprintf( '%s carries %d lessons — at least eight, so the same sentence is not back every third day', $class, count( $list ) ) );
}
hti_games_check( count( $flat ) >= 24, sprintf( '%d lessons in total', count( $flat ) ) );

echo "\nEvery lesson is complete in both languages\n";
$missing = array();
$same    = array();
$short   = array();
foreach ( $flat as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		if ( ! isset( $lesson[ $lang ] ) || '' === trim( (string) $lesson[ $lang ] ) ) {
			$missing[] = $lesson['id'] . ':' . $lang;
		}
		if ( isset( $lesson[ $lang ] ) && mb_strlen( trim( (string) $lesson[ $lang ] ) ) < 40 ) {
			$short[] = $lesson['id'] . ':' . $lang;
		}
	}
	// A PT string identical to its EN one is the classic "translated later"
	// placeholder, and it renders without complaining.
	if ( isset( $lesson['en'], $lesson['pt'] ) && $lesson['en'] === $lesson['pt'] ) {
		$same[] = $lesson['id'];
	}
}
hti_games_check( array() === $missing, 'no lesson is missing a language (' . ( $missing ? implode( ', ', $missing ) : 'clean' ) . ')' );
hti_games_check( array() === $same, 'no Portuguese lesson is just the English one (' . ( $same ? implode( ', ', $same ) : 'clean' ) . ')' );
hti_games_check( array() === $short, 'no lesson is a stub (' . ( $short ? implode( ', ', $short ) : 'clean' ) . ')' );

$ids = Lessons::ids();
hti_games_check( count( $ids ) === count( $flat ), 'ids() reports every lesson' );
hti_games_check( count( array_unique( $ids ) ) === count( $ids ), 'and no id is used twice' );

$bad_id = array_filter( $ids, fn( string $id ): bool => 1 !== preg_match( '/^stc_lesson_(reasonable|ambiguous|trap)_\d{2}$/', $id ) );
hti_games_check( array() === $bad_id, 'every id names its class and its position (' . ( $bad_id ? implode( ', ', $bad_id ) : 'clean' ) . ')' );

$mislabelled = array_filter( $flat, fn( array $l ): bool => ! str_contains( $l['id'], $l['class'] ) );
hti_games_check( array() === $mislabelled, 'and no lesson is filed under a class its id disagrees with' );

echo "\nPicking a lesson is deterministic and never out of range\n";
foreach ( CPT::SCENARIO_CLASSES as $class ) {
	$n     = count( $all[ $class ] );
	$first = Lessons::for_class( $class, 0 );

	hti_games_check( $first === Lessons::for_class( $class, 0 ), "for_class('{$class}', 0) is the same lesson every time" );
	hti_games_check( $first === Lessons::for_class( $class, $n ), 'and the rotation wraps at the end of the list' );
	hti_games_check( $first !== Lessons::for_class( $class, 1 ), 'while the next day is a different lesson' );
	hti_games_check( in_array( Lessons::for_class( $class, -1 ), $all[ $class ], true ), 'a negative index still lands inside the list' );
	hti_games_check( str_contains( Lessons::for_class( $class, 3 )['id'], $class ), 'and what comes back belongs to the class that was asked for' );
}

$seen = array();
for ( $i = 0; $i < count( $all['trap'] ); $i++ ) {
	$seen[] = Lessons::for_class( 'trap', $i )['id'];
}
hti_games_check( count( array_unique( $seen ) ) === count( $seen ), 'walking the rotation once visits every lesson exactly once' );

$fallback = Lessons::for_class( 'nonsense', 0 );
hti_games_check( str_contains( $fallback['id'], Lessons::FALLBACK_CLASS ), 'an unknown class falls back to the lessons that are true on any day' );
hti_games_check( isset( $fallback['id'], $fallback['en'], $fallback['pt'] ), 'and the shape that comes back is always id/en/pt' );

echo "\nThe voice holds\n";
// Imperatives, promises, urgency, and anything that frames the day as a
// prediction problem. Checked in both languages because the PT copy is written,
// not translated, and can drift on its own.
$forbidden = array(
	'you should',
	'you must',
	'you need to',
	'make sure you',
	'next time, ',
	'always ',
	'never trade',
	'guaranteed',
	'guarantee',
	'beat the market',
	'act now',
	'predict',
	'prediction',
	'risk-free',
	'easy money',
	'deves ',
	'tens de ',
	'nunca faças',
	'garantido',
	'garantia',
	'bater o mercado',
	'sem risco',
	'prever',
	'previsão',
	'dinheiro fácil',
);
$hits = array();
foreach ( $flat as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		$body = ' ' . mb_strtolower( (string) $lesson[ $lang ] ) . ' ';
		foreach ( $forbidden as $phrase ) {
			if ( str_contains( $body, $phrase ) ) {
				$hits[] = $lesson['id'] . ':' . $lang . ' → "' . $phrase . '"';
			}
		}
	}
}
hti_games_check( array() === $hits, 'nothing instructs, promises or predicts (' . ( $hits ? implode( '; ', $hits ) : 'clean' ) . ')' );

$loud = array();
foreach ( $flat as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		if ( str_contains( (string) $lesson[ $lang ], '!' ) ) {
			$loud[] = $lesson['id'] . ':' . $lang;
		}
	}
}
hti_games_check( array() === $loud, 'and nothing shouts (' . ( $loud ? implode( ', ', $loud ) : 'clean' ) . ')' );

// The subject of the whole game. A lesson that mentions neither what was at
// stake nor whether to be in the market at all has drifted into commentary
// about the chart, which is the one thing these must never be.
$decision = array(
	'size', 'sizing', 'tier', 'percent', 'account', 'risk', 'risked', 'position', 'cost', 'costs', 'passing', 'not trading',
	'tamanho', 'escalão', 'por cento', 'conta', 'risc', 'arrisc', 'posição', 'custa', 'custam', 'passar', 'não operar',
);
$drifted = array();
foreach ( $flat as $lesson ) {
	$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
	$hit  = false;
	foreach ( $decision as $word ) {
		if ( str_contains( $body, $word ) ) {
			$hit = true;
			break;
		}
	}
	if ( ! $hit ) {
		$drifted[] = $lesson['id'];
	}
}
hti_games_check(
	array() === $drifted,
	'every lesson is about what was at stake or whether to be in at all (' . ( $drifted ? implode( ', ', $drifted ) : 'clean' ) . ')'
);

// And most of them name it outright, rather than gesturing at it. A library
// that drifted to "well, you could have passed" everywhere would satisfy the
// check above while quietly dropping the actual subject.
$named = 0;
foreach ( $flat as $lesson ) {
	$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
	foreach ( array( 'size', 'sizing', 'tier', 'percent', 'account', 'tamanho', 'escalão', 'por cento', 'conta', 'risc', 'arrisc' ) as $word ) {
		if ( str_contains( $body, $word ) ) {
			++$named;
			break;
		}
	}
}
hti_games_check(
	$named * 5 >= count( $flat ) * 3,
	sprintf( '%d of %d name position size, the tier or the account outright', $named, count( $flat ) )
);

// The specific one the brief calls out: a win at a heavy tier is the sizing
// getting away with it, and at least one reasonable-day lesson has to say so.
$honest = false;
foreach ( $all['reasonable'] as $lesson ) {
	$body = mb_strtolower( $lesson['en'] );
	if ( str_contains( $body, 'getting away with it' ) || str_contains( $body, 'was not tested' ) ) {
		$honest = true;
		break;
	}
}
hti_games_check( $honest, 'a win at a heavy tier is described as the sizing getting away with it, not as a read being right' );

echo "\nA lesson fits the meta field it is stored in\n";
// CPT::san_block truncates at 2000 characters; a lesson that needed truncating
// would be stored half-written and nobody would be told.
$long = array();
foreach ( $flat as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		if ( strlen( (string) $lesson[ $lang ] ) > 2000 ) {
			$long[] = $lesson['id'] . ':' . $lang;
		}
	}
}
hti_games_check( array() === $long, 'no lesson would be truncated by hti_stc_lesson_en/pt (' . ( $long ? implode( ', ', $long ) : 'clean' ) . ')' );

$markup = array_filter( $flat, fn( array $l ): bool => $l['en'] !== wp_strip_all_tags( $l['en'] ) || $l['pt'] !== wp_strip_all_tags( $l['pt'] ) );
hti_games_check( array() === $markup, 'and none carries markup the sanitizer would strip' );

hti_games_done();
