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
 * anyone ever rewrites these functions as "copy the meta, unset the secrets",
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
require_once __DIR__ . '/../includes/class-stc-engine.php';
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

$ticks = array();
for ( $i = 0; $i < 120; $i++ ) {
	// Visible ticks live in the 1000xx range, outcome ticks in the 99xxxx one,
	// so a leaked tick is identifiable by its value alone.
	$base    = $i < Config::STC_VISIBLE ? 100000 + $i : 990000 + $i;
	$ticks[] = array( $base, $base + 50, $base - 50, $base + 10 );
}

// The same series again, this time with poison riding INSIDE each candle —
// the thing a blacklist never catches, because it never looks there.
$dirty = array();
foreach ( $ticks as $i => $quad ) {
	$dirty[] = array(
		'o'      => $quad[0],
		'h'      => $quad[1],
		'l'      => $quad[2],
		'c'      => $quad[3],
		'ts'     => '2019-03-04T00:00:00Z',
		'symbol' => 'EURUSD',
		'label'  => 'EURUSD daily, 2019',
	);
}

$stc_meta = array(
	// Stored as a JSON string by CPT::san_ticks(), which is how it arrives.
	'hti_stc_ticks'      => (string) wp_json_encode( $ticks ),
	'hti_stc_scale'      => Config::TICK_SCALE,
	'hti_stc_visible'    => Config::STC_VISIBLE,
	'hti_stc_outcome'    => Config::STC_OUTCOME,
	'hti_stc_class'      => 'forex-major',
	'hti_stc_pass_right' => '1',
	'hti_stc_real'       => '1',
	'hti_stc_source'     => 'Dukascopy EURUSD daily, 2019',
	'hti_stc_symbol'     => 'EURUSD',
	'hti_stc_seed'       => 'eurusd-2019-03',
	'hti_stc_checksum'   => 'deadbeefcafe',
	'hti_stc_lesson_en'  => 'The stop was hit long before the target.',
	'hti_stc_lesson_pt'  => 'O stop foi atingido muito antes do alvo.',
	'ID'                 => 987654321,
	'post_id'            => 987654321,
);

