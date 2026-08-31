<?php
/**
 * Accessibility, as arithmetic and as grep rather than as an opinion.
 *
 * The games are the most interactive thing on this site and the only part of
 * it with a time limit, an animation and a death screen. Four of the five
 * hazards that carries — contrast, colour as the only channel, focus on a
 * phase change, and motion — are decisions somebody makes in a stylesheet at
 * four in the afternoon and nobody re-measures. So they are measured here, on
 * every run, from the files themselves: the tokens are parsed out of the CSS
 * rather than copied into this file, which is the only version of the check
 * that still means something after the palette changes.
 *
 * What this file CANNOT tell you is in the block at the bottom. A static
 * audit proves a label exists; it cannot prove a screen reader says something
 * useful with it, that Tab reaches the skip button before the replay ends, or
 * that the layout survives 200% zoom. Those are on the staging QA list and
 * they are the half that matters.
 *
 *   php wp-content/plugins/hti-games/tests/test-a11y.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-strings.php';

use HTI\Games\Strings;

$root   = dirname( __DIR__ );
$css    = $root . '/assets/css/';
$js     = $root . '/assets/js/';
$inc    = $root . '/includes/';
$sheets = array(
	'games.css'  => (string) file_get_contents( $css . 'games.css' ),
	'stc.css'    => (string) file_get_contents( $css . 'stc.css' ),
	'reveal.css' => (string) file_get_contents( $css . 'reveal.css' ),
);
$scripts = array(
	'games-shared.js' => (string) file_get_contents( $js . 'games-shared.js' ),
	'stc.js'          => (string) file_get_contents( $js . 'stc.js' ),
	'reveal.js'       => (string) file_get_contents( $js . 'reveal.js' ),
);
$frontend = (string) file_get_contents( $inc . 'class-frontend.php' );
$seeder   = (string) file_get_contents( $inc . 'class-seeder.php' );

/* -------------------------------------------------------------------------
 * The contrast formula, verbatim from hti-engine/tests/test-focus-contrast.php
 * ---------------------------------------------------------------------- */

/**
 * One channel, linearized as WCAG defines it.
 *
 * @param int $c 0-255.
 */
function hti_a11y_channel( int $c ): float {
	$v = $c / 255;
	return $v <= 0.04045 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
}

/**
 * Relative luminance of a #rrggbb colour.
 *
 * @param string $hex Colour.
 */
function hti_a11y_luminance( string $hex ): float {
	$hex = ltrim( trim( $hex ), '#' );
	return 0.2126 * hti_a11y_channel( (int) hexdec( substr( $hex, 0, 2 ) ) )
		+ 0.7152 * hti_a11y_channel( (int) hexdec( substr( $hex, 2, 2 ) ) )
		+ 0.0722 * hti_a11y_channel( (int) hexdec( substr( $hex, 4, 2 ) ) );
}

/**
 * Contrast ratio between two colours.
 *
 * @param string $a First colour.
 * @param string $b Second colour.
 */
