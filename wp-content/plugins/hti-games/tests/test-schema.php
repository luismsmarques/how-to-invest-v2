<?php
/**
 * The JSON-LD graph, asserted without WordPress.
 *
 * The two things worth guarding here are both about honesty rather than
 * syntax.
 *
 * The first is what is NOT in the graph. AggregateRating and Review would
 * paint stars beside a result, and there are no ratings and no reviews of
 * these games — publishing either is a manual action under Google's
 * structured-data policies, and a manual action is levied on a domain, not on
 * a page. So the absence is a test, not a convention, and it walks the whole
 * encoded graph rather than checking the top level: a rating nested inside a
 * Game node counts just the same.
 *
 * The second is that the FAQPage is built from the same array the page copy
 * renders. A question answered one way in the visible text and another way in
 * the markup is exactly the mismatch that gets rich results removed, and the
 * only reliable defence is that there is one array. The test asserts the
 * count matches what Seeder::faqs() returns.
 *
 *   php wp-content/plugins/hti-games/tests/test-schema.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for output.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-seeder.php';
require_once __DIR__ . '/../includes/class-schema.php';

use HTI\Games\Config;
use HTI\Games\Schema;
use HTI\Games\Seeder;

/**
 * A context for one page, the way Schema::emit() assembles it.
 *
 * @param string $key  Page key.
 * @param string $lang 'en' or 'pt'.
 * @return array<string,mixed>
 */
function hti_games_ctx( string $key, string $lang = 'en' ): array {
	$home = 'https://howtoinvest.pro/';
	return array(
		'page'        => $key,
		'url'         => rtrim( $home, '/' ) . Seeder::url( $key, $lang ),
		'title'       => Seeder::c( $key . '_title', $lang ),
		'description' => Seeder::c( $key . '_seo_desc', $lang ),
		'lang'        => 'pt' === $lang ? 'pt-PT' : 'en-US',
		'faqs'        => Seeder::faqs( $key, $lang ),
		'home_url'    => $home,
		'hub_url'     => rtrim( $home, '/' ) . Seeder::url( 'hub', $lang ),
		'hub_title'   => Seeder::c( 'hub_title', $lang ),
		'home_title'  => 'pt' === $lang ? 'Início' : 'Home',
		'org_id'      => $home . '#organization',
		'parts'       => array(
			rtrim( $home, '/' ) . Seeder::url( Config::GAME_STC, $lang ),
			rtrim( $home, '/' ) . Seeder::url( Config::GAME_REVEAL, $lang ),
		),
	);
}

/**
 * The nodes of a graph, keyed by @type.
 *
 * @param array<int,array<string,mixed>> $graph Graph.
 * @return array<string,array<string,mixed>>
 */
function hti_games_by_type( array $graph ): array {
	$out = array();
	foreach ( $graph as $node ) {
		$out[ (string) $node['@type'] ] = $node;
	}
	return $out;
}

$stc   = Schema::graph( hti_games_ctx( 'stc' ) );
$types = hti_games_by_type( $stc );

echo "A game page describes itself twice, on purpose\n";
hti_games_check( isset( $types['Game'] ), 'a Game node says what the thing is' );
hti_games_check( isset( $types['WebApplication'] ), 'a WebApplication node says what actually renders' );
hti_games_check( isset( $types['FAQPage'] ), 'a FAQPage node carries the questions the page answers' );
hti_games_check( isset( $types['BreadcrumbList'] ), 'and a BreadcrumbList places it in the site' );
hti_games_check( 4 === count( $stc ), 'four nodes and no more' );

