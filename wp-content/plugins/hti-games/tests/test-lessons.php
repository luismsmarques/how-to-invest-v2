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
 * anything, and nothing that says the turn was there to be seen. That last
 * one is the newest and the most important: the scenarios are generated so
 * that on a trap day NEITHER direction was profitable, so a lesson implying
 * the player could have called it is not merely off-voice, it is false about
 * the thing it sits under. See .claude/skills/brand-voice/SKILL.md.
 *
 * The third failure is arithmetic. Every figure in the library is a `%d`
 * filled from STC_Engine::losses_to_ruin() — the same function the tier
 * button warns with — because the prototype's hand-written counts were out by
 * roughly a factor of four, in a library whose entire argument is that the
 * numbers are bigger than they feel. So the raw table is checked for
 * placeholder parity between the two languages, the declared tiers are
 * checked against the tiers the game actually offers, and the rendered
 * sentences are checked to carry the engine's answer and no hand-typed digit
 * at all.
 *
 *   php wp-content/plugins/hti-games/tests/test-lessons.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-lessons.php';

use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\Lessons;
use HTI\Games\STC_Engine;
use HTI\Games\Strings;

$all   = Lessons::all();
$raw   = Lessons::table();
$flat  = array();
foreach ( $all as $class => $list ) {
	foreach ( $list as $lesson ) {
		$flat[] = $lesson + array( 'class' => $class );
	}
}
$flat_raw = array();
foreach ( $raw as $class => $list ) {
	foreach ( $list as $lesson ) {
		$flat_raw[] = $lesson + array( 'class' => $class );
	}
}

echo "The library covers every class the generator can produce\n";
hti_games_check( array_keys( $all ) === CPT::SCENARIO_CLASSES, 'there is a set of lessons for each of the three scenario classes' );
hti_games_check( Lessons::LANGS === Strings::LANGS, 'and it speaks the same languages as the rest of the copy table' );
hti_games_check( in_array( Lessons::FALLBACK_CLASS, CPT::SCENARIO_CLASSES, true ), 'the fallback class is a real class' );

// Twenty and not eight. A daily game serves each class roughly every third
// day, so eight sentences per class come round inside a month — and a sentence
// a player has already read three times is furniture, which is not read at
// all. Twenty is two months of play before a repeat, and enough room for the
// rotation to walk a curriculum rather than shuffle synonyms.
foreach ( $all as $class => $list ) {
	hti_games_check( count( $list ) >= 20, sprintf( '%s carries %d lessons — at least twenty, so nothing repeats inside two months of daily play', $class, count( $list ) ) );
	hti_games_check( count( $raw[ $class ] ) === count( $list ), sprintf( '%s: the raw table and the rendered one are the same length', $class ) );
}
hti_games_check( count( $flat ) >= 60, sprintf( '%d lessons in total', count( $flat ) ) );

echo "\nNo sentence is in the library twice\n";
// Two lessons that say the same thing in different words are one lesson and a
// gap in the rotation. Checked per language, because a copy-paste that changed
// the English and not the Portuguese shows up only on the PT side.
foreach ( Lessons::LANGS as $lang ) {
	$bodies = array_map( fn( array $l ): string => $l[ $lang ], $flat );
	$dupes  = array_unique( array_diff_assoc( $bodies, array_unique( $bodies ) ) );
	hti_games_check(
		array() === $dupes,
		sprintf( 'every %s lesson is a different sentence (%s)', strtoupper( $lang ), $dupes ? implode( ' | ', $dupes ) : 'clean' )
	);
}

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

