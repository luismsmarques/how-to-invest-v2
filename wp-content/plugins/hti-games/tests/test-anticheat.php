<?php
/**
 * The boundary the whole design rests on: what the client gets before it decides.
 *
 * Both games are worthless the moment the answer is in the page, and the only
 * thing standing between the two is REST::public_challenge_stc() and
 * REST::public_challenge_reveal(). So they are run here against a fixture that
 * is deliberately WORSE than any real scenario — every secret meta key
 * present, and poison planted inside each candle and each fundamental too —
 * and the assertions are made against the SERIALISED JSON rather than against
 * the array. Checking `isset( $out['hti_stc_symbol'] )` would pass happily
 * while the symbol sat three levels down inside a candle; searching the bytes
 * that actually go over the wire cannot be fooled that way.
 *
 * This is also the regression test for the whitelist-vs-blacklist decision. If
 * someone ever rewrites these functions as "copy the meta, unset the secrets",
 * the nested poison in this fixture is what turns red.
 *
 *   php wp-content/plugins/hti-games/tests/test-anticheat.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stand-in for the WordPress salt: the day handle is an HMAC under it.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-rest.php';

use HTI\Games\Config;
use HTI\Games\Day;
use HTI\Games\REST;

/**
 * Every array key appearing anywhere in a structure, at any depth.
 *
 * @param mixed $data Structure.
 * @return array<int,string>
 */
