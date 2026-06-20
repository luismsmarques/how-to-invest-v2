/**
 * Term-deposit comparator — client-side filter / sort / search.
 *
 * Progressive enhancement: the full list is server-rendered (indexable, works
 * without JS); this filters and reorders the existing cards by their data
 * attributes. No network, no scoring — purely presentational.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var root = document.querySelector( '.hti-dep' );
	if ( ! root ) {
		return;
	}

	var list  = root.querySelector( '.hti-dep__list' );
	var cards = Array.prototype.slice.call( root.querySelectorAll( '.hti-dep__card' ) );
	var count = root.querySelector( '.hti-dep__count' );
	var empty = root.querySelector( '.hti-dep__empty' );

	var q      = root.querySelector( '.hti-dep__q' );
	var term   = root.querySelector( '.hti-dep__term' );
	var bank   = root.querySelector( '.hti-dep__bank' );
	var amount = root.querySelector( '.hti-dep__amount' );
	var sort   = root.querySelector( '.hti-dep__sort' );
	var nc     = root.querySelector( '.hti-dep__nc' );
	var mobil  = root.querySelector( '.hti-dep__mobil' );
	var nat    = root.querySelector( '.hti-dep__nat' );

	function num( v ) {
		var n = parseFloat( v );
		return isNaN( n ) ? null : n;
	}

	function matches( card ) {
		var d = card.dataset;

		var query = ( q.value || '' ).trim().toLowerCase();
		if ( query && d.text.indexOf( query ) === -1 ) {
			return false;
		}
		if ( term.value && d.term !== term.value ) {
			return false;
		}
		if ( bank.value && d.bank !== bank.value ) {
			return false;
		}
		var amt = num( amount.value );
		if ( amt !== null ) {
			var min = num( d.min );
			var max = num( d.max );
			if ( min !== null && amt < min ) {
				return false;
			}
			if ( max !== null && amt > max ) {
				return false;
			}
		}
		if ( nc.checked && d.nc !== '1' ) {
			return false;
		}
		if ( mobil.checked && d.mobil !== '1' ) {
			return false;
		}
		if ( nat.checked && d.irs !== '0' ) {
			return false;
		}
		return true;
	}

	function sortCards( visible ) {
		var key = sort.value;
		visible.sort( function ( a, b ) {
			if ( 'term' === key ) {
				return ( +a.dataset.term - +b.dataset.term ) || ( +b.dataset.rate - +a.dataset.rate );
			}
			if ( 'min' === key ) {
				var am = num( a.dataset.min ),
					bm = num( b.dataset.min );
				if ( am === null ) {
					am = Infinity;
				}
				if ( bm === null ) {
					bm = Infinity;
				}
				return ( am - bm ) || ( +b.dataset.rate - +a.dataset.rate );
			}
			// Default: rate desc, then term asc.
			return ( +b.dataset.rate - +a.dataset.rate ) || ( +a.dataset.term - +b.dataset.term );
		} );
		return visible;
	}

	function apply() {
		var visible = [];
		cards.forEach( function ( card ) {
			if ( matches( card ) ) {
				visible.push( card );
				card.hidden = false;
			} else {
				card.hidden = true;
			}
		} );

		sortCards( visible ).forEach( function ( card ) {
			list.appendChild( card ); // Reorder in place (hidden ones stay too).
		} );

		count.textContent = visible.length + ( 1 === visible.length ? ' oferta' : ' ofertas' );
		if ( empty ) {
			empty.hidden = visible.length !== 0;
		}
	}

	function reset() {
		q.value = '';
		term.value = '';
		bank.value = '';
		amount.value = '';
		sort.value = 'rate';
		nc.checked = false;
		mobil.checked = false;
		nat.checked = false;
		apply();
	}

	[ q, amount ].forEach( function ( el ) {
		el.addEventListener( 'input', apply );
	} );
	[ term, bank, sort, nc, mobil, nat ].forEach( function ( el ) {
		el.addEventListener( 'change', apply );
	} );
	root.querySelectorAll( '.hti-dep__reset' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', reset );
	} );

	apply();
}() );
