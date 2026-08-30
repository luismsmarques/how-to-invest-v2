<?php
/**
 * The publish gate for The Reveal, and the decay of a verification.
 *
 * This is the test that keeps a written exemption honest. CLAUDE.md invariant
 * 2 forbids naming companies; The Reveal may name one, on the condition that
 * the case is old enough to be history and that every number beside the name
 * is sourced and checked. publishable() is that condition expressed once, and
 * the whole plugin defers to it, so this file is where the condition is
 * actually held to.
 *
 * There are two gates rather than one, because there are two claims a case can
 * make. `hti_rev_provenance` says which: a VERIFIED case claims its figures
 * came out of a document, so it needs that document and a tick; an
 * ILLUSTRATIVE case claims no document, so what it needs instead is the whole
 * dossier — the thing the player is actually looking at. Both are tested here,
 * and so is the direction of the default, which is the part that would quietly
 * undo the rest: anything that does not say 'illustrative' is judged strictly,
 * so a case created by hand in the admin, or a row written before this field
 * existed, falls INTO the source requirement rather than out of it.
 *
 * The awkward cases are the interesting ones: a five-year return of exactly
 * zero is a real, publishable answer (so the check is "is it set", never
 * empty()), and a verification that survives an edit to the number it
 * verified is not a verification at all.
 *
 *   php wp-content/plugins/hti-games/tests/test-case-gate.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-case-admin.php';

use HTI\Games\Case_Admin;
use HTI\Games\Config;

/**
 * A fixed "now" so a passing suite does not start failing on New Year's Day.
 */
$now  = (int) strtotime( '2026-08-30 12:00:00 UTC' );
$year = 2026 - Config::REVEAL_MIN_AGE_YEARS;

/**
 * A case that ought to be publishable, with one field overridden.
 *
 * @param array<string,mixed> $override Fields to replace.
 * @return array<string,mixed>
 */
function hti_case( array $override = array() ): array {
	return array_merge(
		array(
			'hti_rev_company'            => 'Kodak',
			'hti_rev_year'               => '2011',
			'hti_rev_source_url'         => 'https://www.sec.gov/some-filing',
			'hti_rev_verified'           => '1',
			'hti_rev_return_5y_bp'       => '-9700',
			'hti_rev_index_return_5y_bp' => '8300',
		),
		$override
	);
}

/**
 * Six complete fundamentals rows and three complete headlines.
 *
 * The illustrative gate counts them, so the fixtures have to be countable
 * rather than merely present.
 *
 * @param int $rows How many of the six to complete.
 * @return string JSON.
 */
function hti_case_fundamentals( int $rows = 6 ): string {
	$out = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$full  = $i < $rows;
		$out[] = array(
			'key'           => 'row_' . $i,
			'label_en'      => 'Operating margin',
			'label_pt'      => 'Margem operacional',
			'value_en'      => $full ? '4%' : '',
			'value_pt'      => $full ? '4%' : '',
			'sector_avg_en' => '9%',
			'sector_avg_pt' => '9%',
			'tint'          => 'bad',
		);
	}

	return (string) wp_json_encode( $out );
}

/**
 * Three headlines, bilingual.
 *
 * @param int $rows How many to write in both languages.
 * @return string JSON.
 */
function hti_case_headlines( int $rows = 3 ): string {
	$out = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$out[] = array(
			'en' => 'Something being said at the time',
			'pt' => $i < $rows ? 'O que se dizia na altura' : '',
		);
	}

	return (string) wp_json_encode( $out );
}

/**
 * A finished ILLUSTRATIVE case: no source, no tick, whole dossier.
 *
 * @param array<string,mixed> $override Fields to replace.
 * @return array<string,mixed>
 */
