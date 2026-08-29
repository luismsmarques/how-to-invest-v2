#!/usr/bin/env bash
# Regenerate the Telegram avatars from avatars.html.
#
# Telegram wants a square image and recommends at least 512×512; it crops to a
# circle itself, which is why the art is already circular and bleeds to the
# edge. Rendered through Chromium so the bot mark can use the site's real
# Poppins glyph for ₹ instead of an approximation — Poppins carries U+20B9.
#
# Playwright drives the browser rather than `chromium --screenshot`, because
# the plain flag screenshots the whole viewport and clips the disc; an element
# screenshot gives exactly the 512×512 the SVG declares.
#
#   ./build.sh
#   CHROMIUM=/path/to/chrome ./build.sh
set -euo pipefail
cd "$(dirname "$0")"

BIN="${CHROMIUM:-}"
if [ -z "$BIN" ]; then
	for c in /opt/pw-browsers/chromium chromium chromium-browser google-chrome; do
		if [ -x "$c" ]; then BIN="$c"; break; fi
		if command -v "$c" >/dev/null 2>&1; then BIN="$(command -v "$c")"; break; fi
	done
fi
[ -n "$BIN" ] || { echo "No chromium found. Set CHROMIUM=/path/to/chrome." >&2; exit 1; }

NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}" CHROMIUM="$BIN" node - <<'JS'
const { chromium } = require( 'playwright' );
const path = require( 'path' );

( async () => {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROMIUM,
		args: [ '--no-sandbox', '--allow-file-access-from-files' ],
	} );
	const page = await browser.newPage( { viewport: { width: 600, height: 600 } } );
	await page.goto( 'file://' + path.resolve( 'avatars.html' ) );
	await page.waitForFunction( () => document.fonts.ready.then( () => true ) );
	await page.waitForTimeout( 400 );

	for ( const id of [ 'channel', 'bot' ] ) {
		const out = path.resolve( '..', `hti-forex-telegram-${ id }.png` );
		await page.locator( '#' + id ).screenshot( { path: out, omitBackground: true } );
		console.log( `Wrote assets/brand/hti-forex-telegram-${ id }.png` );
	}
	await browser.close();
} )();
JS