function hti_a11y_contrast( string $a, string $b ): float {
	$la = hti_a11y_luminance( $a );
	$lb = hti_a11y_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/**
 * Strip CSS comments, so a selector named in prose is never read as a rule.
 *
 * @param string $css Stylesheet source.
 */
function hti_a11y_strip( string $css ): string {
	return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
}

/**
 * Every `--g-*` custom property declared in one selector's block.
 *
 * The token tables are what the two worlds actually are — stc.css and
 * reveal.css do almost nothing but re-point them — so reading them out of the
 * sheet is the difference between a test that measures the palette and a test
 * that measures a copy of the palette somebody made once.
 *
 * @param string $css      Stylesheet source.
 * @param string $selector Selector whose block to read, e.g. '.hti-rv'.
 * @return array<string,string> Token name (without the `--g-` prefix) to hex.
 */
function hti_a11y_tokens( string $css, string $selector ): array {
	$css = hti_a11y_strip( $css );
	$at  = strpos( $css, $selector . ' {' );
	if ( false === $at ) {
		return array();
	}
	$block = substr( $css, $at, (int) strpos( $css, '}', $at ) - $at );
	$out   = array();
	if ( preg_match_all( '/--g-([a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$value = trim( $row[2] );
			// `var( --wp--preset--color--x, #HEX )` — the fallback is what the
			// games ship with and what a theme.json change would replace.
			if ( preg_match( '/#([0-9A-Fa-f]{6})\s*\)?\s*$/', $value, $hex ) ) {
				$out[ $row[1] ] = '#' . strtoupper( $hex[1] );
			}
		}
	}
	return $out;
}

echo "The formula agrees with the published figures\n";
hti_games_check( abs( hti_a11y_contrast( '#FFFFFF', '#000000' ) - 21.0 ) < 0.01, 'black on white is 21:1' );
hti_games_check( abs( hti_a11y_contrast( '#FF6B5E', '#FFFFFF' ) - 2.79 ) < 0.01, 'the brand coral on white is 2.79:1 — same arithmetic as hti-engine' );

/* -------------------------------------------------------------------------
 * 1. The three palettes, read out of the sheets
 * ---------------------------------------------------------------------- */

echo "\nThe three palettes parse\n";

$site = hti_a11y_tokens( $sheets['games.css'], '.hti-g' );
$dark = array_merge( $site, hti_a11y_tokens( $sheets['stc.css'], '.hti-stc' ) );
$rev  = array_merge( $site, hti_a11y_tokens( $sheets['reveal.css'], '.hti-rv' ) );

$palettes = array(
	'site (hub, board, profile)' => $site,
	'dark (Survive the Charts)'  => $dark,
	'cream (The Reveal)'         => $rev,
);

foreach ( $palettes as $name => $p ) {
	hti_games_check( count( $p ) >= 20, sprintf( '%s declares %d colour tokens', $name, count( $p ) ) );
}

// Every token the checks below name has to exist in every palette, or a
// missing token silently becomes a skipped assertion.
$required = array( 'bg', 'surface', 'surface-2', 'surface-3', 'text', 'text-2', 'muted', 'brand', 'brand-ink', 'brand-soft', 'up', 'up-soft', 'down', 'down-soft', 'warn', 'warn-soft', 'ring', 'field' );
foreach ( $palettes as $name => $p ) {
	$missing = array_diff( $required, array_keys( $p ) );
	hti_games_check( array() === $missing, sprintf( '%s carries every token the audit measures (%s)', $name, $missing ? implode( ', ', $missing ) : 'all present' ) );
}

/* -------------------------------------------------------------------------
 * 2. Text contrast — 4.5:1, because almost nothing here is large text
 *
 * WCAG calls text large at 18.66px bold or 24px regular. The largest thing
 * on these screens that is not a heading is the P&L at 28px; every figure,
 * label, note and disclaimer below is small text and takes the 4.5:1 bar.
 * The pairs are the ones the sheets actually paint, not every combination
 * the tokens allow.
 * ---------------------------------------------------------------------- */

echo "\nEvery text colour on the surface it is painted on clears 4.5:1\n";

/**
 * fg token, bg token, and what wears that pair.
 */
$text_pairs = array(
	array( 'text', 'surface', 'body text on the panel' ),
	array( 'text', 'surface-2', 'body text on a card' ),
	array( 'text-2', 'surface', 'secondary text on the panel' ),
	array( 'text-2', 'surface-2', 'the warning box, the rules list' ),
	array( 'text-2', 'surface-3', 'quoted headlines' ),
	array( 'muted', 'surface', 'notes and the disclaimer (11.5px)' ),
	array( 'muted', 'surface-2', 'tile sub-labels (10px)' ),
	array( 'muted', 'surface-3', 'meter labels (9.5px)' ),
	array( 'muted', 'bg', 'muted on the page ground' ),
	array( 'brand', 'surface', 'the kicker and the game title' ),
	array( 'brand', 'surface-2', 'the kicker on a card' ),
	array( 'brand', 'surface-3', 'the streak figure' ),
	array( 'brand', 'brand-soft', 'the streak chip, the lesson block' ),
	array( 'brand-ink', 'brand', 'the primary button label' ),
	array( 'brand-ink', 'down', 'the grave confirm button label (15px bold)' ),
	array( 'up', 'surface', 'a gain on the panel' ),
	array( 'up', 'surface-2', 'a gain on a card, a chosen tile' ),
	array( 'up', 'up-soft', 'a gain inside its own tint' ),
	array( 'down', 'surface', 'a loss on the panel, the error line' ),
	array( 'down', 'surface-2', 'a loss on a card, a chosen tile' ),
	array( 'down', 'down-soft', 'a loss inside its own tint, the death card' ),
	array( 'warn', 'surface-2', 'the middle fundamentals tint (14px bold)' ),
	array( 'warn', 'warn-soft', 'the warning tier inside its own tint' ),
);

foreach ( $palettes as $name => $p ) {
	foreach ( $text_pairs as $pair ) {
		if ( ! isset( $p[ $pair[0] ], $p[ $pair[1] ] ) ) {
			continue;
		}
		$ratio = hti_a11y_contrast( $p[ $pair[0] ], $p[ $pair[1] ] );
		hti_games_check(
			$ratio >= 4.5,
			sprintf( '%s: --g-%s on --g-%s → %.2f:1 — %s', $name, $pair[0], $pair[1], $ratio, $pair[2] )
		);
	}
}

/* -------------------------------------------------------------------------
 * 3. Non-text contrast — 3:1 (WCAG 1.4.11)
 * ---------------------------------------------------------------------- */

echo "\nThe focus ring clears 3:1 on every ground it is drawn over\n";

foreach ( $palettes as $name => $p ) {
	foreach ( array( 'bg', 'surface', 'surface-2', 'surface-3', 'brand-soft', 'up-soft', 'down-soft', 'warn-soft' ) as $ground ) {
		if ( ! isset( $p[ $ground ] ) ) {
			continue;
		}
		$ratio = hti_a11y_contrast( $p['ring'], $p[ $ground ] );
		hti_games_check( $ratio >= 3.0, sprintf( '%s: ring on --g-%s → %.2f:1', $name, $ground, $ratio ) );
	}
}

echo "\nA chosen tile is told apart from an unchosen one by more than a hue\n";
// The selected state paints the border in the tier's own colour. That border
// is the state indicator, so it takes the 3:1 of 1.4.11 against the tile fill.
foreach ( $palettes as $name => $p ) {
	foreach ( array( 'up', 'brand', 'warn', 'down' ) as $tone ) {
		$ratio = hti_a11y_contrast( $p[ $tone ], $p['surface-2'] );
		hti_games_check( $ratio >= 3.0, sprintf( '%s: a chosen --g-%s tile edge → %.2f:1', $name, $tone, $ratio ) );
	}
}

echo "\nA text field's own outline is visible — it is the only thing that says a field is there\n";
foreach ( $palettes as $name => $p ) {
	foreach ( array( 'surface', 'surface-2' ) as $ground ) {
		$ratio = hti_a11y_contrast( $p['field'], $p[ $ground ] );
		hti_games_check( $ratio >= 3.0, sprintf( '%s: --g-field on --g-%s → %.2f:1', $name, $ground, $ratio ) );
	}
}

echo "\nThe candles clear 3:1 on the chart ground\n";
// SC 1.4.11 covers "graphical objects required to understand the content", and
// on this screen the candles ARE the content. The ground is the one colour in
// the section that is not a token: it is the canvas wrapper's own background.
if ( preg_match( '/\.hti-stc__canvaswrap\s*\{[^}]*background:\s*(#[0-9A-Fa-f]{6})/', hti_a11y_strip( $sheets['stc.css'] ), $m ) ) {
	$ground = $m[1];
	hti_games_check( true, "the chart ground is {$ground}" );
	foreach ( array( 'up', 'down', 'past-up', 'past-down', 'brand' ) as $token ) {
		$ratio = hti_a11y_contrast( $dark[ $token ], $ground );
		hti_games_check( $ratio >= 3.0, sprintf( '--g-%s on the chart ground → %.2f:1', $token, $ratio ) );
	}
} else {
	hti_games_check( false, 'the chart ground could be read out of stc.css' );
}

/* -------------------------------------------------------------------------
 * 4. Focus
 * ---------------------------------------------------------------------- */

echo "\nNothing throws the focus indicator away\n";

// Suppressing the outline on a base `:focus` is fine and is the house pattern
// for a heading that only ever receives focus programmatically — it is never a
// Tab stop. Doing it under `:focus-visible` removes the ring for exactly the
// people it exists for. hti-engine/tests/test-focus-contrast.php makes the
// same check across every plugin; this one is scoped to the games so a failure
// says which file.
$offenders = array();
foreach ( $sheets as $file => $body ) {
	if ( preg_match_all( '/([^{}]*:focus-visible[^{}]*)\{([^}]*)\}/', hti_a11y_strip( $body ), $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $rule ) {
			if ( preg_match( '/outline\s*:\s*none/', $rule[2] ) ) {
				$offenders[] = $file . ' → ' . trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
			}
		}
	}
}
hti_games_check( array() === $offenders, 'no :focus-visible rule sets outline: none (' . ( $offenders ? implode( '; ', $offenders ) : 'clean' ) . ')' );

$focus_rule = array();
foreach ( $sheets as $file => $body ) {
	if ( preg_match( '/:focus-visible[^{]*\{([^}]*outline\s*:\s*[^;]+;)/', hti_a11y_strip( $body ), $m ) ) {
		$focus_rule[] = $file;
	}
}
hti_games_check( in_array( 'games.css', $focus_rule, true ), 'the shared sheet draws a focus ring of its own rather than relying on the UA default' );

// Every element the phase manager can hand focus to has to be able to take it.
$targets = preg_match_all( '/tabindex="-1"/', $frontend );
hti_games_check( $targets >= 8, sprintf( 'every phase carries a programmatic focus target (%d of them)', $targets ) );

echo "\nFocus is never handed to <body>, and never taken on load\n";
foreach ( array( 'stc.js', 'reveal.js' ) as $file ) {
	hti_games_check(
		str_contains( $scripts[ $file ], 'entered = true' ) && str_contains( $scripts[ $file ], 'var entered = false' ),
		"{$file} does not seize focus on the phase that arrives with the first response"
	);
	hti_games_check(
		str_contains( $scripts[ $file ], '=== document.activeElement' ),
		"{$file} moves focus off a button before disabling it"
	);
}
hti_games_check(
	str_contains( $scripts['games-shared.js'], 'false === moveFocus' ),
	'HTIGames.phase() can be asked not to move focus'
);
hti_games_check(
	str_contains( $scripts['games-shared.js'], 'previous.focus()' ),
	'the share dialog returns focus to whatever opened it'
);
hti_games_check(
	str_contains( $scripts['games-shared.js'], "'Escape' === event.key" ) && str_contains( $scripts['games-shared.js'], "'Tab' !== event.key" ),
	'and it closes on Escape and keeps Tab inside itself'
);

/* -------------------------------------------------------------------------
 * 5. The canvas
 * ---------------------------------------------------------------------- */

echo "\nEvery canvas is an image with a name, and never a control\n";

$canvases = 0;
foreach ( array_merge( array( 'class-frontend.php' => $frontend ), $scripts ) as $file => $body ) {
	if ( preg_match_all( '/<canvas\b[^>]*>/', $body, $m ) ) {
		foreach ( $m[0] as $tag ) {
			++$canvases;
			hti_games_check( str_contains( $tag, 'role="img"' ), "{$file}: the canvas is role=\"img\"" );
			hti_games_check( 1 === preg_match( '/aria-label="[^"]+"/', $tag ), "{$file}: and it has a non-empty label" );
			hti_games_check( ! preg_match( '/\b(onclick|tabindex|role="button")/', $tag ), "{$file}: and it is not a control" );
		}
	}
}
hti_games_check( $canvases > 0, sprintf( 'there is a canvas to audit (%d)', $canvases ) );
hti_games_check( ! preg_match( "/createElement\(\s*'canvas'/", implode( '', $scripts ) ), 'no canvas is conjured in JavaScript where the shell cannot label it' );
hti_games_check(
	! preg_match( '/canvas\.addEventListener/', $scripts['stc.js'] ),
	'nothing listens on the canvas — every control is a real button outside it'
);
// The shell is a loop over five keys, so the source carries one templated
// row; test-frontend.php counts the five in the rendered HTML.
hti_games_check( str_contains( $frontend, 'data-hti="chart-table"' ), 'the chart has a text equivalent under it' );
hti_games_check( str_contains( $frontend, '<tr><th scope="row">' ), 'and every row of it is a real row header' );
foreach ( array( 'entry', 'stop', 'target', 'outcome', 'pnl' ) as $row ) {
	hti_games_check( str_contains( $frontend, "'" . $row . "'" ) && str_contains( $frontend, "lbl_" . ( 'pnl' === $row ? 'pnl' : $row ) ), "the table carries a {$row} row" );
}

/* -------------------------------------------------------------------------
 * 6. Motion
 * ---------------------------------------------------------------------- */

echo "\nEvery animation and transition is answered by prefers-reduced-motion\n";

/**
 * Every class that an animation or a transition is declared on, and every
 * class a reduced-motion block turns one off on.
 *
 * A blanket `* { animation: none }` is not what these sheets do — each rule
 * names the thing it is switching off — so the check is per class rather than
 * per file, and a new animation on a new class fails until it is covered.
 *
 * @param string $css Stylesheet source.
 * @return array{moves:array<int,string>,covered:array<int,string>}
 */
function hti_a11y_motion( string $css ): array {
	$css     = hti_a11y_strip( $css );
	$moves   = array();
	$covered = array();

	// The reduced-motion blocks first, so they can be removed from what is
	// left and never counted as animations of their own.
	$offset = 0;
	while ( true ) {
		$at = strpos( $css, '@media ( prefers-reduced-motion: reduce )', $offset );
		if ( false === $at ) {
			break;
		}
		$open  = (int) strpos( $css, '{', $at );
		$depth = 0;
		$end   = $open;
		for ( $i = $open; $i < strlen( $css ); $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		$block = substr( $css, $open, $end - $open );
		if ( preg_match_all( '/([^{}]+)\{([^}]*)\}/', $block, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $rule ) {
				if ( ! preg_match( '/(animation|transition)\s*:\s*none/', $rule[2] ) ) {
					continue;
				}
				if ( preg_match_all( '/\.([a-z0-9_-]+)/i', $rule[1], $classes ) ) {
					$covered = array_merge( $covered, $classes[1] );
				}
			}
		}
		$css    = substr( $css, 0, $at ) . str_repeat( ' ', $end - $at + 1 ) . substr( $css, $end + 1 );
		$offset = $end;
	}

	// Then everything that moves. @keyframes bodies use percentages as
	// selectors, so they are dropped before the scan.
	$css = (string) preg_replace( '/@keyframes[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css );
	if ( preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $rule ) {
			if ( ! preg_match( '/(?:^|[;\s])(animation|transition)\s*:\s*(?!none)[^;]+/', $rule[2] ) ) {
				continue;
			}
			if ( preg_match_all( '/\.([a-z0-9_-]+)/i', $rule[1], $classes ) ) {
				// The last class in the selector is the thing being animated.
				$moves[] = end( $classes[1] );
			}
		}
	}

	return array(
		'moves'   => array_values( array_unique( $moves ) ),
		'covered' => array_values( array_unique( $covered ) ),
	);
}

$total_moves = 0;
foreach ( $sheets as $file => $body ) {
	$motion       = hti_a11y_motion( $body );
	$total_moves += count( $motion['moves'] );
	$uncovered    = array_diff( $motion['moves'], $motion['covered'] );
	hti_games_check(
		array() === $uncovered,
		sprintf(
			'%s: %d animated selector(s), all switched off under reduce (%s)',
			$file,
			count( $motion['moves'] ),
			$uncovered ? 'MISSING: ' . implode( ', ', $uncovered ) : 'covered'
		)
	);
}
hti_games_check( $total_moves >= 5, sprintf( 'there is real motion to switch off (%d selectors), so the check has teeth', $total_moves ) );

echo "\nAnd the two timed sequences are removed rather than merely slowed\n";
hti_games_check( str_contains( $scripts['games-shared.js'], "'(prefers-reduced-motion: reduce)'" ), 'the shared helper asks the media query' );
hti_games_check( 2 === substr_count( $scripts['stc.js'], 'H.reducedMotion()' ), 'the chart replay checks it before the phase and before the frame loop' );
hti_games_check( 2 === substr_count( $scripts['reveal.js'], 'H.reducedMotion()' ), 'so does the reveal sequence — which is what stops the counting number' );
hti_games_check(
	str_contains( $scripts['reveal.js'], 'if ( H.reducedMotion() ) {' ) && strpos( $scripts['reveal.js'], 'H.reducedMotion()' ) < strpos( $scripts['reveal.js'], 'function count(' ),
	'and count() is only ever reached through that guard'
);

/* -------------------------------------------------------------------------
 * 7. The time limit (WCAG 2.2.1 / 2.2.2)
 * ---------------------------------------------------------------------- */

echo "\nThe timed replay can be left, from the keyboard, first\n";

foreach ( array( 'stc' => 'replay', 'reveal' => 'reveal' ) as $game => $phase ) {
	$at    = (int) strpos( $frontend, 'data-hti-phase="' . $phase . '"' );
	$slice = substr( $frontend, $at, 900 );
	hti_games_check(
		strpos( $slice, 'data-hti="skip"' ) > 0 && strpos( $slice, 'data-hti="skip"' ) < 260,
		"{$game}: the skip control is the first thing in the {$phase} phase"
	);
	hti_games_check(
		1 === preg_match( '/<button type="button"[^>]*data-hti="skip"/', $slice ),
		"{$game}: and it is a real button"
	);
}
foreach ( array( 'stc.js', 'reveal.js' ) as $file ) {
	hti_games_check(
		str_contains( $scripts[ $file ], "'[data-hti=\"skip\"]'" ),
		"{$file} moves focus to it on entry, so it is reachable without a Tab"
	);
}
foreach ( array( 'stc_skip_replay', 'cta_skip' ) as $key ) {
	foreach ( Strings::LANGS as $lang ) {
		hti_games_check( '' !== Strings::get( $key, $lang ), "the escape hatch is worded in {$lang} ({$key})" );
	}
}

/* -------------------------------------------------------------------------
 * 8. Status messages (WCAG 4.1.3)
 * ---------------------------------------------------------------------- */

echo "\nEverything that changes without a page load is announced\n";

hti_games_check( 2 === substr_count( $frontend, 'role="status" aria-live="polite" data-hti="say"' ), 'both games carry a polite live region' );
hti_games_check( str_contains( $frontend, 'role="status" aria-live="polite" data-hti="board-status"' ), 'so does the board' );
hti_games_check( str_contains( $frontend, 'role="status" aria-live="polite" data-hti="profile-status"' ), 'and the profile' );
hti_games_check( 2 === substr_count( $frontend, 'role="alert"' ), 'the two server-rendered form errors are alerts, not status' );
hti_games_check( str_contains( $scripts['games-shared.js'], "class: 'hti-g__err', role: 'alert'" ), 'and so is the one the onboarding builds' );

// The announcement fires on the response, not on the end of the animation:
// say() has to be called before the phase that animates towards the result.
foreach ( array( 'stc.js', 'reveal.js' ) as $file ) {
	$body   = $scripts[ $file ];
	$landed = (int) strpos( $body, 'function landed(' );
	$said   = strpos( $body, 'H.say( region', $landed );
	$went   = strpos( $body, "go( '", $landed );
	hti_games_check( false !== $said && $said < $went, "{$file}: the result is announced when it lands, not when the animation ends" );
	hti_games_check(
		str_contains( substr( $body, $landed, 900 ), "H.t( 'capital_label' )" ) && str_contains( substr( $body, $landed, 900 ), "H.t( 'streak_label' )" ),
		"{$file}: and the announcement carries the HUD figures that changed with it"
	);
}

hti_games_check(
	str_contains( $scripts['games-shared.js'], 'region.htiSayTimer' ),
	'a queued announcement cannot land after the one that replaced it'
);
hti_games_check(
	str_contains( $scripts['games-shared.js'], "say( status, t( 'daily' === board" ),
	'switching board announces which board arrived and how full it is'
);
hti_games_check(
	str_contains( $scripts['games-shared.js'], "t( 'nick_saved' )" ),
	'and saving a nickname says so'
);

/* -------------------------------------------------------------------------
 * 9. Every control is a real control
 * ---------------------------------------------------------------------- */

echo "\nEvery control is a real element with a real role\n";

/**
 * The attributes the JavaScript binds click or keydown to. Kept as a list
 * rather than derived, because deriving it needs to follow a variable from
 * hook() to addEventListener() and a list that is wrong fails loudly.
 */
$control_hooks = array(
	'data-hti-decide=',
	'data-hti-risk=',
	'data-hti-size=',
	'data-hti-board=',
	'data-hti-bgame=',
	'data-hti-pgame=',
	'data-hti="skip"',
	'data-hti="share"',
	'data-hti="next"',
	'data-hti="forget"',
	'data-hti="double"',
	'data-hti="risk-back"',
	'data-hti="risk-confirm"',
	'data-hti="size-back"',
	'data-hti="size-confirm"',
);

$not_buttons = array();
foreach ( $control_hooks as $hook ) {
	$offset = 0;
	$found  = 0;
	while ( true ) {
		$at = strpos( $frontend, $hook, $offset );
		if ( false === $at ) {
			break;
		}
		++$found;
		$offset = $at + 1;
		// Walk back to whichever tag opened this element.
		$open = strrpos( substr( $frontend, 0, $at ), '<' );
		$tag  = false === $open ? '' : strtolower( substr( $frontend, $open + 1, 8 ) );
		if ( ! preg_match( '/^(button|a |a\b|input|select|summary)/', $tag ) ) {
			$not_buttons[] = $hook . ' on <' . trim( $tag ) . '>';
		}
	}
	hti_games_check( $found > 0, "the shell renders {$hook}" );
}
hti_games_check( array() === $not_buttons, 'every one of them is a button, a link or a field (' . ( $not_buttons ? implode( '; ', $not_buttons ) : 'clean' ) . ')' );

hti_games_check( ! preg_match( '/\bonclick=/i', $frontend . implode( '', $scripts ) ), 'nothing uses an onclick attribute' );

// A widget role on something that is not a button is the mistake this catches:
// it looks right in the accessibility tree and does nothing on the keyboard.
$roles = array();
if ( preg_match_all( '/<(\w+)[^>]*role="(radio|switch|tab|button|checkbox|link)"/', $frontend, $m, PREG_SET_ORDER ) ) {
	foreach ( $m as $hit ) {
		if ( 'button' !== $hit[1] && 'a' !== $hit[1] ) {
			$roles[] = $hit[2] . ' on <' . $hit[1] . '>';
		}
	}
}
hti_games_check( array() === $roles, 'every widget role sits on a button (' . ( $roles ? implode( '; ', $roles ) : 'clean' ) . ')' );

// The radiogroup contract, both games.
hti_games_check( 2 === substr_count( $frontend, 'role="radiogroup"' ), 'both tier pickers are radiogroups' );
hti_games_check( 2 === substr_count( $frontend, 'role="radiogroup" aria-label="' ), 'and both are named' );
hti_games_check(
	substr_count( $frontend, 'role="radio"' ) === substr_count( $frontend, 'role="radio" aria-checked=' ),
	'every radio reports whether it is chosen'
);
// Both groups are one sprintf each, so the roving tabindex is a template and
// a ternary rather than a literal. test-frontend.php counts the rendered tab
// stops (one per group); this checks the template can only ever produce that.
hti_games_check(
	2 === substr_count( $frontend, 'role="radio" aria-checked="%2$s" tabindex="%3$s"' ),
	'both groups set aria-checked and tabindex from the same pair of slots'
);
// Three roving-tabindex widgets in the section — the two radiogroups and the
// board's tablist — and each sets its one tab stop from a single ternary, so
// a group can never end up with two or none.
hti_games_check(
	3 === substr_count( $frontend, "? '0' : '-1'," ),
	'each of the three roving-tabindex widgets has exactly one tab stop'
);
// The game switches are toggle buttons, not radios: each is its own tab stop
// and reports its state with aria-pressed (the house pattern, news-hub.js).
hti_games_check( 2 === substr_count( $frontend, 'aria-pressed="%1$s"' ), 'both game switches report aria-pressed' );
foreach ( array( 'bgame', 'pgame' ) as $group ) {
	hti_games_check( str_contains( $scripts['games-shared.js'], "tab.setAttribute( 'aria-pressed'" ), "and the {$group} tabs keep it current" );
}
foreach ( array( "'ArrowRight'", "'ArrowLeft'", "'ArrowUp'", "'ArrowDown'", "'Home'", "'End'", "' ' === event.key", "'Enter'" ) as $key ) {
	hti_games_check( str_contains( $scripts['games-shared.js'], $key ), "the radiogroup handles {$key}" );
}
hti_games_check( str_contains( $frontend, 'role="switch" aria-checked="false"' ), 'the multiplier is a switch and says so' );
hti_games_check( str_contains( $scripts['stc.js'], "toggle.setAttribute( 'aria-checked'" ), 'and keeps saying so when it flips' );

echo "\nThe board tabs are a tablist that names its own panel\n";
hti_games_check( str_contains( $frontend, 'role="tablist" aria-label="' ), 'the tablist is named' );
hti_games_check( str_contains( $frontend, 'role="tab" id="hti-board-tab-%1$s"' ), 'each tab is given an id the panel can point at' );
hti_games_check( str_contains( $frontend, 'aria-controls="hti-board-panel"' ), 'and names the panel it controls' );
hti_games_check( str_contains( $frontend, 'aria-selected="%2$s"' ), 'and reports whether it is the selected one' );
hti_games_check( str_contains( $frontend, 'role="tabpanel" aria-labelledby="hti-board-tab-daily"' ), 'the panel names the tab that is selected' );
hti_games_check( str_contains( $scripts['games-shared.js'], "panel.setAttribute( 'aria-labelledby', tab.id )" ), 'and re-points it when the selection changes' );

echo "\nEvery field has a label, and every error is tied to its field\n";
foreach ( array( 'hti-g-nick', 'hti-g-email' ) as $field ) {
	hti_games_check( str_contains( $frontend, 'for="' . $field . '"' ), "{$field} has a label element" );
	hti_games_check( str_contains( $frontend, 'id="' . $field . '"' ), 'and the field carries the matching id' );
	hti_games_check( str_contains( $frontend, 'aria-describedby="' . $field . '-note ' . $field . '-err"' ), 'and its note and its error are described by it' );
}
hti_games_check( str_contains( $frontend, 'aria-describedby="hti-g-forget-note"' ), 'the delete button is described by what deleting does' );
hti_games_check(
	str_contains( $frontend, 'class="hti-g__hp" aria-hidden="true"' ) && str_contains( $frontend, 'name="hti_hp" tabindex="-1"' ),
	'the honeypot is out of the accessibility tree and out of the tab order'
);

// Every button JavaScript builds needs a type, or it submits the form it is in.
$typeless = 0;
foreach ( $scripts as $body ) {
	if ( preg_match_all( "/el\(\s*'button',\s*\{([^}]*)\}/", $body, $m ) ) {
		foreach ( $m[1] as $attrs ) {
			if ( ! str_contains( $attrs, 'type:' ) ) {
				++$typeless;
			}
		}
	}
}
hti_games_check( 0 === $typeless, 'every button built in JavaScript declares its type' );

/* -------------------------------------------------------------------------
 * 10. Colour is never the only channel (WCAG 1.4.1)
 * ---------------------------------------------------------------------- */

echo "\nNo state is told in colour alone\n";

/**
 * Every `is-*` state the JavaScript applies, and the channel that carries the
 * same meaning for somebody who cannot see the colour.
 *
 * A new state that is not in this table fails the check below. That is the
 * point of the table: the mistake is not writing a red rule, it is writing a
 * red rule and forgetting that red is not a word.
 */
$states = array(
	'is-up'    => 'the figure beside it is written by signed(), which always carries + or −',
	'is-down'  => 'ditto — a loss reads "−$420" before it reads red',
	'is-flat'  => 'a zero, which is its own word',
	'is-brand' => 'the middle band of the risk bars, labelled with its percentage',
	'is-on'    => 'chosen: aria-checked / aria-pressed, plus a heavier border',
	'is-ok'    => 'the sentence in the box differs, not only its colour',
	'is-me'    => 'the row also carries a screen-reader "— you"',
	'is-grave' => 'the button relabels itself as well as reddening',
	'is-warn'  => 'the third band of the risk bars, labelled with its percentage above it',
);

$applied = array();
foreach ( $scripts as $body ) {
	if ( preg_match_all( "/'(is-[a-z0-9-]+)'/", $body, $m ) ) {
		$applied = array_merge( $applied, $m[1] );
	}
}
$applied  = array_values( array_unique( $applied ) );
$unlisted = array_diff( $applied, array_keys( $states ) );
hti_games_check( array() === $unlisted, 'every literal state class has a non-colour channel on record (' . ( $unlisted ? implode( ', ', $unlisted ) : 'all accounted for' ) . ')' );
hti_games_check( count( $applied ) >= 6, sprintf( 'and there are %d of them, so the table is not empty', count( $applied ) ) );

// signed() is what makes is-up and is-down redundant rather than load-bearing.
hti_games_check(
	str_contains( $scripts['games-shared.js'], "return ( n > 0 ? '+' : '−' ) + money" ),
	'signed() writes the sign, which is why a gain is never only green'
);

// The two places a state is applied from a server-supplied vocabulary rather
// than from a literal — the fundamentals tint and the calendar — each need a
// mark of their own.
hti_games_check(
	str_contains( $scripts['reveal.js'], "var marks = { good: '✓', warn: '~', bad: '!' }" ),
	'the fundamentals tint draws a mark as well as a colour'
);
hti_games_check(
	str_contains( $scripts['reveal.js'], "H.t( 'rev_tint_' + row.tint )" ),
	'and says the same judgement in words for a screen reader'
);
foreach ( array( 'good', 'warn', 'bad' ) as $tint ) {
	foreach ( Strings::LANGS as $lang ) {
		hti_games_check( '' !== Strings::get( 'rev_tint_' . $tint, $lang ), "rev_tint_{$tint} is worded in {$lang}" );
	}
}
hti_games_check(
	str_contains( $scripts['games-shared.js'], "var marks = { won: '+', lost: '−', passed: '·', flat: '=' }" ),
	'the calendar signs each cell instead of only tinting it'
);
hti_games_check(
	str_contains( $sheets['games.css'], '.hti-profile__mark' ) && str_contains( $sheets['reveal.css'], '.hti-rv__mark' ),
	'and both marks are styled rather than invisible'
);
hti_games_check(
	! str_contains( $sheets['games.css'], 'opacity: 0.5;' ) || ! preg_match( '/\.hti-profile__badge\s*\{[^}]*opacity/', hti_a11y_strip( $sheets['games.css'] ) ),
	'a locked badge is not drawn at an opacity that puts its own name under AA'
);
hti_games_check(
	str_contains( $sheets['games.css'], 'border-style: solid' ),
	'earned and locked badges differ by border style, not only by border colour'
);

// The chart is the biggest colour-only surface in the section, and its answer
// is the table underneath rather than a pattern on the candles.
hti_games_check(
	str_contains( $scripts['stc.js'], "set( 'tbl-outcome'" ) && str_contains( $scripts['stc.js'], "set( 'tbl-pnl'" ),
	'the chart writes its outcome and its P&L into the text equivalent, in words'
);

/* -------------------------------------------------------------------------
 * 11. Targets
 * ---------------------------------------------------------------------- */

echo "\nNothing you have to hit is smaller than a fingertip\n";

/**
 * The declared min-height of one rule.
 *
 * @param string $css      Stylesheet source.
 * @param string $selector Selector to read.
 * @return int Pixels, 0 when the rule or the property is absent.
 */
function hti_a11y_min_height( string $css, string $selector ): int {
	if ( preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', hti_a11y_strip( $css ), $m )
		&& preg_match( '/min-height:\s*(\d+)px/', $m[1], $h ) ) {
		return (int) $h[1];
	}
	return 0;
}

$targets = array(
	array( 'games.css', '.hti-g__choice', 44, 'buy / sell / pass' ),
	array( 'games.css', '.hti-g__tile', 44, 'a risk or size tile' ),
	array( 'games.css', '.hti-g__switch', 44, 'the multiplier' ),
	array( 'games.css', '.hti-g__input', 44, 'a text field' ),
	array( 'games.css', '.hti-board__tab', 44, 'a board tab' ),
	array( 'games.css', '.hti-board__gtab', 44, 'a game tab' ),
	array( 'games.css', '.hti-g__rules > summary', 44, 'the rules disclosure' ),
);
foreach ( $targets as $row ) {
	$h = hti_a11y_min_height( $sheets[ $row[0] ], $row[1] );
	hti_games_check( $h >= $row[2], sprintf( '%s is %dpx tall — %s', $row[1], $h, $row[3] ) );
}

$cta = hti_a11y_min_height( $sheets['games.css'], '.hti-g__btn' );
hti_games_check( $cta >= 52 && $cta <= 54, sprintf( 'the primary CTA is %dpx, inside the 52–54 the design asks for', $cta ) );

/* -------------------------------------------------------------------------
 * 12. The bilingual layer
 * ---------------------------------------------------------------------- */

echo "\nNothing announces English on a Portuguese page\n";

// The accessibility furniture is read aloud, so a second copy of it is a
// second screen-reader experience. There is one table, and Frontend reads it.
hti_games_check(
	! preg_match( "/'lbl_entry'\s*=>\s*array\(/", $frontend ),
	'class-frontend.php keeps no second copy of the screen-reader labels'
);
foreach ( array( 'needs_js', 'lbl_entry', 'lbl_stop', 'lbl_target', 'lbl_outcome', 'lbl_pnl', 'lbl_rank', 'lbl_player', 'lbl_capital' ) as $key ) {
	foreach ( Strings::LANGS as $lang ) {
		hti_games_check( '' !== Strings::get( $key, $lang ), "{$key} is worded in {$lang}" );
	}
}
hti_games_check(
	Strings::get( 'lbl_outcome', 'en' ) !== Strings::get( 'lbl_outcome', 'pt' ),
	'and the Portuguese ones really are Portuguese'
);

// Nothing a screen reader reads may be typed into a JavaScript file: the site
// runs pt_PT_ao90 against pt_PT files and WordPress does not fall back.
$typed = array();
foreach ( $scripts as $file => $body ) {
	// Anything handed to sr(), or set as an aria-label, that carries a word
	// rather than punctuation. " — " and " · " are separators, not copy.
	if ( preg_match_all( "/(?:sr\(|'aria-label':)\s*'([^']*[A-Za-z]{3,}[^']*)'/", $body, $m ) ) {
		foreach ( $m[1] as $literal ) {
			$typed[] = $file . ': "' . $literal . '"';
		}
	}
}
hti_games_check( array() === $typed, 'no screen-reader string is typed into JavaScript (' . ( $typed ? implode( '; ', $typed ) : 'clean' ) . ')' );

echo "\nThe editorial half around the game is structured, not just styled\n";
hti_games_check( ! str_contains( $frontend, '<h3 class="hti-hub__name">' ), 'the hub cards no longer skip from H1 to H3' );
hti_games_check( str_contains( $seeder, '<h2 class="wp-block-heading">' ) && str_contains( $seeder, '{"level":3}' ), 'the seeded pages use real H2s and H3s' );
hti_games_check( 2 === substr_count( $frontend, '<noscript>' ), 'both games say what a reader without JavaScript is missing' );
hti_games_check( str_contains( $frontend, '<details class="hti-g__rules">' ), 'the rules use a native disclosure rather than a scripted one' );

/* -------------------------------------------------------------------------
 * What this file cannot tell you
 *
 * Every one of these is on the staging QA list. A static audit proves the
 * markup is capable of being accessible; only a person proves it is.
 *
 *   1. A screen-reader walkthrough of both games end to end (NVDA + Firefox,
 *      VoiceOver + Safari), in English and in Portuguese: does the result
 *      announcement land once and read in a useful order, does the chart's
 *      table make sense read aloud, does the death screen announce itself.
 *   2. Keyboard-only traversal of a whole day in each game, including the
 *      onboarding cards, the share dialog and the two account forms — no trap,
 *      no lost focus, and the skip button reachable DURING the replay rather
 *      than only in the DOM.
 *   3. The replay under a real screen reader: does the announcement fired at
 *      the response get spoken before the animation finishes, or does the
 *      focus move to the skip button cut it off.
 *   4. Zoom to 200% and 400%, and a 320px viewport: the chart, the six-tile
 *      grid and the sticky HUD.
 *   5. Windows High Contrast / forced-colors: the canvas, the tinted cards and
 *      the two marks this file just added.
 *   6. The focus ring against the CANVAS, which is painted rather than styled
 *      and so is invisible to any static check.
 *   7. Voice control ("click Confirm"): do the visible labels match the
 *      accessible names on the tiles, whose names are label plus amount.
 *   8. Real prefers-reduced-motion at the OS level on iOS and Android, not
 *      just the media query in devtools.
 * ---------------------------------------------------------------------- */

hti_games_done();
