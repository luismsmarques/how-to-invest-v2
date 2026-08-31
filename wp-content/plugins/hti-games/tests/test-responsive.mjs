/**
 * The responsive contract, checked in a real browser.
 *
 * Everything else in this suite reads CSS as a string. That catches a declared
 * min-height and cannot catch a layout: whether anything overflows, whether a
 * control is 44px once the box model has had its say, whether type that looks
 * fine in the source renders at 9.5px. Those are browser questions, so this
 * file asks a browser.
 *
 * It exists because the review it locks in found three classes of defect that
 * no string scan would have seen, and because the next stylesheet edit will
 * undo them silently otherwise.
 *
 * Skips with a clear message when Playwright or Chromium is absent — a machine
 * without a browser should say so, not fail. Same posture as the GD skip in
 * hti-rss-ai's fallback-card test.
 *
 *   node wp-content/plugins/hti-games/tests/test-responsive.mjs
 *
 * @package HTI_Games
 */

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { readdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const VIEWPORTS = [
	{ name: '360', width: 360, height: 780 },
	{ name: '390', width: 390, height: 844 },
	{ name: '834', width: 834, height: 1112 },
	{ name: '1024', width: 1024, height: 768 },
	{ name: '1440', width: 1440, height: 900 },
];

/** Below this a control is hard to hit with a thumb (WCAG 2.5.5 / 2.5.8). */
const MIN_TAP = 44;

/** Below this is not a font size, it is an apology. */
const MIN_FONT = 12;

let pass = 0;
let fail = 0;
const check = ( ok, msg ) => {
	if ( ok ) {
		++pass;
	} else {
		++fail;
		console.log( '  FAIL: ' + msg );
	}
};

// Playwright is a developer tool, not a dependency of the plugin, so it is
// resolved from wherever the machine happens to keep it rather than vendored.
let chromium;
try {
	( { chromium } = await import( 'playwright' ) );
} catch {
	// Not beside this file — try wherever npm installs globally. createRequire
	// rather than a path import because playwright's CommonJS entry point is
	// what exports chromium.
	try {
		const req = createRequire( execFileSync( 'npm', [ 'root', '-g' ] ).toString().trim() + '/' );
		( { chromium } = req( 'playwright' ) );
	} catch {
		// Reported below.
	}
}
if ( ! chromium ) {
	console.log( 'responsive: SKIPPED — playwright is not installed on this machine.' );
	process.exit( 0 );
}

// Render the pages with the same harness the visual review used.
const dir = mkdtempSync( path.join( tmpdir(), 'hti-games-pages-' ) );
try {
	execFileSync( 'php', [ path.join( import.meta.dirname, 'render-pages.php' ) ], {
		env: { ...process.env, HTI_RENDER_OUT: dir },
		stdio: 'pipe',
	} );
} catch ( e ) {
	console.log( 'responsive: SKIPPED — could not render the pages: ' + e.message );
	process.exit( 0 );
}

const files = readdirSync( dir ).filter( ( f ) => f.endsWith( '.html' ) ).sort();
check( files.length >= 10, `rendered ${ files.length } pages (five screens, two languages)` );

const AUDIT = ( { MIN_TAP, MIN_FONT } ) => {
	const out = { overflow: null, taps: [], tiny: [] };
	const de = document.documentElement;
	if ( de.scrollWidth > de.clientWidth + 1 ) {
		out.overflow = `${ de.scrollWidth } > ${ de.clientWidth }`;
	}

	// A stretched link (::after over its card) has a hit area far larger than
	// its own box; measuring the box alone reports a false positive on exactly
	// the pattern used to fix a small target.
	const hitArea = ( el ) => {
		const r = el.getBoundingClientRect();
		const a = getComputedStyle( el, '::after' );
		if ( a && 'absolute' === a.position && 'none' !== a.content ) {
			const zero = ( v ) => '0px' === v || 'auto' === v;
			if ( zero( a.top ) && zero( a.left ) && zero( a.right ) && zero( a.bottom ) ) {
				const p = el.offsetParent;
				if ( p ) {
					const pr = p.getBoundingClientRect();
					if ( pr.width >= r.width && pr.height >= r.height ) {
						return pr;
					}
				}
			}
		}
		return r;
	};

	// A visually-hidden ancestor does not shrink its children's boxes, so a
	// clipped honeypot still reports 185x21. Skipping it is the difference
	// between a finding and "fixing" a working anti-spam field.
	const clipped = ( el ) => {
		for ( let n = el; n && n !== document.body; n = n.parentElement ) {
			const cs = getComputedStyle( n );
			if ( 'inset(50%)' === cs.clipPath || 'rect(0px, 0px, 0px, 0px)' === cs.clip ) {
				return true;
			}
			const r = n.getBoundingClientRect();
			if ( r.width <= 1 && r.height <= 1 && 'hidden' === cs.overflow ) {
				return true;
			}
		}
		return false;
	};

	const label = ( el ) =>
		`${ el.tagName.toLowerCase() }.${ ( el.className || '' ).toString().split( ' ' )[ 0 ] }`;

	const TAP = 'a,button,input,select,textarea,[role=button],[tabindex]:not([tabindex="-1"])';
	for ( const el of document.querySelectorAll( TAP ) ) {
		if ( clipped( el ) ) {
			continue;
		}
		const r = hitArea( el );
		if ( 0 === r.width && 0 === r.height ) {
			continue;
		}
		if ( r.width < MIN_TAP || r.height < MIN_TAP ) {
			out.taps.push( `${ label( el ) } ${ Math.round( r.width ) }x${ Math.round( r.height ) }` );
		}
	}

	for ( const el of document.querySelectorAll( 'body *' ) ) {
		if ( el.childElementCount || ( el.textContent || '' ).trim().length <= 3 ) {
			continue;
		}
		if ( clipped( el ) ) {
			continue;
		}
		const fs = parseFloat( getComputedStyle( el ).fontSize );
		if ( fs && fs < MIN_FONT ) {
			out.tiny.push( `${ label( el ) } ${ fs }px` );
		}
	}
	return out;
};

const browser = await chromium.launch();
const overflows = [];
const taps = [];
const tiny = [];

for ( const file of files ) {
	const slug = file.replace( /\.html$/, '' );
	for ( const vp of VIEWPORTS ) {
		const page = await browser.newPage( {
			viewport: { width: vp.width, height: vp.height },
		} );
		await page.goto( 'file://' + path.join( dir, file ) );
		const r = await page.evaluate( AUDIT, { MIN_TAP, MIN_FONT } );
		if ( r.overflow ) {
			overflows.push( `${ slug } @${ vp.name }: ${ r.overflow }` );
		}
		for ( const t of r.taps ) {
			taps.push( `${ slug } @${ vp.name }: ${ t }` );
		}
		for ( const t of r.tiny ) {
			tiny.push( `${ slug } @${ vp.name }: ${ t }` );
		}
		await page.close();
	}
}
await browser.close();

const show = ( list ) => [ ...new Set( list ) ].slice( 0, 8 ).join( '; ' );

console.log( '\nNothing pushes the page sideways, at any width' );
check( 0 === overflows.length, `horizontal overflow: ${ show( overflows ) }` );

console.log( '\nEvery control is big enough to hit with a thumb' );
check( 0 === taps.length, `under ${ MIN_TAP }px: ${ show( taps ) }` );

console.log( '\nNo text renders below 12px, on any screen' );
check( 0 === tiny.length, `under ${ MIN_FONT }px: ${ show( tiny ) }` );

console.log( `\nresponsive: ${ pass } passed, ${ fail } failed ` +
	`(${ files.length } pages x ${ VIEWPORTS.length } widths)` );
process.exit( fail ? 1 : 0 );
