<?php
/**
 * The seeded cases cannot reach a player, and that is the whole test.
 *
 * CLAUDE.md invariant 2 forbids naming companies. The Reveal's exemption lets
 * it name one — but only inside `hti_reveal_case`, only for a period at least
 * Config::REVEAL_MIN_AGE_YEARS old, and only with a verified source recorded on
 * the case. The library names dozens of real businesses, so it is exactly the
 * content the exemption is about, and it was written in an environment with no
 * network access where nothing could be checked against anything.
 *
 * So this file asserts the uncomfortable thing rather than the comfortable one:
 * that not one case is publishable, that none carries a return figure, that no
 * headline was invented and no outcome written — and, the broadest of them,
 * that NO NUMERIC-LOOKING FINANCIAL VALUE appears anywhere in the seed file at
 * all, in a field, a comment or a docblock. That last one is the guarantee
 * against the specific way this file gets ruined: not by somebody publishing an
 * unverified case, but by somebody "finishing" the seed data from memory,
 * starting with a plausible figure in a comment that is one paste from being
 * somebody's answer.
 *
 * What the file IS allowed to carry is everything that is not a claim: the
 * company, the year, the sector, the pattern the dossier is expected to have,
 * the six fundamental labels, and the research brief telling an editor which
 * document to open and which line item feeds which label. Those are asserted
 * too, and just as hard — a form with the wrong questions on it is worse than
 * no form, because somebody fills it in anyway.
 *
 * The positive control at the bottom matters as much as any of it: a case DOES
 * become publishable once a source, a tick and both figures are supplied.
 * Without it, a test that always says "not publishable" would pass just as
 * happily against an empty array.
 *
 *   php wp-content/plugins/hti-games/tests/test-seed-cases.php
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
require_once __DIR__ . '/../includes/class-case-admin.php';
require_once __DIR__ . '/../includes/class-reveal-lessons.php';
require_once __DIR__ . '/../includes/class-seed-cases.php';

use HTI\Games\Case_Admin;
use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\Reveal_Lessons;
use HTI\Games\Seed_Cases;

/**
 * Every financial-looking value in a piece of text.
 *
 * A percentage, a currency amount, a magnitude word behind a digit, a decimal,
 * a multiple, a figure in basis points, a thousands separator, or a bare
 * integer too long to be an array index. A four-digit number inside the range
 * a dossier year can take is allowed, and is the only thing that is: the year
 * is the SUBJECT of a case, not a claim about it.
 *
 * Deliberately blunt. This check exists to be annoying in exactly the moment
 * somebody is about to write down a number they cannot source.
 *
 * @param string $text Haystack.
 * @return array<int,string> What was found, for the failure message.
 */
