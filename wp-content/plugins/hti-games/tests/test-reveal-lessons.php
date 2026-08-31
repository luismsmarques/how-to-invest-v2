<?php
/**
 * The Reveal's lesson library: complete, company-free, and figure-free.
 *
 * Three failures this file exists to catch, and the third is the one that
 * matters most on this particular library.
 *
 * The bilingual one is the same trap Strings and Lessons guard: the site runs
 * pt_PT_ao90 against pt_PT translation files, WordPress does not fall back
 * between them, and a missing Portuguese lesson would not raise anything — it
 * would show a Portuguese player an English sentence under their reveal.
 *
 * The editorial one is that a lesson is read the moment the outcome lands,
 * which is exactly when it is easiest to write a rule that would have paid on
 * this case: "the cheap one was right", "the fraud was obvious". The
 * vocabulary sweep is a blunt instrument against that, and against the house
 * voice slipping into imperatives. See .claude/skills/brand-voice/SKILL.md.
 *
 * The third is the reason this library is keyed by pattern at all. CLAUDE.md
 * invariant 2 lets The Reveal name a company only inside a sourced, verified
 * case. A LESSON is not a case: it ships in the plugin, it is attached to a
 * shape rather than to a business, and nothing about it is verified against
 * anything. So a lesson that named a company, or carried a figure about one,
 * would be an unsourced claim shipped in code — outside the exemption
 * entirely. Both are asserted below, the company sweep against the actual
 * seeded library so it cannot go stale, and the figure sweep against the
 * source file itself so a number in a comment fails too.
 *
 *   php wp-content/plugins/hti-games/tests/test-reveal-lessons.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Strip tags, keep newlines — enough of core's behaviour for these checks.
	 *
	 * @param string $str Input.
	 */
	function sanitize_textarea_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-reveal-lessons.php';
require_once __DIR__ . '/../includes/class-seed-cases.php';

use HTI\Games\CPT;
use HTI\Games\Reveal_Lessons;
use HTI\Games\Seed_Cases;
use HTI\Games\Strings;

/**
 * Every financial-looking value in a piece of text.
 *
 * Shared, in spirit, with tests/test-seed-cases.php: a percentage, a currency
 * amount, a magnitude word behind a digit, a decimal, a multiple, a figure in
 * basis points, a thousands separator, or a bare integer too long to be an
 * array index. A four-digit number inside the range a dossier year can take is
 * allowed and is the only thing that is — the year is the subject of a case,
 * not a claim about it.
 *
 * @param string $text Haystack.
 * @return array<int,string> What was found, for the failure message.
 */
function hti_rev_figures( string $text ): array {
	$patterns = array(
		'a percentage'         => '/\d+(?:[.,]\d+)?\s*%/u',
		'a currency amount'    => '/[\$\x{20AC}\x{00A3}\x{00A5}]\s?\d/u',
		'a magnitude'          => '/\b\d+(?:[.,]\d+)?\s*(?:bn|billion|million|mn|trillion|milh(?:ao|ão|oes|ões)|mil milh(?:oes|ões))\b/iu',
		'a decimal'            => '/\b\d+[.,]\d+\b/u',
		'a multiple'           => '/\b\d+(?:[.,]\d+)?\s*x\b/iu',
		'a basis-point figure' => '/\b\d+\s*(?:bp|bps|basis points|pontos base)\b/iu',
		'a thousands group'    => '/\b\d{1,3}(?:,\d{3})+\b/u',
		'a spelled percentage' => '/\b\d+\s*(?:per cent|percent|por cento)\b/iu',
	);

	$hits = array();
	foreach ( $patterns as $what => $pattern ) {
		if ( preg_match_all( $pattern, $text, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$hits[] = $what . ': "' . trim( $hit ) . '"';
			}
		}
	}

	// Bare integers. One to three digits are array indices, row counts and
	// slice offsets; four digits are allowed only where a dossier year could
	// live; anything longer is a quantity and has no business here.
	preg_match_all( '/\d+/u', $text, $m );
	foreach ( $m[0] as $n ) {
		$len = strlen( $n );
		if ( $len >= 5 ) {
			$hits[] = 'a long integer: "' . $n . '"';
		} elseif ( 4 === $len && ( (int) $n < 1900 || (int) $n > 2030 ) ) {
			$hits[] = 'a four-digit value that is not a plausible year: "' . $n . '"';
		}
	}

	return array_values( array_unique( $hits ) );
}