function hti_case_illustrative( array $override = array() ): array {
	return array_merge(
		array(
			'hti_rev_company'            => 'Kodak',
			'hti_rev_year'               => '2011',
			'hti_rev_provenance'         => 'illustrative',
			'hti_rev_sector_en'          => 'Imaging',
			'hti_rev_sector_pt'          => 'Imagem',
			'hti_rev_revenue_band_en'    => '$1bn–$5bn',
			'hti_rev_revenue_band_pt'    => '1 a 5 mil milhões de dólares',
			'hti_rev_fundamentals'       => hti_case_fundamentals(),
			'hti_rev_headlines'          => hti_case_headlines(),
			'hti_rev_context_en'         => 'What happened next.',
			'hti_rev_context_pt'         => 'O que aconteceu a seguir.',
			'hti_rev_lesson_en'          => 'The lesson.',
			'hti_rev_lesson_pt'          => 'A lição.',
			'hti_rev_return_5y_bp'       => '-9700',
			'hti_rev_index_return_5y_bp' => '8300',
			'hti_rev_source_url'         => '',
			'hti_rev_verified'           => '0',
		),
		$override
	);
}

echo "A complete, sourced, verified, old-enough case may be published\n";
hti_games_check( Case_Admin::publishable( hti_case(), $now ), 'the reference case passes' );
hti_games_check( array() === Case_Admin::missing( hti_case(), $now ), 'and nothing is reported missing' );

echo "\nThe source URL is not optional\n";
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_source_url' => '' ) ), $now ), 'an empty source URL blocks publication' );
hti_games_check( in_array( 'hti_rev_source_url', Case_Admin::missing( hti_case( array( 'hti_rev_source_url' => '' ) ), $now ), true ), 'and it is named in the report, so the editor is not left guessing' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_source_url' => 'the annual report' ) ), $now ), 'a description is not a URL' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_source_url' => 'javascript:alert(1)' ) ), $now ), 'nor is a javascript: URL — this value ends up as an href' );
hti_games_check( ! Case_Admin::is_url( 'data:text/html,<h1>hi</h1>' ), 'nor a data: URL' );
hti_games_check( Case_Admin::is_url( 'http://example.org/a?b=c#d' ), 'a plain http URL with a query and a fragment is fine' );

echo "\nVerification is not optional either\n";
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_verified' => '0' ) ), $now ), 'an unverified case cannot be published' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_verified' => '' ) ), $now ), 'nor one where the field was never set' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_verified' => 'yes' ) ), $now ), 'nor one where something truthy but not "1" was stored' );

echo "\nBoth returns must be present — and zero is a present value\n";
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_return_5y_bp' => '' ) ), $now ), 'a missing five-year return blocks publication' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_index_return_5y_bp' => '' ) ), $now ), 'so does a missing index return' );
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_return_5y_bp' => '0' ) ), $now ), 'a return of exactly 0 bp is publishable: flat for five years is an answer, and empty() would have eaten it' );
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_index_return_5y_bp' => '0' ) ), $now ), 'the same for the index' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_return_5y_bp' => 'about -97%' ) ), $now ), 'prose in a basis-points field is not a number' );
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_return_5y_bp' => -9700 ) ), $now ), 'an integer is as good as its string' );

echo "\nA case must be history, not a view on a listed company today\n";
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_year' => (string) ( $year + 1 ) ) ), $now ), 'a year inside the minimum age is refused' );
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_year' => (string) $year ) ), $now ), 'exactly Config::REVEAL_MIN_AGE_YEARS back is old enough' );
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_year' => '1998' ) ), $now ), 'and anything older certainly is' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_year' => '' ) ), $now ), 'a missing year is refused' );
hti_games_check( ! Case_Admin::publishable( hti_case( array( 'hti_rev_year' => '2030' ) ), $now ), 'and so is a year in the future' );

echo "\nThe report names every problem at once, not the first one\n";
$broken = Case_Admin::missing(
	array(
		'hti_rev_year'     => '2025',
		'hti_rev_verified' => '0',
	),
	$now
);
hti_games_check( 5 === count( $broken ), 'a hollow case reports all five blocked fields in one pass' );
hti_games_check( array_key_exists( 'hti_rev_year', Case_Admin::labels() ), 'every reported field has a human label for the notice' );
$unlabelled = array_diff( $broken, array_keys( Case_Admin::labels() ) );
hti_games_check( array() === $unlabelled, 'no field can be reported without wording an editor can act on (' . implode( ', ', $unlabelled ) . ')' );

