<?php
/**
 * The seeded cases are complete, playable, and honest about what they are.
 *
 * This file used to assert the opposite of most of what is below: that no case
 * was publishable and that no figure was populated anywhere. That was right
 * while the library was a form waiting to be filled in. It is wrong now — the
 * owner decided the cases ship playable with reconstructed figures, on the
 * condition that the product says so rather than implying an accuracy nobody
 * checked — so the assertions changed with the decision.
 *
 * What replaces them is not weaker, it is different. A library of thirty-four
 * dossiers with numbers in them can fail in three ways this file exists to
 * catch:
 *
 *  1. IT CAN LIE ABOUT WHAT IT IS. Every case must be stamped illustrative,
 *     must carry no source URL and no verification tick, and a case that
 *     claims 'verified' must still be refused without both. The claim and the
 *     evidence have to match, and that is the whole basis on which CLAUDE.md
 *     invariant 2 lets any of this name a real company at all.
 *  2. IT CAN INVERT HISTORY. A reconstructed ratio is a reconstruction; a
 *     company that went to nothing and is recorded as having doubled is an
 *     error, not a simplification. The directions are pinned by name below.
 *  3. IT CAN BE INCOHERENT. A dossier with four filled rows and two blank
 *     ones, a tint the rubric does not allow, a Portuguese value that is the
 *     English one with a dollar sign still in it, a headline dressed as a
 *     quotation — each of those is something a player sees.
 *
 * The assertion that has NOT changed is the one about the briefs: a research
 * brief names document types and where they live, and never a URL, a registry
 * id or a filing reference, because those still cannot be checked from here
 * and a guessed one would forge the audit trail that promoting a case to
 * verified is supposed to create.
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
 * Whether a figure is language-neutral: digits, separators, a percent sign, a
 * multiplier. Those read identically in both languages apart from the decimal
 * comma, which Seed_Cases::pt_figure() applies.
 *
 * Anything else carries a word or a currency symbol, and therefore has to be
 * written out in Portuguese rather than derived — "$1.1bn" is not Portuguese,
 * and a reader of one language must never be shown the other one's units.
 *
 * @param string $value A stored value or sector average.
 */
