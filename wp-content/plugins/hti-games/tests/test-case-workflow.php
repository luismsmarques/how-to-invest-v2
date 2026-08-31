<?php
/**
 * The editorial workflow around a Reveal case: the checklist, the queue and
 * the preview.
 *
 * tests/test-case-gate.php holds the gate honest — may this be published? This
 * file holds the DOOR honest: an editor has to be able to see what is missing
 * before they try, an editorial lead has to be able to see which case is
 * closest to launchable, and the preview has to show what a player would
 * actually be served rather than a second opinion about it.
 *
 * Three things here are guarding real mistakes rather than style.
 *
 * First, the checklist must never disagree with the gate. It is derived from
 * missing() and this file proves the derivation: a case is publishable exactly
 * when no blocking row is open, and every field missing() can name has wording
 * an editor can act on.
 *
 * Second, the checklist has to change shape with the claim the case makes. An
 * illustrative case has no document, so a "source URL" row reading DONE beside
 * an empty box — or reading OPEN when nothing is waiting on it — would teach an
 * editor that this screen does not mean anything. What it gets instead is the
 * dossier as blocking rows and one optional row describing what promoting the
 * case to verified would take.
 *
 * Third, the awkward values again. A five-year return of exactly zero is a
 * real answer and reads as done; a fundamentals row with no key looks filled in
 * the editor and is DROPPED by REST::fundamentals() on the way to the player,
 * so it must not count as done here either.
 *
 * Fourth, the two renderers. The player's dossier is painted by reveal.js into
 * the empty shell Frontend::shell_reveal() prints; the admin preview paints the
 * same dossier in PHP, because there is no server-side renderer of a filled
 * dossier to call and building one would mean putting the answer's neighbours
 * in the HTML of a cacheable page. That is a real duplication, so it is pinned:
 * every class name and every tint mark the preview emits has to be one the
 * front end actually uses, and every Strings key it reads has to exist.
 *
 *   php wp-content/plugins/hti-games/tests/test-case-workflow.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-case-admin.php';

use HTI\Games\Case_Admin;
use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\Strings;

/**
 * A fixed "now", so a passing suite does not start failing on New Year's Day.
 */
$now = (int) strtotime( '2026-08-30 12:00:00 UTC' );

/**
 * Six complete fundamentals rows.
 *
 * @param int $rows How many of the six to fill.
 * @return string JSON.
 */
function hti_wf_fundamentals( int $rows = 6 ): string {
	$out = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$full  = $i < $rows;
		$out[] = array(
			'key'           => 'row_' . $i,
			'label_en'      => 'Operating margin',
			'label_pt'      => 'Margem operacional',
			'value_en'      => $full ? '4%' : '',
			'value_pt'      => $full ? '4%' : '',
			'sector_avg_en' => $full ? '9%' : '',
			'sector_avg_pt' => $full ? '9%' : '',
			'tint'          => 'warn',
		);
	}

	return (string) wp_json_encode( $out );
}

/**
 * Three complete headlines.
 *
 * @param int $rows How many of the three to fill in both languages.
 * @return string JSON.
 */
function hti_wf_headlines( int $rows = 3 ): string {
	$out = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$out[] = array(
			'en' => 'A headline from the year',
			'pt' => $i < $rows ? 'Uma manchete do ano' : '',
		);
	}

	return (string) wp_json_encode( $out );
}

/**
 * A case with nothing left to do, with fields overridden.
 *
 * @param array<string,mixed> $override Fields to replace.
 * @return array<string,mixed>
 */
function hti_wf_case( array $override = array() ): array {
	return array_merge(
		array(
			'hti_rev_company'            => 'Kodak',
			'hti_rev_year'               => '2011',
			'hti_rev_sector_en'          => 'Photography',
			'hti_rev_sector_pt'          => 'Fotografia',
			'hti_rev_revenue_band_en'    => '$1bn–$5bn',
			'hti_rev_revenue_band_pt'    => '1–5 mil milhões de dólares',
			'hti_rev_fundamentals'       => hti_wf_fundamentals(),
			'hti_rev_headlines'          => hti_wf_headlines(),
			'hti_rev_return_5y_bp'       => '-9700',
			'hti_rev_index_return_5y_bp' => '8300',
			'hti_rev_context_en'         => 'What happened next.',
			'hti_rev_context_pt'         => 'O que aconteceu a seguir.',
			'hti_rev_lesson_en'          => 'The lesson.',
			'hti_rev_lesson_pt'          => 'A lição.',
			'hti_rev_source_url'         => 'https://www.sec.gov/some-filing',
			'hti_rev_source_label'       => 'Annual report',
			'hti_rev_source_accessed'    => '2026-08-30',
			'hti_rev_verified'           => '1',
		),
		$override
	);
}

