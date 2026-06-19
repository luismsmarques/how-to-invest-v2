/**
 * Mobile header menu toggle (progressive enhancement).
 *
 * On small screens the header collapses the nav + CTA into a dropdown panel
 * opened by the hamburger button. No framework — a few lines of vanilla JS.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var header = document.querySelector( '.hti-header' );
	if ( ! header ) {
		return;
	}
	var toggle = header.querySelector( '.hti-nav-toggle' );
	if ( ! toggle ) {
		return;
	}

	function close() {
		header.classList.remove( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
	}

	toggle.addEventListener( 'click', function () {
		var open = header.classList.toggle( 'is-open' );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );

	// Close after choosing a link.
	header.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.hti-nav-wrap a' ) ) {
			close();
		}
	} );

	// Close on Escape and when resizing up to desktop.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			close();
		}
	} );
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 782 ) {
			close();
		}
	} );
}() );
