/**
 * HTI Forex — DOM layer. Reads [data-field] inputs, computes via HTIForex
 * (forex-core.js) and writes [data-out] values with en-IN formatting
 * (lakh/crore digit grouping). Also runs the live IST session clock.
 * Recalculates on every input — no submit button, no network.
 */
( function () {
	'use strict';

	var core = window.HTIForex;
	var cfg = window.HTI_FOREX || {};
	if ( ! core ) {
		return;
	}

	/* -------------------------------------------------------------------
	 * Formatting (en-IN: ₹1,00,000 grouping)
	 * ----------------------------------------------------------------- */

	var fmtINR = new Intl.NumberFormat( 'en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 } );
	var fmtINR0 = new Intl.NumberFormat( 'en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 } );
	var fmtUSD = new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 } );
	var fmtInt = new Intl.NumberFormat( 'en-IN', { maximumFractionDigits: 0 } );

	function fmt( value, format ) {
		if ( 'number' !== typeof value || ! isFinite( value ) ) {
			return '—';
		}
		switch ( format ) {
			case 'inr':
				return fmtINR.format( value );
			case 'inr0':
				return fmtINR0.format( value );
			case 'usd':
				return fmtUSD.format( value );
			case 'int':
				return fmtInt.format( value );
			case 'lots':
				return value.toFixed( 2 ) + ' lots';
			default:
				return String( value );
		}
	}

	function write( form, key, value, formatOverride ) {
		var el = form.querySelector( '[data-out="' + key + '"]' );
		if ( el ) {
			el.textContent = 'string' === typeof value ? value : fmt( value, formatOverride || el.getAttribute( 'data-format' ) );
		}
	}

	/* -------------------------------------------------------------------
	 * Calculators
	 * ----------------------------------------------------------------- */

	function num( form, key ) {
		var el = form.querySelector( '[data-field="' + key + '"]' );
		var v = el ? parseFloat( el.value ) : NaN;
		return isFinite( v ) ? v : NaN;
	}

	function str( form, key ) {
		var el = form.querySelector( '[data-field="' + key + '"]' );
		return el ? el.value : '';
	}

	// Editable rate inputs win over the server-provided reference rates.
	function readRates( form ) {
		var base = cfg.rates || {};
		var inr = num( form, 'rate_usdinr' );
		var jpy = num( form, 'rate_usdjpy' );
		return {
			USDINR: inr > 0 ? inr : base.USDINR,
			USDJPY: jpy > 0 ? jpy : base.USDJPY
		};
	}

	function toggleJpyField( form ) {
		var jpyField = form.querySelector( '.hti-fx-field--jpy' );
		if ( jpyField ) {
			jpyField.hidden = 'USDJPY' !== str( form, 'pair' );
		}
	}

	function computePositionSize( form ) {
		var result = core.positionSize( num( form, 'balance' ), num( form, 'risk' ), num( form, 'stop' ), str( form, 'pair' ), readRates( form ) );
		var tooSmall = form.querySelector( '[data-toosmall]' );

		if ( ! result ) {
			[ 'lots', 'units', 'risk_inr', 'pip_inr' ].forEach( function ( k ) {
				write( form, k, '—' );
			} );
			if ( tooSmall ) {
				tooSmall.hidden = true;
			}
			return;
		}

		write( form, 'pip_inr', result.pipInrPerLot );
		if ( result.tooSmall ) {
			write( form, 'lots', 'Below one micro lot' );
			write( form, 'units', '—' );
			write( form, 'risk_inr', '—' );
		} else {
			write( form, 'lots', result.lots );
			write( form, 'units', result.units );
			write( form, 'risk_inr', result.actualRiskINR );
		}
		if ( tooSmall ) {
			tooSmall.hidden = ! result.tooSmall;
		}
	}

	function computePipValue( form ) {
		var pair = str( form, 'pair' );
		var rates = readRates( form );
		var atSize = core.pipValue( pair, num( form, 'lots' ), rates );
		var perLot = core.pipValue( pair, 1, rates );

		write( form, 'pip_inr', atSize ? atSize.inr : '—' );
		write( form, 'pip_usd', atSize ? atSize.usd : '—' );
		write( form, 'standard', perLot ? perLot.inr : '—' );
		write( form, 'mini', perLot ? perLot.inr / 10 : '—' );
		write( form, 'micro', perLot ? perLot.inr / 100 : '—' );
	}

	function initCalculator( form ) {
		var name = form.getAttribute( 'data-tool' );
		var compute = 'pip_value' === name ? computePipValue : computePositionSize;

		function run() {
			toggleJpyField( form );
			compute( form );
		}

		form.addEventListener( 'input', run );
		form.addEventListener( 'change', run );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );
		run();
	}

	/* -------------------------------------------------------------------
	 * Live IST session clock
	 * ----------------------------------------------------------------- */

	function initSessions( box ) {
		var clock = box.querySelector( '[data-clock]' );
		var clockTime = box.querySelector( '[data-clock-time]' );
		var overlapEl = box.querySelector( '[data-overlap]' );

		box.querySelectorAll( '[data-status-col], [data-status]' ).forEach( function ( el ) {
			el.hidden = false;
		} );
		if ( clock ) {
			clock.hidden = false;
		}

		function tickClock() {
			if ( ! clockTime ) {
				return;
			}
			var now = new Date( Date.now() + 5.5 * 3600000 );
			var h = now.getUTCHours();
			var m = now.getUTCMinutes();
			var s = now.getUTCSeconds();
			clockTime.textContent =
				( h < 10 ? '0' + h : h ) + ':' + ( m < 10 ? '0' + m : m ) + ':' + ( s < 10 ? '0' + s : s );
		}

		function renderStatus() {
			var now = Date.now();
			core.sessionWindowsIST( now ).forEach( function ( w ) {
				var row = box.querySelector( '[data-session="' + w.id + '"]' );
				if ( ! row ) {
					return;
				}
				var open = row.querySelector( '[data-open]' );
				var close = row.querySelector( '[data-close]' );
				var status = row.querySelector( '[data-status]' );
				if ( open ) {
					open.textContent = w.openIST;
				}
				if ( close ) {
					close.textContent = w.closeIST + ( w.closesNextDay ? ' +1' : '' );
				}
				if ( status ) {
					var label = w.weekend ? 'Weekend' : ( w.isOpen ? 'Open' : 'Closed' );
					status.textContent = label;
					status.className = 'hti-fx-status-col hti-fx-status hti-fx-status--' + ( w.isOpen ? 'open' : 'closed' );
				}
				row.classList.toggle( 'is-open', w.isOpen );
			} );

			var overlap = core.overlapLondonNY( now );
			if ( overlapEl ) {
				overlapEl.innerHTML =
					'London–New York overlap today: <strong>' + overlap.startIST + '–' + overlap.endIST + ' IST</strong>' +
					( overlap.active ? ' — <span class="hti-fx-status--open">active now</span>.' : ' — historically the busiest hours of the trading day.' );
			}
		}

		tickClock();
		renderStatus();
		setInterval( tickClock, 1000 );
		setInterval( renderStatus, 30000 );
	}

	/* -------------------------------------------------------------------
	 * Boot
	 * ----------------------------------------------------------------- */

	document.querySelectorAll( 'form.hti-fx-tool[data-tool]' ).forEach( initCalculator );
	document.querySelectorAll( '.hti-fx-sessions[data-tool="sessions"]' ).forEach( initSessions );
}() );