/**
 * A finished ILLUSTRATIVE case: whole dossier, no document, no tick.
 *
 * @param array<string,mixed> $override Fields to replace.
 * @return array<string,mixed>
 */
function hti_wf_illustrative( array $override = array() ): array {
	return hti_wf_case(
		array_merge(
			array(
				'hti_rev_provenance'      => 'illustrative',
				'hti_rev_source_url'      => '',
				'hti_rev_source_label'    => '',
				'hti_rev_source_accessed' => '',
				'hti_rev_verified'        => '0',
			),
			$override
		)
	);
}

/**
 * One checklist row by key.
 *
 * @param array<int,array<string,mixed>> $list Checklist.
 * @param string                         $key  Row key.
 * @return array<string,mixed>
 */
function hti_wf_row( array $list, string $key ): array {
	foreach ( $list as $row ) {
		if ( $key === $row['key'] ) {
			return $row;
		}
	}

	return array();
}

/* -------------------------------------------------------------------------
 * 1. The checklist says the same thing the gate does
 * ---------------------------------------------------------------------- */

echo "A finished case has nothing left on it\n";
$full = Case_Admin::checklist( hti_wf_case(), $now );
$prog = Case_Admin::progress( $full );
hti_games_check( 0 === $prog['todo'], 'every row of a complete case is done' );
hti_games_check( 0 === $prog['blocking'], 'and nothing blocks a publish' );
hti_games_check( Case_Admin::publishable( hti_wf_case(), $now ), 'which is what the gate says too' );
hti_games_check( $prog['total'] === $prog['done'], 'the tally adds up' );

echo "\nAn empty case reports every requirement, not the first one\n";
$empty = Case_Admin::checklist( array(), $now );
$prog  = Case_Admin::progress( $empty );
hti_games_check( count( $empty ) === $prog['todo'], 'nothing on a hollow case is done' );
hti_games_check( 4 === $prog['blocking'], 'and all four blocking requirements are named at once' );

echo "\nThe blocking half IS missing(), never a second opinion\n";
$cases = array(
	'hti_rev_source_url'         => 'source',
	'hti_rev_verified'           => 'verified',
	'hti_rev_year'               => 'year',
	'hti_rev_return_5y_bp'       => 'returns',
	'hti_rev_index_return_5y_bp' => 'returns',
);
foreach ( $cases as $field => $row_key ) {
	$meta = hti_wf_case( array( $field => '' ) );
	$row  = hti_wf_row( Case_Admin::checklist( $meta, $now ), $row_key );
	hti_games_check(
		! $row['done'] && in_array( $field, Case_Admin::missing( $meta, $now ), true ),
		"emptying {$field} opens the '{$row_key}' row and is reported by the gate"
	);
}

$agree = true;
foreach ( array( array(), hti_wf_case(), hti_wf_case( array( 'hti_rev_verified' => '0' ) ), hti_wf_case( array( 'hti_rev_year' => '2025' ) ) ) as $meta ) {
	$blocking = Case_Admin::progress( Case_Admin::checklist( $meta, $now ) )['blocking'];
	$agree    = $agree && ( 0 === $blocking ) === Case_Admin::publishable( $meta, $now );
}
hti_games_check( $agree, 'a case is publishable exactly when no blocking row is open — the screen cannot promise what the gate refuses' );

echo "\nOnly one of the two returns is not both of them\n";
$one = Case_Admin::checklist( hti_wf_case( array( 'hti_rev_index_return_5y_bp' => '' ) ), $now );
$row = hti_wf_row( $one, 'returns' );
hti_games_check( 1 === $row['have'] && 2 === $row['need'], 'the returns row counts one of two' );
hti_games_check( ! $row['done'], 'and is not done' );

$zero = hti_wf_row( Case_Admin::checklist( hti_wf_case( array( 'hti_rev_return_5y_bp' => '0' ) ), $now ), 'returns' );
hti_games_check( $zero['done'], 'a return of exactly 0 bp reads as done: flat for five years is an answer, and an empty() test would have eaten it' );

echo "\nEvery row an editor can be shown has wording an editor can act on\n";
$labels     = Case_Admin::checklist_labels();
$unlabelled = array();
foreach ( Case_Admin::checklist( array(), $now ) as $row ) {
	$pair = $labels[ $row['key'] ] ?? array();
	if ( 2 !== count( $pair ) || '' === trim( (string) ( $pair[0] ?? '' ) ) || '' === trim( (string) ( $pair[1] ?? '' ) ) ) {
		$unlabelled[] = (string) $row['key'];
	}
}
hti_games_check( array() === $unlabelled, 'each row says what it is AND what done looks like (' . ( $unlabelled ? implode( ', ', $unlabelled ) : 'all worded' ) . ')' );
hti_games_check(
	false !== stripos( $labels['verified'][1], 'withdraw' ) || false !== stripos( $labels['verified'][1], 'again' ),
	'and the verification row warns that editing a number takes the tick back'
);

