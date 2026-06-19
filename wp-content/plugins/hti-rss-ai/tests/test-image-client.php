<?php
/**
 * Tests for Image_Client::extract_base64 (tolerant response parsing).
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-image-client.php';

use HTI\RssAI\Image_Client;

$imagen = array( 'predictions' => array( array( 'bytesBase64Encoded' => 'AAAA' ) ) );
rssai_ok( 'AAAA' === Image_Client::extract_base64( $imagen ), 'imagen predictions parsed' );

$imagen2 = array( 'predictions' => array( array( 'image' => array( 'imageBytes' => 'BBBB' ) ) ) );
rssai_ok( 'BBBB' === Image_Client::extract_base64( $imagen2 ), 'imagen image.imageBytes parsed' );

$gemini = array(
	'candidates' => array(
		array( 'content' => array( 'parts' => array( array( 'inlineData' => array( 'data' => 'CCCC' ) ) ) ) ),
	),
);
rssai_ok( 'CCCC' === Image_Client::extract_base64( $gemini ), 'gemini inlineData parsed' );

$gemini_snake = array(
	'candidates' => array(
		array( 'content' => array( 'parts' => array( array( 'inline_data' => array( 'data' => 'DDDD' ) ) ) ) ),
	),
);
rssai_ok( 'DDDD' === Image_Client::extract_base64( $gemini_snake ), 'gemini inline_data parsed' );

rssai_ok( null === Image_Client::extract_base64( array( 'predictions' => array() ) ), 'empty → null' );

rssai_done( 'image-client' );
