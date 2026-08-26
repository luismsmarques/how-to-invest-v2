/**
 * Broker comparison — client-side search, sort and CFD filter.
 *
 * Progressive enhancement: the full list is server-rendered (indexable, works
 * without JS). This filters/sorts the existing cards by their data attributes
 * and fires the first-party view events. No network beyond the beacon.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	function track( name, params ) {
		if ( window.HTITrack && window.HTITrack.event ) {
			window.HTITrack.event( name, params || {} );
		}
	}

	// View events: single review, account-opening guide (the inline CTA box),
	// or the comparison itself (identified by its card list).
	var single = document.querySelector( '.hti-broker-review' );
	var guideBox = document.querySelector( '.hti-bkr__cta--inline' );
	var list = document.querySelector( '.hti-bk__list' );
	var root = list ? list.closest( '.hti-bk' ) : null;
	if ( single ) {
		track( 'broker_review_view', {} );
	} else if ( guideBox ) {
		track( 'broker_guide_view', {} );
	}
	if ( ! root ) {
		return;
	}
	track( 'broker_compare_view', {} );

	var cards  = Array.prototype.slice.call( root.querySelectorAll( '.hti-bk__card' ) );
	var q      = root.querySelector( '.hti-bk__q' );
	var noCfd  = root.querySelector( '.hti-bk__nocfd' );
	var sort   = root.querySelector( '.hti-bk__sort' );
	var countN = root.querySelector( '.hti-bk__count-n' );
	var empty  = root.querySelector( '.hti-bk__empty' );
	var reset  = root.querySelector( '.hti-bk__reset' );

	if ( ! list || ! cards.length ) {
		return;
	}

	function apply() {
		var term = ( q && q.value ? q.value : '' ).toLowerCase().trim();
		var hideCfd = !! ( noCfd && noCfd.checked );
		var mode = sort ? sort.value : 'editorial';
		var visible = 0;

		var ordered = cards.slice().sort( function ( a, b ) {
			if ( 'rating' === mode ) {
				return parseFloat( b.getAttribute( 'data-rating' ) ) - parseFloat( a.getAttribute( 'data-rating' ) );
			}
			if ( 'name' === mode ) {
				return a.getAttribute( 'data-name' ).localeCompare( b.getAttribute( 'data-name' ) );
			}
			return parseInt( a.getAttribute( 'data-order' ), 10 ) - parseInt( b.getAttribute( 'data-order' ), 10 );
		} );

		ordered.forEach( function ( card ) {
			var show = true;
			if ( term && -1 === card.getAttribute( 'data-text' ).indexOf( term ) ) {
				show = false;
			}
			if ( hideCfd && '1' === card.getAttribute( 'data-cfd' ) ) {
				show = false;
			}
			card.hidden = ! show;
			if ( show ) {
				visible++;
			}
			list.appendChild( card );
		} );

		if ( countN ) {
			countN.textContent = String( visible );
		}
		if ( empty ) {
			empty.hidden = visible > 0;
		}
	}

	if ( q ) {
		q.addEventListener( 'input', apply );
	}
	if ( noCfd ) {
		noCfd.addEventListener( 'change', apply );
	}
	if ( sort ) {
		sort.addEventListener( 'change', apply );
	}
	if ( reset ) {
		reset.addEventListener( 'click', function () {
			if ( q ) {
				q.value = '';
			}
			if ( noCfd ) {
				noCfd.checked = false;
			}
			apply();
		} );
	}
}() );