echo "\nAn illustrative case gets a different checklist, because it is a different claim\n";
$illus = Case_Admin::checklist( hti_wf_illustrative(), $now );
$prog  = Case_Admin::progress( $illus );
hti_games_check( 0 === $prog['blocking'], 'a complete illustrative case has nothing blocking' );
hti_games_check( Case_Admin::publishable( hti_wf_illustrative(), $now ), 'which is what the gate says too' );
hti_games_check( array() === hti_wf_row( $illus, 'source' ), 'there is no source row: no document is being claimed, and a row that said "done" beside an empty box would be a lie' );
hti_games_check( array() === hti_wf_row( $illus, 'verified' ), 'and no verification row either' );

$promote = hti_wf_row( $illus, 'promote' );
hti_games_check( array() !== $promote && empty( $promote['blocking'] ), 'instead there is an optional row for promoting the case to verified' );
hti_games_check( 0 === $promote['have'] && 2 === $promote['need'], 'open at nought of two, which is what promoting would cost' );
hti_games_check(
	1 === hti_wf_row( Case_Admin::checklist( hti_wf_illustrative( array( 'hti_rev_source_url' => 'https://example.org/a' ) ), $now ), 'promote' )['have'],
	'and it counts a source URL pasted in advance, so the upgrade path is visibly half done rather than invisible'
);

$hollow_illus = Case_Admin::checklist( hti_wf_illustrative( array(
	'hti_rev_revenue_band_pt' => '',
	'hti_rev_context_pt'      => '',
	'hti_rev_fundamentals'    => hti_wf_fundamentals( 4 ),
	'hti_rev_headlines'       => hti_wf_headlines( 1 ),
) ), $now );
$prog = Case_Admin::progress( $hollow_illus );
hti_games_check( 4 === $prog['blocking'], 'and a half-written dossier blocks publication on all four of its rows at once' );
foreach ( array( 'dossier', 'fundamentals', 'headlines', 'aftermath' ) as $key ) {
	$row = hti_wf_row( $hollow_illus, $key );
	hti_games_check( ! empty( $row['blocking'] ), "the '{$key}' row blocks on an illustrative case" );
	hti_games_check( empty( hti_wf_row( Case_Admin::checklist( hti_wf_case(), $now ), $key )['blocking'] ), "and does not on a verified one, where the document is the evidence" );
}

$agree = true;
foreach ( array(
	hti_wf_illustrative(),
	hti_wf_illustrative( array( 'hti_rev_lesson_pt' => '' ) ),
	hti_wf_illustrative( array( 'hti_rev_headlines' => hti_wf_headlines( 2 ) ) ),
	hti_wf_illustrative( array( 'hti_rev_year' => '2025' ) ),
	hti_wf_illustrative( array( 'hti_rev_provenance' => 'verified' ) ),
) as $meta ) {
	$blocking = Case_Admin::progress( Case_Admin::checklist( $meta, $now ) )['blocking'];
	$agree    = $agree && ( 0 === $blocking ) === Case_Admin::publishable( $meta, $now );
}
hti_games_check( $agree, 'on this path too, a case is publishable exactly when no blocking row is open' );

$unworded = array();
foreach ( array_merge( Case_Admin::checklist( hti_wf_illustrative(), $now ), Case_Admin::checklist( array(), $now ) ) as $row ) {
	if ( ! array_key_exists( (string) $row['key'], Case_Admin::checklist_labels() ) ) {
		$unworded[] = (string) $row['key'];
	}
}
hti_games_check( array() === $unworded, 'every row either checklist can show has wording (' . ( $unworded ? implode( ', ', $unworded ) : 'all worded' ) . ')' );

$queue_illus = Case_Admin::queue_row( 5, 'Illustrative', 'draft', 'Photography', hti_wf_illustrative(), $now );
hti_games_check( $queue_illus['publishable'] && array() === $queue_illus['open_blocking'], 'the queue reports a finished illustrative case as ready rather than as missing a source' );
hti_games_check( in_array( 'promote', $queue_illus['open'], true ), 'and still lists the optional promotion, so the upgrade path is visible to the lead as well' );

/* -------------------------------------------------------------------------
 * 2. The dossier half: what counts as a finished row
 * ---------------------------------------------------------------------- */

echo "\nA fundamentals row counts only when the player would actually see it\n";
hti_games_check( 6 === Case_Admin::fundamentals_complete( hti_wf_fundamentals( 6 ) ), 'six filled rows count as six' );
hti_games_check( 3 === Case_Admin::fundamentals_complete( hti_wf_fundamentals( 3 ) ), 'three filled rows count as three' );
hti_games_check( 0 === Case_Admin::fundamentals_complete( '' ), 'an unwritten table counts as none' );
hti_games_check( 0 === Case_Admin::fundamentals_complete( 'not json' ), 'and so does a broken one, rather than warning' );

