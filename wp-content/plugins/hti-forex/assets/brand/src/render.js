/**
 * Screenshot the .card element, not the viewport.
 *
 * A viewport screenshot depends on the window size matching the design; an
 * element screenshot is exactly the 2560x1440 the CSS declares, whatever the
 * browser was launched with.
 */
const { chromium } = require( 'playwright' );
const path = require( 'path' );

( async () => {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROMIUM,
		args: [ '--no-sandbox', '--allow-file-access-from-files' ],
	} );
	const page = await browser.newPage( { viewport: { width: 2560, height: 1440 } } );
	await page.goto( 'file://' + path.resolve( 'news-card.html' ) + '?' + process.env.CARD_QUERY );
	await page.waitForFunction( () => document.fonts.ready.then( () => true ) );
	await page.waitForTimeout( 300 );
	await page.locator( '.card' ).screenshot( { path: path.resolve( process.env.CARD_OUT ) } );
	await browser.close();
} )();
