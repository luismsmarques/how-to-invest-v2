/**
 * Single-news article — reading-progress bar + copy-link.
 * Progressive enhancement: everything works without JS; this only adds the
 * scroll progress indicator and the clipboard copy.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var art = document.querySelector( '.hti-art' );
	if ( ! art ) {
		return;
	}

	// Click-to-load YouTube (privacy-first): the embed only contacts Google
	// after an explicit click, so nothing loads before consent.
	var facade = art.querySelector( '.hti-art__video-facade' );
	if ( facade ) {
		facade.addEventListener( 'click', function () {
			var src = facade.getAttribute( 'data-embed' );
			if ( ! src ) { return; }
			var iframe = document.createElement( 'iframe' );
			iframe.src = src;
			iframe.title = facade.getAttribute( 'data-title' ) || '';
			iframe.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
			iframe.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' );
			iframe.setAttribute( 'allowfullscreen', '' );
			facade.parentNode.replaceChild( iframe, facade );
		} );
	}

	// Reading progress bar.
	var bar = art.querySelector( '.hti-art__bar' );
	if ( bar ) {
		var update = function () {
			var total = art.offsetHeight - window.innerHeight;
			var scrolled = -art.getBoundingClientRect().top;
			var p = total > 0 ? Math.min( 1, Math.max( 0, scrolled / total ) ) : 0;
			bar.style.transform = 'scaleX(' + p + ')';
		};
		window.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}

	// Copy link.
	var copy = art.querySelector( '.hti-art__copy' );
	if ( copy ) {
		copy.addEventListener( 'click', function () {
			var url = copy.getAttribute( 'data-url' ) || window.location.href;
			var done = copy.getAttribute( 'data-copied' ) || 'Copied!';
			var label = copy.querySelector( '.hti-art__copy-label' );
			var orig = label ? label.textContent : '';
			var ok = function () {
				copy.classList.add( 'is-copied' );
				if ( label ) {
					label.textContent = done;
				}
				setTimeout( function () {
					copy.classList.remove( 'is-copied' );
					if ( label ) {
						label.textContent = orig;
					}
				}, 2000 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( ok, function () {} );
			}
		} );
	}
}() );