function hti_seed_neutral( string $value ): bool {
	return 1 === preg_match( '/^[-+0-9.,%x×\s]+$/u', $value );
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

$unmarked = array_filter( $cases, fn( array $c ): bool => ! str_contains( $c['title'], Seed_Cases::TITLE_MARK ) );
hti_games_check( array() === $unmarked, 'every title says out loud, in the post list, that the case is an illustrative reconstruction' );

echo "\nEvery case is finished, and the gate agrees\n";
$refused = array();
foreach ( $cases as $case ) {
	$missing = Case_Admin::missing( $case['meta'], $now );
	if ( array() !== $missing ) {
		$refused[] = $case['company'] . ': ' . implode( ', ', $missing );
	}
}
hti_games_check( array() === $refused, 'Case_Admin::publishable() says yes to every one of them (' . ( $refused ? implode( '; ', array_slice( $refused, 0, 4 ) ) : 'all publishable' ) . ')' );

$open = array();
foreach ( $cases as $case ) {
	$progress = Case_Admin::progress( Case_Admin::checklist( $case['meta'], $now ) );
	if ( 0 !== $progress['blocking'] ) {
		$open[] = $case['company'];
	}
}
hti_games_check( array() === $open, 'and the editor checklist shows nothing blocking on any of them (' . ( $open ? implode( ', ', $open ) : 'clean' ) . ')' );

echo "\nAnd every one of them says what its figures are\n";
$claims = array();
foreach ( $cases as $case ) {
	if ( 'illustrative' !== (string) $case['meta']['hti_rev_provenance'] ) {
		$claims[] = $case['company'] . ': provenance is ' . $case['meta']['hti_rev_provenance'];
	}
	if ( 'illustrative' !== CPT::san_provenance( $case['meta']['hti_rev_provenance'] ) ) {
		$claims[] = $case['company'] . ': the provenance would not survive sanitising';
	}
	if ( 'illustrative' !== Case_Admin::provenance( $case['meta'] ) ) {
		$claims[] = $case['company'] . ': the gate reads it as something else';
	}
}
hti_games_check( array() === $claims, 'every case is stamped illustrative, and the stamp survives the sanitizer (' . ( $claims ? implode( '; ', $claims ) : 'all 34' ) . ')' );

// The stamp is only honest while nothing beside it claims a document.
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
hti_games_check( array() === $bad, 'no case carries a source URL, a credit or a tick — nothing here was read out of a document and nothing pretends it was (' . ( $bad ? implode( '; ', $bad ) : 'clean' ) . ')' );

echo "\nThe strict path is still strict: claiming verified needs the evidence\n";
// The one that matters most. If a case could be relabelled 'verified' and stay
// publishable, the provenance field would be decoration and the whole
// arrangement would be a way around the source requirement rather than a
// second, differently-evidenced route through it.
$promoted                        = $cases[0]['meta'];
$promoted['hti_rev_provenance']  = 'verified';
hti_games_check( ! Case_Admin::publishable( $promoted, $now ), 'a seeded case relabelled verified is refused' );
hti_games_check(
	in_array( 'hti_rev_source_url', Case_Admin::missing( $promoted, $now ), true )
		&& in_array( 'hti_rev_verified', Case_Admin::missing( $promoted, $now ), true ),
	'and it is refused for exactly the two things it now claims and does not have'
);

$with_source                       = $promoted;
$with_source['hti_rev_source_url'] = 'https://www.sec.gov/some-filing';
hti_games_check( ! Case_Admin::publishable( $with_source, $now ), 'a source URL alone does not unlock the verified path' );

$with_tick                     = $promoted;
$with_tick['hti_rev_verified'] = '1';
hti_games_check( ! Case_Admin::publishable( $with_tick, $now ), 'and neither does ticking verified alone' );

$finished                         = $promoted;
$finished['hti_rev_source_url']   = 'https://www.sec.gov/some-filing';
$finished['hti_rev_source_label'] = 'Annual report';
$finished['hti_rev_verified']     = '1';
hti_games_check( Case_Admin::publishable( $finished, $now ), 'with both, the promoted case may be published — the workflow does end somewhere' );

// An unset provenance is the strict path too, so a case written by hand before
// this field existed cannot escape the source requirement by saying nothing.
$silent = $cases[0]['meta'];
unset( $silent['hti_rev_provenance'] );
hti_games_check( ! Case_Admin::publishable( $silent, $now ), 'a case that does not say what it is falls into the strict path, not out of it' );

echo "\nAn illustrative case with a hole in the dossier cannot publish\n";
// The dossier IS the case when there is no document behind it, so a hole in it
// is not untidiness — it is what the player is looking at while being told the
// figures show a pattern.
foreach ( array(
	'hti_rev_revenue_band_pt' => 'the Portuguese revenue band',
	'hti_rev_context_en'      => 'what happened next',
	'hti_rev_lesson_pt'       => 'the Portuguese lesson',
	'hti_rev_sector_en'       => 'the sector',
) as $key => $what ) {
	$holed         = $cases[0]['meta'];
	$holed[ $key ] = '';
	hti_games_check(
		! Case_Admin::publishable( $holed, $now ) && in_array( $key, Case_Admin::missing( $holed, $now ), true ),
		"a case missing {$what} is refused, and told why"
	);
}

$five_rows = json_decode( (string) $cases[0]['meta']['hti_rev_fundamentals'], true );
$five_rows[2]['value_pt'] = '';
$holed                    = $cases[0]['meta'];
$holed['hti_rev_fundamentals'] = (string) wp_json_encode( $five_rows );
hti_games_check( ! Case_Admin::publishable( $holed, $now ), 'five complete fundamentals out of six is refused — the sixth row is a blank line in the dossier' );

$two_heads = json_decode( (string) $cases[0]['meta']['hti_rev_headlines'], true );
$two_heads[1]['pt'] = '';
$holed              = $cases[0]['meta'];
$holed['hti_rev_headlines'] = (string) wp_json_encode( $two_heads );
hti_games_check( ! Case_Admin::publishable( $holed, $now ), 'and so is a headline written in English only — the site has no locale fallback to save it' );

echo "\nBoth returns are set, and neither is prose\n";
$returns = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp' ) as $key ) {
		$value = $case['meta'][ $key ];
		if ( ! is_int( $value ) ) {
			$returns[] = $case['company'] . ': ' . $key . ' is not an integer';
		}
		if ( CPT::san_int( $value ) !== $value ) {
			$returns[] = $case['company'] . ': ' . $key . ' would not survive sanitising';
		}
	}
	// -10000 bp is a total loss and is the floor: there is no such thing as
	// losing more than everything, and a figure past it would pay a player who
	// passed. The ceiling is generous — one of these did rise many times over
	// — but not unbounded, because a stray extra zero is a plausible typo.
	$own = (int) $case['meta']['hti_rev_return_5y_bp'];
	if ( $own < -10000 || $own > 200000 ) {
		$returns[] = $case['company'] . ': a five-year return of ' . $own . ' bp';
	}
	// A broad index over five years: a bad window is a third off, a good one
	// roughly doubles. Anything outside that is not an index.
	$idx = (int) $case['meta']['hti_rev_index_return_5y_bp'];
	if ( $idx < -4000 || $idx > 15000 ) {
		$returns[] = $case['company'] . ': an index return of ' . $idx . ' bp is not plausible for five years';
	}
}
hti_games_check( array() === $returns, 'every case carries two signed integer returns inside a sane range (' . ( $returns ? implode( '; ', $returns ) : 'clean' ) . ')' );