echo "\nThe Game node makes the claims the brief requires\n";
$game = $types['Game'];
hti_games_check( 'educational' === $game['genre'], 'genre is educational' );
hti_games_check( 'Web browser' === $game['gamePlatform'], 'it is played in a web browser' );
hti_games_check( true === $game['isAccessibleForFree'], 'it is free, stated as a boolean rather than implied' );
hti_games_check( 'en-US' === $game['inLanguage'], 'the language is declared' );
hti_games_check( 'https://howtoinvest.pro/#organization' === ( $game['publisher']['@id'] ?? '' ), 'the publisher references the existing #organization node rather than describing a second one' );
hti_games_check( str_ends_with( (string) $game['@id'], '#game' ), 'its @id is stable and page-scoped' );
hti_games_check( '' !== ( $game['description'] ?? '' ), 'it carries a description' );
hti_games_check( str_ends_with( (string) ( $game['isPartOf']['@id'] ?? '' ), '#collection' ), 'and it belongs to the hub collection' );

echo "\nThe WebApplication node describes the running thing\n";
$app = $types['WebApplication'];
hti_games_check( 'GameApplication' === $app['applicationCategory'], 'the category is GameApplication' );
hti_games_check( '0' === $app['offers']['price'], 'the price is the string "0"' );
hti_games_check( 'Offer' === $app['offers']['@type'], 'inside a proper Offer' );
hti_games_check( true === $app['isAccessibleForFree'], 'and free is asserted here too, where a crawler looks for it' );
hti_games_check( ( $app['publisher']['@id'] ?? '' ) === ( $game['publisher']['@id'] ?? '' ), 'both nodes name the same publisher' );
hti_games_check( $app['@id'] !== $game['@id'], 'the two nodes have distinct @ids, so neither overwrites the other' );

echo "\nNo ratings, and no reviews\n";
// Walked over the encoded graph rather than the top level: a rating nested
// three levels down inside a Game node would be published just the same, and
// inventing one is a manual action against the whole domain.
$forbidden = array( 'AggregateRating', 'aggregateRating', 'Review', 'reviewRating', 'ratingValue', 'bestRating' );
$hits      = array();
foreach ( array( 'hub', 'stc', 'reveal', 'leaderboard' ) as $key ) {
	foreach ( array( 'en', 'pt' ) as $lang ) {
		$json = (string) json_encode( Schema::graph( hti_games_ctx( $key, $lang ) ) );
		foreach ( $forbidden as $needle ) {
			if ( str_contains( $json, $needle ) ) {
				$hits[] = "{$key}.{$lang}: {$needle}";
			}
		}
	}
}
hti_games_check( array() === $hits, 'nothing in any graph claims a rating or a review (' . ( $hits ? implode( '; ', $hits ) : 'clean' ) . ')' );

echo "\nThe FAQ in the markup is the FAQ on the page\n";
$faqs = Seeder::faqs( 'stc', 'en' );
hti_games_check( count( $faqs ) === count( $types['FAQPage']['mainEntity'] ), sprintf( 'the same %d questions, from the same array', count( $faqs ) ) );
hti_games_check( $faqs[0]['q'] === $types['FAQPage']['mainEntity'][0]['name'], 'the first question is verbatim' );
hti_games_check( $faqs[0]['a'] === $types['FAQPage']['mainEntity'][0]['acceptedAnswer']['text'], 'and so is its answer' );
$shapes = array();
foreach ( $types['FAQPage']['mainEntity'] as $i => $q ) {
	if ( 'Question' !== $q['@type'] || 'Answer' !== ( $q['acceptedAnswer']['@type'] ?? '' ) || '' === trim( (string) $q['name'] ) || '' === trim( (string) $q['acceptedAnswer']['text'] ) ) {
		$shapes[] = (string) $i;
	}
}
hti_games_check( array() === $shapes, 'every entry is a well-formed Question/Answer pair' );