echo "\nEvery figure comes from the engine and none is typed\n";
// The raw table is where the mistake would be made, so the raw table is what
// is checked: a `%d` in one language and not the other renders a lesson with a
// stray placeholder under somebody's chart, and a tier that is not a tier the
// game offers renders a number that answers a question nobody was asked.
$parity   = array();
$unoffered = array();
$figures  = array_fill_keys( array_keys( $raw ), 0 );
foreach ( $flat_raw as $lesson ) {
	$declared = count( (array) $lesson['risk'] );
	if ( $declared > 0 ) {
		++$figures[ $lesson['class'] ];
	}
	foreach ( Lessons::LANGS as $lang ) {
		if ( substr_count( (string) $lesson[ $lang ], '%d' ) !== $declared ) {
			$parity[] = $lesson['id'] . ':' . $lang;
		}
	}
	foreach ( (array) $lesson['risk'] as $bp ) {
		if ( ! Config::is_risk_bp( (int) $bp ) ) {
			$unoffered[] = $lesson['id'] . ':' . $bp;
		}
	}
}
hti_games_check( array() === $parity, 'each language has exactly one %d per tier the lesson declares (' . ( $parity ? implode( ', ', $parity ) : 'clean' ) . ')' );
hti_games_check( array() === $unoffered, 'and every tier a lesson argues from is one the game actually offers (' . ( $unoffered ? implode( ', ', $unoffered ) : 'clean' ) . ')' );

$bare = array();
foreach ( $flat_raw as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		// A stray per-cent sign would be read by vsprintf() as a format
		// specifier and silently eat the rest of the sentence. The copy spells
		// percentages out in words, and this is what keeps it that way.
		if ( str_contains( str_replace( '%d', '', (string) $lesson[ $lang ] ), '%' ) ) {
			$bare[] = $lesson['id'] . ':' . $lang;
		}
	}
}
hti_games_check( array() === $bare, 'no lesson carries a per-cent sign vsprintf() could misread (' . ( $bare ? implode( ', ', $bare ) : 'clean' ) . ')' );

foreach ( CPT::SCENARIO_CLASSES as $class ) {
	hti_games_check( $figures[ $class ] >= 1, sprintf( '%s carries %d lessons that put a real number in front of the player', $class, $figures[ $class ] ) );
}

$unfilled = array();
$typed    = array();
foreach ( $flat as $i => $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		$body = (string) $lesson[ $lang ];
		if ( str_contains( $body, '%' ) ) {
			$unfilled[] = $lesson['id'] . ':' . $lang;
		}
		// A lesson with no tiers declared has no business containing a digit:
		// every quantity in the library is either spelled out in words or
		// asked of the engine. This is the check that stops the prototype's
		// hand-written counts from creeping back in one sentence at a time.
		if ( array() === $lesson['risk'] && 1 === preg_match( '/\d/', $body ) ) {
			$typed[] = $lesson['id'] . ':' . $lang;
		}
	}
	unset( $i );
}
hti_games_check( array() === $unfilled, 'nothing that ships still has a placeholder in it (' . ( $unfilled ? implode( ', ', $unfilled ) : 'clean' ) . ')' );
hti_games_check( array() === $typed, 'and no lesson types a number the engine was not asked for (' . ( $typed ? implode( ', ', $typed ) : 'clean' ) . ')' );

// End to end: the number the player reads is the number losses_to_ruin()
// returns, for the tier the lesson names. The prototype was wrong here by
// roughly four times in both directions, on the one library whose whole
// argument is that these numbers are larger than they feel.
$wrong = array();
foreach ( $raw as $class => $list ) {
	foreach ( $list as $at => $lesson ) {
		foreach ( (array) $lesson['risk'] as $bp ) {
			$expected = (string) STC_Engine::losses_to_ruin( (int) $bp );
			foreach ( Lessons::LANGS as $lang ) {
				if ( 1 !== preg_match( '/\b' . $expected . '\b/', $all[ $class ][ $at ][ $lang ] ) ) {
					$wrong[] = $lesson['id'] . ':' . $lang . ' missing ' . $expected;
				}
			}
		}
	}
}
hti_games_check( array() === $wrong, 'every rendered figure is the engine\'s answer for the tier it names (' . ( $wrong ? implode( '; ', $wrong ) : 'clean' ) . ')' );

