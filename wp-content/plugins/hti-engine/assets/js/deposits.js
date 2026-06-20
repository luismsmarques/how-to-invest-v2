/**
 * Term-deposit comparator — client-side calculator, filter, sort and search.
 *
 * Progressive enhancement: the full list is server-rendered (indexable, works
 * without JS). This recomputes the estimated interest for the chosen amount,
 * filters/sorts the existing cards by their data attributes, and keeps the
 * "★ Melhor taxa" / "acima do Aforro" markers in sync. No network, no scoring.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var root = document.querySelector( '.hti-dep' );
	if ( ! root ) {
		return;
	}

	var amount0 = parseInt( root.getAttribute( 'data-amount' ), 10 ) || 10000;
	var aforro  = parseFloat( root.getAttribute( 'data-aforro' ) ) || 2.215;

	var list   = root.querySelector( '.hti-dep__list' );
	var cards  = Array.prototype.slice.call( root.querySelectorAll( '.hti-dep__card' ) );
	var countN = root.querySelector( '.hti-dep__count-n' );
	var beatsN = root.querySelector( '.hti-dep__beats-n' );
	var empty  = root.querySelector( '.hti-dep__empty' );

	var amount = root.querySelector( '.hti-dep__amount' );
	var q      = root.querySelector( '.hti-dep__q' );
	var tanb   = root.querySelector( '.hti-dep__tanb' );
	var tanbV  = root.querySelector( '.hti-dep__tanb-val' );
	var bank   = root.querySelector( '.hti-dep__bank' );
	var sort   = root.querySelector( '.hti-dep__sort' );
	var nc     = root.querySelector( '.hti-dep__nc' );
	var early  = root.querySelector( '.hti-dep__early' );
	var naores = root.querySelector( '.hti-dep__naores' );
	var chips  = Array.prototype.slice.call( root.querySelectorAll( '.hti-dep__chip' ) );

	var term = ''; // Active term chip value.

	function num( v ) {
		var n = parseFloat( v );
		return isNaN( n ) ? null : n;
	}

	// Parse "10 000" / "10.000 €" → 10000.
	function parseAmount( v ) {
		var digits = ( v || '' ).replace( /[^\d]/g, '' );
		return digits === '' ? 0 : parseInt( digits, 10 );
	}

	function group( n ) {
		return String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, ' ' );
	}

	function eur( n ) {
		return group( n ) + ' €';
	}

	function fmtPct( n ) {
		return n.toFixed( 2 ).replace( '.', ',' ) + '%';
	}

	// Recompute the estimated interest on every card for the current amount.
	function recalc() {
		var A = parseAmount( amount.value );
		cards.forEach( function ( card ) {
			var rate = num( card.dataset.tanb ) || 0;
			var t    = num( card.dataset.term ) || 0;
			var gross = A * rate / 100 * t / 12;
			var net   = gross * 0.72;
			var netEl   = card.querySelector( '.hti-dep__est-net' );
			var grossEl = card.querySelector( '.hti-dep__est-gross' );
			if ( netEl ) {
				netEl.textContent = '+' + eur( net );
			}
			if ( grossEl ) {
				grossEl.textContent = 'bruto ' + eur( gross );
			}
		} );
	}

	function matches( card ) {
		var d = card.dataset;

		var query = ( q.value || '' ).trim().toLowerCase();
		if ( query && d.text.indexOf( query ) === -1 ) {
			return false;
		}
		var minT = num( tanb.value );
		if ( minT && num( d.tanb ) < minT ) {
			return false;
		}
		if ( term && d.term !== term ) {
			return false;
		}
		if ( bank.value && d.bank !== bank.value ) {
			return false;
		}
		if ( nc.checked && d.novos !== '1' ) {
			return false;
		}
		if ( early.checked && d.early !== '1' ) {
			return false;
		}
		if ( naores.checked && d.naores !== '1' ) {
			return false;
		}
		var amt = parseAmount( amount.value );
		if ( amt > 0 ) {
			var mn = num( d.min );
			var mx = num( d.max );
			if ( mn !== null && amt < mn ) {
				return false;
			}
			if ( mx !== null && amt > mx ) {
				return false;
			}
		}
		return true;
	}

	function sortCards( visible ) {
		var key = sort.value;
		visible.sort( function ( a, b ) {
			if ( 'term' === key ) {
				return ( +a.dataset.term - +b.dataset.term ) || ( +b.dataset.tanb - +a.dataset.tanb );
			}
			if ( 'min' === key ) {
				var am = num( a.dataset.min ),
					bm = num( b.dataset.min );
				if ( am === null ) { am = Infinity; }
				if ( bm === null ) { bm = Infinity; }
				return ( am - bm ) || ( +b.dataset.tanb - +a.dataset.tanb );
			}
			return ( +b.dataset.tanb - +a.dataset.tanb ) || ( +a.dataset.term - +b.dataset.term );
		} );
		return visible;
	}

	function apply() {
		var visible = [];
		var maxTanb = 0;
		var beats   = 0;

		cards.forEach( function ( card ) {
			if ( matches( card ) ) {
				visible.push( card );
				card.hidden = false;
				maxTanb = Math.max( maxTanb, num( card.dataset.tanb ) || 0 );
				if ( ( num( card.dataset.tanb ) || 0 ) > aforro ) {
					beats++;
				}
			} else {
				card.hidden = true;
			}
		} );

		sortCards( visible ).forEach( function ( card ) {
			list.appendChild( card );
		} );

		// Refresh the "★ Melhor taxa" badge to the visible leader.
		cards.forEach( function ( card ) {
			var topEl  = card.querySelector( '.hti-dep__top' );
			var isTop  = ! card.hidden && ( num( card.dataset.tanb ) || 0 ) >= maxTanb && maxTanb > 0;
			card.classList.toggle( 'is-top', isTop );
			if ( topEl ) {
				topEl.hidden = ! isTop;
			}
		} );

		if ( countN ) {
			countN.textContent = visible.length;
		}
		if ( beatsN ) {
			beatsN.textContent = beats;
		}
		if ( empty ) {
			empty.hidden = visible.length !== 0;
			list.hidden = visible.length === 0;
		}
	}

	function onAmount() {
		// Re-group thousands as the user types, keeping the caret at the end.
		var n = parseAmount( amount.value );
		amount.value = n > 0 ? group( n ) : '';
		recalc();
		apply();
	}

	function reset() {
		amount.value = group( amount0 );
		q.value = '';
		tanb.value = 0;
		tanbV.textContent = '0,00%';
		bank.value = '';
		sort.value = 'rate';
		nc.checked = false;
		early.checked = false;
		naores.checked = false;
		term = '';
		chips.forEach( function ( c ) { c.classList.toggle( 'is-active', c.dataset.term === '' ); } );
		recalc();
		apply();
	}

	amount.addEventListener( 'input', onAmount );
	q.addEventListener( 'input', apply );
	tanb.addEventListener( 'input', function () {
		tanbV.textContent = fmtPct( num( tanb.value ) || 0 );
		apply();
	} );
	[ bank, sort, nc, early, naores ].forEach( function ( el ) {
		el.addEventListener( 'change', apply );
	} );
	chips.forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			term = chip.dataset.term;
			chips.forEach( function ( c ) { c.classList.toggle( 'is-active', c === chip ); } );
			apply();
		} );
	} );
	root.querySelectorAll( '.hti-dep__reset' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', reset );
	} );

	apply();
}() );