$keyless = json_decode( hti_wf_fundamentals( 6 ), true );
$keyless[0]['key'] = '';
hti_games_check(
	5 === Case_Admin::fundamentals_complete( (string) wp_json_encode( $keyless ) ),
	'a row with no key counts as unfinished, because REST::fundamentals() drops it on the way to the player'
);

$half = json_decode( hti_wf_fundamentals( 6 ), true );
$half[1]['value_pt'] = '';
hti_games_check(
	5 === Case_Admin::fundamentals_complete( (string) wp_json_encode( $half ) ),
	'and so does one written in English only — the site has no locale fallback to save it'
);

echo "\nA headline counts only in both languages\n";
hti_games_check( 3 === Case_Admin::headlines_complete( hti_wf_headlines( 3 ) ), 'three bilingual headlines count as three' );
hti_games_check( 1 === Case_Admin::headlines_complete( hti_wf_headlines( 1 ) ), 'an English-only headline does not count' );
hti_games_check( 0 === Case_Admin::headlines_complete( '[]' ), 'an empty list counts as none' );

hti_games_check( 2 === Case_Admin::filled( array( 'a' => 'x', 'b' => ' ', 'c' => '0' ), array( 'a', 'b', 'c' ) ), 'whitespace is not a filled field, and "0" is' );

/* -------------------------------------------------------------------------
 * 3. Verification still decays, and the consequence is visible
 * ---------------------------------------------------------------------- */

echo "\nEditing a verified number un-verifies the case, checklist and all\n";
$stored   = hti_wf_case();
$incoming = hti_wf_case( array( 'hti_rev_return_5y_bp' => '-9600' ) );
hti_games_check( Case_Admin::clears_verification( $stored, $incoming ), 'changing the five-year return still clears the tick' );

// What gate() does in one request: merge, decay, then judge. The point of
// doing it here is that the editor is told the case became unpublishable by
// the same edit that made it so, rather than a page load later.
$merged = array_merge( $stored, $incoming );
if ( Case_Admin::clears_verification( $stored, $merged ) ) {
	$merged['hti_rev_verified'] = '0';
}
hti_games_check( ! Case_Admin::publishable( $merged, $now ), 'and the same save that changes it makes the case unpublishable again' );
$after = hti_wf_row( Case_Admin::checklist( $merged, $now ), 'verified' );
hti_games_check( ! $after['done'], 'the checklist shows the verification row open again' );

$safe = array_merge( $stored, hti_wf_case( array( 'hti_rev_company' => 'Nokia' ) ) );
hti_games_check( Case_Admin::publishable( $safe, $now ), 'renaming the company does not withdraw anything — it is not what was checked' );
hti_games_check(
	array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp', 'hti_rev_year' ) === Case_Admin::VERIFIED_FIELDS,
	'and the three fields the block names are the three the decay rule watches'
);

/* -------------------------------------------------------------------------
 * 4. The queue: closest to launchable first
 * ---------------------------------------------------------------------- */

echo "\nThe queue puts the case that is one field away at the top\n";
$rows = array(
	Case_Admin::queue_row( 3, 'Hollow', 'draft', 'Photography', array(), $now ),
	Case_Admin::queue_row( 1, 'Ready', 'draft', 'Beverages', hti_wf_case(), $now ),
	Case_Admin::queue_row( 2, 'One field away', 'draft', 'Energy', hti_wf_case( array( 'hti_rev_verified' => '0' ) ), $now ),
);
$sorted = Case_Admin::sort_queue( $rows );
hti_games_check( 'Ready' === $sorted[0]['title'], 'the publishable draft is first — it is the one click that grows the pool' );
hti_games_check( 'One field away' === $sorted[1]['title'], 'then the one missing a single field' );
hti_games_check( 'Hollow' === $sorted[2]['title'], 'and the empty one last' );
hti_games_check( $sorted[0]['publishable'] && ! $sorted[0]['live'], 'a publishable draft is not live: publishing is still a decision somebody takes' );
hti_games_check( Case_Admin::queue_row( 4, 'Served', 'publish', '', hti_wf_case(), $now )['live'], 'a published, verified case is live and waits on nobody' );

echo "\nAnd it does not reshuffle itself between page loads\n";
$tie = array(
	Case_Admin::queue_row( 9, 'beta', 'draft', '', array(), $now ),
	Case_Admin::queue_row( 7, 'Alpha', 'draft', '', array(), $now ),
	Case_Admin::queue_row( 8, 'alpha', 'draft', '', array(), $now ),
);
$order = array_column( Case_Admin::sort_queue( $tie ), 'id' );
hti_games_check( array( 7, 8, 9 ) === $order, 'equal cases sort by title case-insensitively, then by id (' . implode( ',', $order ) . ')' );
hti_games_check( $order === array_column( Case_Admin::sort_queue( array_reverse( $tie ) ), 'id' ), 'and the input order does not change the answer' );

