<?php
/**
 * The focus indicator, checked as arithmetic rather than as an opinion.
 *
 * WCAG 2.1 SC 1.4.11 (Non-text Contrast) asks for 3:1 between a focus
 * indicator and what surrounds it. The brand coral does not clear it — the
 * ring was drawn in #FF6B5E and sat at 2.79:1 on white — so the ring has a
 * token of its own, and this file is what stops the next person from pointing
 * it back at a colour that was chosen to look good rather than to be seen.
 *
 * Two checks, because either one alone can pass while the site fails:
 *   1. the token clears 3:1 against every surface we paint on;
 *   2. no `:focus-visible` rule in our CSS throws the outline away.
 *
 *   php wp-content/plugins/hti-engine/tests/test-focus-contrast.php
 *
 * @package HTI_Engine
 */

$passes   = 0;
$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Label.
 */
function check( bool $cond, string $label ): void {
	global $passes, $failures;
	if ( $cond ) {
		++$passes;
		echo "\033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "\033[31m✗\033[0m {$label}\n";
	}
}

/**
 * One channel, linearized as WCAG defines it.
 *
 * @param int $c 0-255.
 */
function channel( int $c ): float {
	$v = $c / 255;
	return $v <= 0.04045 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
}

/**
 * Relative luminance of a #rrggbb colour.
 *
 * @param string $hex Colour.
 */
function luminance( string $hex ): float {
	$hex = ltrim( trim( $hex ), '#' );
	return 0.2126 * channel( (int) hexdec( substr( $hex, 0, 2 ) ) )
		+ 0.7152 * channel( (int) hexdec( substr( $hex, 2, 2 ) ) )
		+ 0.0722 * channel( (int) hexdec( substr( $hex, 4, 2 ) ) );
}

/**
 * Contrast ratio between two colours.
 *
 * @param string $a First colour.
 * @param string $b Second colour.
 */
function contrast( string $a, string $b ): float {
	$la = luminance( $a );
	$lb = luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

$root  = dirname( __DIR__, 3 );
$theme = $root . '/themes/howtoinvest';

echo "Sanity: the formula agrees with the published figures\n";
check( abs( contrast( '#FFFFFF', '#000000' ) - 21.0 ) < 0.01, 'black on white is 21:1' );
check( abs( contrast( '#FF6B5E', '#FFFFFF' ) - 2.79 ) < 0.01, 'the brand coral on white is 2.79:1 — the reason this file exists' );

echo "\nThe ring clears 3:1 on every surface\n";

$json = json_decode( (string) file_get_contents( $theme . '/theme.json' ), true );
$ring = (string) ( $json['settings']['custom']['focusRing'] ?? '' );
check( 1 === preg_match( '/^#[0-9A-Fa-f]{6}$/', $ring ), "theme.json declares a focus ring ({$ring})" );

$palette = array();
foreach ( (array) ( $json['settings']['color']['palette'] ?? array() ) as $entry ) {
	$palette[ (string) $entry['slug'] ] = (string) $entry['color'];
}

// Every colour a focus ring can be drawn against: the page grounds, the field
// borders it sits beside, and the dark cards the forex and Learn sections use.
$surfaces = array(
	'background'  => $palette['background'] ?? '#FFF6F1',
	'white'       => $palette['white'] ?? '#ffffff',
	'border'      => $palette['border'] ?? '#F2E4DD',
	'primary-soft'=> $palette['primary-soft'] ?? '#FFEDE9',
	'contrast'    => $palette['contrast'] ?? '#2A2438',
	'navy'        => $palette['navy'] ?? '#1E2147',
	'forex-dark'  => '#0E1116',
	// The two game shells (hti-games). Neither colour is in theme.json — the
	// games carry their own tokens so no dark palette leaks into the editor —
	// so without these two rows the ring is never measured against the
	// surfaces half the interactive product is painted on.
	'game-dark'   => '#0A0D12',
	'game-cream'  => '#F7F4EC',
);

foreach ( $surfaces as $name => $hex ) {
	$ratio = contrast( $ring, $hex );
	check( $ratio >= 3.0, sprintf( 'ring vs %-12s %s → %.2f:1', $name, $hex, $ratio ) );
}

echo "\nNo focus-visible rule throws the outline away\n";

// A `:focus-visible` block that sets `outline: none` removes the indicator for
// exactly the users the rule exists for. Suppressing the outline on a base
// rule is fine — the browser default is only a floor — and so is doing it on a
// container focused programmatically for a screen reader, which is never a Tab
// stop. Those are `:focus`, never `:focus-visible`.
$sheets = array_merge(
	(array) glob( $theme . '/*.css' ),
	(array) glob( $theme . '/assets/css/*.css' ),
	(array) glob( dirname( __DIR__, 2 ) . '/*/assets/css/*.css' )
);

$offenders = array();
foreach ( $sheets as $sheet ) {
	// Comments first: one of them names `.hti-app *:focus-visible` in prose,
	// and a selector scan that reads comments finds rules nobody wrote.
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $sheet ) );
	if ( preg_match_all( '/([^{}]*:focus-visible[^{}]*)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $rule ) {
			if ( preg_match( '/outline\s*:\s*none/', $rule[2] ) ) {
				$offenders[] = basename( $sheet ) . ' → ' . trim( preg_replace( '/\s+/', ' ', $rule[1] ) );
			}
		}
	}
}

check( count( $sheets ) > 5, sprintf( 'found %d stylesheets to audit', count( $sheets ) ) );
check( array() === $offenders, 'no :focus-visible rule sets outline: none (' . ( $offenders ? implode( '; ', $offenders ) : 'none' ) . ')' );

/* ---------------------------------------------------------------------------
 * 4. Every stylesheet declares its palette through theme.json.
 *
 * Not a style preference. learn.css spent its whole life redeclaring the brand
 * hexes by hand — the last sheet in the project that did — and the cost was not
 * only that a palette change in theme.json left /learn/ behind. Copied values
 * drift, and these had: seven text colours ended up between 2.48:1 and 4.39:1,
 * including a navigation link, a button label and the quiz's own
 * correct-answer tag. A sheet on the token system inherits the contrast work
 * done once; a sheet off it re-does that work badly, quietly.
 *
 * The rule is per-file rather than global because the token blocks themselves
 * legitimately carry hexes — that is what a fallback is. What is counted here
 * is raw hexes in the BODY of a sheet, past its own token declarations.
 * ------------------------------------------------------------------------ */

/**
 * Two sheets carry this debt today. The numbers are a ratchet, not approval:
 * they may fall and may never rise, so the rule binds every new line written
 * while the existing ones get paid off. learn.css is deliberately absent —
 * it was the worst of the three and is now on the token system, which is what
 * the default of 40 holds it to.
 */
$budget = array(
	'style.css' => 103,
	'app.css'   => 201,
);

$raw_hex = array();
foreach ( $sheets as $sheet ) {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $sheet ) );
	// Everything inside a var( --x, #hex ) fallback is the token system working
	// as intended, so those come out before counting.
	$css   = (string) preg_replace( '/var\(\s*--[^)]*\)/', '', $css );
	$count = preg_match_all( '/#[0-9A-Fa-f]{3,8}\b/', $css );
	$name  = basename( $sheet );
	$allow = $budget[ $name ] ?? 40;
	if ( $count > $allow ) {
		$raw_hex[] = sprintf( '%s has %d, allowed %d', $name, $count, $allow );
	}
}
check(
	array() === $raw_hex,
	'no stylesheet grows its hand-written palette (' . ( $raw_hex ? implode( '; ', $raw_hex ) : 'none' ) . ')'
);

