<?php
/**
 * Tests for Items::select_list (the column whitelist behind query()'s
 * 'fields' arg).
 *
 * The grouper reads 500 items in one request; SELECT * there hauled
 * transcripts and other unused longtext columns into memory, which is how a
 * big batch dies of OOM. The whitelist keeps the lean select safe: only real
 * column names ever reach the SQL.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-items.php';

use HTI\RssAI\Items;

rssai_ok( '*' === Items::select_list( array() ), 'no fields requested → select everything' );
rssai_ok( '`id`, `title`' === Items::select_list( array( 'id', 'title' ) ), 'known columns → backtick-quoted list' );
rssai_ok( '`id`' === Items::select_list( array( 'id', 'nope' ) ), 'unknown column names are dropped' );
rssai_ok( '*' === Items::select_list( array( 'nope', 'also_nope' ) ), 'only unknown names → fall back to everything' );
rssai_ok( '*' === Items::select_list( array( 'id; DROP TABLE x--' ) ), 'an injection-shaped name never reaches the SQL' );

$lean = Items::select_list( array( 'id', 'title', 'description', 'published_at', 'embedding' ) );
rssai_ok( str_contains( $lean, '`embedding`' ) && ! str_contains( $lean, 'transcript' ), 'the grouping select carries embeddings but never transcripts' );

rssai_done( 'items' );