echo "\nBreadcrumbs walk from the home page down\n";
$crumbs = $types['BreadcrumbList']['itemListElement'];
hti_games_check( 3 === count( $crumbs ), 'home, hub, page' );
hti_games_check( 1 === $crumbs[0]['position'] && 3 === $crumbs[2]['position'], 'positions are 1-based and contiguous' );
hti_games_check( 'https://howtoinvest.pro/' === $crumbs[0]['item'], 'the first crumb is the home page' );
hti_games_check( 'https://howtoinvest.pro/games/' === $crumbs[1]['item'], 'the second is the games hub' );
hti_games_check( 'https://howtoinvest.pro/games/survive-the-charts/' === $crumbs[2]['item'], 'the third is the page itself' );

$hub_crumbs = hti_games_by_type( Schema::graph( hti_games_ctx( 'hub' ) ) )['BreadcrumbList']['itemListElement'];
hti_games_check( 2 === count( $hub_crumbs ), 'the hub does not list itself twice' );

echo "\nThe hub is a collection, not a game\n";
$hub = hti_games_by_type( Schema::graph( hti_games_ctx( 'hub' ) ) );
hti_games_check( isset( $hub['CollectionPage'] ), 'the hub is a CollectionPage' );
hti_games_check( ! isset( $hub['Game'] ) && ! isset( $hub['WebApplication'] ), 'and claims nothing playable is on it' );
hti_games_check( 2 === count( $hub['CollectionPage']['hasPart'] ), 'it points at both games' );
hti_games_check(
	'https://howtoinvest.pro/games/survive-the-charts/#game' === $hub['CollectionPage']['hasPart'][0]['@id'],
	'by @id, so a game is described once and referenced everywhere else'
);
hti_games_check( isset( $hub['FAQPage'] ), 'the hub answers its own questions too' );

$board = hti_games_by_type( Schema::graph( hti_games_ctx( 'leaderboard' ) ) );
hti_games_check( isset( $board['CollectionPage'] ) && ! isset( $board['Game'] ), 'so is the leaderboard' );

echo "\nPortuguese pages describe themselves in Portuguese\n";
$pt = hti_games_by_type( Schema::graph( hti_games_ctx( 'reveal', 'pt' ) ) );
hti_games_check( 'pt-PT' === $pt['Game']['inLanguage'], 'the Game node is tagged pt-PT' );
hti_games_check( 'pt-PT' === $pt['FAQPage']['inLanguage'], 'and so is the FAQ' );
hti_games_check( str_contains( (string) $pt['Game']['url'], '/pt/jogos/a-revelacao/' ), 'the URL is the Portuguese one' );
hti_games_check( $pt['Game']['name'] !== hti_games_by_type( Schema::graph( hti_games_ctx( 'reveal' ) ) )['Game']['name'], 'and the name is translated, not reused' );

echo "\nThe emitter only fires where it should\n";
hti_games_check( Schema::should_emit( 'stc' ) && Schema::should_emit( 'reveal' ), 'both game pages get structured data' );
hti_games_check( Schema::should_emit( 'hub' ) && Schema::should_emit( 'leaderboard' ), 'so do the hub and the board' );
hti_games_check( ! Schema::should_emit( 'profile' ), 'the noindexed profile page does not — markup nobody reads is markup nobody maintains' );
hti_games_check( ! Schema::should_emit( '' ), 'and an unrecognised page emits nothing at all, which is what keeps this off every other page on the site' );
hti_games_check( ! Schema::should_emit( 'privacy-policy' ), 'including a real page that is simply not ours' );

echo "\nThe graph is publishable\n";
$json = json_encode(
	array(
		'@context' => 'https://schema.org',
		'@graph'   => $stc,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
hti_games_check( is_string( $json ) && '' !== $json, 'it encodes to JSON' );
hti_games_check( is_array( json_decode( (string) $json, true ) ), 'and decodes back' );
hti_games_check( ! str_contains( (string) $json, '</script' ), 'and cannot close the script tag it is printed into' );

$ids = array();
foreach ( $stc as $node ) {
	$ids[] = $node['@id'];
}
hti_games_check( count( $ids ) === count( array_unique( $ids ) ), 'every node has a distinct @id' );

hti_games_done();