function hti_seed_figures( string $text ): array {
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

/**
 * A fixed "now", so a green suite does not turn red on a New Year's Day.
 */
$now      = (int) strtotime( '2026-08-30 12:00:00 UTC' );
$cases    = Seed_Cases::cases();
$metrics  = Seed_Cases::metrics();
$sectors  = Seed_Cases::sectors();
$patterns = Reveal_Lessons::patterns();

echo "The library is big enough to be a library\n";
// Twenty-four is the floor because the game is daily and a pattern a player
// meets once is a story, not a pattern — the library carries at least two
// cases of every shape so the second one is recognisable.
hti_games_check( count( $cases ) >= 24, sprintf( '%d cases are seeded', count( $cases ) ) );

$named = array();
foreach ( $cases as $case ) {
	$named[] = $case['company'] . ' ' . $case['year'];
}
hti_games_check( count( array_unique( $named ) ) === count( $named ), 'no company and year appears twice, which is what the seeder dedupes on' );

// The five prototypes from the design handoff are still here and still theirs.
$original = array( 'Amazon 2001', 'Coca-Cola 2010', 'Enron 2000', 'Nokia 2007', 'Pets.com 1999' );
$kept     = array_values( array_intersect( $original, $named ) );
sort( $kept );
hti_games_check( $original === $kept, 'the five original prototypes survived the expansion (' . implode( ', ', $kept ) . ')' );

$unmarked = array_filter( $cases, fn( array $c ): bool => ! str_contains( $c['title'], Seed_Cases::DRAFT_MARK ) );
hti_games_check( array() === $unmarked, 'every title says out loud that it is an unverified seed' );

echo "\nNot one of them can be published\n";
$publishable = array();
foreach ( $cases as $case ) {
	if ( Case_Admin::publishable( $case['meta'], $now ) ) {
		$publishable[] = $case['company'];
	}
}
hti_games_check( array() === $publishable, 'Case_Admin::publishable() says no to every single one (' . ( $publishable ? implode( ', ', $publishable ) : 'clean' ) . ')' );

$no_source   = 0;
$no_verified = 0;
foreach ( $cases as $case ) {
	$missing = Case_Admin::missing( $case['meta'], $now );
	if ( in_array( 'hti_rev_source_url', $missing, true ) ) {
		++$no_source;
	}
	if ( in_array( 'hti_rev_verified', $missing, true ) ) {
		++$no_verified;
	}
}
hti_games_check( count( $cases ) === $no_source, 'the gate names the missing source URL on every one' );
hti_games_check( count( $cases ) === $no_verified, 'and the missing verification on every one' );

$bad = array();
foreach ( $cases as $case ) {
	if ( '' !== (string) $case['meta']['hti_rev_source_url'] ) {
		$bad[] = $case['company'] . ': source url';
	}
	if ( '0' !== (string) $case['meta']['hti_rev_verified'] ) {
		$bad[] = $case['company'] . ': verified';
	}
	if ( '' !== (string) $case['meta']['hti_rev_verified_by'] || '' !== (string) $case['meta']['hti_rev_verified_at'] ) {
		$bad[] = $case['company'] . ': verification stamp';
	}
	if ( '' !== (string) $case['meta']['hti_rev_source_label'] || '' !== (string) $case['meta']['hti_rev_source_accessed'] ) {
		$bad[] = $case['company'] . ': source credit';
	}
}
hti_games_check( array() === $bad, 'the source URL is deliberately empty and nobody is recorded as having verified anything (' . ( $bad ? implode( '; ', $bad ) : 'clean' ) . ')' );

echo "\nNo case carries a figure\n";
$figures = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp' ) as $key ) {
		$value = (string) $case['meta'][ $key ];
		if ( '' !== $value ) {
			$figures[] = $case['company'] . ': ' . $key . ' = ' . $value;
		}
		if ( 0 !== (int) $case['meta'][ $key ] ) {
			$figures[] = $case['company'] . ': ' . $key . ' is non-zero';
		}
	}
}
hti_games_check( array() === $figures, 'neither five-year return is filled in anywhere (' . ( $figures ? implode( '; ', $figures ) : 'clean' ) . ')' );

// The prose that sits beside the figures on the reveal screen is a claim about
// the company too, so it is empty for the same reason the numbers are.
$prose = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_context_en', 'hti_rev_context_pt', 'hti_rev_lesson_en', 'hti_rev_lesson_pt' ) as $key ) {
		if ( '' !== trim( (string) $case['meta'][ $key ] ) ) {
			$prose[] = $case['company'] . ': ' . $key;
		}
	}
}
hti_games_check( array() === $prose, 'and no "what happened next" or lesson is written for a company nobody has checked (' . ( $prose ? implode( '; ', $prose ) : 'clean' ) . ')' );

echo "\nAnd neither does the file, in a field, a comment or a docblock\n";
// The broadest assertion in the suite, and the one that catches the way this
// file actually gets ruined: a plausible figure written down "for now".
$source = (string) file_get_contents( __DIR__ . '/../includes/class-seed-cases.php' );
hti_games_check( '' !== $source, 'class-seed-cases.php is readable' );
$src_hits = hti_seed_figures( $source );
hti_games_check( array() === $src_hits, 'no percentage, ratio, multiple, amount or magnitude appears anywhere in the source (' . ( $src_hits ? implode( '; ', $src_hits ) : 'clean' ) . ')' );