$player = array(
	'lang'   => 'en',
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

echo "\nA scenario claiming a wider window still cannot open one\n";
$greedy            = $stc_meta;
$greedy['hti_stc_visible'] = 500;
hti_games_check( 80 === count( REST::public_challenge_stc( $greedy, $player )['candles'] ), 'hti_stc_visible = 500 is clamped to the configured 80 — the slice fails closed' );
$narrow            = $stc_meta;
$narrow['hti_stc_visible'] = 60;
hti_games_check( 60 === count( REST::public_challenge_stc( $narrow, $player )['candles'] ), 'a genuinely narrower window is respected' );

echo "\nNor does anything that answers the question\n";
foreach ( array(
	'hti_stc_class'      => 'the asset class key',
	'hti_stc_pass_right' => 'whether passing was right',
	'hti_stc_symbol'     => 'the instrument key',
	'hti_stc_lesson_en'  => 'the lesson key',
	'hti_stc_source'     => 'the source key',
	'EURUSD'             => 'the instrument name',
	'forex-major'        => 'the asset class value',
	'The stop was hit'   => 'the English lesson',
	'O stop foi'         => 'the Portuguese lesson',
	'987654321'          => 'the scenario post id',
	'Dukascopy'          => 'the data source',
	'deadbeefcafe'       => 'the content checksum',
	'eurusd-2019-03'     => 'the generator seed',
) as $needle => $label ) {
	hti_games_check( ! str_contains( $json, $needle ), "the payload does not contain {$label}" );
}

echo "\nPoison riding inside a candle does not ride out with it\n";
$dirty_meta                  = $stc_meta;
$dirty_meta['hti_stc_ticks'] = $dirty;
$dirty_json                  = (string) wp_json_encode( REST::public_challenge_stc( $dirty_meta, $player ) );
hti_games_check( ! str_contains( $dirty_json, 'EURUSD' ), 'a symbol stored inside every candle never reaches the client' );
hti_games_check( ! str_contains( $dirty_json, '2019-03-04' ), 'nor does the real date of the chart' );
hti_games_check( 80 === count( REST::public_challenge_stc( $dirty_meta, $player )['candles'] ), 'and the keyed shape still yields 80 candles' );

echo "\nThe ATR is derived, so it cannot disagree with the candles it came from\n";
hti_games_check( 100 === $stc['atr'], 'the served ATR is computed over the visible window (' . $stc['atr'] . ')' );
$lying               = $stc_meta;
$lying['hti_stc_atr'] = 999999;
hti_games_check( 100 === REST::public_challenge_stc( $lying, $player )['atr'], 'a stray stored ATR is ignored — there is no such meta key and none is read' );

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

$fundamentals = array();
foreach ( array( 'pe', 'ps', 'debt_equity', 'roe', 'margin', 'growth', 'payout', 'beta' ) as $key ) {
	$fundamentals[] = array(
		'key'           => $key,
		'label_en'      => 'Price / earnings',
		'label_pt'      => 'Preço / lucro',
		'value_en'      => '18.3x',
		'value_pt'      => '18,3x',
		'sector_avg_en' => '22.1x',
		'sector_avg_pt' => '22,1x',
		'tint'          => 'good',
		// Poison riding INSIDE a fundamental.
		'company'       => 'Nokia Oyj',
		'year'          => 2007,
		'note'          => 'from the annual report',
	);
}

$rev_meta = array(
	'hti_rev_company'            => 'Nokia Oyj',
	'hti_rev_year'               => 2007,
	'hti_rev_sector_en'          => 'Technology hardware',
	'hti_rev_sector_pt'          => 'Equipamento tecnológico',
	'hti_rev_revenue_band_en'    => 'Over $50bn',
	'hti_rev_revenue_band_pt'    => 'Mais de 50 mil milhões',
	'hti_rev_fundamentals'       => (string) wp_json_encode( $fundamentals ),
	'hti_rev_headlines'          => (string) wp_json_encode(
		array(
			array( 'en' => 'Handset maker holds a third of the global market', 'pt' => 'Fabricante detém um terço do mercado global' ),
			array( 'en' => 'Rivals launch touchscreen devices', 'pt' => 'Rivais lançam ecrãs tácteis' ),
			array( 'en' => 'Operating margin slips for a second quarter', 'pt' => 'Margem operacional cai no segundo trimestre' ),
			array( 'en' => 'A fourth headline that must not be served', 'pt' => 'Um quarto título' ),
		)
	),
	'hti_rev_return_5y_bp'       => -8213,
	'hti_rev_index_return_5y_bp' => 4127,
	'hti_rev_context_en'         => 'By 2012 the share price had fallen by four fifths.',
	'hti_rev_context_pt'         => 'Em 2012 a ação tinha caído quatro quintos.',
	'hti_rev_lesson_en'          => 'Dominance is a snapshot, not a moat.',
	'hti_rev_lesson_pt'          => 'A liderança é um retrato, não um fosso.',
	'hti_rev_source_url'         => 'https://example.org/nokia-annual-2007',
	'hti_rev_source_label'       => 'Annual report 2007',
	'hti_rev_source_accessed'    => '2026-05-01',
	'hti_rev_verified'           => '1',
	'hti_rev_verified_by'        => 'editor@example.org',
	'hti_rev_verified_at'        => '2026-05-01 10:00:00',
	'ID'                         => 123456789,
);

$rev  = REST::public_challenge_reveal( $rev_meta, $player );
$json = (string) wp_json_encode( $rev );

echo "\nThe Reveal: the client sees the dossier and nothing else\n";
hti_games_check( 6 === count( $rev['fundamentals'] ), 'exactly six fundamentals are served, not the eight stored' );
hti_games_check( 3 === count( $rev['headlines'] ), 'exactly three headlines are served, not the four stored' );

$fund_keys = array_unique( array_merge( ...array_map( 'array_keys', $rev['fundamentals'] ) ) );
sort( $fund_keys );
hti_games_check( array( 'key', 'label', 'sector_avg', 'tint', 'value' ) === $fund_keys, 'a fundamental is rebuilt as exactly key/label/value/sector_avg/tint' );
hti_games_check( 'good' === $rev['fundamentals'][0]['tint'], 'the tint survives when it is in the vocabulary' );

$loud                          = $rev_meta;
$loud['hti_rev_fundamentals'] = (string) wp_json_encode( array( array( 'key' => 'pe', 'tint' => '<script>x</script>' ) ) );
hti_games_check( 'warn' === REST::public_challenge_reveal( $loud, $player )['fundamentals'][0]['tint'], 'and an invented tint becomes warn rather than a class attribute of its own choosing' );

foreach ( array(
	'hti_rev_company'         => 'the company key',
	'hti_rev_year'            => 'the year key',
	'hti_rev_return_5y_bp'    => 'the return key',
	'hti_rev_context_en'      => 'the context key',
	'hti_rev_lesson_en'       => 'the lesson key',
	'hti_rev_source_url'      => 'the source key',
	'Nokia'                   => 'the company name, including the copy inside every fundamental',
	'2007'                    => 'the year, including the copy inside every fundamental',
	'-8213'                   => 'the five-year return',
	'4127'                    => "the index's five-year return",
	'four fifths'             => 'the outcome context',
	'Dominance is a snapshot' => 'the English lesson',
	'A liderança'             => 'the Portuguese lesson',
	'example.org'             => 'the source URL — which names the company in its own slug',
	'editor@example.org'      => 'the verifier',
	'123456789'               => 'the case post id',
	'A fourth headline'       => 'the fourth headline',
	'annual report'           => 'the editor note stored inside every fundamental',
) as $needle => $label ) {
	hti_games_check( ! str_contains( $json, $needle ), "the payload does not contain {$label}" );
}

echo "\nWhat the dossier IS allowed to say, it says — in one language\n";
hti_games_check( 'Technology hardware' === $rev['sector'], 'the sector is served' );
hti_games_check( 'Over $50bn' === $rev['revenue_band'], 'the revenue band is served' );
hti_games_check( str_contains( $rev['headlines'][0], 'third of the global market' ), 'the anonymised headlines are served' );
hti_games_check( Config::REVEAL_SIZES === $rev['sizes'], 'and the sizes on offer' );

$player_pt         = $player;
$player_pt['lang'] = 'pt';
$pt                = REST::public_challenge_reveal( $rev_meta, $player_pt );
hti_games_check( 'Equipamento tecnológico' === $pt['sector'], 'a Portuguese player gets the Portuguese sector' );
hti_games_check( '18,3x' === $pt['fundamentals'][0]['value'], 'and Portuguese decimal commas in the fundamentals' );
hti_games_check( ! str_contains( (string) wp_json_encode( $pt ), 'Technology hardware' ), 'and not the English half as well — one language on the wire, not both' );

echo "\nNo secret meta key survives anywhere in either payload, at any depth\n";
$all_keys  = array_merge( keys_deep( $stc ), keys_deep( $rev ) );
$forbidden = array_values(
	array_filter(
		$all_keys,
		fn( $k ) => str_starts_with( $k, 'hti_' ) || in_array( $k, array( 'ID', 'post_id', 'symbol', 'company', 'ts', 'label_en', 'note', 'source_url', 'year', 'content_id' ), true )
	)
);
hti_games_check( array() === $forbidden, 'not one meta-shaped key appears in either payload (' . implode( ', ', $forbidden ) . ')' );

echo "\nThe whitelist survives garbage as well as secrets\n";
$empty = REST::public_challenge_stc( array(), array() );
hti_games_check( array() === $empty['candles'], 'a scenario with no ticks yields no candles rather than a warning' );
hti_games_check( 0 === $empty['atr'], 'and no ATR rather than a null' );
hti_games_check( Config::TICK_SCALE === $empty['tick_scale'], 'and falls back to the configured tick scale' );
hti_games_check( array() === REST::fundamentals( 'not-json' ), 'a fundamentals blob that is not JSON yields nothing' );
hti_games_check( array() === REST::headlines( null ), 'nor do null headlines' );
$junk = REST::visible_ticks( array( 'hti_stc_ticks' => '{"not":"a list"}' ) );
hti_games_check( array( 0, 0, 0, 0 ) === ( $junk[0] ?? null ), 'a malformed tick series becomes flat zeroes rather than a fatal on a public page' );

hti_games_done();