echo "\nThe direction of history is not negotiable\n";
// The figures are reconstructions and the outcomes are not. These are pinned
// by name because getting one of them backwards is the single worst thing this
// library could do: a beginner would be taught that the fraud paid.
$directions = array(
	'Enron'               => array( 'max' => -9000, 'why' => 'went to nothing' ),
	'Pets.com'            => array( 'max' => -9000, 'why' => 'was wound up' ),
	'WorldCom'            => array( 'max' => -9000, 'why' => 'went to nothing' ),
	'Northern Rock'       => array( 'max' => -9000, 'why' => 'was taken into public ownership' ),
	'Carillion'           => array( 'max' => -9000, 'why' => 'was liquidated' ),
	'Peabody Energy'      => array( 'max' => -9000, 'why' => 'filed for bankruptcy protection' ),
	'SVB Financial Group' => array( 'max' => -9000, 'why' => 'was closed by regulators' ),
	'Parmalat'            => array( 'max' => -9000, 'why' => 'collapsed' ),
	'Blockbuster'         => array( 'max' => -9000, 'why' => 'did not recover' ),
	'Blue Apron'          => array( 'max' => -9000, 'why' => 'did not recover' ),
	'Lucent Technologies' => array( 'max' => -9000, 'why' => 'lost almost everything' ),
	'GoPro'               => array( 'max' => -8000, 'why' => 'did not recover' ),
	'Eastman Kodak'       => array( 'max' => -5000, 'why' => 'did not recover' ),
	'Nokia'               => array( 'max' => -5000, 'why' => 'lost the handset market' ),
	'Research In Motion'  => array( 'max' => -5000, 'why' => 'lost the handset market' ),
	'J. C. Penney'        => array( 'max' => -5000, 'why' => 'did not recover' ),
	'Amazon'              => array( 'min' => 10000, 'why' => 'compounded enormously' ),
	'Apple'               => array( 'min' => 5000, 'why' => 'was the start of something extraordinary' ),
	"Domino's Pizza"      => array( 'min' => 10000, 'why' => 'was one of the decade\'s best performers' ),
	'Salesforce'          => array( 'min' => 10000, 'why' => 'compounded strongly' ),
	'Copart'              => array( 'min' => 5000, 'why' => 'kept compounding' ),
);
$inverted = array();
$checked  = 0;
foreach ( $cases as $case ) {
	$rule = $directions[ (string) $case['company'] ] ?? null;
	if ( null === $rule ) {
		continue;
	}
	++$checked;
	$bp = (int) $case['meta']['hti_rev_return_5y_bp'];
	if ( isset( $rule['max'] ) && $bp > $rule['max'] ) {
		$inverted[] = $case['company'] . ' ' . $rule['why'] . ', and is recorded at ' . $bp . ' bp';
	}
	if ( isset( $rule['min'] ) && $bp < $rule['min'] ) {
		$inverted[] = $case['company'] . ' ' . $rule['why'] . ', and is recorded at ' . $bp . ' bp';
	}
}
hti_games_check( count( $directions ) === $checked, sprintf( 'all %d pinned outcomes are still in the library', count( $directions ) ) );
hti_games_check( array() === $inverted, 'and not one of them has been recorded backwards (' . ( $inverted ? implode( '; ', $inverted ) : 'clean' ) . ')' );