// Every string the seeder would actually write to the database, swept the same
// way — the source sweep would miss anything assembled at run time.
$emitted = array();
foreach ( $cases as $case ) {
	$blob = $case['title'] . ' ';
	foreach ( $case['meta'] as $value ) {
		$blob .= (string) $value . ' ';
	}
	foreach ( hti_seed_figures( $blob ) as $hit ) {
		$emitted[] = $case['company'] . ' → ' . $hit;
	}
}
hti_games_check( array() === $emitted, 'and none in anything the seeder would store, the composed briefs included (' . ( $emitted ? implode( '; ', array_slice( $emitted, 0, 6 ) ) : 'clean' ) . ')' );

hti_games_check(
	array() !== hti_seed_figures( 'a net margin of 12.4% on $3bn of revenue, 2.1x covered, -8000 bp' )
		&& array() === hti_seed_figures( 'the 2007 annual report, six rows, three headlines, slot 0' ),
	'the figure sweep catches a real one and lets a dossier year through'
);

echo "\nWhat IS filled is the shape of the answer\n";
$holes = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_company', 'hti_rev_sector_en', 'hti_rev_sector_pt', 'hti_rev_revenue_band_en', 'hti_rev_revenue_band_pt', 'hti_rev_pattern', 'hti_rev_brief' ) as $key ) {
		if ( '' === trim( (string) $case['meta'][ $key ] ) ) {
			$holes[] = $case['company'] . ': ' . $key;
		}
	}
	if ( (int) $case['meta']['hti_rev_year'] <= 0 ) {
		$holes[] = $case['company'] . ': year';
	}
}
hti_games_check( array() === $holes, 'company, year, sector, pattern, brief and revenue band give the editor a form rather than a blank page (' . ( $holes ? implode( '; ', $holes ) : 'clean' ) . ')' );

// The revenue band is filled with the SHAPE of an answer, not an answer: a
// band is still a figure about a real company. It states the criterion and
// deliberately shows no specimen band, because a specimen sitting in the
// revenue-band box of every case is one paste from being somebody's answer.
$claims = array_filter(
	$cases,
	fn( array $c ): bool => ! str_contains( mb_strtolower( (string) $c['meta']['hti_rev_revenue_band_en'] ), 'to fill' )
		|| ! str_contains( mb_strtolower( (string) $c['meta']['hti_rev_revenue_band_pt'] ), 'a preencher' )
);
hti_games_check( array() === $claims, 'and the revenue band reads as an instruction, in both languages, never as a figure' );

$missing_keys = array();
foreach ( $cases as $case ) {
	foreach ( array_keys( CPT::case_meta() ) as $key ) {
		if ( ! array_key_exists( $key, $case['meta'] ) ) {
			$missing_keys[] = $case['company'] . ': ' . $key;
		}
	}
}
hti_games_check( array() === $missing_keys, 'every registered meta key is present on every case, blank ones included (' . ( $missing_keys ? implode( '; ', $missing_keys ) : 'clean' ) . ')' );

$stray = array();
foreach ( $cases as $case ) {
	foreach ( array_keys( $case['meta'] ) as $key ) {
		if ( ! array_key_exists( $key, CPT::case_meta() ) ) {
			$stray[] = $key;
		}
	}
}
hti_games_check( array() === $stray, 'and no case invents a key the registry does not know (' . ( $stray ? implode( ', ', array_unique( $stray ) ) : 'clean' ) . ')' );

echo "\nEvery case names a pattern the lesson library knows\n";
$bad_pattern = array();
$per_pattern = array();
foreach ( $cases as $case ) {
	$pattern = (string) $case['meta']['hti_rev_pattern'];
	if ( ! Reveal_Lessons::is_pattern( $pattern ) ) {
		$bad_pattern[] = $case['company'] . ': ' . $pattern;
	}
	if ( Reveal_Lessons::FALLBACK_PATTERN === $pattern ) {
		$bad_pattern[] = $case['company'] . ': filed under the fallback pattern';
	}
	if ( CPT::san_key( $pattern ) !== $pattern ) {
		$bad_pattern[] = $case['company'] . ': the pattern would not survive sanitising';
	}
	$per_pattern[ $pattern ] = ( $per_pattern[ $pattern ] ?? 0 ) + 1;
}
hti_games_check( array() === $bad_pattern, 'and never the fallback, which is the one reserved for a case nobody has thought about yet (' . ( $bad_pattern ? implode( '; ', $bad_pattern ) : 'clean' ) . ')' );