$patterns = Reveal_Lessons::patterns();
$all      = Reveal_Lessons::all();
$flat     = array();
foreach ( $all as $pattern => $list ) {
	foreach ( $list as $lesson ) {
		$flat[] = $lesson + array( 'pattern' => $pattern );
	}
}

echo "There is a pattern taxonomy, and the lessons cover all of it\n";
hti_games_check( count( $patterns ) >= 16, sprintf( 'the taxonomy names %d dossier patterns', count( $patterns ) ) );
hti_games_check( array_keys( $all ) === array_keys( $patterns ), 'every pattern has lessons, and no lesson set belongs to a pattern the taxonomy does not name' );
hti_games_check( Reveal_Lessons::LANGS === Strings::LANGS, 'and it speaks the same languages as the rest of the copy table' );
hti_games_check( Reveal_Lessons::is_pattern( Reveal_Lessons::FALLBACK_PATTERN ), 'the fallback pattern is a real pattern' );
hti_games_check( ! Reveal_Lessons::is_pattern( 'not_a_pattern' ), 'and is_pattern() says no to one that is not' );

$taxon_bad = array();
foreach ( $patterns as $id => $row ) {
	foreach ( array( 'en', 'pt', 'asks_en', 'asks_pt' ) as $field ) {
		if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
			$taxon_bad[] = $id . ':' . $field;
		}
	}
	if ( ( $row['en'] ?? '' ) === ( $row['pt'] ?? '' ) ) {
		$taxon_bad[] = $id . ': the Portuguese name is the English one';
	}
	if ( ( $row['asks_en'] ?? '' ) === ( $row['asks_pt'] ?? '' ) ) {
		$taxon_bad[] = $id . ': the Portuguese question is the English one';
	}
	// The question the dossier asks is a question. A pattern whose `asks` line
	// had drifted into a statement would be asserting something instead.
	if ( ! str_contains( (string) ( $row['asks_en'] ?? '' ), '?' ) || ! str_contains( (string) ( $row['asks_pt'] ?? '' ), '?' ) ) {
		$taxon_bad[] = $id . ': asks nothing';
	}
}
hti_games_check( array() === $taxon_bad, 'every pattern is named and questioned in both languages (' . ( $taxon_bad ? implode( '; ', $taxon_bad ) : 'clean' ) . ')' );

echo "\nTwo lessons per pattern, so the same sentence is not back next fortnight\n";
foreach ( $all as $pattern => $list ) {
	hti_games_check( count( $list ) >= 2, sprintf( '%s carries %d lessons', $pattern, count( $list ) ) );
}
hti_games_check( count( $flat ) >= 24, sprintf( '%d lessons in total', count( $flat ) ) );

echo "\nNo sentence is in the library twice\n";
foreach ( Reveal_Lessons::LANGS as $lang ) {
	$bodies = array_map( fn( array $l ): string => (string) $l[ $lang ], $flat );
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
	foreach ( Reveal_Lessons::LANGS as $lang ) {
		if ( ! isset( $lesson[ $lang ] ) || '' === trim( (string) $lesson[ $lang ] ) ) {
			$missing[] = $lesson['id'] . ':' . $lang;
		}
		if ( isset( $lesson[ $lang ] ) && mb_strlen( trim( (string) $lesson[ $lang ] ) ) < 40 ) {
			$short[] = $lesson['id'] . ':' . $lang;
		}
	}
	if ( isset( $lesson['en'], $lesson['pt'] ) && $lesson['en'] === $lesson['pt'] ) {
		$same[] = $lesson['id'];
	}
}
hti_games_check( array() === $missing, 'no lesson is missing a language (' . ( $missing ? implode( ', ', $missing ) : 'clean' ) . ')' );
hti_games_check( array() === $same, 'no Portuguese lesson is just the English one (' . ( $same ? implode( ', ', $same ) : 'clean' ) . ')' );
hti_games_check( array() === $short, 'no lesson is a stub (' . ( $short ? implode( ', ', $short ) : 'clean' ) . ')' );

$ids = Reveal_Lessons::ids();
hti_games_check( count( $ids ) === count( $flat ), 'ids() reports every lesson' );
hti_games_check( count( array_unique( $ids ) ) === count( $ids ), 'and no id is used twice' );

$bad_id = array_filter( $ids, fn( string $id ): bool => 1 !== preg_match( '/^rev_lesson_[a-z_]+_\d{2}$/', $id ) );
hti_games_check( array() === $bad_id, 'every id names its pattern and its position (' . ( $bad_id ? implode( ', ', $bad_id ) : 'clean' ) . ')' );

