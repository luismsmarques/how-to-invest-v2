<?php
/**
 * The seeded cases cannot reach a player, and that is the whole test.
 *
 * CLAUDE.md invariant 2 forbids naming companies. The Reveal's exemption lets
 * it name one — but only inside `hti_reveal_case`, only for a period at least
 * Config::REVEAL_MIN_AGE_YEARS old, and only with a verified source recorded on
 * the case. The five prototypes name five real businesses, so they are exactly
 * the content the exemption is about, and they were written in an environment
 * with no network access where nothing could be checked against anything.
 *
 * So this file asserts the uncomfortable thing rather than the comfortable one:
 * that not one of them is publishable, that none carries a return figure, and
 * that the publish gate names the missing fields. It is the guarantee that an
 * unverified claim about a real company cannot reach production by accident —
 * and if somebody ever "finishes" the seed data by filling the numbers in from
 * memory, this is the file that goes red.
 *
 * The positive control at the bottom matters as much: a case DOES become
 * publishable once a source, a tick and both figures are supplied. Without it,
 * a test that always says "not publishable" would pass just as happily against
 * an empty array.
 *
 *   php wp-content/plugins/hti-games/tests/test-seed-cases.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-case-admin.php';
require_once __DIR__ . '/../includes/class-seed-cases.php';

use HTI\Games\Case_Admin;
use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\Seed_Cases;

/**
 * A fixed "now", so a green suite does not turn red on a New Year's Day.
 */
$now   = (int) strtotime( '2026-08-30 12:00:00 UTC' );
$cases = Seed_Cases::cases();

echo "The five prototypes are here\n";
hti_games_check( 5 === count( $cases ), sprintf( '%d cases are seeded', count( $cases ) ) );

$named = array();
foreach ( $cases as $case ) {
	$named[] = $case['company'] . ' ' . $case['year'];
}
sort( $named );
hti_games_check(
	array( 'Amazon 2001', 'Coca-Cola 2010', 'Enron 2000', 'Nokia 2007', 'Pets.com 1999' ) === $named,
	'and they are the five from the design handoff (' . implode( ', ', $named ) . ')'
);
hti_games_check( count( array_unique( $named ) ) === count( $named ), 'no company and year appears twice, which is what the seeder dedupes on' );

$unmarked = array_filter( $cases, fn( array $c ): bool => ! str_contains( $c['title'], Seed_Cases::DRAFT_MARK ) );
hti_games_check( array() === $unmarked, 'every title says out loud that it is an unverified seed' );

echo "\nNot one of them can be published\n";
$publishable = array();
foreach ( $cases as $case ) {
	if ( Case_Admin::publishable( $case['meta'], $now ) ) {
		$publishable[] = $case['company'];
	}
}
hti_games_check( array() === $publishable, 'Case_Admin::publishable() says no to all five (' . ( $publishable ? implode( ', ', $publishable ) : 'clean' ) . ')' );

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
hti_games_check( 5 === $no_source, 'the gate names the missing source URL on every one' );
hti_games_check( 5 === $no_verified, 'and the missing verification on every one' );

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

echo "\nWhat IS filled is the shape of the answer\n";
$holes = array();
foreach ( $cases as $case ) {
	foreach ( array( 'hti_rev_company', 'hti_rev_sector_en', 'hti_rev_sector_pt', 'hti_rev_revenue_band_en', 'hti_rev_revenue_band_pt' ) as $key ) {
		if ( '' === trim( (string) $case['meta'][ $key ] ) ) {
			$holes[] = $case['company'] . ': ' . $key;
		}
	}
	if ( (int) $case['meta']['hti_rev_year'] <= 0 ) {
		$holes[] = $case['company'] . ': year';
	}
}
hti_games_check( array() === $holes, 'company, year, sector and revenue band give the editor a form rather than a blank page (' . ( $holes ? implode( '; ', $holes ) : 'clean' ) . ')' );

// The revenue band is filled with the SHAPE of an answer, not an answer: a
// band is still a figure about a real company.
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

echo "\nSix labelled questions, no answers\n";
$rows_bad = array();
foreach ( $cases as $case ) {
	$rows = json_decode( (string) $case['meta']['hti_rev_fundamentals'], true );

	if ( ! is_array( $rows ) || Seed_Cases::FUNDAMENTALS !== count( $rows ) ) {
		$rows_bad[] = $case['company'] . ': not six rows';
		continue;
	}

	foreach ( $rows as $row ) {
		if ( '' === trim( (string) $row['key'] ) || '' === trim( (string) $row['label_en'] ) || '' === trim( (string) $row['label_pt'] ) ) {
			$rows_bad[] = $case['company'] . ': an unlabelled row';
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
}
hti_games_check( array() === $rows_bad, 'six labelled fundamentals per case, every value and sector average empty, every tint neutral (' . ( $rows_bad ? implode( '; ', $rows_bad ) : 'clean' ) . ')' );

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
hti_games_check( array() === $heads_bad, 'three empty headline slots per case — a period headline is a quotation, and none of these has been read (' . ( $heads_bad ? implode( '; ', $heads_bad ) : 'clean' ) . ')' );

// The JSON has to survive the sanitizer that will actually write it, or the
// editor opens a meta box with six blank rows and no labels.
$survives = true;
foreach ( $cases as $case ) {
	$stored = CPT::san_fundamentals( $case['meta']['hti_rev_fundamentals'] );
	$rows   = json_decode( $stored, true );
	$survives = $survives && is_array( $rows ) && Seed_Cases::FUNDAMENTALS === count( $rows ) && 'revenue_growth' === $rows[0]['key'];
}
hti_games_check( $survives, 'the fundamentals JSON survives CPT::san_fundamentals unchanged in shape' );

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
hti_games_check( array() === $too_recent, 'all five are at least ' . Config::REVEAL_MIN_AGE_YEARS . ' years past (' . ( $too_recent ? implode( ', ', $too_recent ) : 'clean' ) . ')' );

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