$thin = array_keys( array_filter( $per_pattern, fn( int $n ): bool => $n < 2 ) );
hti_games_check( array() === $thin, 'every pattern in the library has at least two cases, so a shape can be recognised rather than remembered (' . ( $thin ? implode( ', ', $thin ) : 'clean' ) . ')' );

// The library is meant to span the patterns the game exists to teach, not to
// pile up on the two or three easiest to find.
$covered = count( $per_pattern );
hti_games_check( $covered >= count( $patterns ) - 1, sprintf( '%d of the %d patterns are represented (the fallback is deliberately not)', $covered, count( $patterns ) ) );

$uncovered = array_diff( array_keys( $patterns ), array_keys( $per_pattern ), array( Reveal_Lessons::FALLBACK_PATTERN ) );
hti_games_check( array() === $uncovered, 'no pattern is left with lessons and no case (' . ( $uncovered ? implode( ', ', $uncovered ) : 'clean' ) . ')' );

echo "\nSix labelled questions, no answers\n";
$rows_bad = array();
$used     = array();
foreach ( $cases as $case ) {
	$rows = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );

	if ( ! is_array( $rows ) || Seed_Cases::FUNDAMENTALS !== count( $rows ) ) {
		$rows_bad[] = $case['company'] . ': not six rows';
		continue;
	}

	$keys = array();
	foreach ( $rows as $row ) {
		$keys[] = (string) $row['key'];
		$used[] = (string) $row['key'];

		if ( '' === trim( (string) $row['key'] ) || '' === trim( (string) $row['label_en'] ) || '' === trim( (string) $row['label_pt'] ) ) {
			$rows_bad[] = $case['company'] . ': an unlabelled row';
		}
		if ( ! isset( $metrics[ (string) $row['key'] ] ) ) {
			$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' is not in metrics()';
		}
		foreach ( array( 'value_en', 'value_pt', 'sector_avg_en', 'sector_avg_pt' ) as $field ) {
			if ( '' !== trim( (string) $row[ $field ] ) ) {
				$rows_bad[] = $case['company'] . ': ' . $row['key'] . '.' . $field . ' carries a value';
			}
		}
		// A tint is a verdict rendered in colour, and there is no number here
		// to pass a verdict on.
		if ( 'warn' !== (string) $row['tint'] ) {
			$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' is tinted ' . $row['tint'];
		}
	}

	if ( count( array_unique( $keys ) ) !== count( $keys ) ) {
		$rows_bad[] = $case['company'] . ': the same question twice';
	}
}
hti_games_check( array() === $rows_bad, 'six labelled fundamentals per case, every value and sector average empty, every tint neutral (' . ( $rows_bad ? implode( '; ', array_slice( $rows_bad, 0, 6 ) ) : 'clean' ) . ')' );

// The point of a per-case label set is that it differs per case. A library
// where every dossier asked the same six questions would satisfy every check
// above and teach one thing.
$signatures = array();
foreach ( $cases as $case ) {
	$rows = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );
	$keys = array_map( fn( array $r ): string => (string) $r['key'], is_array( $rows ) ? $rows : array() );
	sort( $keys );
	$signatures[] = implode( ',', $keys );
}
hti_games_check(
	count( array_unique( $signatures ) ) === count( $signatures ),
	sprintf( 'no two cases ask the same six questions (%d distinct label sets for %d cases)', count( array_unique( $signatures ) ), count( $signatures ) )
);

echo "\nThe metric table is a table of questions, and all of it is used\n";
$metric_bad = array();
foreach ( $metrics as $key => $metric ) {
	foreach ( array( 'en', 'pt', 'from_en', 'from_pt' ) as $field ) {
		if ( '' === trim( (string) ( $metric[ $field ] ?? '' ) ) ) {
			$metric_bad[] = $key . ':' . $field;
		}
	}
	if ( ( $metric['en'] ?? '' ) === ( $metric['pt'] ?? '' ) ) {
		$metric_bad[] = $key . ': the Portuguese label is the English one';
	}
	// A label has to survive the field it is stored in, or the editor opens a
	// meta box with a sentence cut in half.
	foreach ( array( 'en', 'pt' ) as $lang ) {
		if ( CPT::san_text( $metric[ $lang ] ) !== $metric[ $lang ] ) {
			$metric_bad[] = $key . ': the ' . $lang . ' label would be truncated by san_text';
		}
	}
}
hti_games_check( array() === $metric_bad, sprintf( 'all %d metrics are labelled and sourced in both languages (%s)', count( $metrics ), $metric_bad ? implode( '; ', $metric_bad ) : 'clean' ) );

