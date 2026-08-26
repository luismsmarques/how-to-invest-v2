/**
 * News hub — client-side category filtering.
 *
 * Progressive enhancement: everything is server-rendered (indexable, works
 * without JS). The tabs filter the existing cards by their data-cat attribute.
 *
 * "All" view: show the featured block and the non-duplicate list rows.
 * Category view: hide the featured block and show every list row (including the
 * featured duplicates) whose category matches — so no story is ever lost.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var root = document.querySelector( '.hti-newshub' );
	if ( ! root ) {
		return;
	}

	var tabs    = Array.prototype.slice.call( root.querySelectorAll( '.hti-newshub__tab' ) );
	var feature = root.querySelector( '.hti-newshub__feature' );
	var rows    = Array.prototype.slice.call( root.querySelectorAll( '.hti-newshub__row' ) );
	var empty   = root.querySelector( '.hti-newshub__empty' );

	function apply( cat ) {
		var shown = 0;
		rows.forEach( function ( row ) {
			var isDup = row.classList.contains( 'is-dup' );
			var match;
			if ( ! cat ) {
				match = ! isDup; // All: hide the featured duplicates.
			} else {
				match = row.getAttribute( 'data-cat' ) === cat;
			}
			row.hidden = ! match;
			if ( match ) {
				shown++;
			}
		} );

		if ( feature ) {
			feature.hidden = !! cat;
		}
		if ( empty ) {
			empty.hidden = shown !== 0;
		}
		root.setAttribute( 'data-cat', cat || '' );
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			tabs.forEach( function ( t ) {
				var on = t === tab;
				t.classList.toggle( 'is-active', on );
				t.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			apply( tab.getAttribute( 'data-cat' ) || '' );
		} );
	} );
}() );