// The library has to teach both halves. A set of thirty-four disasters would
// teach a player that the answer is always to pass.
$won = array_filter( $cases, fn( array $c ): bool => (int) $c['meta']['hti_rev_return_5y_bp'] > (int) $c['meta']['hti_rev_index_return_5y_bp'] );
hti_games_check( count( $won ) >= 5, sprintf( '%d of the cases beat their index, so passing is not always the right answer', count( $won ) ) );
$lost = array_filter( $cases, fn( array $c ): bool => (int) $c['meta']['hti_rev_return_5y_bp'] < (int) $c['meta']['hti_rev_index_return_5y_bp'] );
hti_games_check( count( $lost ) >= 15, sprintf( 'and %d lost to it', count( $lost ) ) );

// "A good company that still lost to the index" only exists as a lesson if the
// index number is sane for the window rather than a placeholder.
$flat_index = array_filter( $cases, fn( array $c ): bool => 0 === (int) $c['meta']['hti_rev_index_return_5y_bp'] );
hti_games_check( array() === $flat_index, 'no case leaves the index at zero, which is what an unfilled box looks like' );
hti_games_check(
	count( array_unique( array_column( array_column( $cases, 'meta' ), 'hti_rev_index_return_5y_bp' ) ) ) >= 8,
	'and the index figure varies with the period rather than being one number copied across the library'
);

echo "\nWhat the player reads is filled, in both languages\n";
$holes = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_company', 'hti_rev_sector_en', 'hti_rev_sector_pt', 'hti_rev_revenue_band_en', 'hti_rev_revenue_band_pt', 'hti_rev_pattern', 'hti_rev_brief', 'hti_rev_context_en', 'hti_rev_context_pt', 'hti_rev_lesson_en', 'hti_rev_lesson_pt' ) as $key ) {
		if ( '' === trim( (string) $case['meta'][ $key ] ) ) {
			$holes[] = $case['company'] . ': ' . $key;
		}
	}
	if ( (int) $case['meta']['hti_rev_year'] <= 0 ) {
		$holes[] = $case['company'] . ': year';
	}
}
hti_games_check( array() === $holes, 'company, year, sector, band, pattern, brief, aftermath and lesson are present on every case (' . ( $holes ? implode( '; ', $holes ) : 'clean' ) . ')' );

