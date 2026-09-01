<?php
/**
 * Tests for Featured_Image's source resolution.
 *
 * The one that matters is the item branch: an article written from a single
 * feed item used to be handed null instead of its row, so it never saw its own
 * photo — no image-to-image, no vision brief, and not even the plain fallback.
 * That defect is pinned here.
 *
 * The second group asserts, at source level, that the raw feed photo is no
 * longer a publishable outcome. It is read to write the brief and nothing else.
 *
 * @package HTI_RSS_AI
 */

namespace {
	require __DIR__ . '/bootstrap.php';
}

namespace HTI\RssAI {
	// Stand-in for the group→items lookup, so the group branch is exercised
	// without a database.
	class Groups {
		/** @var array<int,array<int,object>> */
		public static $rows = array();

		public static function items( int $group_id ): array {
			return self::$rows[ $group_id ] ?? array();
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/includes/class-featured-image.php';

	use HTI\RssAI\Featured_Image;
	use HTI\RssAI\Groups;

	/**
	 * Build an item row the way the items table hands one back.
	 */
	function rssai_item( $image_url ) {
		$item            = new stdClass();
		$item->id        = 7;
		$item->title     = 'Some headline';
		$item->image_url = $image_url;
		return $item;
	}

	/**
	 * Build a group row: it has an id and, crucially, no image_url of its own.
	 */
	function rssai_group( int $id ) {
		$group         = new stdClass();
		$group->id     = $id;
		$group->label  = 'Some cluster';
		$group->status = 'open';
		return $group;
	}

	/* ------------------------------------------------- the item branch (the bug) */

	$item = rssai_item( 'https://example.com/photo.jpg' );
	rssai_ok(
		'https://example.com/photo.jpg' === Featured_Image::feed_image_url( $item ),
		'an item row reaches its own feed image'
	);

	rssai_ok( '' === Featured_Image::feed_image_url( rssai_item( '' ) ), 'an item with no image → empty' );
	rssai_ok( '' === Featured_Image::feed_image_url( rssai_item( null ) ), 'a null image_url → empty' );
	rssai_ok(
		'https://example.com/p.jpg' === Featured_Image::feed_image_url( rssai_item( "  https://example.com/p.jpg\n" ) ),
		'the URL is trimmed'
	);

	/* ------------------------------------------------------------ the group branch */

	Groups::$rows = array(
		3 => array(
			(object) array( 'id' => 1, 'image_url' => '' ),
			(object) array( 'id' => 2, 'image_url' => 'https://example.com/second.jpg' ),
			(object) array( 'id' => 3, 'image_url' => 'https://example.com/third.jpg' ),
		),
		4 => array(
			(object) array( 'id' => 5, 'image_url' => '' ),
		),
		5 => array(),
	);

	rssai_ok(
		'https://example.com/second.jpg' === Featured_Image::feed_image_url( rssai_group( 3 ) ),
		'a group takes the first item that has an image'
	);
	rssai_ok( '' === Featured_Image::feed_image_url( rssai_group( 4 ) ), 'a group whose items have no image → empty' );
	rssai_ok( '' === Featured_Image::feed_image_url( rssai_group( 5 ) ), 'an empty group → empty' );

	/* ------------------------------------------------------------------- neither */

	rssai_ok( '' === Featured_Image::feed_image_url( null ), 'null source → empty, no fatal' );
	rssai_ok( '' === Featured_Image::feed_image_url( new stdClass() ), 'a shapeless object → empty, no fatal' );

	/* ------------------------------- the feed photo is read, never published */

	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-featured-image.php' );

	rssai_ok(
		false === strpos( $source, "return array( \$feed_bytes," ),
		'the raw feed image is never returned as the photo to publish'
	);
	rssai_ok(
		false !== strpos( $source, 'Fallback_Card::render' ),
		'the last resort is our own brand card'
	);
	rssai_ok(
		false !== strpos( $source, 'acquire_brief' ),
		'the brief is acquired before anything is generated'
	);

	// The call site the defect lived at: maybe_generate must take the row, and
	// the generator must pass the item rather than null.
	$generator = file_get_contents( dirname( __DIR__ ) . '/includes/class-generator.php' );
	rssai_ok(
		false === strpos( $generator, 'maybe_generate( $post_id, $data, null' ),
		'no call site passes null where the source row belongs'
	);
	rssai_ok(
		2 === substr_count( $generator, 'Featured_Image::maybe_generate(' ),
		'both generator paths still ask for a featured image'
	);

	/* ------------------------------------------------------------ source labels */

	rssai_ok( '' !== Featured_Image::source_label( 'ai-from-brief' ), 'brief-drawn images have a label' );
	rssai_ok( '' !== Featured_Image::source_label( 'brand-card' ), 'the brand card has a label' );
	rssai_ok( 'something-else' === Featured_Image::source_label( 'something-else' ), 'an unknown source shows verbatim' );

	rssai_done( 'featured-image' );
}
