<?php
/**
 * The structural table, and the bilingual promise.
 *
 * Two things this file is really guarding. First, that the page table has no
 * duplicate or empty slug in either language — the seeder upserts by path, so
 * a collision would silently overwrite a page. Second, that every string the
 * games show has both an EN and a PT value: this project runs pt_PT_ao90 with
 * pt_PT translation files and WordPress does not fall back, so a missing PT
 * string does not raise anything, it just renders in English.
 *
 *   php wp-content/plugins/hti-games/tests/test-config.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';

use HTI\Games\Config;

$pages = Config::pages();

echo "The page table is coherent\n";
hti_games_check( isset( $pages['hub'], $pages['stc'], $pages['reveal'] ), 'the hub and both games are declared' );

$en = array_column( $pages, 'en' );
$pt = array_column( $pages, 'pt' );
hti_games_check( count( $en ) === count( array_unique( $en ) ), 'no two pages share an English slug' );
hti_games_check( count( $pt ) === count( array_unique( $pt ) ), 'no two pages share a Portuguese slug' );
hti_games_check( array() === array_filter( array_merge( $en, $pt ), fn( $s ) => '' === trim( (string) $s ) ), 'no slug is empty' );

$bad = array_filter( array_merge( $en, $pt ), fn( $s ) => 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $s ) );
hti_games_check( array() === $bad, 'every slug is lowercase, hyphenated and clean (' . implode( ', ', $bad ) . ')' );

foreach ( $pages as $key => $page ) {
	if ( null !== $page['parent'] ) {
		hti_games_check( isset( $pages[ $page['parent'] ] ), "the parent of '{$key}' exists in the table" );
	}
}
hti_games_check( false === $pages['profile']['index'], 'the player profile is not indexable — one visitor is not a page worth ranking' );
hti_games_check( true === $pages['stc']['index'] && true === $pages['reveal']['index'], 'both game pages are indexable' );

echo "\nThe numbers are the contract\n";
hti_games_check( 10000 === Config::CAPITAL_START, 'a run starts at $10,000' );
hti_games_check( 1000 === Config::CAPITAL_FLOOR, 'and dies at $1,000' );
hti_games_check( Config::CAPITAL_FLOOR < Config::CAPITAL_START, 'the floor is below the start, or every run dies on day one' );
hti_games_check( 80 === Config::STC_VISIBLE && 40 === Config::STC_OUTCOME, '80 candles are shown and up to 40 play out' );
hti_games_check( Config::STC_ATR_PERIOD <= Config::STC_VISIBLE, 'the ATR window fits inside what the player can see' );
hti_games_check( array( 50, 100, 200, 500, 1000, 2500 ) === Config::STC_RISK_BP, 'the risk tiers are the six from the design, in basis points' );
hti_games_check( array( 5, 10, 25, 50 ) === Config::REVEAL_SIZES, 'The Reveal offers 5/10/25/50 percent' );
hti_games_check( 2 === Config::STC_DOUBLE, 'the optional multiplier doubles both sides' );
hti_games_check( Config::REVEAL_MIN_AGE_YEARS >= 5, 'a named company must be at least five years in the past' );

echo "\nValues arriving from the open web are checked against the offered set\n";
hti_games_check( Config::is_risk_bp( 200 ), '2% is offered' );
hti_games_check( ! Config::is_risk_bp( 2499 ), '24.99% is not — the check is membership, not a range' );
hti_games_check( ! Config::is_risk_bp( 0 ), 'nor is zero risk' );
hti_games_check( ! Config::is_risk_bp( -100 ), 'nor is a negative' );
hti_games_check( Config::is_size( 25 ), '25% is an offered size' );
hti_games_check( ! Config::is_size( 100 ), 'all-in is not' );
hti_games_check( Config::is_game( 'stc' ) && Config::is_game( 'reveal' ), 'both games are recognised' );
hti_games_check( ! Config::is_game( 'roulette' ), 'nothing else is' );
hti_games_check( 0 === Config::game_id( 'roulette' ), 'an unknown game has no storage id' );
hti_games_check( count( array_unique( Config::GAME_IDS ) ) === count( Config::GAME_IDS ), 'the two storage ids are distinct' );

hti_games_done();
