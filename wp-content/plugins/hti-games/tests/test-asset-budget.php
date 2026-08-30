<?php
/**
 * The front-end asset budget, measured rather than intended.
 *
 * A budget without a test is a wish. The handoff's non-functional requirement
 * is a first load under 200 KB gzip, and the two games sit a long way inside
 * it — but "a long way inside it" is exactly the state that erodes one library
 * at a time, and nobody notices until a phone on a train does.
 *
 * So this file gzips every asset the plugin actually ships and asserts three
 * ceilings: one per file, one per game (the set a player downloads to play a
 * day), and one for everything together. It also checks the things that make
 * the budget hold — no bundled library, no second copy of a font, no icon
 * font, no CSS asking for a webfont of its own.
 *
 * The numbers are ceilings with room, not the current sizes: a test that
 * fails on a paragraph of comments teaches people to delete comments.
 *
 *   php wp-content/plugins/hti-games/tests/test-asset-budget.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * The transferred size of a file: gzip, because that is what the wire carries
 * and what a hosting panel's "compress output" setting has been doing since
 * before this project started.
 *
 * @param string $path Absolute path.
 * @return int Bytes, 0 when the file is missing.
 */
function hti_games_gz( string $path ): int {
	if ( ! is_readable( $path ) ) {
		return 0;
	}
	return strlen( (string) gzencode( (string) file_get_contents( $path ), 9 ) );
}

/**
 * Kilobytes, for a label a person can read.
 *
 * @param int $bytes Bytes.
 */
function hti_games_kb( int $bytes ): string {
	return number_format( $bytes / 1024, 1 ) . ' KB';
}

$assets = dirname( __DIR__ ) . '/assets/';

/**
 * Every file the front end ships, and which game pays for it.
 *
 * `shared` is on every games page; the two game keys are only on their own.
 */
$files = array(
	'css/games.css'        => 'shared',
	'js/games-shared.js'   => 'shared',
	'css/stc.css'          => 'stc',
	'js/stc-core.js'       => 'stc',
	'js/stc.js'            => 'stc',
	'css/reveal.css'       => 'reveal',
	'js/reveal-core.js'    => 'reveal',
	'js/reveal.js'         => 'reveal',
);

/* -------------------------------------------------------------------------
 * Ceilings
 * ---------------------------------------------------------------------- */

/**
 * The ceilings, with the measurements they were set from.
 *
 * At the time of writing: games.css 5.2, games-shared.js 11.2, stc.css 2.0,
 * stc-core.js 5.3, stc.js 9.1, reveal.css 2.2, reveal-core.js 2.8, reveal.js
 * 5.9 — 32.8 KB to play Survive the Charts and 27.3 KB to play The Reveal.
 * The headroom is deliberate: a ceiling that fails on a paragraph of comments
 * teaches people to delete comments.
 *
 * games-shared.js is the biggest single file and the one to watch. It carries
 * the leaderboard and profile screens, which a game page downloads and never
 * runs; splitting them into a fourth file is the obvious move the day this
 * budget gets tight.
 */

/** No single file may exceed this, gzipped. */
const HTI_GAMES_FILE_MAX = 13312;

/** Everything one game page downloads: shared + that game. */
const HTI_GAMES_GAME_MAX = 36864;

/** Every front-end asset the plugin ships, together. */
const HTI_GAMES_TOTAL_MAX = 49152;

/** The handoff's non-functional requirement for a first load. */
const HTI_GAMES_FIRST_LOAD_MAX = 204800;

echo "Every shipped asset exists and is measurable\n";

$sizes = array();
foreach ( $files as $rel => $owner ) {
	$bytes = hti_games_gz( $assets . $rel );
	$sizes[ $rel ] = $bytes;
	hti_games_check( $bytes > 0, sprintf( '%s ships (%s gzipped)', $rel, hti_games_kb( $bytes ) ) );
	unset( $owner );
}

echo "\nNo single file is out of proportion\n";
foreach ( $sizes as $rel => $bytes ) {
	hti_games_check(
		$bytes <= HTI_GAMES_FILE_MAX,
		sprintf( '%s is %s of the %s per-file ceiling', $rel, hti_games_kb( $bytes ), hti_games_kb( HTI_GAMES_FILE_MAX ) )
	);
}