$orphans = array_values( array_diff( array_keys( $metrics ), array_unique( $used ) ) );
hti_games_check( array() === $orphans, 'no metric is defined and never asked (' . ( $orphans ? implode( ', ', $orphans ) : 'clean' ) . ')' );

$dup_labels = array();
foreach ( array( 'en', 'pt' ) as $lang ) {
	$labels = array_map( fn( array $m ): string => mb_strtolower( (string) $m[ $lang ] ), $metrics );
	$dupes  = array_unique( array_diff_assoc( $labels, array_unique( $labels ) ) );
	foreach ( $dupes as $dupe ) {
		$dup_labels[] = $lang . ': ' . $dupe;
	}
}
hti_games_check( array() === $dup_labels, 'and no two metrics ask the same question in different words (' . ( $dup_labels ? implode( '; ', $dup_labels ) : 'clean' ) . ')' );

echo "\nThe sector taxonomy is one table, so two dossiers can be compared\n";
$sector_bad = array();
$used_sect  = array();
foreach ( $cases as $case ) {
	$en = (string) $case['meta']['hti_rev_sector_en'];
	$pt = (string) $case['meta']['hti_rev_sector_pt'];
	if ( $en === $pt ) {
		$sector_bad[] = $case['company'] . ': the Portuguese sector is the English one';
	}
	$found = false;
	foreach ( $sectors as $key => $pair ) {
		if ( $pair['en'] === $en && $pair['pt'] === $pt ) {
			$found       = true;
			$used_sect[] = $key;
			break;
		}
	}
	if ( ! $found ) {
		$sector_bad[] = $case['company'] . ': a sector typed by hand rather than taken from sectors()';
	}
}
hti_games_check( array() === $sector_bad, sprintf( 'every case draws its sector from the taxonomy, in both languages (%s)', $sector_bad ? implode( '; ', $sector_bad ) : 'clean' ) );

$sector_orphans = array_values( array_diff( array_keys( $sectors ), array_unique( $used_sect ) ) );
hti_games_check( array() === $sector_orphans, 'and no sector is defined and never used (' . ( $sector_orphans ? implode( ', ', $sector_orphans ) : 'clean' ) . ')' );

echo "\nEvery case carries a brief an editor can work from\n";
$brief_bad = array();
foreach ( $cases as $case ) {
	$brief   = (string) $case['meta']['hti_rev_brief'];
	$company = (string) $case['meta']['hti_rev_company'];
	$year    = (string) $case['meta']['hti_rev_year'];

	// Both languages, because a Portuguese editor should not have to read the
	// English half to find out which note to open.
	if ( ! str_contains( $brief, 'RESEARCH BRIEF (EN)' ) || ! str_contains( $brief, 'GUIÃO DE PESQUISA (PT)' ) ) {
		$brief_bad[] = $company . ': only one language';
	}
	if ( ! str_contains( $brief, $company ) || ! str_contains( $brief, $year ) ) {
		$brief_bad[] = $company . ': does not name the document to open';
	}
	// Six labels in, six labels out: the brief is the map from the dossier
	// row to the line item, and a missing row leaves the editor guessing.
	$rows = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		foreach ( array( 'label_en', 'label_pt' ) as $field ) {
			if ( ! str_contains( $brief, (string) $row[ $field ] ) ) {
				$brief_bad[] = $company . ': the brief skips ' . $row['key'] . ' (' . $field . ')';
			}
		}
	}
	// The two returns, the sector comparison and the source discipline.
	foreach ( array( 'SECTOR AVERAGE', 'THE TWO RETURNS', 'HEADLINES', 'MÉDIA DO SETOR', 'OS DOIS RETORNOS', 'MANCHETES' ) as $heading ) {
		if ( ! str_contains( $brief, $heading ) ) {
			$brief_bad[] = $company . ': no "' . $heading . '" section';
		}
	}
	// The ready-written lesson for the pattern, so the editor is not asked to
	// write bilingual copy at the end of a research session.
	$lesson = Reveal_Lessons::for_pattern( (string) $case['meta']['hti_rev_pattern'], 0 );
	if ( ! str_contains( $brief, $lesson['en'] ) || ! str_contains( $brief, $lesson['pt'] ) ) {
		$brief_bad[] = $company . ': the pattern lesson is not offered';
	}
	// And it survives the field it is stored in — a brief cut at the sanitizer
	// ceiling would lose the Portuguese half silently.
	if ( CPT::san_brief( $brief ) !== $brief ) {
		$brief_bad[] = $company . ': the brief would be truncated by san_brief';
	}
}
hti_games_check( array() === $brief_bad, 'each brief names the document, maps all six rows, and carries the sector, returns, headline and lesson sections (' . ( $brief_bad ? implode( '; ', array_slice( $brief_bad, 0, 6 ) ) : 'clean' ) . ')' );

