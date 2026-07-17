<?php
/**
 * Tests for Grouping::tokenize / Grouping::jaccard.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-grouping.php';

use HTI\RssAI\Grouping;

$tokens = Grouping::tokenize( 'Ações globais sobem após dados de inflação' );
rssai_ok( isset( $tokens['acoes'] ), 'accents folded (ações → acoes)' );
rssai_ok( isset( $tokens['inflacao'] ), 'token inflacao present' );
rssai_ok( ! isset( $tokens['de'] ), 'short word "de" dropped' );

$en = Grouping::tokenize( 'The market and the latest news report' );
rssai_ok( ! isset( $en['the'] ) && ! isset( $en['and'] ), 'EN stopwords dropped' );
rssai_ok( isset( $en['market'] ) && isset( $en['report'] ), 'content words kept' );

$nums = Grouping::tokenize( 'Q3 2024 earnings 1234 report' );
rssai_ok( isset( $nums['2024'] ), 'four-digit year 2024 kept (story signal)' );
rssai_ok( ! isset( $nums['1234'] ), 'non-year number 1234 dropped' );

$a = array( 'a' => true, 'b' => true, 'c' => true );
$b = array( 'b' => true, 'c' => true, 'd' => true );
rssai_ok( abs( Grouping::jaccard( $a, $a ) - 1.0 ) < 1e-9, 'jaccard identical = 1' );
rssai_ok( abs( Grouping::jaccard( $a, $b ) - 0.5 ) < 1e-9, 'jaccard 2/4 = 0.5' );
rssai_ok( 0.0 === Grouping::jaccard( $a, array( 'x' => true ) ), 'jaccard disjoint = 0' );
rssai_ok( 0.0 === Grouping::jaccard( $a, array() ), 'jaccard empty = 0' );

// IDF weighting + weighted similarity.
$docs = array(
	1 => array( 'fed' => true, 'rates' => true ),
	2 => array( 'fed' => true, 'inflation' => true ),
	3 => array( 'weather' => true ),
);
$idf = Grouping::idf( $docs );
rssai_ok( $idf['weather'] > $idf['fed'], 'rarer token gets higher idf' );
$x = array( 'fed' => true, 'rates' => true );
$y = array( 'fed' => true, 'inflation' => true );
rssai_ok( abs( Grouping::weighted_sim( $x, $x, $idf ) - 1.0 ) < 1e-9, 'weighted sim identical = 1' );
$s = Grouping::weighted_sim( $x, $y, $idf );
rssai_ok( $s > 0.0 && $s < 1.0, 'weighted sim partial overlap in (0,1)' );
rssai_ok( 0.0 === Grouping::weighted_sim( $x, array( 'zzz' => true ), $idf ), 'weighted sim disjoint = 0' );

// best_group: a new item joins the existing open group its closest member matches.
$idf2   = Grouping::idf(
	array(
		1 => array( 'barings' => true, 'leeson' => true, 'bank' => true ),
		2 => array( 'barings' => true, 'trader' => true ),
		3 => array( 'weather' => true, 'storm' => true ),
	)
);
$groups = array(
	// index 0: the Barings story.
	array(
		'gid'     => 10,
		'members' => array(
			array( 'barings' => true, 'leeson' => true, 'bank' => true ),
			array( 'barings' => true, 'trader' => true ),
		),
	),
	// index 1: an unrelated weather story.
	array(
		'gid'     => 20,
		'members' => array( array( 'weather' => true, 'storm' => true ) ),
	),
);

$new   = array( 'barings' => true, 'leeson' => true );
$match = Grouping::best_group( $new, $groups, $idf2 );
rssai_ok( 0 === $match['index'], 'best_group picks the matching existing group (index 0 → gid 10)' );
rssai_ok( $match['sim'] > 0.0, 'best_group reports a positive similarity for a real match' );

$off      = array( 'unrelated' => true, 'token' => true );
$no_match = Grouping::best_group( $off, $groups, $idf2 );
rssai_ok( -1 === $no_match['index'] && 0.0 === $no_match['sim'], 'best_group returns no match (index -1, sim 0) for a disjoint item' );

rssai_ok( array( 'index' => -1, 'sim' => 0.0 ) === Grouping::best_group( $new, array(), $idf2 ), 'best_group with no existing groups returns no match' );

rssai_done( 'grouping' );
