<?php
/**
 * The news hub and the glossary index, guarded as arithmetic over their CSS.
 *
 * Both surfaces are theme render callbacks that read posts and terms, so the
 * only way to look at them is with fixture data and a browser. That review
 * happened, and it found what a browser finds: a hero card carrying 268px of
 * blank because it was stretched by its neighbour, a tab strip that snapped
 * itself past its own padding on load and arrived with the active pill clipped
 * against the screen edge, a search box permanently reserving room for a button
 * that is hidden until you type, twenty-seven letter buttons at 42px, and four
 * labels at 11px.
 *
 * What is NOT here is that harness. It needs some forty shims to load a theme
 * without WordPress, and a test that fails for reasons unrelated to what it
 * guards teaches people to ignore it. So the findings are pinned the way the
 * broker text sizes are: as assertions about the stylesheet, which need no
 * rendering and cannot rot when the render path changes.
 *
 *   php wp-content/plugins/hti-engine/tests/test-news-glossary.php
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
 * A stylesheet with its comments removed, so a number quoted in prose is never
 * mistaken for a declaration.
 *
 * @param string $path File.
 */
function css_of( string $path ): string {
	return (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
}

/**
 * Every `selector { ... }` rule in a stylesheet, as [selector, body] pairs.
 *
 * @param string $css Stylesheet.
 * @return array<int,array{0:string,1:string}>
 */
function rules_of( string $css ): array {
	$out = array();
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $r ) {
			$sel = trim( (string) preg_replace( '/\s+/', ' ', $r[1] ) );
			if ( '' !== $sel && ! str_starts_with( $sel, '@' ) ) {
				$out[] = array( $sel, $r[2] );
			}
		}
	}
	return $out;
}

$theme = dirname( __DIR__, 3 ) . '/themes/howtoinvest';
$style = css_of( $theme . '/style.css' );
$rules = rules_of( $style );

/* ---------------------------------------------------------------------------
 * 1. Controls big enough to hit.
 *
 * Measured in Chromium at five widths: the A–Z buttons were 42x42, the topic
 * chips 42px, the Clear button 38px, the category tabs 38px and the term-of-
 * the-day link 34px. WCAG 2.5.5 asks for 44.
 * ------------------------------------------------------------------------ */

$MIN_TAP  = 44;
$controls = array(
	'.hti-gloss__letter'         => 'height',
	'.hti-gloss__topic'          => 'min-height',
	'.hti-gloss__clear'          => 'min-height',
	'.hti-newshub__tab'          => 'min-height',
	'.hti-newshub__termday-cta'  => 'min-height',
);
foreach ( $controls as $sel => $prop ) {
	$found = null;
	foreach ( $rules as $r ) {
		if ( $sel === $r[0] && preg_match( '/(?<![a-z-])' . $prop . ':\s*([0-9.]+)px/', $r[1], $m ) ) {
			$found = (float) $m[1];
		}
	}
	check(
		null !== $found && $found >= $MIN_TAP,
		"{$sel} declares {$prop} >= {$MIN_TAP}px (" . ( null === $found ? 'absent' : $found . 'px' ) . ')'
	);
}
// The letter buttons are square: a 44px height with a 42px width is still a
// 42px target.
$letter_w = null;
foreach ( $rules as $r ) {
	if ( '.hti-gloss__letter' === $r[0] && preg_match( '/(?<![a-z-])width:\s*([0-9.]+)px/', $r[1], $m ) ) {
		$letter_w = (float) $m[1];
	}
}
check( null !== $letter_w && $letter_w >= $MIN_TAP, ".hti-gloss__letter is {$letter_w}px wide, not just tall" );

/* ---------------------------------------------------------------------------
 * 2. No text below 12px on either surface.
 *
 * Four labels rendered at 11px: the FEATURED badge, the term-of-the-day
 * eyebrow, and the day and tag in the weekly agenda. Uppercase micro-type is
 * where this always creeps back.
 * ------------------------------------------------------------------------ */

$MIN_FONT = 12;
$tiny     = array();
foreach ( $rules as $r ) {
	if ( ! preg_match( '/\.hti-(newshub|gloss)__/', $r[0] ) ) {
		continue;
	}
	// Both the longhand and the `font:` shorthand these files use.
	if ( preg_match_all( '/font(?:-size)?:\s*(?:[0-9]{3}\s+)?([0-9.]+)px/', $r[1], $m ) ) {
		foreach ( $m[1] as $px ) {
			if ( (float) $px < $MIN_FONT ) {
				$tiny[] = $r[0] . ' at ' . $px . 'px';
			}
		}
	}
}
check(
	array() === $tiny,
	"no news/glossary rule sets text below {$MIN_FONT}px (" . ( $tiny ? implode( '; ', $tiny ) : 'none' ) . ')'
);

