<?php
/**
 * Tests for Social_Card::parse_hex (pure colour parsing).
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-social-card.php';

use HTI\RssAI\Social_Card;

rssai_ok( Social_Card::parse_hex( '#FF6B5E' ) === array( 255, 107, 94 ), 'full hex parsed' );
rssai_ok( Social_Card::parse_hex( '1C2150' ) === array( 28, 33, 80 ), 'hex without hash' );
rssai_ok( Social_Card::parse_hex( '#fff' ) === array( 255, 255, 255 ), 'shorthand expanded' );
rssai_ok( Social_Card::parse_hex( '#000' ) === array( 0, 0, 0 ), 'shorthand black' );
rssai_ok( Social_Card::parse_hex( 'nothex' ) === array( 0, 0, 0 ), 'invalid → black' );

rssai_done( 'social-card' );