function keys_deep( $data ): array {
	$out = array();
	if ( ! is_array( $data ) ) {
		return $out;
	}
	foreach ( $data as $key => $value ) {
		$out[] = (string) $key;
		$out   = array_merge( $out, keys_deep( $value ) );
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* A scenario with every secret it could possibly have                 */
/* ------------------------------------------------------------------ */

$candles = array();
for ( $i = 0; $i < 120; $i++ ) {
	// Visible ticks live in the 100xxx range, outcome ticks in the 99xxxx one,
	// so a leaked tick is identifiable by its value alone.
	$base      = $i < Config::STC_VISIBLE ? 100000 + $i : 990000 + $i;
	$candles[] = array(
		'o'      => $base,
		'h'      => $base + 50,
		'l'      => $base - 50,
		'c'      => $base + 10,
		// Poison riding INSIDE a candle: the thing a blacklist never catches.
		'ts'     => '2019-03-04T00:00:00Z',
		'symbol' => 'EURUSD',
		'label'  => 'EURUSD daily, 2019',
	);
}

$stc_meta = array(
	'hti_stc_candles'    => $candles,
	'hti_stc_atr'        => 420,
	'hti_stc_symbol'     => 'EURUSD',
	'hti_stc_class'      => 'forex-major',
	'hti_stc_pass_right' => 1,
	'hti_stc_outcome'    => 'stop',
	'hti_stc_touch_idx'  => 17,
	'hti_stc_lesson_en'  => 'The stop was hit long before the target.',
	'hti_stc_lesson_pt'  => 'O stop foi atingido muito antes do alvo.',
	'hti_stc_source_url' => 'https://example.com/eurusd-2019',
	'ID'                 => 987654321,
	'post_id'            => 987654321,
);

$player = array(
	'stc'    => array(
		'capital' => 8400,
		'streak'  => 3,
	),
	'reveal' => array(
		'capital'   => 12500,
		'index_cap' => 10900,
		'streak'    => 1,
	),
);

$stc  = REST::public_challenge_stc( $stc_meta, $player );
$json = (string) wp_json_encode( $stc );

echo "Survive the Charts: the client sees the setup and nothing else\n";
hti_games_check( 80 === count( $stc['candles'] ), 'exactly 80 candles come back (' . count( $stc['candles'] ) . ')' );
hti_games_check( Config::STC_VISIBLE === count( $stc['candles'] ), 'and 80 is what Config calls the visible window' );

$shapes = array_filter( $stc['candles'], fn( $c ) => 4 !== count( $c ) || array_keys( $c ) !== array( 0, 1, 2, 3 ) );
hti_games_check( array() === $shapes, 'every candle is exactly four positional integers' );
hti_games_check( 100000 === $stc['candles'][0][0], 'the first candle is the first visible tick' );
hti_games_check( 100079 === $stc['candles'][79][0], 'the last candle is tick 79 — the one before the outcome starts' );

echo "\nNot one tick of the outcome escapes\n";
$leaked = array();
for ( $i = Config::STC_VISIBLE; $i < 120; $i++ ) {
	if ( str_contains( $json, (string) ( 990000 + $i ) ) ) {
		$leaked[] = 990000 + $i;
	}
}
hti_games_check( array() === $leaked, 'no tick from index 80 onwards appears in the payload (' . count( $leaked ) . ' leaked)' );

echo "\nNor does anything that answers the question\n";
foreach ( array(
	'hti_stc_class'      => 'the asset class key',
	'hti_stc_pass_right' => 'whether passing was right',
	'hti_stc_symbol'     => 'the instrument key',
	'hti_stc_outcome'    => 'the outcome key',
	'hti_stc_touch_idx'  => 'where price touched',
	'hti_stc_lesson_en'  => 'the lesson key',
	'EURUSD'             => 'the instrument name, including the copy inside every candle',
	'forex-major'        => 'the asset class value',
	'stop'               => 'the outcome value',
	'The stop was hit'   => 'the English lesson',
	'O stop foi'         => 'the Portuguese lesson',
	'987654321'          => 'the scenario post id',
	'example.com'        => 'the source URL',
	'2019-03-04'         => 'the real date of the chart',
) as $needle => $label ) {
	hti_games_check( ! str_contains( $json, $needle ), "the payload does not contain {$label}" );
}

echo "\nThe day handle is a handle, not an identifier\n";
hti_games_check( 16 === strlen( $stc['ref'] ), 'the ref is 16 hex characters' );
hti_games_check( 1 === preg_match( '/^[0-9a-f]{16}$/', $stc['ref'] ), 'and is hexadecimal' );
hti_games_check( $stc['ref'] === REST::ref( 'stc', Day::key() ), 'it is stable for the same game and day' );
hti_games_check( $stc['ref'] !== REST::ref( 'reveal', Day::key() ), 'and differs between the two games on the same day' );
hti_games_check( $stc['ref'] !== REST::ref( 'stc', '2000-01-01' ), 'and between two days of the same game' );

echo "\nThe player's own numbers travel with the challenge\n";
hti_games_check( 8400 === $stc['capital'], 'their capital is theirs, not the default' );
hti_games_check( 3 === $stc['streak'], 'so is their streak' );
hti_games_check( false === $stc['played'], 'and a fresh challenge is not marked played' );
hti_games_check( Config::CAPITAL_START === REST::public_challenge_stc( $stc_meta, array() )['capital'], 'a visitor with no row gets the starting capital' );

/* ------------------------------------------------------------------ */
/* A Reveal case with every secret it could possibly have              */
/* ------------------------------------------------------------------ */

$metrics = array();
foreach ( array( 'pe', 'ps', 'debt_equity', 'roe', 'margin', 'growth', 'payout', 'beta' ) as $key ) {
	$metrics[] = array(
		'key'     => $key,
		'value'   => 1830,
		'avg'     => 2210,
		// Poison riding INSIDE a fundamental.
		'company' => 'Nokia Oyj',
		'year'    => 2007,
		'note'    => 'from the annual report',
	);
}

$rev_meta = array(
	'hti_rev_sector'             => 'Technology hardware',
	'hti_rev_region'             => 'Europe',
	'hti_rev_size'               => 'Large cap',
	'hti_rev_metrics'            => $metrics,
	'hti_rev_headlines'          => array(
		array(
			'text'       => 'Handset maker holds a third of the global market',
			'source_url' => 'https://example.org/nokia-2007',
		),
		array( 'text' => 'Rivals launch touchscreen devices' ),
		array( 'text' => 'Operating margin slips for a second quarter' ),
		array( 'text' => 'A fourth headline that must not be served' ),
		array( 'text' => 'Nor a fifth' ),
	),
	'hti_rev_company'            => 'Nokia Oyj',
	'hti_rev_year'               => 2007,
	'hti_rev_return_5y_bp'       => -8213,
	'hti_rev_index_return_5y_bp' => 4127,
	'hti_rev_outcome_headline'   => 'The market leader that missed the touchscreen',
	'hti_rev_lesson_en'          => 'Dominance is a snapshot, not a moat.',
	'hti_rev_lesson_pt'          => 'A liderança é um retrato, não um fosso.',
	'hti_rev_sources'            => array( array( 'label' => 'Annual report', 'url' => 'https://example.org/nokia-2007' ) ),
	'ID'                         => 123456789,
);

$rev  = REST::public_challenge_reveal( $rev_meta, $player );
$json = (string) wp_json_encode( $rev );

echo "\nThe Reveal: the client sees the dossier and nothing else\n";
hti_games_check( 6 === count( $rev['metrics'] ), 'exactly six fundamentals are served, not the eight stored' );
hti_games_check( 3 === count( $rev['headlines'] ), 'exactly three headlines are served, not the five stored' );

$metric_keys = array_unique( array_merge( ...array_map( 'array_keys', $rev['metrics'] ) ) );
sort( $metric_keys );
hti_games_check( array( 'avg', 'key', 'value' ) === $metric_keys, 'a fundamental is rebuilt as exactly key/value/avg' );

foreach ( array(
	'hti_rev_company'            => 'the company key',
	'hti_rev_year'               => 'the year key',
	'hti_rev_return_5y_bp'       => 'the return key',
	'hti_rev_index_return_5y_bp' => 'the index return key',
	'hti_rev_outcome_headline'   => 'the outcome headline key',
	'hti_rev_lesson_en'          => 'the lesson key',
	'hti_rev_sources'            => 'the sources key',
	'Nokia'                      => 'the company name, including the copy inside every fundamental',
	'2007'                       => 'the year, including the copy inside every fundamental',
	'-8213'                      => 'the five-year return',
	'4127'                       => "the index's five-year return",
	'missed the touchscreen'     => 'the outcome headline',
	'Dominance is a snapshot'    => 'the English lesson',
	'A liderança'                => 'the Portuguese lesson',
	'example.org'                => 'the source URL — which names the company in its own slug',
	'123456789'                  => 'the case post id',
	'A fourth headline'          => 'the fourth headline',
) as $needle => $label ) {
	hti_games_check( ! str_contains( $json, $needle ), "the payload does not contain {$label}" );
}

echo "\nWhat the dossier IS allowed to say, it says\n";
hti_games_check( 'Technology hardware' === $rev['sector'], 'the sector is served' );
hti_games_check( 'Europe' === $rev['region'], 'the region is served' );
hti_games_check( 'Large cap' === $rev['size_band'], 'the size band is served' );
hti_games_check( str_contains( $rev['headlines'][0], 'third of the global market' ), 'the anonymised headlines are served' );
hti_games_check( Config::REVEAL_SIZES === $rev['sizes'], 'and the sizes on offer' );

echo "\nNo secret meta key survives anywhere in either payload, at any depth\n";
$all_keys  = array_merge( keys_deep( $stc ), keys_deep( $rev ) );
$forbidden = array_values(
	array_filter(
		$all_keys,
		fn( $k ) => str_starts_with( $k, 'hti_' ) || in_array( $k, array( 'ID', 'post_id', 'symbol', 'company', 'ts', 'label', 'note', 'source_url', 'year' ), true )
	)
);
hti_games_check( array() === $forbidden, 'not one meta-shaped key appears in either payload (' . implode( ', ', $forbidden ) . ')' );

echo "\nThe whitelist survives garbage as well as secrets\n";
$empty = REST::public_challenge_stc( array(), array() );
hti_games_check( array() === $empty['candles'], 'a scenario with no candles yields no candles rather than a warning' );
hti_games_check( 0 === $empty['atr'], 'and no ATR rather than a null' );
hti_games_check( array() === REST::metrics( 'not-an-array' ), 'a metrics blob that is not a list yields nothing' );
hti_games_check( array() === REST::headlines( null ), 'nor do null headlines' );
hti_games_check( 2 === count( REST::visible_candles( '[[1,2,3,4],[5,6,7,8]]' ) ), 'candles stored as JSON are decoded' );
hti_games_check( array( 1, 2, 3, 4 ) === REST::visible_candles( '[[1,2,3,4]]' )[0], 'and normalised to four integers' );

hti_games_done();