/* ---------------------------------------------------------------------------
 * 3. The things a browser found that a number alone would not.
 * ------------------------------------------------------------------------ */

// The hero shares a stretch-aligned row with two stacked cards. Without the
// media absorbing the surplus, the difference lands as blank card: 195px at
// 1024, 268px at 1440.
check(
	(bool) preg_match( '/\.hti-newshub__hero-media\s*\{[^}]*flex:\s*1/', $style ),
	'the hero image absorbs the height its neighbour column forces on the card'
);

// scroll-snap measures from the scrollport edge, not from the padding, so on
// load the browser snapped the first tab to x=0 and the active pill arrived
// clipped against the screen.
check(
	(bool) preg_match( '/\.hti-newshub__tabs\s*\{[^}]*scroll-padding-inline/', $style ),
	'the swipeable tab strip tells scroll-snap about its own inset'
);

// A hard -20px bleed against a page padding that clamps up to 32px leaves the
// row short of the screen edge on the wider half of its range.
check(
	(bool) preg_match( '/\.hti-newshub__tabs\s*\{[^}]*margin-inline:\s*calc\([^)]*--wp--style--root--padding/', $style ),
	'the tab strip bleeds by the page padding rather than by a guessed number'
);

// 110px of right padding for a Clear button that is hidden until you type cut
// the placeholder mid-word on every phone.
$input_pad = '';
foreach ( $rules as $r ) {
	if ( '.hti-gloss__input' === $r[0] && preg_match( '/padding:\s*([^;]+);/', $r[1], $m ) ) {
		$input_pad = $m[1];
	}
}
check(
	! preg_match( '/\b(?:[6-9][0-9]|[12][0-9]{2})px\b/', $input_pad ),
	'the search box does not reserve room for a button that is not there (' . trim( $input_pad ) . ')'
);
check(
	(bool) preg_match( '/:has\(\s*\.hti-gloss__clear:not\(\s*\[hidden\]\s*\)\s*\)/', $style ),
	'it reserves that room once the Clear button appears'
);

// A 1280px canvas showing one column of full-width rows is 4 900px of scroll
// with a definition line running the whole width. Two columns halve both.
check(
	(bool) preg_match( '/@media\s*\(\s*min-width:\s*1100px\s*\)\s*\{[^@]*\.hti-gloss__groups\s*\{[^}]*columns:\s*2/s', $style ),
	'the glossary index uses the desktop width instead of one very long column'
);

/* ---------------------------------------------------------------------------
 * 4. Sticky offsets come from the header token, never from a guess.
 *
 * Four sticky elements in two files used to carry four different numbers for
 * the same measurement, and none of them knew about the admin bar.
 * ------------------------------------------------------------------------ */

$guessed = array();
foreach ( array( $theme . '/style.css', $theme . '/assets/css/learn.css' ) as $f ) {
	foreach ( rules_of( css_of( $f ) ) as $r ) {
		if ( str_contains( $r[1], 'position: sticky' ) && preg_match( '/(?<![a-z-])top:\s*([0-9.]+)px/', $r[1], $m ) ) {
			$guessed[] = basename( $f ) . ' ' . $r[0] . ' top:' . $m[1] . 'px';
		}
	}
}
check(
	array() === $guessed,
	'no sticky element guesses the header height (' . ( $guessed ? implode( '; ', $guessed ) : 'none' ) . ')'
);

/* ---------------------------------------------------------------------------
 * 5. The consent checkbox.
 *
 * The one control on the newsletter form with a legal consequence rendered at
 * the browser default of 13x13 — the smallest target on the page.
 * ------------------------------------------------------------------------ */

$sub = css_of( dirname( __DIR__ ) . '/assets/css/subscribe.css' );
foreach ( array( '.hti-subscribe__consent input', '.hti-digest__consent input' ) as $sel ) {
	$size = null;
	foreach ( rules_of( $sub ) as $r ) {
		if ( $sel === $r[0] && preg_match( '/(?<![a-z-])width:\s*([0-9.]+)px/', $r[1], $m ) ) {
			$size = (float) $m[1];
		}
	}
	check( null !== $size && $size >= 24, "{$sel} is at least 24px (" . ( null === $size ? 'absent' : $size . 'px' ) . ')' );
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