$untranslated = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_revenue_band', 'hti_rev_context', 'hti_rev_lesson' ) as $prefix ) {
		if ( (string) $case['meta'][ $prefix . '_en' ] === (string) $case['meta'][ $prefix . '_pt' ] ) {
			$untranslated[] = $case['company'] . ': ' . $prefix;
		}
	}
	// A block has to survive the field it is stored in, or the player reads
	// half a sentence.
	foreach ( array( 'hti_rev_context_en', 'hti_rev_context_pt', 'hti_rev_lesson_en', 'hti_rev_lesson_pt' ) as $key ) {
		if ( CPT::san_block( (string) $case['meta'][ $key ] ) !== (string) $case['meta'][ $key ] ) {
			$untranslated[] = $case['company'] . ': ' . $key . ' would be truncated by san_block';
		}
	}
}
hti_games_check( array() === $untranslated, 'and the Portuguese half of each is Portuguese, and fits the field it is stored in (' . ( $untranslated ? implode( '; ', $untranslated ) : 'clean' ) . ')' );

// The lesson is the ready-written one for the PATTERN rather than a paragraph
// about the company: that is what keeps it free of any claim nobody checked.
$lesson_bad = array();
$variants   = array();
foreach ( $cases as $case ) {
	$pattern = (string) $case['meta']['hti_rev_pattern'];
	$found   = false;
	foreach ( Reveal_Lessons::all()[ $pattern ] ?? array() as $lesson ) {
		if ( $lesson['en'] === (string) $case['meta']['hti_rev_lesson_en'] && $lesson['pt'] === (string) $case['meta']['hti_rev_lesson_pt'] ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$lesson_bad[] = $case['company'];
	}
	$variants[ $pattern ][] = (string) $case['meta']['hti_rev_lesson_en'];
}
hti_games_check( array() === $lesson_bad, 'every lesson comes out of Reveal_Lessons for that pattern, so no lesson is a claim about a company (' . ( $lesson_bad ? implode( ', ', $lesson_bad ) : 'clean' ) . ')' );

$repeats = array();
foreach ( $variants as $pattern => $list ) {
	if ( count( array_unique( $list ) ) < min( count( $list ), 2 ) ) {
		$repeats[] = $pattern;
	}
}
hti_games_check( array() === $repeats, 'and two cases of the same shape take different lesson variants, so a player meeting the shape twice is not read the same sentence twice (' . ( $repeats ? implode( ', ', $repeats ) : 'clean' ) . ')' );

$missing_keys = array();
foreach ( $cases as $case ) {
	foreach ( array_keys( CPT::case_meta() ) as $key ) {
		if ( ! array_key_exists( $key, $case['meta'] ) ) {
			$missing_keys[] = $case['company'] . ': ' . $key;
		}
	}
}
hti_games_check( array() === $missing_keys, 'every registered meta key is present on every case, the deliberately blank ones included (' . ( $missing_keys ? implode( '; ', $missing_keys ) : 'clean' ) . ')' );

$stray = array();
foreach ( $cases as $case ) {
	foreach ( array_keys( $case['meta'] ) as $key ) {
		if ( ! array_key_exists( $key, CPT::case_meta() ) ) {
			$stray[] = $key;
		}
	}
}
hti_games_check( array() === $stray, 'and no case invents a key the registry does not know (' . ( $stray ? implode( ', ', array_unique( $stray ) ) : 'clean' ) . ')' );

echo "\nThe revenue band is a band, never a figure that resolves to one company\n";
$band_bad = array();
foreach ( $cases as $case ) {
	$en = (string) $case['meta']['hti_rev_revenue_band_en'];
	// A band is a range, an "over"/"under" bound, or a magnitude — never a
	// number to the nearest million, which a search engine resolves in one go.
	$is_band = str_contains( $en, '–' )
		|| str_contains( mb_strtolower( $en ), 'over' )
		|| str_contains( mb_strtolower( $en ), 'under' );
	if ( ! $is_band ) {
		$band_bad[] = $case['company'] . ': "' . $en . '"';
	}
	if ( CPT::san_text( $en ) !== $en ) {
		$band_bad[] = $case['company'] . ': the band would be truncated by san_text';
	}
}
hti_games_check( array() === $band_bad, 'every band is a range or a bound (' . ( $band_bad ? implode( '; ', $band_bad ) : 'clean' ) . ')' );

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

$covered = count( $per_pattern );
hti_games_check( $covered >= count( $patterns ) - 1, sprintf( '%d of the %d patterns are represented (the fallback is deliberately not)', $covered, count( $patterns ) ) );

$uncovered = array_diff( array_keys( $patterns ), array_keys( $per_pattern ), array( Reveal_Lessons::FALLBACK_PATTERN ) );
hti_games_check( array() === $uncovered, 'no pattern is left with lessons and no case (' . ( $uncovered ? implode( ', ', $uncovered ) : 'clean' ) . ')' );

echo "\nSix answered, tinted, bilingual fundamentals per case\n";
$rows_bad = array();
$used     = array();
$tints    = array();
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

		if ( ! isset( $metrics[ (string) $row['key'] ] ) ) {
			$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' is not in metrics()';
			continue;
		}
		// The label is the metric's, so two dossiers asking the same question
		// ask it in the same words — and in the same Portuguese.
		if ( $metrics[ (string) $row['key'] ]['en'] !== (string) $row['label_en'] || $metrics[ (string) $row['key'] ]['pt'] !== (string) $row['label_pt'] ) {
			$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' was labelled by hand';
		}
		foreach ( array( 'key', 'label_en', 'label_pt', 'value_en', 'value_pt', 'sector_avg_en', 'sector_avg_pt' ) as $field ) {
			if ( '' === trim( (string) $row[ $field ] ) ) {
				$rows_bad[] = $case['company'] . ': ' . $row['key'] . '.' . $field . ' is empty';
			}
			if ( CPT::san_text( (string) $row[ $field ] ) !== (string) $row[ $field ] ) {
				$rows_bad[] = $case['company'] . ': ' . $row['key'] . '.' . $field . ' would be truncated by san_text';
			}
		}
		if ( ! in_array( (string) $row['tint'], CPT::TINTS, true ) ) {
			$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' is tinted ' . $row['tint'];
		}
		$tints[] = (string) $row['tint'];

		// A value carrying a word or a currency symbol has to be written out
		// in Portuguese. "$1.1bn" in the Portuguese column is the English
		// column with a comma in it.
		foreach ( array( 'value', 'sector_avg' ) as $pair ) {
			$en = (string) $row[ $pair . '_en' ];
			$pt = (string) $row[ $pair . '_pt' ];
			if ( hti_seed_neutral( $en ) ) {
				if ( Seed_Cases::pt_figure( $en ) !== $pt ) {
					$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' ' . $pair . ' is a plain figure but its Portuguese was typed separately';
				}
			} elseif ( $en === $pt ) {
				$rows_bad[] = $case['company'] . ': ' . $row['key'] . ' ' . $pair . ' was never translated ("' . $en . '")';
			}
		}
	}

	if ( count( array_unique( $keys ) ) !== count( $keys ) ) {
		$rows_bad[] = $case['company'] . ': the same question twice';
	}
}
hti_games_check( array() === $rows_bad, 'six answered fundamentals per case, labelled from metrics(), tinted from the rubric, bilingual throughout (' . ( $rows_bad ? implode( '; ', array_slice( $rows_bad, 0, 6 ) ) : 'clean' ) . ')' );