echo "\nThe queue can say what is missing in words\n";
$hollow = Case_Admin::queue_row( 3, 'Hollow', 'draft', '', array(), $now );
$unworded = array_diff( $hollow['open'], array_keys( Case_Admin::checklist_labels() ) );
hti_games_check( array() === $unworded, 'every open row it reports has wording (' . ( $unworded ? implode( ', ', $unworded ) : 'all worded' ) . ')' );
hti_games_check( 4 === count( $hollow['open_blocking'] ), 'the blocking rows are separated from the rest, so the column leads with what stops a publish' );
hti_games_check( count( $hollow['open'] ) > count( $hollow['open_blocking'] ), 'and the rest is still counted rather than hidden' );
hti_games_check( array() === Case_Admin::queue_row( 1, 'Ready', 'draft', '', hti_wf_case(), $now )['open'], 'a finished case reports nothing open' );

/* -------------------------------------------------------------------------
 * 5. The guards around another workstream's fields
 * ---------------------------------------------------------------------- */

echo "\nA field another workstream owns is tolerated, present or absent\n";
hti_games_check( '' === Case_Admin::optional_key( Case_Admin::BRIEF_KEYS, array( 'hti_rev_company' ) ), 'an unregistered brief key resolves to nothing rather than to a guess' );
hti_games_check( 'hti_rev_brief' === Case_Admin::optional_key( Case_Admin::BRIEF_KEYS, array( 'hti_rev_company', 'hti_rev_brief' ) ), 'and a registered one resolves to itself' );
hti_games_check(
	'hti_rev_brief' === Case_Admin::optional_key( array( 'hti_rev_brief', 'hti_rev_notes' ), array( 'hti_rev_notes', 'hti_rev_brief' ) ),
	'the candidate list decides the winner, not the order the registry happens to be in'
);

$live_brief = Case_Admin::optional_key( Case_Admin::BRIEF_KEYS );
hti_games_check(
	'' === $live_brief || array_key_exists( $live_brief, CPT::case_meta() ),
	'whatever it resolves to today is a key the registry of record actually declares (' . ( '' !== $live_brief ? $live_brief : 'not landed yet' ) . ')'
);

hti_games_check( 'Photography' === Case_Admin::pattern_of( array( 'hti_rev_sector_en' => 'Photography' ), array() ), 'with no pattern field, the queue shows the sector' );
hti_games_check(
	'unknown_shape' === Case_Admin::pattern_of( array( 'hti_rev_pattern' => 'unknown_shape', 'hti_rev_sector_en' => 'Photography' ), array( 'hti_rev_pattern' ) ),
	'a pattern the lesson library does not name falls back to its own id, never to a blank'
);
hti_games_check( '' === Case_Admin::pattern_of( array(), array() ), 'and a case with neither shows nothing rather than a guess' );

// With the lesson library present — which is how the queue actually runs — the
// id becomes the sentence the library already words, in one place, for both
// the queue and the lesson a player is shown.
require_once __DIR__ . '/../includes/class-reveal-lessons.php';
$known = array_key_first( \HTI\Games\Reveal_Lessons::patterns() );
hti_games_check(
	Case_Admin::pattern_of( array( 'hti_rev_pattern' => $known ), array( 'hti_rev_pattern' ) ) === \HTI\Games\Reveal_Lessons::patterns()[ $known ]['en'],
	"a known pattern is shown by its name, not its slug ({$known})"
);

/* -------------------------------------------------------------------------
 * 6. The preview cannot drift away from the game
 * ---------------------------------------------------------------------- */

$admin    = (string) file_get_contents( __DIR__ . '/../includes/class-case-admin.php' );
$frontend = (string) file_get_contents( __DIR__ . '/../includes/class-frontend.php' );
$js       = (string) file_get_contents( __DIR__ . '/../assets/js/reveal.js' );
$css      = (string) file_get_contents( __DIR__ . '/../assets/css/reveal.css' ) . (string) file_get_contents( __DIR__ . '/../assets/css/games.css' );

preg_match( '/function render_preview_dossier\(.*?\n\t\}/s', $admin, $found );
$preview = $found[0] ?? '';

echo "\nThe preview is built from the payload the player is served\n";
hti_games_check( '' !== $preview, 'the preview renderer is readable' );
hti_games_check(
	str_contains( $preview, 'REST::public_challenge_reveal(' ),
	'it reads the same whitelisted payload /today is built from, so what it shows is what survives the boundary'
);
hti_games_check(
	! str_contains( $preview, 'hti_rev_fundamentals' ) && ! str_contains( $preview, 'hti_rev_sector' ),
	'and never reaches around it into the raw meta'
);