$mislabelled = array_filter( $flat, fn( array $l ): bool => ! str_contains( $l['id'], $l['pattern'] ) );
hti_games_check( array() === $mislabelled, 'and no lesson is filed under a pattern its id disagrees with' );

echo "\nNo lesson names a company\n";
// The sweep runs against the seeded library rather than a hand-kept list, so
// it cannot go stale the day a case is added. A lesson is attached to a SHAPE
// of dossier and ships in the plugin unverified; naming a business in one
// would be an unsourced claim outside the exemption entirely.
$companies = array();
foreach ( Seed_Cases::cases() as $case ) {
	$companies[] = (string) $case['company'];
}
$companies = array_values( array_unique( $companies ) );
hti_games_check( count( $companies ) >= 24, sprintf( 'the sweep has %d company names to look for', count( $companies ) ) );

$named = array();
foreach ( $flat as $lesson ) {
	$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
	foreach ( $companies as $company ) {
		$needle = mb_strtolower( $company );
		if ( str_contains( $body, $needle ) ) {
			$named[] = $lesson['id'] . ' → ' . $company;
		}
	}
}
hti_games_check( array() === $named, 'not one lesson mentions a company in the case library (' . ( $named ? implode( '; ', $named ) : 'clean' ) . ')' );

echo "\nNo lesson carries a figure, and neither does the file\n";
$figured = array();
foreach ( $flat as $lesson ) {
	foreach ( Reveal_Lessons::LANGS as $lang ) {
		foreach ( hti_rev_figures( (string) $lesson[ $lang ] ) as $hit ) {
			$figured[] = $lesson['id'] . ':' . $lang . ' → ' . $hit;
		}
	}
}
hti_games_check( array() === $figured, 'no lesson states a percentage, a ratio, a multiple or an amount (' . ( $figured ? implode( '; ', $figured ) : 'clean' ) . ')' );

$source = (string) file_get_contents( __DIR__ . '/../includes/class-reveal-lessons.php' );
hti_games_check( '' !== $source, 'class-reveal-lessons.php is readable' );
$src_hits = hti_rev_figures( $source );
hti_games_check( array() === $src_hits, 'and no figure appears anywhere in the file, comments and docblocks included (' . ( $src_hits ? implode( '; ', $src_hits ) : 'clean' ) . ')' );

// And the sweep is capable of finding one — a pattern that never fires is
// indistinguishable from a pattern that is wrong.
hti_games_check(
	array() !== hti_rev_figures( 'a net margin of 12.4% on $3bn of revenue, 2.1x covered' )
		&& array() === hti_rev_figures( 'the 2007 annual report, six rows, three headlines' ),
	'the figure sweep catches a real one and lets a dossier year through'
);

