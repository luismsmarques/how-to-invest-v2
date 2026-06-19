<?php
/**
 * Tests for Validator::validate.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-validator.php';

use HTI\RssAI\Validator;

$valid = array(
	'headline'         => 'Markets recap',
	'meta_description' => 'A calm summary of the week.',
	'body_blocks'      => array( array( 'type' => 'paragraph', 'text' => 'Indices moved modestly this week.' ) ),
	'sources'          => array( array( 'title' => 'Reuters', 'url' => 'https://example.com' ) ),
);

rssai_ok( true === Validator::validate( $valid ), 'valid article passes' );

$no_sources            = $valid;
$no_sources['sources'] = array();
rssai_ok( Validator::validate( $no_sources ) instanceof \WP_Error, 'no sources rejected' );

$missing = $valid;
unset( $missing['headline'] );
rssai_ok( Validator::validate( $missing ) instanceof \WP_Error, 'missing headline rejected' );

$advice                = $valid;
$advice['body_blocks'] = array( array( 'type' => 'paragraph', 'text' => 'You should buy now before it rises.' ) );
rssai_ok( Validator::validate( $advice ) instanceof \WP_Error, 'advice language rejected' );

$ticker             = $valid;
$ticker['headline'] = 'Why $AAPL jumped today';
rssai_ok( Validator::validate( $ticker ) instanceof \WP_Error, 'ticker symbol rejected' );

rssai_done( 'validator' );
