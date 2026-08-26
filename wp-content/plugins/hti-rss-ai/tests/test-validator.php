<?php
/**
 * Tests for Validator::validate.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-validator.php';

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

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

// Meta description is clamped to ~155 chars (in place).
$long                     = $valid;
$long['meta_description'] = trim( str_repeat( 'word ', 60 ) ); // ~299 chars.
$clamped                  = Validator::validate( $long );
rssai_ok( true === $clamped && mb_strlen( $long['meta_description'] ) <= 156, 'meta description clamped to ~155' );

$en_text = 'The market and the economy are the focus of this report, and you will find that the numbers are steady with the trend from this quarter.';

// Wrong language: an English article when Portuguese is expected is rejected.
$wrong_lang                = $valid;
$wrong_lang['body_blocks'] = array( array( 'type' => 'paragraph', 'text' => $en_text ) );
rssai_ok( Validator::validate( $wrong_lang, 'pt' ) instanceof \WP_Error, 'wrong-language (EN when PT expected) rejected' );

// The same article passes when the expected language matches.
$right_lang                = $valid;
$right_lang['body_blocks'] = array( array( 'type' => 'paragraph', 'text' => $en_text ) );
rssai_ok( true === Validator::validate( $right_lang, 'en' ), 'correct language (EN when EN expected) passes' );

// Near-verbatim copy of the source item is rejected.
$src                  = 'The central bank raised interest rates today by half a point to fight rising inflation across the country this year.';
$copy                 = $valid;
$copy['body_blocks']  = array( array( 'type' => 'paragraph', 'text' => $src ) );
rssai_ok( Validator::validate( $copy, 'en', $src ) instanceof \WP_Error, 'near-verbatim source copy rejected' );

// An original rewrite of the same topic passes.
$rewrite                = $valid;
$rewrite['body_blocks'] = array( array( 'type' => 'paragraph', 'text' => 'Policymakers lifted borrowing costs to cool prices, a modest move that markets had largely expected.' ) );
rssai_ok( true === Validator::validate( $rewrite, 'en', $src ), 'original rewrite (not a copy) passes' );

rssai_done( 'validator' );