echo "\nAnd every class name it paints is one the game actually uses\n";
preg_match_all( '/(hti-rv__[a-z]+|hti-g__[a-z]+)/', $preview, $classes );
$names   = array_values( array_unique( $classes[1] ) );
$strays  = array();
foreach ( $names as $name ) {
	if ( ! str_contains( $frontend, $name ) && ! str_contains( $js, $name ) && ! str_contains( $css, $name ) ) {
		$strays[] = $name;
	}
}
hti_games_check( count( $names ) >= 10, sprintf( 'the dossier is drawn with %d of the front end\'s own classes', count( $names ) ) );
hti_games_check( array() === $strays, 'none of them is invented here (' . ( $strays ? implode( ', ', $strays ) : 'all shared' ) . ')' );

echo "\nThe three tints mean the same thing on both screens\n";
preg_match( '/var marks = \{([^}]*)\}/', $js, $js_marks );
$marks = $js_marks[1] ?? '';
$mismatch = array();
foreach ( array( 'good' => '&#10003;', 'warn' => '~', 'bad' => '!' ) as $tint => $entity ) {
	$mark = html_entity_decode( $entity, ENT_QUOTES, 'UTF-8' );
	if ( ! str_contains( $preview, $entity ) || ! str_contains( $marks, "'" . $mark . "'" ) ) {
		$mismatch[] = $tint;
	}
	if ( ! str_contains( $css, 'is-' . $tint ) ) {
		$mismatch[] = $tint . ' (css)';
	}
}
hti_games_check( array() === $mismatch, 'the mark beside a figure is the same character reveal.js draws, and the row class is one reveal.css styles (' . ( $mismatch ? implode( ', ', $mismatch ) : 'good, warn, bad' ) . ')' );
hti_games_check( CPT::TINTS === array( 'good', 'warn', 'bad' ), 'and those are the three the sanitizer allows in the first place' );

echo "\nThe preview speaks the player's own copy, in both languages\n";
preg_match_all( "/Strings::get\(\s*'([a-z0-9_]+)'/", $admin, $keys );
$all     = Strings::all();
$read    = array_values( array_unique( $keys[1] ) );
$unknown = array();
foreach ( $read as $key ) {
	if ( isset( $all[ $key ] ) ) {
		continue;
	}
	// One key is assembled rather than written: 'rev_tint_' . $tint, exactly
	// as reveal.js assembles it. That is legitimate because the vocabulary is
	// closed and validated (CPT::TINTS), so the prefix is expanded over it and
	// every member has to exist.
	$expanded = true;
	foreach ( CPT::TINTS as $tint ) {
		$expanded = $expanded && isset( $all[ $key . $tint ] );
	}
	if ( ! $expanded ) {
		$unknown[] = $key;
	}
}
hti_games_check( count( $read ) >= 8, sprintf( 'it reads %d strings out of the bilingual table', count( $read ) ) );
hti_games_check( array() === $unknown, 'and every one of them exists, tint prefixes expanded (' . ( $unknown ? implode( ', ', $unknown ) : 'all known' ) . ')' );
hti_games_check(
	str_contains( $js, "'rev_tint_' + row.tint" ),
	'the one assembled key is assembled the same way on the player\'s screen, so both read the same three sentences'
);

/* -------------------------------------------------------------------------
 * 7. The admin screens stay in the admin
 * ---------------------------------------------------------------------- */

echo "\nNone of this reaches the front end\n";
hti_games_check( ! str_contains( $admin, 'wp_enqueue_scripts' ), 'the workflow enqueues nothing on the public site' );
hti_games_check( str_contains( $admin, "add_action( 'admin_enqueue_scripts'" ), 'its sheet is hooked to the admin enqueue only' );
hti_games_check( ! str_contains( $frontend, 'admin-case.css' ), 'and the front end has never heard of the admin sheet' );
hti_games_check( ! str_contains( $admin, '.js' ) || ! preg_match( '/wp_enqueue_script\(/', $admin ), 'the workflow ships no JavaScript at all — every screen here is HTML and CSS' );

$budget = (string) file_get_contents( __DIR__ . '/test-asset-budget.php' );
hti_games_check( ! str_contains( $budget, 'admin-case' ), 'so it is correctly absent from the front-end budget' );

