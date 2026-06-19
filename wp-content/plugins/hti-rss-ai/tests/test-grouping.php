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
rssai_ok( ! isset( $nums['2024'] ) && ! isset( $nums['1234'] ), 'pure numbers dropped' );

$a = array( 'a' => true, 'b' => true, 'c' => true );
$b = array( 'b' => true, 'c' => true, 'd' => true );
rssai_ok( abs( Grouping::jaccard( $a, $a ) - 1.0 ) < 1e-9, 'jaccard identical = 1' );
rssai_ok( abs( Grouping::jaccard( $a, $b ) - 0.5 ) < 1e-9, 'jaccard 2/4 = 0.5' );
rssai_ok( 0.0 === Grouping::jaccard( $a, array( 'x' => true ) ), 'jaccard disjoint = 0' );
rssai_ok( 0.0 === Grouping::jaccard( $a, array() ), 'jaccard empty = 0' );

rssai_done( 'grouping' );