$ruin   = array();
$echoed = true;
foreach ( Config::STC_RISK_BP as $bp ) {
	$ruin[ $bp ] = Lessons::ruin( $bp );
	if ( $ruin[ $bp ] !== STC_Engine::losses_to_ruin( $bp ) ) {
		$echoed = false;
	}
}
hti_games_check( $echoed, 'Lessons::ruin() memoises the engine rather than remembering a table (' . implode( ' / ', $ruin ) . ')' );

// Shape, not values: whatever the tiers and the floor become, a heavier tier
// has strictly less runway than a lighter one. A hand-typed table would be the
// easiest way to break this and the hardest to notice.
$sorted = array_values( $ruin );
$falls  = true;
for ( $i = 1; $i < count( $sorted ); $i++ ) {
	if ( $sorted[ $i ] >= $sorted[ $i - 1 ] ) {
		$falls = false;
	}
}
hti_games_check( $falls, 'and a heavier tier always has strictly less runway than a lighter one' );

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

// The specific claim the game is built to make impossible: that the outcome
// was there to be read. On a trap day NEITHER direction was profitable, and
// on an ambiguous one nothing resolved at all — so a lesson saying the turn
// was obvious, or that it can be spotted next time, is not off-voice, it is
// untrue about the day it is printed under. A word list is a first line of
// defence and not a proof, but it catches the phrasings that arrive by habit
// when somebody writes a lesson knowing how the chart ended.
$hindsight = array(
	'obvious',
	'obviously',
	'unmistak',
	'telegraph',
	'foresee',
	'foreseeable',
	'could have seen',
	'could have known',
	'should have known',
	'signs were there',
	'the signal was',
	'textbook setup',
	'sure thing',
	'no doubt',
	'next time',
	'spot it',
	'called it',
	'read it right',
	'óbvi',
	'obviamente',
	'inequívoc',
	'dava para ver',
	'via-se logo',
	'era de esperar',
	'sinal claro',
	'adivinh',
	'antecipar',
	'da próxima vez',
	'sem dúvida',
	'notava-se',
);
$foretold = array();
foreach ( $flat as $lesson ) {
	foreach ( Lessons::LANGS as $lang ) {
		$body = ' ' . mb_strtolower( (string) $lesson[ $lang ] ) . ' ';
		foreach ( $hindsight as $phrase ) {
			if ( str_contains( $body, $phrase ) ) {
				$foretold[] = $lesson['id'] . ':' . $lang . ' → "' . $phrase . '"';
			}
		}
	}
}
hti_games_check( array() === $foretold, 'no lesson claims the outcome could have been called in advance (' . ( $foretold ? implode( '; ', $foretold ) : 'clean' ) . ')' );

// And the sweep can actually find one, so a list that stopped matching would
// not pass as a clean library.
$probe = false;
foreach ( $hindsight as $phrase ) {
	if ( str_contains( ' it was obvious in hindsight, and you will spot it next time. ', $phrase ) ) {
		$probe = true;
	}
}
hti_games_check( $probe, 'and the hindsight sweep catches a sentence that does claim it' );

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

// Per class as well as in aggregate. A class could satisfy the ratio above
// while itself never mentioning the subject — and the ambiguous set in
// particular exists precisely because on those days the size was the only
// thing that happened.
$subject = array( 'size', 'sizing', 'tier', 'percent', 'account', 'tamanho', 'escalão', 'por cento', 'conta', 'risc', 'arrisc' );
foreach ( $all as $class => $list ) {
	$hits_in_class = 0;
	foreach ( $list as $lesson ) {
		$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
		foreach ( $subject as $word ) {
			if ( str_contains( $body, $word ) ) {
				++$hits_in_class;
				break;
			}
		}
	}
	hti_games_check( $hits_in_class >= 1, sprintf( '%s: %d of %d name the size, the tier or the account outright', $class, $hits_in_class, count( $list ) ) );
}

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
