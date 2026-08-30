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

echo "\nThe gate and the pool query agree on what 'verified' means\n";
hti_games_check( in_array( 'hti_rev_verified', array_keys( \HTI\Games\CPT::case_meta() ), true ), 'hti_rev_verified is a registered meta key' );
hti_games_check( '1' === \HTI\Games\CPT::san_bool( '1' ) && '0' === \HTI\Games\CPT::san_bool( '' ), 'and it is stored as the literal 1/0 the meta_query compares against' );
hti_games_check( array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp', 'hti_rev_year' ) === Case_Admin::VERIFIED_FIELDS, 'the three fields verification is about are the three the gate checks' );

hti_games_done();