// A dossier tinted entirely green or entirely red is not a dossier, it is an
// answer. The library as a whole has to use all three.
$spread = array_count_values( $tints );
hti_games_check(
	count( $spread ) === count( CPT::TINTS ) && min( $spread ) >= 20,
	sprintf( 'all three tints are used across the library (%s)', implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $spread ), $spread ) ) )
);

// The two shapes whose whole lesson lives in the tints. A fraud dossier has to
// show profit and cash disagreeing where a beginner can see it; a
// technology-shift dossier has to look good, because the point is that the
// accounts described a business about to be overtaken.
$shape_bad = array();
foreach ( $cases as $case ) {
	$rows    = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );
	$pattern = (string) $case['meta']['hti_rev_pattern'];
	$by_tint = array_count_values( array_map( fn( array $r ): string => (string) $r['tint'], is_array( $rows ) ? $rows : array() ) );

	if ( 'tech_shift' === $pattern && ( $by_tint['good'] ?? 0 ) < 3 ) {
		$shape_bad[] = $case['company'] . ': a technology-shift dossier that does not look good';
	}
	if ( 'fraud' === $pattern && ( $by_tint['bad'] ?? 0 ) < 2 ) {
		$shape_bad[] = $case['company'] . ': a fraud dossier with nothing visibly wrong in it';
	}
}
hti_games_check( array() === $shape_bad, 'the tints make the pattern legible on the two shapes that live or die by it (' . ( $shape_bad ? implode( '; ', $shape_bad ) : 'clean' ) . ')' );

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

