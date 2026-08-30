<?php
/**
 * The shell the five shortcodes render, and the promises it makes.
 *
 * This file is guarding four things that are easy to break and expensive to
 * find in a browser:
 *
 * 1. The page is meaningful BEFORE JavaScript. Headings, controls, the rules,
 *    the disclaimer and a <noscript> are all in the HTML. Half the point of
 *    the section is that these two pages rank.
 * 2. The accessibility furniture is actually there — the canvas is an image
 *    with a label and never a control, the tiles are a radiogroup with exactly
 *    one tab stop, the replay's first focusable thing is the way out of it,
 *    and the chart has a text equivalent under it.
 * 3. Not one word of copy is invented. Every string the shell renders comes
 *    from Strings, and every key the JavaScript asks for exists there — a
 *    typo'd key renders as an empty label on a live page and nothing warns.
 * 4. The numbers in the warnings are the engine's. The design prototype
 *    hardcoded "30 losses in a row" at 2% from a linear model; compounding
 *    says 114, and this file checks the sentence gets the engine's answer.
 *
 *   php wp-content/plugins/hti-games/tests/test-frontend.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

/* -------------------------------------------------------------------------
 * WordPress shims the render path needs. Everything else comes from
 * bootstrap.php.
 * ---------------------------------------------------------------------- */

/**
 * The queried post, as a WP_Post would be.
 */
