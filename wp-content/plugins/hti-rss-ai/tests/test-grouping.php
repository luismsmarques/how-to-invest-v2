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

// cosine (P1.2).
$vec = array( 1.0, 0.0, 0.0 );
rssai_ok( abs( Grouping::cosine( $vec, $vec ) - 1.0 ) < 1e-9, 'cosine identical = 1' );
rssai_ok( 0.0 === Grouping::cosine( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ), 'cosine orthogonal = 0' );
$partial = Grouping::cosine( array( 1.0, 1.0 ), array( 1.0, 0.0 ) );
rssai_ok( $partial > 0.0 && $partial < 1.0, 'cosine partial overlap in (0,1)' );
rssai_ok( 0.0 === Grouping::cosine( array(), array( 1.0 ) ), 'cosine empty = 0' );

// fingerprint (P1.3): case/order/punctuation-invariant title signature.
$fp_a = Grouping::fingerprint( 'Fed raises interest rates in 2024' );
$fp_b = Grouping::fingerprint( 'RATES interest, raises fed — 2024!' );
rssai_ok( '' !== $fp_a && $fp_a === $fp_b, 'fingerprint ignores case/order/punctuation' );
rssai_ok( $fp_a !== Grouping::fingerprint( 'Oil prices tumble on demand fears' ), 'different stories → different fingerprint' );
rssai_ok( '' === Grouping::fingerprint( 'the and for' ), 'stopwords-only title → empty fingerprint' );

// best_group_hybrid (P1.2): lexical OR semantic join.
$idf3    = Grouping::idf(
	array(
		1 => array( 'fed' => true, 'rates' => true ),
		2 => array( 'oil' => true, 'prices' => true ),
	)
);
$g_lex   = array(
	array(
		'members' => array( array( 'fed' => true, 'rates' => true ) ),
		'vecs'    => array( null ),
	),
	array(
		'members' => array( array( 'oil' => true, 'prices' => true ) ),
		'vecs'    => array( null ),
	),
);
$hl = Grouping::best_group_hybrid( array( 'fed' => true, 'rates' => true ), null, $g_lex, $idf3, 0.4, 0.82 );
rssai_ok( $hl['qualifies'] && 0 === $hl['index'], 'hybrid: lexical match joins the right group (no vectors)' );

$g_sem = array(
	array(
		'members' => array( array( 'completely' => true, 'different' => true ) ),
		'vecs'    => array( array( 1.0, 0.0, 0.0 ) ),
	),
);
$idf_empty = Grouping::idf( array( 1 => array( 'x' => true ) ) );
$hs        = Grouping::best_group_hybrid( array( 'unrelated' => true, 'words' => true ), array( 1.0, 0.0, 0.0 ), $g_sem, $idf_empty, 0.4, 0.82 );
rssai_ok( $hs['qualifies'] && 0 === $hs['index'], 'hybrid: semantic (cosine) match joins despite no shared words' );

$hn = Grouping::best_group_hybrid( array( 'unrelated' => true ), array( 0.0, 1.0, 0.0 ), $g_sem, $idf_empty, 0.4, 0.82 );
rssai_ok( ! $hn['qualifies'], 'hybrid: no lexical overlap and low cosine → no join' );

// ts() (P4): parse datetimes, reject empty/zero.
rssai_ok( is_int( Grouping::ts( '2024-01-02 03:04:05' ) ), 'ts parses a datetime to an int' );
rssai_ok( null === Grouping::ts( '' ), 'ts empty → null' );
rssai_ok( null === Grouping::ts( '0000-00-00 00:00:00' ), 'ts zero date → null' );

// Recency gate (P4): same topic, but the date decides which group an item joins.
$idf_d = Grouping::idf( array( 1 => array( 'merger' => true, 'deal' => true ) ) );
$day   = 86400;
$now   = 1000000000; // fixed epoch literal (no clock calls in tests).
$dated = array(
	// index 0: OLD coverage of the topic.
	array( 'members' => array( array( 'merger' => true, 'deal' => true ) ), 'vecs' => array( null ), 'newest_ts' => $now - 30 * $day ),
	// index 1: RECENT coverage of the topic.
	array( 'members' => array( array( 'merger' => true, 'deal' => true ) ), 'vecs' => array( null ), 'newest_ts' => $now ),
);
$cand = array( 'merger' => true, 'deal' => true );

$r1 = Grouping::best_group_hybrid( $cand, null, $dated, $idf_d, 0.4, 0.82, $now, 3 * $day );
rssai_ok( $r1['qualifies'] && 1 === $r1['index'], 'recency gate: a recent item joins the recent group, not the old one' );

$r2 = Grouping::best_group_hybrid( $cand, null, $dated, $idf_d, 0.4, 0.82, $now - 30 * $day, 3 * $day );
rssai_ok( $r2['qualifies'] && 0 === $r2['index'], 'recency gate: an old item joins the old group, not the recent one' );

$r3 = Grouping::best_group_hybrid( $cand, null, $dated, $idf_d, 0.4, 0.82, $now - 15 * $day, 3 * $day );
rssai_ok( ! $r3['qualifies'], 'recency gate: an item far in time from every group joins none' );

$r4 = Grouping::best_group_hybrid( $cand, null, $dated, $idf_d, 0.4, 0.82, null, 0 );
rssai_ok( $r4['qualifies'], 'gate disabled (span 0 / no timestamp) → dates ignored, still matches' );

rssai_done( 'grouping' );