// A brief must not become an answer sheet. It names document TYPES and where
// they live; it must not pretend to know an address, a filing reference or a
// date it could not have checked.
$invented = array();
foreach ( $cases as $case ) {
	$brief = (string) $case['meta']['hti_rev_brief'];
	foreach ( array( 'http://', 'https://', 'www.', 'sec.gov', 'cik', 'accession' ) as $needle ) {
		if ( str_contains( mb_strtolower( $brief ), $needle ) ) {
			$invented[] = $case['company'] . ' → ' . $needle;
		}
	}
}
hti_games_check( array() === $invented, 'and none of them guesses a URL, a registry id or a filing reference (' . ( $invented ? implode( '; ', $invented ) : 'clean' ) . ')' );

// The two patterns that amount to an allegation cannot be confirmed by the
// accounts of the party they are about, and their briefs have to say so.
$allegation = array();
foreach ( $cases as $case ) {
	$pattern = (string) $case['meta']['hti_rev_pattern'];
	if ( ! in_array( $pattern, array( 'fraud', 'accounting_change' ), true ) ) {
		continue;
	}
	$brief = mb_strtolower( (string) $case['meta']['hti_rev_brief'] );
	if ( ! str_contains( $brief, 'court judgment' ) || ! str_contains( $brief, 'decisão judicial' ) ) {
		$allegation[] = $case['company'];
	}
}
hti_games_check( array() === $allegation, 'a case filed under an allegation-shaped pattern is told to source it from a court or a regulator, never from the accounts (' . ( $allegation ? implode( ', ', $allegation ) : 'clean' ) . ')' );

echo "\nThe tint rubric is written down rather than left to taste\n";
$rubric = Seed_Cases::tint_rubric();
hti_games_check( array_keys( $rubric ) === CPT::TINTS, 'there is a rule for each of the three tints the dossier can carry' );
$rubric_bad = array();
foreach ( $rubric as $tint => $pair ) {
	foreach ( array( 'en', 'pt' ) as $lang ) {
		if ( '' === trim( (string) ( $pair[ $lang ] ?? '' ) ) ) {
			$rubric_bad[] = $tint . ':' . $lang;
		}
	}
	if ( ( $pair['en'] ?? '' ) === ( $pair['pt'] ?? '' ) ) {
		$rubric_bad[] = $tint . ': the Portuguese rule is the English one';
	}
}
hti_games_check( array() === $rubric_bad, 'in both languages (' . ( $rubric_bad ? implode( '; ', $rubric_bad ) : 'clean' ) . ')' );
hti_games_check(
	str_contains( mb_strtolower( $rubric['warn']['en'] ), 'default' ) && str_contains( mb_strtolower( $rubric['warn']['pt'] ), 'omissão' ),
	'and amber is named as the default, which is what CPT::san_fundamentals falls back to'
);