echo "\nThree headlines per case, written as period context and not as quotations\n";
$heads_bad = array();
foreach ( $cases as $case ) {
	$rows = json_decode( (string) $case['meta']['hti_rev_headlines'], true );
	if ( ! is_array( $rows ) || Seed_Cases::HEADLINES !== count( $rows ) ) {
		$heads_bad[] = $case['company'] . ': not three headlines';
		continue;
	}
	foreach ( $rows as $row ) {
		foreach ( array( 'en', 'pt' ) as $lang ) {
			$text = trim( (string) $row[ $lang ] );
			if ( '' === $text ) {
				$heads_bad[] = $case['company'] . ': an empty headline (' . $lang . ')';
			}
			// A headline in quotation marks reads as a citation, and these are
			// reconstructions. The reveal screen says so in words; the
			// punctuation must not say otherwise.
			if ( 1 === preg_match( '/["“”«»]/u', $text ) ) {
				$heads_bad[] = $case['company'] . ': a headline dressed as a quotation (' . $lang . ')';
			}
			// The dossier is anonymous until the decision is recorded, and the
			// headlines are the half a player is most likely to search.
			if ( false !== mb_stripos( $text, (string) $case['meta']['hti_rev_company'] ) ) {
				$heads_bad[] = $case['company'] . ': a headline names the company (' . $lang . ')';
			}
			if ( CPT::san_text( $text ) !== $text ) {
				$heads_bad[] = $case['company'] . ': a headline would be truncated by san_text (' . $lang . ')';
			}
		}
		if ( (string) $row['en'] === (string) $row['pt'] ) {
			$heads_bad[] = $case['company'] . ': a headline was never translated';
		}
	}
}
hti_games_check( array() === $heads_bad, 'three bilingual headlines per case, none of them quoted and none naming the company (' . ( $heads_bad ? implode( '; ', array_slice( $heads_bad, 0, 6 ) ) : 'clean' ) . ')' );

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
		&& $before[0]['label_pt'] === $after[0]['label_pt']
		&& $before[0]['value_pt'] === $after[0]['value_pt']
		&& $before[0]['tint'] === $after[0]['tint'];
}
hti_games_check( $survives, 'the fundamentals JSON survives CPT::san_fundamentals unchanged in shape, labels, values and tints' );

$heads_survive = true;
foreach ( $cases as $case ) {
	$after         = json_decode( CPT::san_headlines( $case['meta']['hti_rev_headlines'] ), true );
	$heads_survive = $heads_survive && is_array( $after ) && Seed_Cases::HEADLINES === count( $after );
}
hti_games_check( $heads_survive, 'and the headlines JSON survives CPT::san_headlines' );

echo "\nThe Portuguese of a plain figure is derived, not typed twice\n";
hti_games_check( '1,4x' === Seed_Cases::pt_figure( '1.4x' ), 'a decimal point between digits becomes a comma' );
hti_games_check( '18%' === Seed_Cases::pt_figure( '18%' ), 'a figure with nothing to change is unchanged' );
hti_games_check( '-9 700 bp.' === Seed_Cases::pt_figure( '-9 700 bp.' ), 'and a full stop that is not a decimal separator is left alone' );

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