echo "\nWhat a player downloads to play one day\n";

$shared = 0;
foreach ( $files as $rel => $owner ) {
	if ( 'shared' === $owner ) {
		$shared += $sizes[ $rel ];
	}
}

foreach ( array( 'stc', 'reveal' ) as $game ) {
	$total = $shared;
	foreach ( $files as $rel => $owner ) {
		if ( $owner === $game ) {
			$total += $sizes[ $rel ];
		}
	}
	hti_games_check(
		$total <= HTI_GAMES_GAME_MAX,
		sprintf( '%s costs %s gzipped, under the %s per-game ceiling', $game, hti_games_kb( $total ), hti_games_kb( HTI_GAMES_GAME_MAX ) )
	);
	hti_games_check(
		$total <= HTI_GAMES_FIRST_LOAD_MAX,
		sprintf( '%s is comfortably inside the %s first-load budget', $game, hti_games_kb( HTI_GAMES_FIRST_LOAD_MAX ) )
	);
}

$all = array_sum( $sizes );
hti_games_check(
	$all <= HTI_GAMES_TOTAL_MAX,
	sprintf( 'the whole front end is %s gzipped, under %s', hti_games_kb( $all ), hti_games_kb( HTI_GAMES_TOTAL_MAX ) )
);

echo "\nThe budget holds because nothing heavy was added\n";

$sources = array();
foreach ( array_keys( $files ) as $rel ) {
	$sources[ $rel ] = (string) file_get_contents( $assets . $rel );
}

// A framework, a chart library or a polyfill bundle is what turns 35 KB into
// 300 KB, and each of them arrives as an import or a global.
$libraries = array( 'React', 'jQuery', 'require(', 'from \'', 'import ', 'Chart.js', 'd3.', 'lodash' );
$found     = array();
foreach ( $sources as $rel => $body ) {
	foreach ( $libraries as $needle ) {
		// The UMD cores legitimately name module.exports for the Node parity
		// test; that is a CommonJS export, not a dependency.
		if ( str_contains( $body, $needle ) ) {
			$found[] = $rel . ': ' . trim( $needle );
		}
	}
}
hti_games_check( array() === $found, 'no framework, chart library or module import (' . ( $found ? implode( ', ', $found ) : 'clean' ) . ')' );

$css = $sources['css/games.css'] . $sources['css/stc.css'] . $sources['css/reveal.css'];
hti_games_check( ! str_contains( $css, '@font-face' ), 'the sheets declare no font of their own — the theme already self-hosts Poppins and Plus Jakarta Sans' );
hti_games_check( ! str_contains( $css, '@import' ), 'and import nothing, which would be a second blocking request' );
hti_games_check( ! preg_match( '/url\(\s*[\'"]?https?:/', $css ), 'and fetch nothing from another origin' );
hti_games_check( str_contains( $css, "'Poppins'" ) && str_contains( $css, "'Plus Jakarta Sans'" ), 'they reuse the theme families by name' );

// An icon font is a whole extra request for a handful of glyphs. Inline SVG or
// nothing; today it is nothing.
hti_games_check( ! preg_match( '/font-family:[^;]*(icon|fontawesome|dashicons)/i', $css ), 'no icon font' );

// Base64 in a stylesheet is a data URI, and a data URI is a file somebody
// pasted into the byte count where the budget cannot see it.
hti_games_check( ! str_contains( $css, 'base64,' ), 'nothing is smuggled in as a data URI' );

echo "\nAnd the JavaScript is the vanilla the project asks for\n";

$js = $sources['js/games-shared.js'] . $sources['js/stc.js'] . $sources['js/reveal.js'];
hti_games_check( str_contains( $js, "'use strict'" ), 'every file is strict mode' );
hti_games_check( ! str_contains( $js, 'document.write' ), 'nothing calls document.write' );
hti_games_check( ! preg_match( '/\beval\s*\(/', $js ), 'nothing calls eval' );
hti_games_check(
	substr_count( $js, 'window.HTIGamesSTC' ) + substr_count( $js, 'window.HTIGamesReveal' ) >= 2,
	'the games read their maths from the parity-tested cores rather than repeating it'
);

hti_games_done();
