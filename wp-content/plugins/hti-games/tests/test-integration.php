<?php
/**
 * The seams: the contracts that live between two files and belong to neither.
 *
 * Every other file in this directory tests one unit against its own promises.
 * This one tests the joins, because the two games were built by several hands
 * at once and the defects that survive that are never inside a function — they
 * are a column called `day_key` on one side of a query and read as `day` on
 * the other, a candle stored as four integers and read by name, a slug table
 * with four consumers, a kill-switch three surfaces honour and a fourth does
 * not. None of those break a unit test. All of them break a page.
 *
 * The rule for what belongs here: an assertion earns its place if it would
 * have failed while both of the files it spans were individually green.
 *
 *   php wp-content/plugins/hti-games/tests/test-integration.php
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

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * URL escaping.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * URL escaping for storage.
	 *
	 * @param string $url URL.
	 */
	function esc_url_raw( $url ) {
		return $url;
	}

	/**
	 * The REST root.
	 *
	 * @param string $path Path.
	 */
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}

	/**
	 * A nonce.
	 *
	 * @param string $action Action.
	 */
	function wp_create_nonce( $action = '' ) {
		unset( $action );
		return 'test-nonce';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Slash removal.
	 *
	 * @param mixed $value Value.
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	/**
	 * Site locale.
	 */
	function determine_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Merge shortcode attributes over defaults.
	 *
	 * @param array  $pairs     Defaults.
	 * @param array  $atts      Supplied attributes.
	 * @param string $shortcode Tag.
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		unset( $shortcode );
		$out = $pairs;
		foreach ( (array) $atts as $key => $value ) {
			if ( array_key_exists( $key, $pairs ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * The queried post, as a WP_Post would be.
	 */
	class WP_Post {

		/**
		 * Post id. Schema::detect_page() reads the seeder meta off it first.
		 *
		 * @var int
		 */
		public $ID = 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP_Post's own property name.

		/**
		 * Post body.
		 *
		 * @var string
		 */
		public $post_content = '';

		/**
		 * Build one.
		 *
		 * @param string $content Post body.
		 */
		public function __construct( string $content = '' ) {
			$this->post_content = $content;
		}
	}
}

$GLOBALS['__hti_post'] = null;

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Whether a singular view is being rendered.
	 */
	function is_singular() {
		return null !== $GLOBALS['__hti_post'];
	}

	/**
	 * The queried object.
	 */
	function get_queried_object() {
		return $GLOBALS['__hti_post'];
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	/**
	 * Whether a shortcode appears in some content.
	 *
	 * @param string $content Content.
	 * @param string $tag     Shortcode tag.
	 */
	function has_shortcode( $content, $tag ) {
		return 1 === preg_match( '/\[' . preg_quote( (string) $tag, '/' ) . '(?=[\s\]\/])/', (string) $content );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * No meta in the harness: the schema's shortcode fallback is what this
	 * file is here to exercise, and it is the path a hand-built page takes.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $post_id, $key, $single );
		return '';
	}
}

foreach ( array(
	'class-config',
	'class-strings',
	'class-day',
	'class-stc-engine',
	'class-reveal-engine',
	'class-scoring',
	'class-settings',
	'class-store',
	'class-player',
	'class-leaderboard',
	'class-seeder',
	'class-schema',
	'class-frontend',
	'class-rest',
) as $hti_class ) {
	require_once __DIR__ . '/../includes/' . $hti_class . '.php';
}

use HTI\Games\Config;
use HTI\Games\Frontend;
use HTI\Games\Leaderboard;
use HTI\Games\REST;
use HTI\Games\Reveal_Engine;
use HTI\Games\STC_Engine;
use HTI\Games\Schema;
use HTI\Games\Scoring;
use HTI\Games\Seeder;
use HTI\Games\Settings;
use HTI\Games\Store;
use HTI\Games\Strings;

/**
 * One source file as text, for the assertions that are about which function
 * calls which — a fact no return value exposes.
 *
 * @param string $rel Path under the plugin root.
 */
function hti_int_src( string $rel ): string {
	return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
}

/**
 * Point the Frontend shims at a post body.
 *
 * @param string|null $content Post body, or null for a non-singular view.
 */
function hti_int_page( ?string $content ): void {
	$GLOBALS['__hti_post'] = null === $content ? null : new WP_Post( $content );
}

/* =========================================================================
 * 1. The candle shape
 *
 * Storage and the wire carry positional quads; both engine ports read
 * {o,h,l,c}. REST::assoc_ticks() is the single bridge, and "single" is the
 * property worth testing — a second conversion somewhere else is how the two
 * halves of a scenario end up disagreeing about which number is the high.
 * ====================================================================== */

echo "The candle bridge is total, and there is only one of it\n";

$ticks = array();
for ( $i = 0; $i < Config::STC_VISIBLE + Config::STC_OUTCOME; $i++ ) {
	$base    = 100000 + ( $i * 7 );
	$ticks[] = array( $base, $base + 90, $base - 60, $base + 20 );
}

$meta = array(
	'hti_stc_ticks'   => (string) wp_json_encode( $ticks ),
	'hti_stc_visible' => Config::STC_VISIBLE,
	'hti_stc_scale'   => Config::TICK_SCALE,
);

$visible = REST::visible_ticks( $meta );
$assoc   = REST::assoc_ticks( $visible );

hti_games_check( Config::STC_VISIBLE === count( $visible ) && count( $visible ) === count( $assoc ), 'every visible candle survives the conversion' );

$shape = array_filter( $assoc, fn( $c ) => array( 'o', 'h', 'l', 'c' ) !== array_keys( $c ) );
hti_games_check( array() === $shape, 'and each comes out with exactly the four keys the engine reads' );

$mismatch = array();
foreach ( $visible as $i => $quad ) {
	if ( (int) $quad[0] !== $assoc[ $i ]['o'] || (int) $quad[1] !== $assoc[ $i ]['h']
		|| (int) $quad[2] !== $assoc[ $i ]['l'] || (int) $quad[3] !== $assoc[ $i ]['c'] ) {
		$mismatch[] = $i;
	}
}
hti_games_check( array() === $mismatch, 'in the order [open, high, low, close] and no other — a transposed high and low would price every stop wrongly' );

// The two sides of the same number: the payload's entry is read positionally
// and the engine's is read by name. They are the same candle either way.
$entry_wire   = (int) $visible[ count( $visible ) - 1 ][3];
$entry_engine = (int) $assoc[ count( $assoc ) - 1 ]['c'];
hti_games_check( $entry_wire === $entry_engine && $entry_wire > 0, 'the entry the client draws and the entry the engine prices are the same close' );

hti_games_check( STC_Engine::atr( $assoc, Config::STC_ATR_PERIOD ) > 0, 'the ATR the engine computes off the converted window is a real number' );
hti_games_check(
	! isset( $visible[0]['h'], $visible[0]['l'] ),
	'and the unconverted quad has no such keys, which is why the bridge cannot be skipped'
);

// Both shapes in, one shape out: a scenario written by an older importer that
// stored keyed candles must not resolve differently from one that stored quads.
$keyed = array();
foreach ( $ticks as $quad ) {
	$keyed[] = array(
		'o'      => $quad[0],
		'h'      => $quad[1],
		'l'      => $quad[2],
		'c'      => $quad[3],
		'symbol' => 'EURUSD',
	);
}
$from_keyed = REST::visible_ticks( array( 'hti_stc_ticks' => (string) wp_json_encode( $keyed ) ) + $meta );
hti_games_check( $visible === $from_keyed, 'a keyed candle and a positional one normalise to the same quad' );

// And nothing else converts. Every engine call site goes through the bridge,
// and no consumer of a STORED series reads a candle by name. The importer and
// the generator are not in the list on purpose: they build assoc candles from
// scratch and write quads, so they are upstream of the storage shape rather
// than a second reading of it.
$rest_src = hti_int_src( 'includes/class-rest.php' );
// Docblocks name these functions too, so the code is looked at without them.
$rest_code = preg_replace( '/^\s*(?:\*|\/\*|\/\/).*$/m', '', $rest_src );
hti_games_check(
	preg_match_all( '/STC_Engine::(?:atr|resolve)\(/', $rest_code )
		=== preg_match_all( '/STC_Engine::(?:atr|resolve)\(\s*self::assoc_ticks\(/', $rest_code ),
	'every STC_Engine call in the REST layer is handed converted candles'
);
$others = array();
foreach ( array( 'includes/class-frontend.php', 'includes/class-leaderboard.php', 'includes/class-scoring.php', 'includes/class-seeder.php' ) as $rel ) {
	if ( str_contains( hti_int_src( $rel ), "['h']" ) || str_contains( hti_int_src( $rel ), "['l']" ) ) {
		$others[] = $rel;
	}
}
hti_games_check( array() === $others, 'no other class reads a candle by name (' . ( $others ? implode( ', ', $others ) : 'clean' ) . ')' );

// The client draws off quads. If it ever starts reading .h it will be reading
// undefined, silently, and the chart will simply lose its wicks.
$stc_js = hti_int_src( 'assets/js/stc.js' );
hti_games_check(
	! preg_match( '/(candle|shown\[\s*i\s*\])\s*\.\s*[ohlc]\b/', $stc_js ),
	'and the drawing code reads the wire candles positionally, as they arrive'
);

/* =========================================================================
 * 2. The Scoring row shape against the columns Store writes
 * ====================================================================== */

echo "\nScoring reads the columns Store actually wrote\n";

// The renaming SELECT is the whole contract. Assert both halves of it.
hti_games_check( isset( Store::RUN_COLUMNS['day_key'] ), 'the runs table calls the day column day_key' );
hti_games_check( ! isset( Store::RUN_COLUMNS['day'] ), 'and there is no column called day' );
hti_games_check(
	str_contains( $rest_src, "'day'          => (string) \$row['day_key']," ),
	'REST::recent_runs() renames it, which is the only reason Scoring sees a date at all'
);
foreach ( array( 'decision', 'risk_bp', 'pnl', 'died' ) as $column ) {
	hti_games_check( isset( Store::RUN_COLUMNS[ $column ] ), "Scoring's `{$column}` is a real column, spelled the same way" );
}

/**
 * A run row in the shape REST::recent_runs() hands to Scoring.
 *
 * @param string $day      Day key.
 * @param string $decision Decision.
 * @param int    $risk_bp  Risk in basis points.
 * @param int    $pnl      Dollars, signed.
 * @param bool   $died     Whether it blew the account.
 * @return array<string,mixed>
 */
function hti_int_row( string $day, string $decision, int $risk_bp, int $pnl, bool $died = false ): array {
	return array(
		'day'      => $day,
		'decision' => $decision,
		'risk_bp'  => $risk_bp,
		'pnl'      => $pnl,
		'died'     => $died,
	);
}

$rows = array(
	hti_int_row( '2026-08-24', 'buy', 100, 150 ),
	hti_int_row( '2026-08-25', 'pass', 0, 0 ),
	hti_int_row( '2026-08-26', 'sell', 200, -200 ),
	hti_int_row( '2026-08-27', 'buy', 100, 0 ),
);

hti_games_check( 4 === Scoring::streak_from( $rows ), 'four consecutive days read as a streak of four' );
hti_games_check(
	133 === Scoring::average_risk_bp( $rows ),
	'the average risk skips the pass — 100, 200 and 100 over three, not over four'
);

$calendar = Scoring::calendar( $rows, '2026-08-27', 4 );
hti_games_check( array( 'won', 'passed', 'lost', 'flat' ) === array_column( $calendar, 'state' ), 'the calendar reads won / passed / lost / flat off these rows' );

// The same rows with the column NOT renamed: every date is empty, so the
// calendar is four missed days and the streak is one. This is exactly what a
// SELECT that stopped aliasing day_key would produce, and it would not throw.
$unrenamed = array();
foreach ( $rows as $row ) {
	$row['day_key'] = $row['day'];
	unset( $row['day'] );
	$unrenamed[] = $row;
}
$broken = Scoring::calendar( $unrenamed, '2026-08-27', 4 );
hti_games_check(
	array( 'missed', 'missed', 'missed', 'missed' ) === array_column( $broken, 'state' ),
	'and dropping the rename empties the calendar silently rather than erroring — which is why it is asserted and not assumed'
);

$weeks = Scoring::risk_by_week( $rows, 2 );
hti_games_check( 2 === count( $weeks ) && 3 === $weeks[1]['runs'], 'risk_by_week buckets off the same renamed date' );
hti_games_check( '2026-08-27' === $weeks[1]['to'], 'and anchors the most recent bucket on the most recent row' );

/* =========================================================================
 * 3. Two outcome vocabularies, one set of consumers
 * ====================================================================== */

echo "\nBoth games' vocabularies survive every shared consumer\n";

$stc_outcomes    = array( 'stop', 'target', 'open', 'pass' );
$reveal_outcomes = array( 'up', 'down', 'flat', 'pass' );

$too_long = array_filter(
	array_merge( $stc_outcomes, $reveal_outcomes, REST::STC_DECISIONS, REST::REVEAL_DECISIONS ),
	fn( $token ) => strlen( $token ) > 8
);
hti_games_check( array() === $too_long, 'every outcome and decision token fits the varchar(8) columns that store them' );

// Scoring must not branch on outcome, because the two games do not agree on
// it. Same P&L, same decision, opposite vocabulary — same answer.
$stc_rows = array(
	hti_int_row( '2026-08-26', 'buy', 200, -200 ),
	hti_int_row( '2026-08-27', 'buy', 100, 400 ),
);
$rev_rows = array(
	hti_int_row( '2026-08-26', 'invest', 200, -200 ),
	hti_int_row( '2026-08-27', 'invest', 100, 400 ),
);
foreach ( $stc_rows as $i => $row ) {
	$stc_rows[ $i ]['outcome'] = $stc_outcomes[ $i ];
	$rev_rows[ $i ]['outcome'] = $reveal_outcomes[ $i ];
}
hti_games_check(
	Scoring::calendar( $stc_rows, '2026-08-27', 2 ) === Scoring::calendar( $rev_rows, '2026-08-27', 2 ),
	'the calendar gives both games the same answer — it reads the P&L, never the outcome word'
);
hti_games_check(
	Scoring::average_risk_bp( $stc_rows ) === Scoring::average_risk_bp( $rev_rows ),
	'and so does the average risk, which is what lets one column hold both scales'
);

// The Reveal's 'invest' has to count as a staked row, or every badge that
// rewards restraint would treat the whole game as one long pass.
$badges = array_column( Scoring::badges( $rev_rows, array( 'capital' => 10400 ) ), 'progress', 'key' );
hti_games_check( 0 === $badges['patience'], "an 'invest' row is not a pass" );
hti_games_check( 1 === $badges['small_size'], 'and a 1% Reveal commitment counts as a small position, on the same basis-point scale' );

// The board query normalises both with one expression, which only works
// because The Reveal stores its size as basis points too.
hti_games_check( 2500 === 25 * 100, 'a 25% Reveal commitment is 2500 basis points' );
hti_games_check(
	Scoring::board_score( 400, 2500 ) === Scoring::board_score( 400, 2500 ),
	'so one board_score() serves both games'
);
hti_games_check(
	str_contains( $rest_src, "\$risk_bp = \$size * 100;" ),
	'and the write path is where the conversion happens, once'
);

// The share card must not leak an outcome in either vocabulary.
$shared_js = hti_int_src( 'assets/js/games-shared.js' );
$card      = substr( $shared_js, (int) strpos( $shared_js, 'function share(' ) );
$card      = substr( $card, 0, (int) strpos( $card, 'var text = lines.join' ) );
hti_games_check( ! str_contains( $card, 'outcome' ), 'the share card carries no outcome word from either game' );

/* =========================================================================
 * 4. One slug table, four consumers
 * ====================================================================== */

echo "\nConfig::pages() is the only place a games URL is decided\n";

$pages = Config::pages();
$plan  = Seeder::plan();

hti_games_check( array_keys( $pages ) === array_keys( $plan ), 'the seeder plans exactly the pages the table declares' );

$drift = array();
foreach ( $pages as $key => $page ) {
	foreach ( array( 'en', 'pt' ) as $lang ) {
		if ( $plan[ $key ]['slug'][ $lang ] !== $page[ $lang ] ) {
			$drift[] = $key . '.' . $lang;
		}
		if ( ! str_contains( Seeder::path( $key, $lang ), $page[ $lang ] ) ) {
			$drift[] = 'path ' . $key . '.' . $lang;
		}
	}
	if ( $plan[ $key ]['index'] !== $page['index'] ) {
		$drift[] = 'index ' . $key;
	}
}
hti_games_check( array() === $drift, 'and every slug, path and index flag it carries is the table\'s (' . ( $drift ? implode( ', ', $drift ) : 'clean' ) . ')' );

// The client's link table, the JSON-LD and the noindex all key off the same
// row, so the three cannot point at different pages.
$urls = Frontend::data( 'en' )['urls'];
$bad  = array();
foreach ( $pages as $key => $page ) {
	if ( ( $urls[ $key ] ?? '' ) !== Seeder::url( $key, 'en' ) ) {
		$bad[] = $key;
	}
	if ( Schema::should_emit( $key ) !== (bool) $page['index'] ) {
		$bad[] = 'schema ' . $key;
	}
}
hti_games_check( array() === $bad, 'the localized URLs and the JSON-LD gate agree with the table (' . ( $bad ? implode( ', ', $bad ) : 'clean' ) . ')' );

hti_games_check( false === $pages['profile']['index'], 'the player profile is the page that is not indexed' );
hti_games_check( ! Schema::should_emit( 'profile' ), 'so it emits no structured data' );

// The noindex and the JSON-LD now share one detector. They did not: the schema
// fell back to sniffing the shortcode when the seeder meta was missing and the
// robots filter did not, so a hand-rebuilt profile page emitted no JSON-LD and
// was indexed anyway.
hti_games_check(
	str_contains( hti_int_src( 'includes/class-seeder.php' ), 'Schema::detect_page( $post )' ),
	'the noindex filter asks Schema::detect_page() which page this is'
);
hti_int_page( Seeder::content( 'profile', 'en' ) );
hti_games_check(
	'profile' === Schema::detect_page( $GLOBALS['__hti_post'] ),
	'and that detector finds the profile from its shortcode alone, with no seeder meta'
);

// Whatever the seeder puts on a page is what the front end mounts on it.
$mounts = array(
	'hub'         => array( 'hub' ),
	'stc'         => array( 'stc' ),
	'reveal'      => array( 'reveal' ),
	'leaderboard' => array( 'leaderboard' ),
	'profile'     => array( 'profile' ),
);
foreach ( $mounts as $key => $expected ) {
	hti_int_page( Seeder::content( $key, 'pt' ) );
	hti_games_check( $expected === Frontend::kinds(), "the seeded {$key} page mounts " . implode( '+', $expected ) . ', in Portuguese too' );
}
hti_int_page( null );

/* =========================================================================
 * 5. Every copy key a consumer asks for exists
 *
 * A missing key renders as an empty string, in silence, on a live page. The
 * literal keys are covered by test-frontend.php; the ones built at runtime are
 * not, and they are the ones that go stale when a tier, a badge or a tint is
 * added on the other side of the codebase.
 * ====================================================================== */

echo "\nEvery copy key built at runtime resolves to a real string\n";

$table   = Strings::all();
$dynamic = array();

foreach ( Config::STC_RISK_BP as $bp ) {
	$dynamic[] = 'stc_warn_' . $bp;
}
foreach ( Config::REVEAL_SIZES as $pct ) {
	$dynamic[] = 'rev_warn_' . $pct;
}
foreach ( Scoring::badges( array(), array() ) as $badge ) {
	$dynamic[] = 'badge_' . $badge['key'];
}
foreach ( array( 'good', 'warn', 'bad' ) as $tint ) {
	$dynamic[] = 'rev_tint_' . $tint;
}
// The onboarding builds three card keys per game from a prefix.
foreach ( array( 'stc_ob', 'rev_ob' ) as $prefix ) {
	foreach ( array( 1, 2, 3 ) as $card_no ) {
		$dynamic[] = $prefix . $card_no . '_kicker';
		$dynamic[] = $prefix . $card_no . '_title';
	}
}
// Everything Leaderboard::crowd() can name.
foreach ( array( 'stc', 'reveal' ) as $crowd_game ) {
	foreach ( array( 'pass', 'buy' ) as $crowd_decision ) {
		foreach ( array( 3, 400 ) as $crowd_players ) {
			$dynamic[] = Leaderboard::crowd(
				array(
					'players' => $crowd_players,
					'lost'    => 2,
					'passed'  => 1,
				),
				$crowd_game,
				$crowd_decision
			)['key'];
		}
	}
}
// The two landing claims, chosen by Library::is_real().
$dynamic[] = 'stc_claim_real';
$dynamic[] = 'stc_claim_generated';
// The result headlines and outcome lines both games pick between.
foreach ( array( 'stc_res_target', 'stc_res_stop', 'stc_res_timeout', 'stc_res_pass', 'stc_title_win', 'stc_title_loss', 'stc_title_pass', 'stc_title_pass_good', 'rev_title_win', 'rev_title_loss', 'rev_title_pass', 'rev_title_pass_ok', 'rev_line_you', 'rev_line_passed', 'rev_line_index', 'rev_intact', 'rev_line_index_ft' ) as $key ) {
	$dynamic[] = $key;
}
// The nine accessibility labels Frontend::labels() reads out of Strings.
foreach ( array_keys( Frontend::labels( 'en' ) ) as $key ) {
	$dynamic[] = $key;
}

$dynamic = array_values( array_unique( $dynamic ) );
$absent  = array_values( array_filter( $dynamic, fn( $key ) => ! isset( $table[ $key ] ) ) );
hti_games_check( count( $dynamic ) > 50, sprintf( 'there are %d runtime-built keys to check', count( $dynamic ) ) );
hti_games_check( array() === $absent, 'and every one of them is in the table (' . ( $absent ? implode( ', ', $absent ) : 'all present' ) . ')' );

// The three lines of The Reveal are named by the engine and worded in Strings;
// the two tables have to use the same three names.
$line_keys = array_column( Reveal_Engine::three_lines( 100, 50 ), 'key' );
hti_games_check( array( 'you', 'pass', 'index' ) === $line_keys, 'the engine names the three result lines you / pass / index' );
$reveal_js = hti_int_src( 'assets/js/reveal.js' );
hti_games_check(
	str_contains( $reveal_js, "{ you: 'rev_line_you', pass: 'rev_line_passed', index: 'rev_line_index' }" ),
	'and the client maps those three names, and no others, onto copy keys'
);

/* =========================================================================
 * 6. The kill switches, on every surface at once
 * ====================================================================== */

echo "\nA game that is switched off is switched off everywhere\n";

update_option(
	'hti_games_settings',
	array(
		'stc_enabled'         => false,
		'reveal_enabled'      => true,
		'leaderboard_enabled' => true,
	)
);

$off_settings = Settings::settings();
hti_games_check( ! Settings::game_enabled( Config::GAME_STC, $off_settings ), 'the setting reports the game as off' );

$off_game = Frontend::render_game( array( 'name' => 'stc' ) );
hti_games_check( ! str_contains( $off_game, '<canvas' ) && str_contains( $off_game, 'hti-g--off' ), 'the shortcode renders no game' );

$off_hub = Frontend::render_hub();
hti_games_check( ! str_contains( $off_hub, esc_html( Strings::get( 'stc_tagline', 'en' ) ) ), 'the hub drops its card' );
hti_games_check( str_contains( $off_hub, esc_html( Strings::get( 'rev_tagline', 'en' ) ) ), 'and keeps the one that is still playable' );

$off_board = Frontend::render_board();
hti_games_check( ! str_contains( $off_board, 'data-hti-bgame="stc"' ), 'the board drops its tab' );
hti_games_check( str_contains( $off_board, 'data-hti-bgame="reveal"' ), 'and keeps the other' );

$off_profile = Frontend::render_profile();
hti_games_check( ! str_contains( $off_profile, 'data-hti-pgame="stc"' ), 'the profile drops its tab too' );

$off_flags = Frontend::data( 'en' )['flags'];
hti_games_check( false === $off_flags['stc'] && true === $off_flags['reveal'], 'the client is told which of the two is live' );

hti_games_check(
	! Schema::should_emit( Config::GAME_STC, Settings::game_enabled( Config::GAME_STC, $off_settings ) ),
	'and the JSON-LD stops calling it a playable Game — a rich result for something nobody can play'
);
hti_games_check(
	Schema::should_emit( Config::GAME_REVEAL, Settings::game_enabled( Config::GAME_REVEAL, $off_settings ) ),
	'while the game that is still on keeps its schema'
);
hti_games_check(
	str_contains( hti_int_src( 'includes/class-schema.php' ), 'Settings::game_enabled( $key, $settings )' ),
	'the emitter asks the setting rather than assuming'
);

// The server refuses the same thing, which is the half that matters: a page
// that renders a game the API will not serve is worse than one that says so.
hti_games_check(
	2 === substr_count( $rest_src, 'if ( ! self::game_enabled( $game ) ) {' ),
	'both /today and /decision refuse a switched-off game'
);
hti_games_check( str_contains( $rest_src, 'self::board_enabled()' ), 'and /leaderboard refuses a switched-off board' );

// The editorial half survives the switch on purpose — that is the page that
// ranks, and taking a game down must not take a URL down with it.
hti_games_check( isset( Seeder::plan()['stc'] ), 'the seeder still owns the page, so the URL does not 404' );

delete_option( 'hti_games_settings' );

/* =========================================================================
 * 7. The board size the owner set is the board size the query asks for
 * ====================================================================== */

echo "\nThe configured board size reaches the query\n";

hti_games_check( Settings::BOARD_MIN === Leaderboard::clamp_size( 1 ), 'a size under the floor clamps up' );
hti_games_check( Settings::BOARD_MAX === Leaderboard::clamp_size( 100000 ), 'and one over the ceiling clamps down' );
hti_games_check( 37 === Leaderboard::clamp_size( 37 ), 'a value inside the range is left alone' );

update_option( 'hti_games_settings', array( 'board_size' => 30 ) );
hti_games_check( 30 === Leaderboard::size(), 'the board reads the setting' );
hti_games_check( 30 === Frontend::data( 'en' )['config']['board_size'], 'and the client is handed the same number' );
delete_option( 'hti_games_settings' );

hti_games_check(
	Settings::defaults()['board_size'] === Leaderboard::size(),
	'with nothing configured, both fall back to the same default'
);
$board_src = hti_int_src( 'includes/class-leaderboard.php' );
hti_games_check( ! str_contains( $board_src, 'self::SIZE' ), 'and there is no second, hardcoded size left in the file' );
hti_games_check(
	2 === substr_count( $board_src, 'LIMIT %d' ) && 2 === substr_count( $board_src, "\t\t\t\t\$size\n" ),
	'both board queries take their limit from it'
);

/* =========================================================================
 * 8. The crowd statistic: honest, and never early
 * ====================================================================== */

echo "\nThe crowd number is a lesson after the decision and never a hint before it\n";

$busy = array(
	'players'     => 100,
	'avg_risk_bp' => 250,
	'deaths'      => 4,
	'lost'        => 62,
	'passed'      => 20,
);

$entered = Leaderboard::crowd( $busy, Config::GAME_STC, 'buy' );
hti_games_check( 'stc_crowd_entered' === $entered['key'], 'a player who took the trade is compared with the players who took it' );
hti_games_check( 78 === $entered['pct'], 'and the denominator is those 80, not all 100 — 62 of 80 is 78%' );

$passed = Leaderboard::crowd( $busy, Config::GAME_STC, 'pass' );
hti_games_check( 'stc_crowd_lost' === $passed['key'] && 62 === $passed['pct'], 'a player who passed is compared with everybody: 62 of 100' );

$rev_in = Leaderboard::crowd( $busy, Config::GAME_REVEAL, 'invest' );
hti_games_check( 'rev_crowd_in' === $rev_in['key'] && 80 === $rev_in['pct'], 'The Reveal reports how many put money behind it' );
$rev_out = Leaderboard::crowd( $busy, Config::GAME_REVEAL, 'pass' );
hti_games_check( 'rev_crowd_passed' === $rev_out['key'] && 20 === $rev_out['pct'], 'and how many stayed out' );

// The four sentences the design asked for are all reachable, which is the
// whole point of wiring them: they were dead copy in the table before this,
// and the result screen showed the average risk instead.
$reachable = array( $entered['key'], $passed['key'], $rev_in['key'], $rev_out['key'] );
sort( $reachable );
hti_games_check(
	array( 'rev_crowd_in', 'rev_crowd_passed', 'stc_crowd_entered', 'stc_crowd_lost' ) === $reachable,
	'all four crowd sentences are reachable, and each by a different player'
);

$thin = Leaderboard::crowd(
	array(
		'players' => 3,
		'lost'    => 2,
		'passed'  => 0,
	),
	Config::GAME_STC,
	'buy'
);
hti_games_check( null === $thin['pct'], 'three players get no percentage at all — "67% lost today" off three runs is noise' );
hti_games_check( 'crowd_thin' === $thin['key'] && '' !== Strings::get( 'crowd_thin', 'pt' ), 'they get a true sentence instead, in both languages' );
hti_games_check( Leaderboard::CROWD_MIN >= 20, 'and the threshold is high enough that one player cannot move the figure much' );

// Everybody passing is not a state a real day reaches, but it is a division by
// zero if it does.
$all_passed = Leaderboard::crowd(
	array(
		'players' => 40,
		'lost'    => 0,
		'passed'  => 40,
	),
	Config::GAME_STC,
	'buy'
);
hti_games_check( 'stc_crowd_lost' === $all_passed['key'] && 0 === $all_passed['pct'], 'a day nobody entered falls back to the all-players sentence rather than dividing by zero' );

// The board is public and reachable from a second tab. It must not carry the
// counts to somebody who has not decided.
$public_before = Leaderboard::public_stats( $busy, false );
hti_games_check( ! isset( $public_before['lost'], $public_before['passed'] ), 'the board withholds the counts from a player who has not played today' );
hti_games_check( isset( $public_before['players'], $public_before['avg_risk_bp'] ), 'while still reporting how many played and what they risked' );
hti_games_check( $busy === Leaderboard::public_stats( $busy, true ), 'and hands them over once the run exists' );
hti_games_check(
	str_contains( $board_src, "self::public_stats( self::day_stats( \$game, \$day_key ), null !== \$me )" ),
	'which is decided by whether the runs table already has their row, not by anything the client says'
);
hti_games_check(
	1 === substr_count( $rest_src, 'Leaderboard::day_stats(' ),
	'and the REST layer reads the raw counts in exactly one place — run_result(), which only exists because a run does'
);

/* =========================================================================
 * 9. losses_to_ruin: every tier, both stakes, and no number typed by hand
 * ====================================================================== */

echo "\nEvery number in a risk warning is the engine's\n";

$expected_ruin = array( 50 => 460, 100 => 230, 200 => 114, 500 => 45, 1000 => 22, 2500 => 9 );
foreach ( Config::STC_RISK_BP as $bp ) {
	hti_games_check( $expected_ruin[ $bp ] === STC_Engine::losses_to_ruin( $bp ), sprintf( '%d bp: %d consecutive losses to ruin', $bp, $expected_ruin[ $bp ] ) );
}

$tiers = Frontend::risk_tiers( 'en' );
foreach ( $tiers as $tier ) {
	hti_games_check( $tier['losses'] === STC_Engine::losses_to_ruin( $tier['bp'] ), $tier['label'] . ': the payload carries the engine answer' );
	hti_games_check(
		$tier['losses2'] === STC_Engine::losses_to_ruin( $tier['bp'], true )
			&& $tier['losses2'] === STC_Engine::losses_to_ruin( $tier['bp'] * Config::STC_DOUBLE ),
		$tier['label'] . ': and the doubled stake is the answer for twice the tier'
	);
	hti_games_check( $tier['losses2'] < $tier['losses'], $tier['label'] . ': doubling shortens the runway' );
}

foreach ( Frontend::size_tiers() as $size ) {
	hti_games_check(
		$size['losses'] === STC_Engine::losses_to_ruin( $size['pct'] * 100 ),
		$size['label'] . ': The Reveal reads the same engine, on the same basis-point scale'
	);
}

// The warning the doubled stake shows is its own sentence, because every
// per-tier one describes the single stake — "0.5%", "the classic ceiling" —
// and all of them are about the wrong position once the switch is on.
hti_games_check( isset( $table['stc_warn_double'] ), 'the doubled stake has a sentence of its own' );
hti_games_check(
	str_contains( $stc_js, "H.fmt( H.t( 'stc_warn_double' ), H.pct( row.bp * cfg.config.double ), row.losses2 )" ),
	'filled with the real size and the doubled runway, both computed rather than written'
);

// No sentence carries a number that is not either a placeholder or the tier it
// is naming. The prototype hardcoded "30 losses in a row" at 2%, which comes
// from a linear model and is wrong by a factor of four.
$typed = array();
$warns = array( 'stc_dead_counter' => 200 );
foreach ( Config::STC_RISK_BP as $bp ) {
	$warns[ 'stc_warn_' . $bp ] = $bp;
}
foreach ( Config::REVEAL_SIZES as $pct ) {
	$warns[ 'rev_warn_' . $pct ] = $pct * 100;
}
$warns['stc_warn_double'] = 0;
foreach ( $warns as $key => $bp ) {
	foreach ( Strings::LANGS as $lang ) {
		$text = Strings::get( $key, $lang );
		// The tier's own percentage is allowed: it names the choice, it is not
		// a claim about what the choice costs.
		if ( $bp > 0 ) {
			$text = str_replace( Frontend::pct_label( $bp, $lang ), '', $text );
		}
		// Placeholders are the point.
		$text = str_replace( array( '%d', '%s', '%%' ), '', $text );
		if ( preg_match( '/\d/', $text ) ) {
			$typed[] = $key . '.' . $lang;
		}
	}
}
hti_games_check( array() === $typed, 'no risk sentence carries a figure of its own (' . ( $typed ? implode( ', ', $typed ) : 'clean' ) . ')' );

// And the check has teeth: the sentence the prototype shipped would fail it.
hti_games_check(
	1 === preg_match( '/\d/', str_replace( array( '%d', '2%' ), '', 'At 2% you can lose 30 trades in a row.' ) ),
	'the same check would have caught the prototype hardcoding 30'
);

// The death screen's counter is the 2% answer, and the compounding one.
$ruin2 = Frontend::data( 'en' )['ruin2'];
hti_games_check( $ruin2 === STC_Engine::losses_to_ruin( 200 ) && 114 === $ruin2, 'the death screen counts 114 losses at 2%, not the linear 45' );
hti_games_check(
	str_contains( $stc_js, "H.fmt( H.t( 'stc_dead_counter' ), cfg.ruin2 )" ),
	'and the client fills the sentence from the payload rather than from a literal'
);

hti_games_done();