echo "\nThe preview is a read of a private post type, guarded like one\n";
hti_games_check( str_contains( $admin, "current_user_can( 'edit_post', \$id )" ), 'the per-case capability is checked before anything is shown' );
hti_games_check( substr_count( $admin, "current_user_can( 'edit_posts' )" ) >= 2, 'and the screens themselves are closed to anybody who cannot edit' );
hti_games_check( str_contains( $admin, 'Config::CPT_CASE !== $post->post_type' ), 'a post id of another type is refused rather than rendered' );
hti_games_check( str_contains( $admin, 'absint( wp_unslash( $_GET[' ), 'the case id off the URL is cast before it is used' );

/* -------------------------------------------------------------------------
 * 8. The panels render, and say the right thing in the right language
 *
 * Seven shims and a reflection call are enough to run the four screens this
 * feature adds. Worth the shims: php -l cannot see a printf with one argument
 * too few, and a checklist that fatals is a case editor nobody can open.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * The day handle is an HMAC under the site salt.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}

	/**
	 * Escaping and translation shims the admin renderers call.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_html__( $text, $domain = '' ) {
		return esc_html( $text );
	}

	/**
	 * Attribute-escaped translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_attr__( $text, $domain = '' ) {
		return esc_attr( $text );
	}

	/**
	 * Plural form.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 */
	function _n( $single, $plural, $number, $domain = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		return 1 === (int) $number ? $single : $plural;
	}

	/**
	 * Checkbox state.
	 *
	 * @param mixed $checked Value.
	 * @param mixed $current Comparison.
	 * @param bool  $echo    Whether to print.
	 */
	function checked( $checked, $current = true, $echo = true ) {
		$out = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $echo ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal.
		}
		return $out;
	}

	/**
	 * URL escaping.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Paragraph wrapping.
	 *
	 * @param string $text Text.
	 */
	function wpautop( $text ) {
		return '<p>' . str_replace( "\n\n", '</p><p>', (string) $text ) . '</p>';
	}

	/**
	 * Post-content filtering.
	 *
	 * @param string $text Text.
	 */
	function wp_kses_post( $text ) {
		return (string) $text;
	}

	/**
	 * Admin URL.
	 *
	 * @param string $path Path.
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}

	/**
	 * Query-argument builder.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @param string              $url  Base URL.
	 */
	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( (array) $args );
	}
}

/**
 * Render one of the private panels and hand back its markup.
 *
 * @param string       $method Method name.
 * @param array<mixed> $args   Arguments.
 */
function hti_wf_render( string $method, array $args ): string {
	$call = new ReflectionMethod( Case_Admin::class, $method );
	$call->setAccessible( true );
	ob_start();
	$call->invokeArgs( null, $args );

	return (string) ob_get_clean();
}

require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-rest.php';

echo "\nThe checklist renders, and names what is missing\n";
$hollow_html = hti_wf_render( 'render_checklist', array( array(), 0 ) );
$ready_html  = hti_wf_render( 'render_checklist', array( hti_wf_case(), 11 ) );

hti_games_check( str_contains( $hollow_html, 'Not publishable yet' ), 'a hollow case says so at the top, before anybody presses Publish' );
hti_games_check( str_contains( $hollow_html, 'The source URL' ), 'and names the source URL as one of the reasons' );
hti_games_check( str_contains( $ready_html, 'Nothing blocks publication' ), 'a finished case says the opposite' );
hti_games_check(
	str_contains( $hollow_html, 'screen-reader-text' ) && str_contains( $hollow_html, 'still to do' ),
	'done and not-done are words as well as a mark, never a colour alone'
);
hti_games_check( str_contains( $ready_html, 'case=11&amp;lang=pt' ), 'and a saved case offers the Portuguese preview, which is the half nobody checks' );

echo "\nThe verification block explains itself\n";
$verified_html = hti_wf_render( 'render_verification', array( hti_wf_case( array( 'hti_rev_verified_by' => 'ana', 'hti_rev_verified_at' => '2026-08-30 10:00:00' ) ) ) );
$open_html     = hti_wf_render( 'render_verification', array( hti_wf_case( array( 'hti_rev_verified' => '0' ) ) ) );

hti_games_check( str_contains( $verified_html, 'Verified by ana on 2026-08-30 10:00:00 UTC' ), 'it says who verified it and when' );
hti_games_check( str_contains( $verified_html, '-9700' ) && str_contains( $verified_html, '8300' ) && str_contains( $verified_html, '2011' ), 'and shows the three numbers the tick is a statement about' );
hti_games_check( str_contains( $verified_html, 'withdrawn' ), 'it warns that editing one of them takes the tick back' );
hti_games_check( str_contains( $open_html, 'Not verified' ), 'an unverified case says so rather than showing an empty box' );

echo "\nThe preview shows the dossier and withholds the answer\n";
$dossier = hti_wf_render( 'render_preview_dossier', array( hti_wf_case(), 'pt' ) );
$answer  = hti_wf_render( 'render_preview_answer', array( hti_wf_case(), 'pt' ) );

