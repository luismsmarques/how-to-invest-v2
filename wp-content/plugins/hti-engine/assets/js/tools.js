/**
 * HowToInvest — interactive tools (progressive enhancement).
 *
 * Each tool is a server-rendered <form class="hti-tool" data-tool="…">. This
 * script reads the inputs, computes with HTITools (tools-core.js), and writes
 * formatted, illustrative results live. No network, no advice.
 */
( function () {
	'use strict';

	var T = window.HTITools;
	if ( ! T ) {
		return;
	}

	function num( el ) {
		var v = parseFloat( ( el && el.value ) || '' );
		return isNaN( v ) ? 0 : v;
	}

	function field( root, key ) {
		return root.querySelector( '[data-field="' + key + '"]' );
	}

	function money( value, locale ) {
		try {
			return new Intl.NumberFormat( locale, {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			} ).format( Math.round( value ) );
		} catch ( e ) {
			return '€' + Math.round( value );
		}
	}

	// Compact, locale-aware words for the non-money outputs.
	var WORDS = {
		en: { year: 'year', years: 'years', month: 'month', months: 'months', times: 'times', never: 'Out of reach' },
		pt: { year: 'ano', years: 'anos', month: 'mês', months: 'meses', times: 'vezes', never: 'Fora de alcance' },
	};

	function decimal( value, places ) {
		var r = Math.round( value * Math.pow( 10, places ) ) / Math.pow( 10, places );
		return r % 1 === 0 ? String( r ) : r.toFixed( places );
	}

	// Format an output value per its data-format ('money' is the default).
	function format( value, fmt, locale ) {
		var w = WORDS[ locale.indexOf( 'pt' ) === 0 ? 'pt' : 'en' ];
		if ( fmt && fmt !== 'money' && ! isFinite( value ) ) {
			return w.never;
		}
		switch ( fmt ) {
			case 'years':
				return decimal( value, 1 ) + ' ' + ( value === 1 ? w.year : w.years );
			case 'months':
				var m = Math.ceil( value );
				return m + ' ' + ( m === 1 ? w.month : w.months );
			case 'times':
				return decimal( value, 1 ) + ' ' + w.times;
			case 'multiple':
				return decimal( value, 1 ) + '×';
			default:
				return money( value, locale );
		}
	}

	var COMPUTE = {
		compound: function ( r ) {
			var initial = num( field( r, 'initial' ) ),
				monthly = num( field( r, 'monthly' ) ),
				rate = num( field( r, 'rate' ) ),
				years = num( field( r, 'years' ) );
			var value = T.futureValue( initial, monthly, rate, years );
			var contributed = T.totalContributed( initial, monthly, years );
			return {
				out: { value: value, contributed: contributed, growth: value - contributed },
				chart: [
					{ data: T.series( initial, monthly, rate, years ).map( pv( 'value' ) ), color: '#FF6B5E', fill: true },
					{ data: T.series( initial, monthly, rate, years ).map( pv( 'contributed' ) ), color: '#7C5CFC', dashed: true },
				],
			};
		},
		inflation: function ( r ) {
			var amount = num( field( r, 'amount' ) ),
				rate = num( field( r, 'rate' ) ),
				years = num( field( r, 'years' ) );
			var power = T.purchasingPower( amount, rate, years );
			var needed = T.amountToMatch( amount, rate, years );
			return { out: { power: power, lost: amount - power, needed: needed } };
		},
		savings_goal: function ( r ) {
			var goal = num( field( r, 'goal' ) ),
				initial = num( field( r, 'initial' ) ),
				rate = num( field( r, 'rate' ) ),
				years = num( field( r, 'years' ) );
			var monthly = T.requiredMonthly( goal, initial, rate, years );
			var contributed = T.totalContributed( initial, monthly, years );
			return { out: { monthly: monthly, contributed: contributed, growth: Math.max( 0, goal - contributed ) } };
		},
		cost_of_waiting: function ( r ) {
			var monthly = num( field( r, 'monthly' ) ),
				rate = num( field( r, 'rate' ) ),
				years = num( field( r, 'years' ) ),
				delay = num( field( r, 'delay' ) );
			var res = T.costOfWaiting( monthly, rate, years, delay );
			var nowSeries = [],
				waitSeries = [];
			var whole = Math.max( 0, Math.floor( years ) );
			for ( var y = 0; y <= whole; y++ ) {
				nowSeries.push( T.futureValue( 0, monthly, rate, y ) );
				waitSeries.push( T.futureValue( 0, monthly, rate, Math.max( 0, y - delay ) ) );
			}
			return {
				out: { now: res.now, delayed: res.delayed, cost: res.cost },
				chart: [
					{ data: nowSeries, color: '#FF6B5E', fill: true },
					{ data: waitSeries, color: '#7C5CFC', dashed: true },
				],
			};
		},
		emergency_fund: function ( r ) {
			var expenses = num( field( r, 'expenses' ) ),
				months = num( field( r, 'months' ) ),
				saved = num( field( r, 'saved' ) ),
				monthly = num( field( r, 'monthly' ) );
			var res = T.emergencyFund( expenses, months, saved, monthly );
			return { out: { target: res.target, gap: res.gap, time: res.monthsToReach } };
		},
		rule_of_72: function ( r ) {
			var rate = num( field( r, 'rate' ) ),
				years = num( field( r, 'years' ) );
			var res = T.rule72( rate, years );
			return { out: { double: res.yearsToDouble, doublings: res.doublings, multiple: res.multiple } };
		},
		fee_impact: function ( r ) {
			var initial = num( field( r, 'initial' ) ),
				monthly = num( field( r, 'monthly' ) ),
				rate = num( field( r, 'rate' ) ),
				fee = num( field( r, 'fee' ) ),
				years = num( field( r, 'years' ) );
			var res = T.feeImpact( initial, monthly, rate, fee, years );
			return {
				out: { net: res.net, gross: res.gross, lost: res.lost },
				chart: [
					{ data: T.series( initial, monthly, rate, years ).map( pv( 'value' ) ), color: '#7C5CFC', dashed: true },
					{ data: T.series( initial, monthly, Math.max( 0, rate - fee ), years ).map( pv( 'value' ) ), color: '#FF6B5E', fill: true },
				],
			};
		},
	};

	function pv( key ) {
		return function ( p ) {
			return p[ key ];
		};
	}

	function drawChart( el, seriesList ) {
		var max = 0;
		seriesList.forEach( function ( s ) {
			s.data.forEach( function ( v ) {
				if ( v > max ) {
					max = v;
				}
			} );
		} );
		if ( max <= 0 ) {
			el.innerHTML = '';
			return;
		}
		var W = 320,
			H = 140,
			n = seriesList[ 0 ].data.length;
		function x( i ) {
			return n <= 1 ? 0 : ( i / ( n - 1 ) ) * W;
		}
		function y( v ) {
			return H - ( v / max ) * H;
		}
		var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-hidden="true">';
		seriesList.forEach( function ( s ) {
			var d = s.data
				.map( function ( v, i ) {
					return ( 0 === i ? 'M' : 'L' ) + x( i ).toFixed( 1 ) + ' ' + y( v ).toFixed( 1 );
				} )
				.join( ' ' );
			if ( s.fill ) {
				svg +=
					'<path d="' + d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z" fill="' + s.color + '" fill-opacity="0.14"/>';
			}
			svg +=
				'<path d="' +
				d +
				'" fill="none" stroke="' +
				s.color +
				'" stroke-width="2.5" stroke-linejoin="round"' +
				( s.dashed ? ' stroke-dasharray="4 6"' : '' ) +
				'/>';
		} );
		svg += '</svg>';
		el.innerHTML = svg;
	}

	function update( root ) {
		var tool = root.getAttribute( 'data-tool' );
		var compute = COMPUTE[ tool ];
		if ( ! compute ) {
			return;
		}
		var locale = root.getAttribute( 'data-locale' ) || 'en';
		var res = compute( root );
		Object.keys( res.out ).forEach( function ( key ) {
			var slot = root.querySelector( '[data-out="' + key + '"]' );
			if ( slot ) {
				slot.textContent = format( res.out[ key ], slot.getAttribute( 'data-format' ), locale );
			}
		} );
		var chartEl = root.querySelector( '[data-chart]' );
		if ( chartEl && res.chart ) {
			drawChart( chartEl, res.chart );
		}
	}

	// Allocation visualiser: archetype tabs → conic-gradient donut + class list.
	function initAllocation() {
		var widgets = document.querySelectorAll( '.hti-alloc[data-allocations]' );
		Array.prototype.forEach.call( widgets, function ( root ) {
			var data;
			try {
				data = JSON.parse( root.getAttribute( 'data-allocations' ) || '[]' );
			} catch ( e ) {
				return;
			}
			if ( ! data.length ) {
				return;
			}
			var donut = root.querySelector( '[data-donut]' );
			var list = root.querySelector( '[data-list]' );
			var tabs = root.querySelectorAll( '.hti-alloc__tab' );
			var center = root.getAttribute( 'data-center' ) || '';
			var sub = root.getAttribute( 'data-sub' ) || '';

			function show( arch ) {
				var stops = [],
					cumulative = 0;
				list.innerHTML = '';
				arch.slices.forEach( function ( s ) {
					var end = cumulative + s.pct;
					stops.push( s.color + ' ' + cumulative + '% ' + end + '%' );
					cumulative = end;
					var li = document.createElement( 'li' );
					li.className = 'hti-alloc__row';
					var dot = document.createElement( 'span' );
					dot.className = 'hti-alloc__dot';
					dot.style.background = s.color;
					var name = document.createElement( 'span' );
					name.className = 'hti-alloc__name';
					name.textContent = s.label;
					var pct = document.createElement( 'span' );
					pct.className = 'hti-alloc__pct';
					pct.textContent = s.pct + '%';
					li.appendChild( dot );
					li.appendChild( name );
					li.appendChild( pct );
					list.appendChild( li );
				} );
				donut.style.background = 'conic-gradient(' + stops.join( ',' ) + ')';
				donut.innerHTML = '<span class="hti-alloc__hole"><span class="hti-alloc__cap">' +
					escapeText( center ) + '</span><span class="hti-alloc__subcap">' + escapeText( sub ) + '</span></span>';
			}

			function select( id ) {
				var arch = data.filter( function ( a ) {
					return String( a.id ) === String( id );
				} )[ 0 ] || data[ 0 ];
				Array.prototype.forEach.call( tabs, function ( t ) {
					t.setAttribute( 'aria-selected', String( t.getAttribute( 'data-arch' ) ) === String( arch.id ) ? 'true' : 'false' );
				} );
				show( arch );
			}

			Array.prototype.forEach.call( tabs, function ( t ) {
				t.addEventListener( 'click', function () {
					select( t.getAttribute( 'data-arch' ) );
				} );
			} );
			select( data[ 0 ].id );
		} );
	}

	function escapeText( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	function init() {
		var tools = document.querySelectorAll( '.hti-tool[data-tool]' );
		Array.prototype.forEach.call( tools, function ( root ) {
			root.addEventListener( 'input', function () {
				update( root );
			} );
			root.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				update( root );
			} );
			update( root );
		} );
		initAllocation();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