echo "\nAnd still publishable once WordPress has sanitised them\n";
// update_post_meta runs the registered sanitizers, so this is the version of
// the data the publish gate will actually see in production.
$after_save = array();
foreach ( $cases as $case ) {
	$meta                               = $case['meta'];
	$meta['hti_rev_return_5y_bp']       = CPT::san_int( $meta['hti_rev_return_5y_bp'] );
	$meta['hti_rev_index_return_5y_bp'] = CPT::san_int( $meta['hti_rev_index_return_5y_bp'] );
	$meta['hti_rev_verified']           = CPT::san_bool( $meta['hti_rev_verified'] );
	$meta['hti_rev_provenance']         = CPT::san_provenance( $meta['hti_rev_provenance'] );
	$meta['hti_rev_fundamentals']       = CPT::san_fundamentals( $meta['hti_rev_fundamentals'] );
	$meta['hti_rev_headlines']          = CPT::san_headlines( $meta['hti_rev_headlines'] );

	if ( ! Case_Admin::publishable( $meta, $now ) ) {
		$after_save[] = $case['company'] . ': ' . implode( ', ', Case_Admin::missing( $meta, $now ) );
	}
}
hti_games_check( array() === $after_save, 'a seeded case is still publishable after the meta sanitizers have run (' . ( $after_save ? implode( '; ', array_slice( $after_save, 0, 4 ) ) : 'clean' ) . ')' );

echo "\nEvery case carries a brief an editor can promote it with\n";
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
	// It has to say what the case currently is, or an editor reads the figures
	// in the boxes as though somebody had checked them.
	if ( ! str_contains( $brief, 'STATE OF THIS CASE: illustrative' ) || ! str_contains( $brief, 'ESTADO DESTE CASO: ilustrativo' ) ) {
		$brief_bad[] = $company . ': the brief does not say the figures are reconstructed';
	}
	// Six labels in, six labels out: the brief is the map from the dossier row
	// to the line item, and a missing row leaves the editor guessing.
	$rows = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		foreach ( array( 'label_en', 'label_pt' ) as $field ) {
			if ( ! str_contains( $brief, (string) $row[ $field ] ) ) {
				$brief_bad[] = $company . ': the brief skips ' . $row['key'] . ' (' . $field . ')';
			}
		}
	}
	foreach ( array( 'SECTOR AVERAGE', 'THE TWO RETURNS', 'HEADLINES', 'MÉDIA DO SETOR', 'OS DOIS RETORNOS', 'MANCHETES' ) as $heading ) {
		if ( ! str_contains( $brief, $heading ) ) {
			$brief_bad[] = $company . ': no "' . $heading . '" section';
		}
	}
	$lesson = Reveal_Lessons::for_pattern( (string) $case['meta']['hti_rev_pattern'], 0 );
	if ( ! str_contains( $brief, $lesson['en'] ) || ! str_contains( $brief, $lesson['pt'] ) ) {
		$brief_bad[] = $company . ': the pattern lesson is not offered';
	}
	if ( CPT::san_brief( $brief ) !== $brief ) {
		$brief_bad[] = $company . ': the brief would be truncated by san_brief';
	}
}
hti_games_check( array() === $brief_bad, 'each brief names the document, says the figures are reconstructions, and maps all six rows (' . ( $brief_bad ? implode( '; ', array_slice( $brief_bad, 0, 6 ) ) : 'clean' ) . ')' );

// UNCHANGED, and the reason it is unchanged is the whole point of the brief. A
// brief must not become an answer sheet: it names document TYPES and where
// they live, and must not pretend to know an address, a filing reference or a
// date that could not have been checked from here.
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

hti_games_done();