// The day handle is a hex digest and could contain any run of digits by
// chance, so it is cut before the dossier is searched for numbers.
$body = (string) preg_replace( '/<p class="hti-g__kicker[^>]*>.*?<\/p>/', '', $dossier );

hti_games_check( str_contains( $dossier, 'Empresa por identificar' ), 'a Portuguese preview is Portuguese — the half that renders in English on a live site without anybody noticing' );
hti_games_check( str_contains( $dossier, 'Fotografia' ) && str_contains( $dossier, 'Margem operacional' ), 'the sector and the fundamentals are the Portuguese ones too' );
hti_games_check(
	! str_contains( $body, 'Kodak' ) && ! str_contains( $body, '-9700' ) && ! str_contains( $body, 'sec.gov' ),
	'and the company, the return and the source are not in the dossier at all — the preview inherits the payload boundary rather than working around it'
);
hti_games_check( str_contains( $answer, 'Kodak' ) && str_contains( $answer, '-9700 bp' ), 'the answer half does carry them, which is what the editor is checking' );
hti_games_check( str_contains( $answer, '(-97.00%)' ), 'basis points are shown as the percentage they mean, next to the figure that was typed' );

echo "\nThe preview says which of the two things the player would be reading\n";
$illus_answer    = hti_wf_render( 'render_preview_answer', array( hti_wf_illustrative(), 'en' ) );
$verified_answer = hti_wf_render( 'render_preview_answer', array( hti_wf_case(), 'en' ) );

hti_games_check( str_contains( $illus_answer, Strings::get( 'rev_illustrative', 'en' ) ), 'an illustrative case shows the sentence the player is shown, word for word' );
hti_games_check( ! str_contains( $illus_answer, 'sec.gov' ), 'and no source, because there is none to credit' );
hti_games_check( str_contains( $verified_answer, 'sec.gov' ) || str_contains( $verified_answer, 'Annual report' ), 'a verified case still credits its document' );
hti_games_check( ! str_contains( $verified_answer, Strings::get( 'rev_illustrative', 'en' ) ), 'and never claims to be a reconstruction — one line or the other, never both' );

$illus_pt = hti_wf_render( 'render_preview_answer', array( hti_wf_illustrative(), 'pt' ) );
hti_games_check( str_contains( $illus_pt, Strings::get( 'rev_illustrative', 'pt' ) ), 'and the Portuguese preview shows the Portuguese sentence, which is the half nobody checks' );

$illus_check = hti_wf_render( 'render_checklist', array( hti_wf_illustrative(), 12 ) );
hti_games_check( str_contains( $illus_check, 'Nothing blocks publication' ), 'the checklist for a finished illustrative case says so' );
hti_games_check( ! str_contains( $illus_check, 'The source URL' ), 'and does not ask for a source URL it does not want' );
hti_games_check( str_contains( $illus_check, 'promote this case to verified' ), 'while still offering the promotion, which is the one thing left to do with it' );

$prov_box = hti_wf_render( 'render_provenance', array( hti_wf_illustrative() ) );
hti_games_check( str_contains( $prov_box, 'name="hti_rev_provenance"' ) && substr_count( $prov_box, 'type="radio"' ) === 2, 'the editor can switch a case between the two claims, and there is no neutral third state' );
hti_games_check( str_contains( $prov_box, 'value="illustrative"' ) && str_contains( $prov_box, 'value="verified"' ), 'the two values it writes are the two the sanitizer accepts' );

echo "\nAnd it says out loud when the Portuguese is missing\n";
$half_pt = hti_wf_render( 'render_preview_answer', array( hti_wf_case( array( 'hti_rev_context_pt' => '' ) ), 'pt' ) );
hti_games_check( str_contains( $half_pt, 'Portuguese missing' ), 'a missing Portuguese block is named rather than quietly filled with English, which is what the player would get' );

echo "\nThe brief is shown, read-only and reachable\n";
$brief_html = hti_wf_render( 'render_brief', array( array( 'hti_rev_brief' => "Open the 2011 annual report.\n\nRow one is the operating margin." ) ) );
if ( '' === Case_Admin::optional_key( Case_Admin::BRIEF_KEYS ) ) {
	hti_games_check( '' === $brief_html, 'no brief field is registered yet, so no panel is drawn about one' );
} else {
	hti_games_check( str_contains( $brief_html, 'Open the 2011 annual report.' ), 'the brief is on the screen where the figures are typed' );
	hti_games_check( ! str_contains( $brief_html, '<textarea' ) && ! str_contains( $brief_html, '<input' ), 'read-only: it is the instruction, not another field to fill' );
	hti_games_check( str_contains( $brief_html, 'role="region" tabindex="0"' ), 'and a long one scrolls somewhere a keyboard can reach' );
}

hti_games_done();