echo "\nThe voice holds\n";
$forbidden = array(
	'you should',
	'you must',
	'you need to',
	'make sure you',
	'next time, ',
	'always ',
	'never buy',
	'guaranteed',
	'guarantee',
	'beat the market',
	'act now',
	'predict',
	'prediction',
	'risk-free',
	'easy money',
	'obvious in hindsight',
	'deves ',
	'tens de ',
	'nunca compres',
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
	foreach ( Reveal_Lessons::LANGS as $lang ) {
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
	foreach ( Reveal_Lessons::LANGS as $lang ) {
		if ( str_contains( (string) $lesson[ $lang ], '!' ) ) {
			$loud[] = $lesson['id'] . ':' . $lang;
		}
	}
}
hti_games_check( array() === $loud, 'and nothing shouts (' . ( $loud ? implode( ', ', $loud ) : 'clean' ) . ')' );

// The subject of the game: how to read the page, and how much to put behind
// what it says. A lesson that names neither has drifted into commentary about
// a business, which is the one thing these must never be.
$subject = array(
	'dossier', 'figure', 'number', 'account', 'cash', 'profit', 'margin', 'price', 'size', 'position', 'balance sheet', 'report', 'share', 'page',
	'business', 'sale', 'revenue', 'growth', 'capital', 'debt', 'protection', 'advantage', 'line',
	'dossiê', 'número', 'conta', 'caixa', 'lucro', 'margem', 'preço', 'tamanho', 'posição', 'balanço', 'relatório', 'ação', 'ações', 'página',
	'negócio', 'venda', 'receita', 'crescimento', 'capital', 'dívida', 'proteção', 'vantagem', 'linha',
);
$drifted = array();
foreach ( $flat as $lesson ) {
	$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
	$hit  = false;
	foreach ( $subject as $word ) {
		if ( str_contains( $body, $word ) ) {
			$hit = true;
			break;
		}
	}
	if ( ! $hit ) {
		$drifted[] = $lesson['id'];
	}
}
hti_games_check( array() === $drifted, 'every lesson is about reading the page or about what is at stake (' . ( $drifted ? implode( ', ', $drifted ) : 'clean' ) . ')' );

// And most of them name the page, a figure or the stake outright rather than
// gesturing at it. The check above is deliberately broad enough to admit a
// lesson about the shape of a business; without this one, a library that had
// drifted entirely into business commentary would still pass it.
$named_outright = 0;
foreach ( $flat as $lesson ) {
	$body = mb_strtolower( $lesson['en'] . ' ' . $lesson['pt'] );
	foreach ( array( 'dossier', 'figure', 'number', 'account', 'cash', 'profit', 'margin', 'price', 'size', 'position', 'page', 'dossiê', 'número', 'conta', 'caixa', 'lucro', 'margem', 'preço', 'tamanho', 'posição', 'página' ) as $word ) {
		if ( str_contains( $body, $word ) ) {
			++$named_outright;
			break;
		}
	}
}
hti_games_check(
	$named_outright * 5 >= count( $flat ) * 3,
	sprintf( '%d of %d name the page, a figure, the account or the size outright', $named_outright, count( $flat ) )
);

// The fallback set is the one that has to say the awkward thing outright: the
// page is incomplete and the size is the part the player chose.
$honest = false;
foreach ( $all[ Reveal_Lessons::FALLBACK_PATTERN ] as $lesson ) {
	$body = mb_strtolower( $lesson['en'] );
	if ( str_contains( $body, 'how much of the account' ) || str_contains( $body, 'incomplete' ) ) {
		$honest = true;
		break;
	}
}
hti_games_check( $honest, 'the fallback lessons say plainly that the page is incomplete and the size is the answer to that' );

echo "\nPicking a lesson is deterministic and never out of range\n";
foreach ( array_keys( $all ) as $pattern ) {
	$n     = count( $all[ $pattern ] );
	$first = Reveal_Lessons::for_pattern( $pattern, 0 );

	hti_games_check( $first === Reveal_Lessons::for_pattern( $pattern, 0 ), "for_pattern('{$pattern}', 0) is the same lesson every time" );
	hti_games_check( $first === Reveal_Lessons::for_pattern( $pattern, $n ), 'and the rotation wraps at the end of the list' );
	hti_games_check( $first !== Reveal_Lessons::for_pattern( $pattern, 1 ), 'while the next one along is a different lesson' );
	hti_games_check( in_array( Reveal_Lessons::for_pattern( $pattern, -1 ), $all[ $pattern ], true ), 'a negative index still lands inside the list' );
}

$seen = array();
for ( $i = 0; $i < count( $all['fraud'] ); $i++ ) {
	$seen[] = Reveal_Lessons::for_pattern( 'fraud', $i )['id'];
}
hti_games_check( count( array_unique( $seen ) ) === count( $seen ), 'walking one rotation visits every lesson exactly once' );

$fallback = Reveal_Lessons::for_pattern( 'nonsense', 0 );
hti_games_check( str_contains( $fallback['id'], Reveal_Lessons::FALLBACK_PATTERN ), 'an unknown pattern falls back to the lessons that are true of every dossier' );
hti_games_check( isset( $fallback['id'], $fallback['en'], $fallback['pt'] ), 'and the shape that comes back is always id/en/pt' );
hti_games_check( Reveal_Lessons::pattern( 'nonsense' ) === $patterns[ Reveal_Lessons::FALLBACK_PATTERN ], 'pattern() falls back the same way rather than returning nothing' );

echo "\nA lesson fits the meta field an editor would paste it into\n";
// CPT::san_block truncates at 2000 characters. A lesson that needed truncating
// would be stored half-written and nobody would be told.
$long = array();
foreach ( $flat as $lesson ) {
	foreach ( Reveal_Lessons::LANGS as $lang ) {
		$stored = CPT::san_block( $lesson[ $lang ] );
		if ( $stored !== $lesson[ $lang ] ) {
			$long[] = $lesson['id'] . ':' . $lang;
		}
	}
}
hti_games_check( array() === $long, 'every lesson survives hti_rev_lesson_en/pt unchanged (' . ( $long ? implode( ', ', $long ) : 'clean' ) . ')' );

$markup = array_filter( $flat, fn( array $l ): bool => $l['en'] !== wp_strip_all_tags( $l['en'] ) || $l['pt'] !== wp_strip_all_tags( $l['pt'] ) );
hti_games_check( array() === $markup, 'and none carries markup the sanitizer would strip' );

hti_games_done();
