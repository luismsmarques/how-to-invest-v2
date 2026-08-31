<?php
/**
 * Tests for Fallback_Card — the last-resort brand illustration.
 *
 * The plan is pure and always checked. The drawing needs GD; when GD is absent
 * the drawing tests are skipped with a clear message rather than failing, since
 * the pipeline already degrades to "no image" in that case.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-fallback-card.php';

use HTI\RssAI\Fallback_Card;

/* ------------------------------------------------------------------ plan() */

$plan = Fallback_Card::plan( 'ECB holds rates as inflation cools', 'Economy' );
foreach ( array( 'motif', 'accent', 'density', 'angle', 'offset', 'seed' ) as $key ) {
	rssai_ok( array_key_exists( $key, $plan ), "plan has $key: $key" );
}
rssai_ok( in_array( $plan['motif'], Fallback_Card::MOTIFS, true ), 'the motif is one of the known motifs' );
rssai_ok( in_array( $plan['accent'], array( 'coral', 'purple' ), true ), 'the accent is a brand accent' );

// Deterministic: same article, same card, every time.
rssai_ok(
	Fallback_Card::plan( 'ECB holds rates as inflation cools', 'Economy' ) === $plan,
	'the same article always plans the same card'
);

// Different articles must not all look alike.
$a = Fallback_Card::plan( 'ECB holds rates as inflation cools', 'Economy' );
$b = Fallback_Card::plan( 'Bitcoin ETF inflows hit a monthly record', 'Crypto' );
rssai_ok( $a !== $b, 'two articles plan different cards' );

// The accent follows the category, so a section reads consistently.
$one = Fallback_Card::plan( 'First headline about the economy', 'Economy' );
$two = Fallback_Card::plan( 'A completely different economy headline', 'Economy' );
rssai_ok( $one['accent'] === $two['accent'], 'one category keeps one accent' );

// The motif follows the headline, so two articles in a section still differ.
$motifs = array();
foreach ( array(
	'ECB holds rates as inflation cools',
	'Bitcoin ETF inflows hit a monthly record',
	'European equities close higher on tech',
	'Housing starts slow for a third quarter',
	'What a bond ladder actually costs you',
	'Oil steadies after OPEC signals a pause',
	'Retail sales beat forecasts in July',
	'Gilt yields ease after the auction',
) as $headline ) {
	$motifs[] = Fallback_Card::plan( $headline, 'Markets' )['motif'];
}
rssai_ok( count( array_unique( $motifs ) ) > 1, 'one category still produces several motifs' );

// Nothing to go on is still a valid plan — this is the last resort, so it may
// never be the thing that throws.
$empty = Fallback_Card::plan( '', '' );
rssai_ok( in_array( $empty['motif'], Fallback_Card::MOTIFS, true ), 'an empty article still plans a card' );
rssai_ok( $empty['density'] >= 4, 'density stays in range for an empty article' );

// Bounds hold across a wide spread of inputs.
for ( $i = 0; $i < 200; $i++ ) {
	$p = Fallback_Card::plan( 'Headline number ' . $i, 0 === $i % 3 ? 'Markets' : 'Economy' );
	if ( ! in_array( $p['motif'], Fallback_Card::MOTIFS, true )
		|| $p['density'] < 4 || $p['density'] > 8
		|| $p['angle'] < -22 || $p['angle'] > 23
		|| $p['offset'] < 0 || $p['offset'] > 200 ) {
		rssai_ok( false, "plan out of bounds for headline $i" );
		break;
	}
}
rssai_ok( true, 'plan stays in bounds over 200 headlines' );

/* ---------------------------------------------------------------- render() */

if ( ! Fallback_Card::available() ) {
	echo "  SKIP: GD is not available on this PHP, so the drawing tests did not run.\n";
	rssai_done( 'fallback-card' );
}

$png = Fallback_Card::render( 'ECB holds rates as inflation cools', 'Economy' );
rssai_ok( is_string( $png ) && '' !== $png, 'render returns bytes' );
rssai_ok( "\x89PNG\r\n\x1a\n" === substr( (string) $png, 0, 8 ), 'the bytes are a PNG' );

$info = getimagesizefromstring( (string) $png );
rssai_ok( false !== $info, 'the PNG is readable' );
rssai_ok( Fallback_Card::WIDTH === $info[0] && Fallback_Card::HEIGHT === $info[1], 'the card is 1200x675' );
rssai_ok( 'image/png' === $info['mime'], 'the MIME type is image/png' );

// Byte-identical for the same article, different for another.
rssai_ok(
	Fallback_Card::render( 'ECB holds rates as inflation cools', 'Economy' ) === $png,
	'the same article renders byte-identical cards'
);
rssai_ok(
	Fallback_Card::render( 'Bitcoin ETF inflows hit a monthly record', 'Crypto' ) !== $png,
	'a different article renders a different card'
);

// Every motif must draw. Find a headline for each, then render it.
$by_motif = array();
for ( $i = 0; $i < 400 && count( $by_motif ) < count( Fallback_Card::MOTIFS ); $i++ ) {
	$headline = 'Sample market headline ' . $i;
	$motif    = Fallback_Card::plan( $headline, 'Markets' )['motif'];
	if ( ! isset( $by_motif[ $motif ] ) ) {
		$by_motif[ $motif ] = $headline;
	}
}
rssai_ok(
	count( $by_motif ) === count( Fallback_Card::MOTIFS ),
	'every motif is reachable from some headline'
);
foreach ( Fallback_Card::MOTIFS as $motif ) {
	if ( ! isset( $by_motif[ $motif ] ) ) {
		continue;
	}
	$bytes = Fallback_Card::render( $by_motif[ $motif ], 'Markets' );
	$size  = is_string( $bytes ) ? getimagesizefromstring( $bytes ) : false;
	rssai_ok(
		false !== $size && Fallback_Card::WIDTH === $size[0] && Fallback_Card::HEIGHT === $size[1],
		"motif $motif draws a valid card: $motif"
	);
}

// An article with no title at all must still get a picture.
$blank = Fallback_Card::render( '', '' );
rssai_ok( is_string( $blank ) && false !== getimagesizefromstring( $blank ), 'a blank article still draws a card' );

// Long and non-ASCII titles are just seeds; they must not break anything.
$long = Fallback_Card::render( str_repeat( 'Inflação, mercados e obrigações ', 40 ), 'Economia' );
rssai_ok( is_string( $long ) && false !== getimagesizefromstring( $long ), 'a long accented title draws a card' );

rssai_done( 'fallback-card' );