echo "\nEditing a verified number un-verifies the case\n";
$verified = array(
	'hti_rev_year'               => '2011',
	'hti_rev_return_5y_bp'       => '-9700',
	'hti_rev_index_return_5y_bp' => '8300',
	'hti_rev_verified'           => '1',
);
hti_games_check( ! Case_Admin::clears_verification( $verified, $verified ), 'saving the same numbers again keeps the verification' );
hti_games_check( Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_return_5y_bp' => '-9600' ) ) ), 'changing the five-year return clears it' );
hti_games_check( Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_index_return_5y_bp' => '8400' ) ) ), 'changing the index return clears it' );
hti_games_check( Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_year' => '2012' ) ) ), 'changing the year clears it' );
hti_games_check( Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_year' => '' ) ) ), 'blanking the year clears it too' );
hti_games_check( ! Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_company' => 'Nokia' ) ) ), 'renaming the company does not — the tick is a statement about the numbers' );
hti_games_check( ! Case_Admin::clears_verification( $verified, array( 'hti_rev_company' => 'Nokia' ) ), 'a partial save that never mentions the numbers does not clear anything' );
hti_games_check( ! Case_Admin::clears_verification( $verified, array_merge( $verified, array( 'hti_rev_return_5y_bp' => '-09700' ) ) ), 'a leading zero is the same number, not an edit' );
hti_games_check(
	! Case_Admin::clears_verification( array( 'hti_rev_year' => '', 'hti_rev_return_5y_bp' => '' ), $verified ),
	'filling in a field that was empty is not an edit: there is no earlier verification to invalidate, and a brand-new case must be publishable in one save'
);

echo "\nAn illustrative case is judged on its dossier instead of on a document\n";
hti_games_check( Case_Admin::publishable( hti_case_illustrative(), $now ), 'a complete illustrative case may be published with no source and no tick' );
hti_games_check( array() === Case_Admin::missing( hti_case_illustrative(), $now ), 'and nothing is reported missing on it' );
hti_games_check( ! in_array( 'hti_rev_source_url', Case_Admin::missing( hti_case_illustrative( array( 'hti_rev_source_url' => '' ) ), $now ), true ), 'the source URL is not asked for: none is being claimed' );
hti_games_check( ! in_array( 'hti_rev_verified', Case_Admin::missing( hti_case_illustrative(), $now ), true ), 'nor is the tick, which is a statement about a document' );

echo "\nBut the dossier is not optional, because the dossier IS the case\n";
foreach ( array(
	'hti_rev_sector_en'        => 'the sector',
	'hti_rev_sector_pt'        => 'the Portuguese sector',
	'hti_rev_revenue_band_en'  => 'the revenue band',
	'hti_rev_revenue_band_pt'  => 'the Portuguese revenue band',
	'hti_rev_context_en'       => 'what happened next',
	'hti_rev_context_pt'       => 'the Portuguese aftermath',
	'hti_rev_lesson_en'        => 'the lesson',
	'hti_rev_lesson_pt'        => 'the Portuguese lesson',
) as $field => $what ) {
	$holed = hti_case_illustrative( array( $field => '' ) );
	hti_games_check(
		! Case_Admin::publishable( $holed, $now ) && in_array( $field, Case_Admin::missing( $holed, $now ), true ),
		"an illustrative case missing {$what} is refused, and told which field"
	);
}
hti_games_check( ! Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_fundamentals' => hti_case_fundamentals( 5 ) ) ), $now ), 'five of six fundamentals is a blank line in the dossier, so it is refused' );
hti_games_check( ! Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_headlines' => hti_case_headlines( 2 ) ) ), $now ), 'and a headline written in English only is refused too — there is no locale fallback' );
hti_games_check( ! Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_fundamentals' => '' ) ), $now ), 'an unwritten fundamentals table is refused' );
hti_games_check( ! Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_return_5y_bp' => '' ) ), $now ), 'both returns and the five-year age still apply on this path' );
hti_games_check( ! Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_year' => (string) ( $year + 1 ) ) ), $now ), 'and so does the minimum age' );

// A verified case is NOT judged on the dossier. The gate deliberately says
// nothing about an empty fundamentals table there, because the document is
// what holds that case up; the checklist still nags about it.
hti_games_check( Case_Admin::publishable( hti_case( array( 'hti_rev_fundamentals' => '' ) ), $now ), 'a verified case with an empty dossier still publishes — its evidence is the document, and the missing dossier is the checklist\'s business' );

echo "\nThe default is the strict path, and the strict path is the safe one\n";
hti_games_check( 'verified' === Case_Admin::provenance( array() ), 'a case that says nothing is a verified case' );
hti_games_check( 'verified' === Case_Admin::provenance( array( 'hti_rev_provenance' => '' ) ), 'so is one with an empty provenance' );
hti_games_check( 'verified' === Case_Admin::provenance( array( 'hti_rev_provenance' => 'Illustrative' ) ), 'so is one whose value is nearly right — the match is exact, and the near miss fails closed' );
hti_games_check( 'verified' === Case_Admin::provenance( array( 'hti_rev_provenance' => 'anything else' ) ), 'and so is one carrying nonsense' );
hti_games_check( 'illustrative' === Case_Admin::provenance( array( 'hti_rev_provenance' => 'illustrative' ) ), 'only the literal value opens the other path' );
hti_games_check( 'verified' === \HTI\Games\CPT::san_provenance( '' ), 'the sanitizer defaults the same way, so an unset field stores as verified rather than as blank' );
hti_games_check( 'illustrative' === \HTI\Games\CPT::san_provenance( 'illustrative' ), 'and stores the illustrative claim when it is made' );

$silent = hti_case_illustrative();
unset( $silent['hti_rev_provenance'] );
hti_games_check( ! Case_Admin::publishable( $silent, $now ), 'a complete dossier that does not claim to be illustrative is refused for want of a source: a default that failed open is how a gate stops being a gate' );

echo "\nPromoting a case to verified puts it behind the verified gate\n";
$promoted = hti_case_illustrative( array( 'hti_rev_provenance' => 'verified' ) );
hti_games_check( ! Case_Admin::publishable( $promoted, $now ), 'the same case, relabelled verified, is refused' );
hti_games_check( in_array( 'hti_rev_source_url', Case_Admin::missing( $promoted, $now ), true ), 'and is asked for the document it now claims' );
hti_games_check( in_array( 'hti_rev_verified', Case_Admin::missing( $promoted, $now ), true ), 'and for the tick' );
hti_games_check(
	Case_Admin::publishable( hti_case_illustrative( array( 'hti_rev_provenance' => 'verified', 'hti_rev_source_url' => 'https://www.sec.gov/some-filing', 'hti_rev_verified' => '1' ) ), $now ),
	'with both supplied it publishes again — promotion is a change of claim, not a trapdoor'
);
hti_games_check(
	array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp', 'hti_rev_year' ) === Case_Admin::VERIFIED_FIELDS,
	'and the decay rule still watches the same three numbers whichever path the case took'
);
hti_games_check(
	! Case_Admin::clears_verification( hti_case_illustrative(), hti_case_illustrative( array( 'hti_rev_provenance' => 'verified' ) ) ),
	'changing the provenance alone does not clear a verification: the tick is a statement about the numbers'
);

echo "\nThe gate and the pool query agree on what 'verified' means\n";
hti_games_check( in_array( 'hti_rev_verified', array_keys( \HTI\Games\CPT::case_meta() ), true ), 'hti_rev_verified is a registered meta key' );
hti_games_check( '1' === \HTI\Games\CPT::san_bool( '1' ) && '0' === \HTI\Games\CPT::san_bool( '' ), 'and it is stored as the literal 1/0 the meta_query compares against' );
hti_games_check( array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp', 'hti_rev_year' ) === Case_Admin::VERIFIED_FIELDS, 'the three fields verification is about are the three the gate checks' );
hti_games_check( in_array( 'hti_rev_provenance', array_keys( \HTI\Games\CPT::case_meta() ), true ), 'hti_rev_provenance is a registered meta key, so it is sanitized on every write' );
$library = (string) file_get_contents( __DIR__ . '/../includes/class-library.php' );
hti_games_check(
	str_contains( $library, "'hti_rev_provenance'" ) && str_contains( $library, "'illustrative'" ),
	'and the pool query knows about it too — the query duplicates the gate on purpose, so a gate with two branches needs a query with two'
);

hti_games_done();
