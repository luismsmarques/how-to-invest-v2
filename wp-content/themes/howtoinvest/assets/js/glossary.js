/**
 * Glossary A–Z filter (progressive enhancement).
 *
 * Clicking a letter shows only the terms starting with it; "All" resets.
 * Without JS, every term is visible and the letters are inert.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var root = document.querySelector( '.hti-gloss' );
	if ( ! root ) {
		return;
	}
	var letters = root.querySelectorAll( '.hti-gloss__letter' );
	var rows = root.querySelectorAll( '.hti-gloss__row' );

	Array.prototype.forEach.call( letters, function ( btn ) {
		btn.addEventListener( 'click', function () {
			var pick = btn.getAttribute( 'data-letter' );

			Array.prototype.forEach.call( letters, function ( b ) {
				b.classList.toggle( 'is-active', b === btn );
			} );
			Array.prototype.forEach.call( rows, function ( row ) {
				var show = 'all' === pick || row.getAttribute( 'data-letter' ) === pick;
				if ( show ) {
					row.removeAttribute( 'hidden' );
				} else {
					row.setAttribute( 'hidden', 'hidden' );
				}
			} );
		} );
	} );
}() );