echo "\nThree empty headline slots per case\n";
$heads_bad = array();
foreach ( $cases as $case ) {
	$rows = json_decode( (string) $case['meta']['hti_rev_headlines'], true );
	if ( ! is_array( $rows ) || Seed_Cases::HEADLINES !== count( $rows ) ) {
		$heads_bad[] = $case['company'] . ': not three slots';
		continue;
	}
	foreach ( $rows as $row ) {
		if ( '' !== trim( (string) $row['en'] ) || '' !== trim( (string) $row['pt'] ) ) {
			$heads_bad[] = $case['company'] . ': an invented headline';
		}
	}
}
hti_games_check( array() === $heads_bad, 'a period headline is a quotation, and none of these has been read (' . ( $heads_bad ? implode( '; ', $heads_bad ) : 'clean' ) . ')' );

// The JSON has to survive the sanitizer that will actually write it, or the
// editor opens a meta box with six blank rows and no labels.
$survives = true;
foreach ( $cases as $case ) {
	$before = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );
	$after  = json_decode( CPT::san_fundamentals( $case['meta']['hti_rev_fundamentals'] ), true );

	$survives = $survives
		&& is_array( $after )
		&& Seed_Cases::FUNDAMENTALS === count( $after )
		&& $before[0]['key'] === $after[0]['key']
		&& $before[0]['label_pt'] === $after[0]['label_pt'];
}
hti_games_check( $survives, 'the fundamentals JSON survives CPT::san_fundamentals unchanged in shape and in labels' );

echo "\nEvery case is history, not a view on a listed company today\n";
$too_recent = array();
foreach ( $cases as $case ) {
	$year = (int) $case['meta']['hti_rev_year'];
	if ( $year > (int) gmdate( 'Y', $now ) - Config::REVEAL_MIN_AGE_YEARS ) {
		$too_recent[] = $case['company'] . ' ' . $year;
	}
	if ( 0 === CPT::san_year( $year ) ) {
		$too_recent[] = $case['company'] . ': year would not survive sanitising';
	}
}
hti_games_check( array() === $too_recent, 'all of them are at least ' . Config::REVEAL_MIN_AGE_YEARS . ' years past (' . ( $too_recent ? implode( ', ', $too_recent ) : 'clean' ) . ')' );

echo "\nAnd still unpublishable once WordPress has sanitised them\n";
// update_post_meta runs the registered sanitizers, so the empty return fields
// land in the database as the integer 0 — which the gate reads as a real,
// deliberate answer. The two remaining locks are what actually hold, and this
// is the version of the data the publish gate will see in production.
$after_save = array();
foreach ( $cases as $case ) {
	$meta                               = $case['meta'];
	$meta['hti_rev_return_5y_bp']       = CPT::san_int( $meta['hti_rev_return_5y_bp'] );
	$meta['hti_rev_index_return_5y_bp'] = CPT::san_int( $meta['hti_rev_index_return_5y_bp'] );
	$meta['hti_rev_verified']           = CPT::san_bool( $meta['hti_rev_verified'] );

	if ( Case_Admin::publishable( $meta, $now ) ) {
		$after_save[] = $case['company'];
	}
}
hti_games_check( array() === $after_save, 'a seeded case is still refused after the meta sanitizers have run (' . ( $after_save ? implode( ', ', $after_save ) : 'clean' ) . ')' );

// The two locks are independent: satisfying one is not enough.
$one = $cases[0]['meta'];

$with_source                       = $one;
$with_source['hti_rev_source_url'] = 'https://www.sec.gov/some-filing';
hti_games_check( ! Case_Admin::publishable( $with_source, $now ), 'a source URL alone does not unlock a case' );

$with_tick                     = $one;
$with_tick['hti_rev_verified'] = '1';
hti_games_check( ! Case_Admin::publishable( $with_tick, $now ), 'and neither does ticking verified alone' );

echo "\nThe positive control: the workflow does end somewhere\n";
$finished                               = $one;
$finished['hti_rev_source_url']         = 'https://www.sec.gov/some-filing';
$finished['hti_rev_source_label']       = 'Annual report';
$finished['hti_rev_verified']           = '1';
$finished['hti_rev_return_5y_bp']       = '-7200';
$finished['hti_rev_index_return_5y_bp'] = '1400';
hti_games_check( Case_Admin::publishable( $finished, $now ), 'a case with a source, a tick and both figures may be published' );
hti_games_check( array() === Case_Admin::missing( $finished, $now ), 'and nothing is reported missing on it' );

hti_games_done();
