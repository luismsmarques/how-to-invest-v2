/**
 * Mobile header menu — progressive enhancement.
 *
 * Open/close is CSS-only (a hidden checkbox), so the menu works without JS.
 * This script only adds the niceties: keyboard activation of the label,
 * aria-expanded sync, and closing on link click / outside click / Escape /
 * resize back to desktop.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var check = document.getElementById( 'hti-nav-check' );
	if ( ! check ) {
		return;
	}
	var header = document.querySelector( '.hti-header' );
	var label = header ? header.querySelector( '.hti-nav-toggle' ) : null;

	function close() {
		check.checked = false;
		sync();
	}

	function sync() {
		if ( label ) {
			label.setAttribute( 'aria-expanded', check.checked ? 'true' : 'false' );
		}
	}

	// Keyboard: Enter/Space on the label toggles the menu.
	if ( label ) {
		label.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				check.checked = ! check.checked;
				sync();
			}
		} );
	}
	check.addEventListener( 'change', sync );

	// Close after choosing a link.
	if ( header ) {
		header.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.hti-nav-wrap a' ) || e.target.closest( '.hti-drawer a' ) ) {
				close();
			}
		} );
	}

	// Close on outside click and on Escape.
	document.addEventListener( 'click', function ( e ) {
		if ( check.checked && ! e.target.closest( '.hti-header' ) ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			close();
		}
	} );

	// Reset when resizing up to desktop.
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 782 ) {
			close();
		}
	} );

	sync();
}() );
