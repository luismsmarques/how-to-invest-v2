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

rssai_done( 'grouping' );