/* ---------------------------------------------------------------------------
 * 5. Four colours on the news hub and the glossary that carried meaning while
 *    being effectively invisible.
 *
 * Found by rendering both surfaces with fixture data and measuring, then
 * computed here rather than judged by eye. The ranking numeral and the group
 * letter are the whole point of the widget they sit in: a "most read" list
 * whose 1-to-5 is at 1.37:1 is not ranked, and an A-Z index whose letter is at
 * 1.69:1 does not say where you are.
 *
 * Each row states the threshold that actually applies, not the strictest one:
 * 4.5:1 for body text, 3:1 for text at or above 24px (WCAG 1.4.3 large text)
 * and for a glyph that carries meaning (1.4.11).
 * ------------------------------------------------------------------------ */

$page = $palette['background'] ?? '#FFF6F1';
$card = $palette['white'] ?? '#ffffff';

echo "\nThe news hub and glossary colours that carry meaning\n";

// [selector, sheet-relative path, ground, minimum, why]
$carriers = array(
	array( '.hti-newshub__rank-n', $theme . '/style.css', $card, 4.5, 'the 1-5 in "most read", at 18px bold (under the 18.66px large-text line)' ),
	array( '.hti-gloss__gletter', $theme . '/style.css', $page, 3.0, 'the A-Z group letter, 30px bold — large text' ),
	array( '.hti-gloss__flabel', $theme . '/style.css', $page, 4.5, 'the TOPIC / LETTER filter labels, 12px' ),
	array( '.hti-gloss__sicon', $theme . '/style.css', $card, 3.0, 'the search glyph — a meaningful graphic' ),
);

foreach ( $carriers as $row ) {
	list( $sel, $file, $ground, $min, $why ) = $row;
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
	$hex = '';
	if ( preg_match_all( '/' . preg_quote( $sel, '/' ) . '\s*\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $rule ) {
			if ( preg_match( '/(?<![a-z-])color:\s*(#[0-9A-Fa-f]{6})/', $rule[1], $c ) ) {
				$hex = $c[1];
			}
		}
	}
	if ( '' === $hex ) {
		// A token instead of a hex is the better answer, not a failure: resolve
		// it through the palette so the ratio is still checked.
		if ( preg_match_all( '/' . preg_quote( $sel, '/' ) . '\s*\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $rule ) {
				if ( preg_match( '/(?<![a-z-])color:\s*var\(\s*--wp--preset--color--([a-z-]+)/', $rule[1], $c ) ) {
					$hex = $palette[ $c[1] ] ?? '';
				}
			}
		}
	}
	$ratio = '' === $hex ? 0.0 : contrast( $hex, $ground );
	check(
		$ratio >= $min,
		sprintf( '%-24s %-8s on %s → %.2f:1 (needs %.1f) — %s', $sel, $hex ?: 'not found', $ground, $ratio, $min, $why )
	);
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