class WP_Post {

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

$GLOBALS['__hti_post'] = null;

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

/**
 * Whether a shortcode appears in some content. Tighter than a substring so
 * `[hti_games_hub]` does not read as `[hti_game`.
 *
 * @param string $content Content.
 * @param string $tag     Shortcode tag.
 */
function has_shortcode( $content, $tag ) {
	return 1 === preg_match( '/\[' . preg_quote( (string) $tag, '/' ) . '(?=[\s\]\/])/', (string) $content );
}

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

/**
 * URL escaping.
 *
 * @param string $url URL.
 */
function esc_url( $url ) {
	return $url;
}

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

/**
 * Slash removal.
 *
 * @param mixed $value Value.
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * The locale.
 */
function determine_locale() {
	return 'en_US';
}

foreach ( array( 'class-config', 'class-strings', 'class-day', 'class-stc-engine', 'class-settings', 'class-player', 'class-seeder', 'class-frontend' ) as $hti_class ) {
	require_once __DIR__ . '/../includes/' . $hti_class . '.php';
}

use HTI\Games\Config;
use HTI\Games\Frontend;
use HTI\Games\Seeder;
use HTI\Games\STC_Engine;
use HTI\Games\Strings;

/**
 * Point the shims at a post body.
 *
 * @param string|null $content Post body, or null for a non-singular view.
 */
function hti_games_page( ?string $content ): void {
	$GLOBALS['__hti_post'] = null === $content ? null : new WP_Post( $content );
}

$stc    = Frontend::render_game( array( 'name' => 'stc' ) );
$reveal = Frontend::render_game( array( 'name' => 'reveal' ) );
$hub    = Frontend::render_hub();
$board  = Frontend::render_board();
$prof   = Frontend::render_profile();
$data   = Frontend::data( 'en' );

/* -------------------------------------------------------------------------
 * 1. The shell exists before JavaScript does
 * ---------------------------------------------------------------------- */

echo "The page says something before a byte of JavaScript runs\n";

hti_games_check( str_contains( $stc, Strings::get( 'stc_name', 'en' ) ), 'the game names itself in the HTML' );
hti_games_check( str_contains( $stc, esc_html( Strings::get( 'disclaimer_full', 'en' ) ) ), 'the full disclaimer is server-rendered, not fetched' );
hti_games_check( str_contains( $stc, '<noscript>' ), 'there is a noscript block' );
hti_games_check( str_contains( $stc, esc_html( Frontend::labels( 'en' )['needs_js'] ) ), 'and it explains that the game needs JavaScript' );
hti_games_check( str_contains( $stc, '<details class="hti-g__rules">' ), 'the rules are in the markup, folded' );
foreach ( array( 'stc_ob2_r1', 'stc_ob2_r2', 'stc_ob2_r3', 'stc_ob2_r4' ) as $rule ) {
	hti_games_check( str_contains( $stc, esc_html( Strings::get( $rule, 'en' ) ) ), "rule {$rule} is rendered" );
}
hti_games_check( str_contains( $reveal, esc_html( Strings::get( 'rev_ob2_r3', 'en' ) ) ), 'The Reveal renders its rules too' );
hti_games_check( str_contains( $reveal, '<noscript>' ) && str_contains( $reveal, esc_html( Strings::get( 'disclaimer_full', 'en' ) ) ), 'and its noscript and disclaimer' );

echo "\nEvery phase is markup, not a template string in a bundle\n";
foreach ( array( 'decide', 'risk', 'replay', 'result', 'dead' ) as $phase ) {
	hti_games_check( str_contains( $stc, 'data-hti-phase="' . $phase . '"' ), "Survive the Charts renders the {$phase} phase" );
}
foreach ( array( 'dossier', 'size', 'reveal', 'result', 'dead' ) as $phase ) {
	hti_games_check( str_contains( $reveal, 'data-hti-phase="' . $phase . '"' ), "The Reveal renders the {$phase} phase" );
}
hti_games_check( 4 === preg_match_all( '/data-hti-phase="[a-z]+" hidden/', $stc ), 'four of the five phases start hidden' );
hti_games_check( ! str_contains( $stc, 'data-hti-phase="decide" hidden' ), 'and the one that does not is the one a player lands on' );
hti_games_check( 4 === preg_match_all( '/data-hti-phase="[a-z]+" hidden/', $reveal ), 'The Reveal opens on the dossier and hides the rest' );

/* -------------------------------------------------------------------------
 * 2. Accessibility
 * ---------------------------------------------------------------------- */

echo "\nThe chart is an image with a text equivalent, and never a control\n";

hti_games_check( str_contains( $stc, '<canvas class="hti-stc__canvas" data-hti="canvas" role="img" aria-label="' ), 'the canvas is role="img" with a label' );
hti_games_check( ! preg_match( '/<canvas[^>]*(onclick|tabindex|role="button")/', $stc ), 'nothing turns the canvas into a click target' );
hti_games_check( str_contains( $stc, 'data-hti="chart-table"' ), 'a table sits under it' );
foreach ( array( 'entry', 'stop', 'target', 'outcome', 'pnl' ) as $row ) {
	hti_games_check( str_contains( $stc, 'data-hti="tbl-' . $row . '"' ), "the table has a {$row} row" );
}
hti_games_check( 5 === substr_count( $stc, '<th scope="row">' ), 'and every row is a real row header' );
hti_games_check( str_contains( $stc, 'class="hti-g__sr" data-hti="chart-table"' ), 'the table is visually hidden rather than absent' );

echo "\nThe tiers are a radiogroup with one tab stop\n";

hti_games_check( str_contains( $stc, 'role="radiogroup"' ), 'the risk tiles are a radiogroup' );
hti_games_check( count( Config::STC_RISK_BP ) === substr_count( $stc, 'role="radio"' ), 'with one radio per offered tier' );
hti_games_check( 1 === substr_count( $stc, 'role="radio" aria-checked="true"' ), 'exactly one is checked' );
hti_games_check( 1 === substr_count( $stc, 'aria-checked="true" tabindex="0"' ), 'and exactly one is a tab stop — roving tabindex' );
hti_games_check( count( Config::REVEAL_SIZES ) === substr_count( $reveal, 'role="radio"' ), 'The Reveal offers one radio per size' );
hti_games_check( 1 === substr_count( $reveal, 'aria-checked="true" tabindex="0"' ), 'and one tab stop' );
hti_games_check( str_contains( $stc, 'role="switch" aria-checked="false"' ), 'the multiplier is a switch, announced as one' );

echo "\nThe timed replay can be left, and the result is announced when it lands\n";

$replay = substr( $stc, (int) strpos( $stc, 'data-hti-phase="replay"' ) );
$replay = substr( $replay, 0, (int) strpos( $replay, 'data-hti-phase="result"' ) );
hti_games_check(
	strpos( $replay, 'data-hti="skip"' ) < strpos( $replay, 'data-hti="position"' ),
	'"Skip to the result" is the first focusable thing in the replay phase'
);
hti_games_check( str_contains( $replay, esc_html( Strings::get( 'stc_skip_replay', 'en' ) ) ), 'and it says so in words from the copy table' );
hti_games_check( str_contains( $stc, 'role="status" aria-live="polite" data-hti="say"' ), 'a polite live region carries the result' );
hti_games_check( str_contains( $reveal, 'role="status" aria-live="polite" data-hti="say"' ), 'The Reveal has one too' );

echo "\nFocus can be moved to every phase\n";
// The replay is the exception on purpose: focus goes to its skip button, not
// to a heading, because the way out of the animation is what a keyboard user
// needs there first.
foreach ( array( 'decide', 'risk', 'result', 'dead' ) as $phase ) {
	$slice = substr( $stc, (int) strpos( $stc, 'data-hti-phase="' . $phase . '"' ) );
	$slice = substr( $slice, 0, (int) ( strpos( $slice, 'data-hti-phase', 20 ) ?: strlen( $slice ) ) );
	hti_games_check( str_contains( $slice, 'tabindex="-1"' ), "the {$phase} phase has something to move focus to" );
}
hti_games_check( str_contains( $board, 'role="tablist"' ) && str_contains( $board, 'role="tabpanel"' ), 'the board tabs are a real tablist' );
hti_games_check( 1 === substr_count( $board, 'aria-selected="true"' ), 'with one selected tab' );

/* -------------------------------------------------------------------------
 * 3. Copy
 * ---------------------------------------------------------------------- */

echo "\nNo word on these screens was written in a PHP or JS file\n";

$frontend_src = (string) file_get_contents( __DIR__ . '/../includes/class-frontend.php' );
hti_games_check( ! preg_match( '/[^_a-z]__\(\s*[\'"]/', $frontend_src ), 'class-frontend.php never uses __() for user-facing copy' );
hti_games_check( ! preg_match( '/_e\(\s*[\'"]/', $frontend_src ), 'nor _e()' );

$table = Strings::all();
$js    = array( 'games-shared.js', 'stc.js', 'reveal.js' );
$bad   = array();
foreach ( $js as $file ) {
	$body = (string) file_get_contents( __DIR__ . '/../assets/js/' . $file );
	if ( preg_match_all( '/\bt\(\s*\'([a-z0-9_]+)\'\s*\)/', $body, $m ) ) {
		foreach ( array_unique( $m[1] ) as $key ) {
			if ( ! isset( $table[ $key ] ) ) {
				$bad[] = $file . ': ' . $key;
			}
		}
	}
}
hti_games_check( array() === $bad, 'every copy key the JavaScript asks for exists in Strings (' . ( $bad ? implode( ', ', $bad ) : 'clean' ) . ')' );

$keys_seen = 0;
foreach ( $js as $file ) {
	$body = (string) file_get_contents( __DIR__ . '/../assets/js/' . $file );
	$keys_seen += (int) preg_match_all( '/\bt\(\s*\'[a-z0-9_]+\'\s*\)/', $body );
}
hti_games_check( $keys_seen > 40, sprintf( 'and it asks for a lot of them (%d call sites), so the check has teeth', $keys_seen ) );

echo "\nThe nine local accessibility labels are complete in both languages\n";
$en = Frontend::labels( 'en' );
$pt = Frontend::labels( 'pt' );
hti_games_check( array_keys( $en ) === array_keys( $pt ), 'both languages carry the same keys' );
$blank = array_filter( array_merge( $en, $pt ), fn( $v ) => '' === trim( (string) $v ) );
hti_games_check( array() === $blank, 'none of them is empty' );
hti_games_check( $en['needs_js'] !== $pt['needs_js'], 'the no-JavaScript notice really is translated' );

echo "\nBoth languages render, and the Portuguese page is Portuguese\n";
$stc_pt = Frontend::labels( 'pt' );
hti_games_check( 'Sobreviver aos Gráficos' === Strings::get( 'stc_name', 'pt' ), 'the copy table has the Portuguese game name' );
hti_games_check( str_contains( Frontend::money( 10000, 'pt' ), '$' ) && str_contains( Frontend::money( 10000, 'pt' ), '10 000' ), 'Portuguese money is "10 000 $"' );
hti_games_check( '$10,000' === Frontend::money( 10000, 'en' ), 'and English money is "$10,000"' );
hti_games_check( '' !== $stc_pt['lbl_outcome'], 'the Portuguese labels resolve' );

/* -------------------------------------------------------------------------
 * 4. The numbers are the engine's
 * ---------------------------------------------------------------------- */

echo "\nEvery risk warning gets its number from the engine, not from prose\n";

hti_games_check( count( Config::STC_RISK_BP ) === count( $data['risk'] ), 'the payload carries one row per tier' );
foreach ( $data['risk'] as $row ) {
	hti_games_check(
		$row['losses'] === STC_Engine::losses_to_ruin( $row['bp'] ) && $row['losses'] > 0,
		sprintf( '%s: %d consecutive losses to ruin, computed server-side', $row['label'], $row['losses'] )
	);
	hti_games_check( isset( $table[ $row['warn'] ] ), "the copy table has {$row['warn']}" );
}
hti_games_check(
	$data['risk'][1]['losses'] > $data['risk'][2]['losses'],
	'a smaller tier survives more losses than a bigger one — the whole lesson, as arithmetic'
);
hti_games_check(
	STC_Engine::losses_to_ruin( 200, true ) < STC_Engine::losses_to_ruin( 200 ),
	'doubling the stake halves the room, and the payload carries both'
);
hti_games_check( $data['ruin2'] === STC_Engine::losses_to_ruin( 200 ), 'the death screen counter is the engine 2% answer' );
hti_games_check( $data['ruin2'] > 30, sprintf( 'and it is the compounding answer (%d), not the linear 30 the prototype printed', $data['ruin2'] ) );

foreach ( $data['sizes'] as $row ) {
	hti_games_check( isset( $table[ $row['warn'] ] ), "the copy table has {$row['warn']}" );
	hti_games_check( $row['losses'] === STC_Engine::losses_to_ruin( $row['pct'] * 100 ), $row['label'] . ': ruin count from the engine' );
}

echo "\nPercentages are written the way each language writes them\n";
hti_games_check( '0.5%' === Frontend::pct_label( 50, 'en' ), '50bp is 0.5% in English' );
hti_games_check( '0,5%' === Frontend::pct_label( 50, 'pt' ), 'and 0,5% in Portuguese' );
hti_games_check( '2%' === Frontend::pct_label( 200, 'en' ), '200bp is 2%, with no decimal nobody needs' );
hti_games_check( '25%' === Frontend::pct_label( 2500, 'en' ), 'and 2500bp is 25%' );

/* -------------------------------------------------------------------------
 * 5. The localized payload
 * ---------------------------------------------------------------------- */

echo "\nOne localized object, carrying what the client cannot compute\n";
foreach ( array( 'root', 'nonce', 'lang', 'strings', 'labels', 'urls', 'config', 'risk', 'sizes', 'ruin2', 'flags' ) as $key ) {
	hti_games_check( isset( $data[ $key ] ), "HTI_GAMES.{$key} is present" );
}
hti_games_check( str_contains( $data['root'], 'htinvest/v1/games' ), 'the REST root points at the games namespace' );
hti_games_check( count( $data['strings'] ) === count( Strings::all() ), 'the whole copy table is handed over, flattened to one language' );
hti_games_check( Config::CAPITAL_START === $data['config']['capital_start'], 'the config constants come from Config' );
hti_games_check( 300 === $data['config']['replay_ms'], 'the replay reveals a candle every 300ms, from the handoff' );
hti_games_check( str_starts_with( $data['urls']['stc'], '/' ) && str_contains( $data['urls']['stc'], Config::pages()['stc']['en'] ), 'the internal URLs come from the page table' );
hti_games_check( '/pt/' === substr( Frontend::data( 'pt' )['urls']['hub'], 0, 4 ), 'and the Portuguese ones carry the language prefix' );
hti_games_check( ! str_contains( wp_json_encode( $data ), 'gemini' ) && ! str_contains( wp_json_encode( $data ), 'secret' ), 'nothing secret rides along' );

/* -------------------------------------------------------------------------
 * 6. The gate: nothing loads where nothing is mounted
 * ---------------------------------------------------------------------- */

echo "\nAssets are gated on the shortcode actually being on the page\n";

hti_games_page( null );
hti_games_check( array() === Frontend::kinds(), 'a non-singular view mounts nothing' );
hti_games_check( array( 'x' ) === Frontend::body_class( array( 'x' ) ), 'and gets no body class' );

hti_games_page( '<p>An ordinary page.</p>' );
hti_games_check( array() === Frontend::kinds(), 'a page without a shortcode mounts nothing' );

hti_games_page( Seeder::content( 'stc', 'en' ) );
hti_games_check( array( 'stc' ) === Frontend::kinds(), 'the seeded Survive the Charts page mounts exactly that game' );
hti_games_check( in_array( 'hti-page-game', Frontend::body_class( array() ), true ), 'and gets the body class the CSS keys off' );
hti_games_check( in_array( 'hti-page-game--stc', Frontend::body_class( array() ), true ), 'plus a per-surface one' );

hti_games_page( Seeder::content( 'reveal', 'pt' ) );
hti_games_check( array( 'reveal' ) === Frontend::kinds(), 'the seeded Portuguese Reveal page mounts The Reveal' );

hti_games_page( Seeder::content( 'hub', 'en' ) );
hti_games_check( array( 'hub' ) === Frontend::kinds(), 'the hub page mounts the hub and neither game' );

hti_games_page( Seeder::content( 'leaderboard', 'en' ) );
hti_games_check( array( 'leaderboard' ) === Frontend::kinds(), 'the board page mounts the board' );

hti_games_page( Seeder::content( 'profile', 'en' ) );
hti_games_check( array( 'profile' ) === Frontend::kinds(), 'the profile page mounts the profile' );

hti_games_page( '[hti_game name="stc"][hti_game name="reveal"][hti_games_leaderboard]' );
hti_games_check( array( 'stc', 'reveal', 'leaderboard' ) === Frontend::kinds(), 'a page mounting three surfaces reports all three' );

hti_games_page( '[hti_game]' );
hti_games_check( array( 'stc' ) === Frontend::kinds(), 'a bare [hti_game] falls back to the default game rather than to nothing' );

/* -------------------------------------------------------------------------
 * 7. The other three screens
 * ---------------------------------------------------------------------- */

echo "\nThe hub, the board and the profile are pages, not empty mounts\n";

hti_games_check( str_contains( $hub, esc_html( Strings::get( 'stc_tagline', 'en' ) ) ), 'the hub describes Survive the Charts' );
hti_games_check( str_contains( $hub, esc_html( Strings::get( 'rev_tagline', 'en' ) ) ), 'and The Reveal' );
hti_games_check( str_contains( $hub, esc_html( Strings::get( 'no_brokers', 'en' ) ) ), 'and makes the no-broker promise where a reader can see it' );
hti_games_check( str_contains( $hub, Seeder::url( 'stc', 'en' ) ) && str_contains( $hub, Seeder::url( 'reveal', 'en' ) ), 'both cards are real links' );

hti_games_check( str_contains( $board, esc_html( Strings::get( 'board_score_note', 'en' ) ) ), 'the board explains why size is not a shortcut up it' );
hti_games_check( str_contains( $board, esc_html( Strings::get( 'board_privacy', 'en' ) ) ), 'and that it carries nicknames only' );
hti_games_check( str_contains( $board, esc_html( Strings::get( 'board_empty', 'en' ) ) ), 'the empty state is the server-rendered default' );

hti_games_check( str_contains( $prof, esc_html( Strings::get( 'profile_risk', 'en' ) ) ), 'the profile leads with the learning metric' );
hti_games_check( str_contains( $prof, esc_html( Strings::get( 'profile_win_note', 'en' ) ) ), 'and says out loud that the win rate is not the point' );
hti_games_check( str_contains( $prof, esc_html( Strings::get( 'forget_me', 'en' ) ) ), 'the RGPD deletion control is on the page, not behind a menu' );
hti_games_check( str_contains( $prof, 'data-hti="link-hp"' ) && str_contains( $prof, 'aria-hidden="true"' ), 'the magic-link form carries a honeypot nobody can tab into' );

/* -------------------------------------------------------------------------
 * 8. Output safety and the section's own rules
 * ---------------------------------------------------------------------- */

echo "\nOutput is escaped, and nothing leaks in\n";

foreach ( array( 'stc' => $stc, 'reveal' => $reveal, 'hub' => $hub, 'board' => $board, 'profile' => $prof ) as $name => $html ) {
	hti_games_check( ! str_contains( $html, '<script' ), "no inline script in the {$name} shell" );
	hti_games_check( ! preg_match( '/\son[a-z]+=/i', $html ), "no inline event handler in the {$name} shell" );
	hti_games_check( ! str_contains( $html, 'javascript:' ), "no javascript: URL in the {$name} shell" );
}

hti_games_check( str_contains( $stc, 'hti-num' ), 'money and percentage figures are tagged for tabular numerals' );
hti_games_check( str_contains( (string) file_get_contents( __DIR__ . '/../assets/css/games.css' ), 'font-variant-numeric: tabular-nums' ), 'and the sheet actually sets them' );

$games_css = (string) file_get_contents( __DIR__ . '/../assets/css/games.css' );
hti_games_check( str_contains( $games_css, 'html.hti-game-active .hti-header' ), 'the takeover hides the site header from our own sheet' );
hti_games_check(
	str_contains( $games_css, '.hti-page-game main { max-width: none; }' ),
	'and the theme content cap is lifted from our own sheet, not by editing the theme'
);
hti_games_check( ! preg_match( '/:focus-visible[^{]*\{[^}]*outline\s*:\s*none/', $games_css ), 'no focus-visible rule throws the outline away' );

$stc_js = (string) file_get_contents( __DIR__ . '/../assets/js/stc.js' );
hti_games_check( str_contains( $stc_js, 'window.performance.now()' ), 'the replay is driven by elapsed time, so a backgrounded tab cannot drift' );
hti_games_check( 1 === substr_count( $stc_js, 'setInterval' ), 'and setInterval appears exactly once — the countdown, never the replay' );
hti_games_check( str_contains( $stc_js, 'prefers-reduced-motion' ) || str_contains( (string) file_get_contents( __DIR__ . '/../assets/js/games-shared.js' ), 'prefers-reduced-motion' ), 'reduced motion skips the animation outright' );
hti_games_check( ! preg_match( '/canvas[^\n]*addEventListener/', $stc_js ), 'nothing binds an event to the canvas' );

echo "\nA game that has been switched off takes its interface with it\n";
update_option(
	'hti_games_settings',
	array(
		'stc_enabled'    => false,
		'reveal_enabled' => true,
	)
);
$off = Frontend::render_game( array( 'name' => 'stc' ) );
hti_games_check( ! str_contains( $off, '<canvas' ), 'the disabled game renders no canvas' );
hti_games_check( str_contains( $off, esc_html( Strings::get( 'st_no_content', 'en' ) ) ), 'and says so in words from the copy table' );
delete_option( 'hti_games_settings' );

hti_games_done();
