<?php
/**
 * Tests for Gemini_Client::extract_json.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-gemini-client.php';

use HTI\RssAI\Gemini_Client;

$fenced = "Here you go:\n```json\n{\"headline\":\"Hello\",\"n\":2}\n```\nthanks";
$plain  = 'prefix {"a":1,"b":[1,2]} suffix';

rssai_ok( Gemini_Client::extract_json( $fenced ) === array( 'headline' => 'Hello', 'n' => 2 ), 'fenced json' );
rssai_ok( Gemini_Client::extract_json( $plain ) === array( 'a' => 1, 'b' => array( 1, 2 ) ), 'surrounded json' );
rssai_ok( null === Gemini_Client::extract_json( 'no json at all' ), 'no json → null' );
rssai_ok( null === Gemini_Client::extract_json( '{ broken ' ), 'broken json → null' );

rssai_done( 'extract-json' );
