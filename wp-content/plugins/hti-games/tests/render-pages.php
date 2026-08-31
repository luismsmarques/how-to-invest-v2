<?php
/**
 * Write each games page as a standalone HTML document — real shell
 * markup, real CSS, real fonts — so Chromium can render what a visitor sees.
 * — real shell markup, real CSS, real fonts — so a browser can render what a
 * visitor sees. Reuses the exact shims and render calls test-no-brokers.php
 * uses; test-responsive.mjs is what consumes the output.
 *
 * @package HTI_Games
 */
$plugin = dirname( __DIR__ );
$theme  = dirname( $plugin, 2 ) . '/themes/howtoinvest';
$out    = ( getenv( 'HTI_RENDER_OUT' ) ?: sys_get_temp_dir() . '/hti-games-pages' );
if ( ! is_dir( $out ) ) { mkdir( $out, 0777, true ); }

require_once $plugin . '/tests/bootstrap.php';

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return $v; }
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return 'en_US'; }
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $sc = '' ) {
		$o = $pairs;
		foreach ( (array) $atts as $k => $v ) { if ( array_key_exists( $k, $pairs ) ) { $o[ $k ] = $v; } }
		return $o;
	}
}

foreach ( array( 'class-config', 'class-strings', 'class-day', 'class-stc-engine', 'class-settings', 'class-player', 'class-seeder', 'class-schema', 'class-frontend' ) as $c ) {
	require_once $plugin . '/includes/' . $c . '.php';
}

$css = '';
foreach ( array( 'games.css', 'board.css', 'stc.css', 'reveal.css' ) as $f ) {
	$css .= "\n/* ---- $f ---- */\n" . file_get_contents( $plugin . '/assets/css/' . $f );
}

// The theme's own front-end CSS, so the shell sits in the page it really sits in.
foreach ( glob( $theme . '/assets/css/*.css' ) as $f ) {
	$css .= "\n/* ---- theme/" . basename( $f ) . " ---- */\n" . file_get_contents( $f );
}

$faces = '';
foreach ( glob( $theme . '/assets/fonts/*.woff2' ) as $f ) {
	$name   = basename( $f, '.woff2' );
	$weight = (int) substr( $name, -3 );
	$family = str_contains( $name, 'poppins' ) ? 'Poppins' : 'Plus Jakarta Sans';
	$faces .= sprintf(
		"@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:block;src:url('file://%s') format('woff2');}\n",
		$family,
		$weight,
		$f
	);
}

function doc( string $title, string $body, string $lang, string $css, string $faces ): string {
	return "<!doctype html>\n<html lang=\"$lang\"><head><meta charset=\"utf-8\">"
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. "<title>$title</title><style>$faces</style><style>$css</style>"
		. '<style>body{margin:0;background:#FFF6F1;font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:#2A2438;}</style>'
		. "</head><body>$body</body></html>";
}

$pages = array();
$was   = $_SERVER['REQUEST_URI'] ?? null;
foreach ( array( 'en' => '/games/', 'pt' => '/pt/jogos/' ) as $lang => $uri ) {
	$_SERVER['REQUEST_URI'] = $uri;
	$pages[ "hub-$lang" ]         = array( 'Hub', \HTI\Games\Frontend::render_hub(), $lang );
	$pages[ "stc-$lang" ]         = array( 'Survive the Charts', \HTI\Games\Frontend::render_game( array( 'name' => 'stc' ) ), $lang );
	$pages[ "reveal-$lang" ]      = array( 'The Reveal', \HTI\Games\Frontend::render_game( array( 'name' => 'reveal' ) ), $lang );
	$pages[ "leaderboard-$lang" ] = array( 'Leaderboard', \HTI\Games\Frontend::render_board(), $lang );
	$pages[ "profile-$lang" ]     = array( 'Profile', \HTI\Games\Frontend::render_profile(), $lang );
}
if ( null === $was ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $was; }

foreach ( $pages as $slug => [ $title, $body, $lang ] ) {
	$file = $out . '/' . $slug . '.html';
	file_put_contents( $file, doc( $title, $body, $lang, $css, $faces ) );
	printf( "%-20s %6d bytes of markup\n", $slug, strlen( $body ) );
}
