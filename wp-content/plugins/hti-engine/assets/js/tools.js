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
				slot.textContent = money( res.out[ key ], locale );
			}
		} );
		var chartEl = root.querySelector( '[data-chart]' );
		if ( chartEl && res.chart ) {
			drawChart( chartEl, res.chart );
		}
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
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
