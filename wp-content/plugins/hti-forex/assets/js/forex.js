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
		var plus = value > 0 ? '+' : '';
		switch ( format ) {
			case 'inr':
				return fmtINR.format( value );
			case 'inr0':
				return fmtINR0.format( value );
			case 'usd':
				return fmtUSD.format( value );
			case 'inr_signed':
				return plus + fmtINR.format( value );
			case 'usd_signed':
				return plus + fmtUSD.format( value );
			case 'int':
				return fmtInt.format( value );
			case 'lots':
				return value.toFixed( 2 ) + ' lots';
			case 'pips':
				return plus + value.toFixed( 1 ) + ' pips';
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
		// Margin variant: notional is price-independent for USD-base pairs.
		var priceField = form.querySelector( '.hti-fx-field--price' );
		if ( priceField ) {
			priceField.hidden = 'USD' === str( form, 'pair' ).slice( 0, 3 );
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

		// Margin variant: notional + margin for the suggested position.
		if ( form.querySelector( '[data-field="leverage"]' ) ) {
			var margin = result.tooSmall ? null : core.marginRequired(
				str( form, 'pair' ),
				result.lots,
				num( form, 'price' ),
				num( form, 'leverage' ),
				readRates( form )
			);
			write( form, 'notional_inr', margin ? margin.notionalINR : '—' );
			write( form, 'margin_inr', margin ? margin.marginINR : '—' );
		}
	}

	// Plausible per-pair price defaults for the profit/loss tool. Prefill
	// only — the user always types their own prices.
	var PRICE_DEFAULTS = {
		EURUSD: [ '1.0900', '1.0920' ],
		GBPUSD: [ '1.2900', '1.2920' ],
		USDJPY: [ '147.00', '147.30' ],
		XAUUSD: [ '3300.00', '3305.00' ],
		USDINR: [ '88.0000', '88.1000' ]
	};

	function prefillPrices( form ) {
		var defaults = PRICE_DEFAULTS[ str( form, 'pair' ) ];
		var entry = form.querySelector( '[data-field="entry"]' );
		var exit = form.querySelector( '[data-field="exit"]' );
		if ( defaults && entry && exit ) {
			entry.value = defaults[ 0 ];
			exit.value = defaults[ 1 ];
		}
	}

	function computeProfitLoss( form ) {
		var result = core.profitLoss(
			str( form, 'pair' ),
			str( form, 'direction' ),
			num( form, 'lots' ),
			num( form, 'entry' ),
			num( form, 'exit' ),
			readRates( form )
		);

		write( form, 'pl_inr', result ? result.inr : '—' );
		write( form, 'pl_usd', result ? result.usd : '—' );
		write( form, 'pips', result ? result.pips : '—' );

		form.querySelectorAll( '.hti-fx-out' ).forEach( function ( box ) {
			box.classList.remove( 'hti-fx-out--pos', 'hti-fx-out--neg' );
			if ( result && 0 !== result.inr && box.querySelector( '[data-out="pl_inr"], [data-out="pl_usd"]' ) ) {
				box.classList.add( result.inr > 0 ? 'hti-fx-out--pos' : 'hti-fx-out--neg' );
			}
		} );
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
		var compute = computePositionSize;
		if ( 'pip_value' === name ) {
			compute = computePipValue;
		} else if ( 'profit_loss' === name ) {
			compute = computeProfitLoss;
		}

		function run() {
			toggleJpyField( form );
			compute( form );
		}

		if ( 'profit_loss' === name ) {
			var pairField = form.querySelector( '[data-field="pair"]' );
			if ( pairField ) {
				pairField.addEventListener( 'change', function () {
					prefillPrices( form );
				} );
			}
		}

		// Margin variant: keep the price prefill in step with the pair.
		if ( 'position_size' === name && form.querySelector( '[data-field="price"]' ) ) {
			var psPair = form.querySelector( '[data-field="pair"]' );
			if ( psPair ) {
				psPair.addEventListener( 'change', function () {
					var defaults = PRICE_DEFAULTS[ str( form, 'pair' ) ];
					var price = form.querySelector( '[data-field="price"]' );
					if ( defaults && price ) {
						price.value = defaults[ 0 ];
					}
				} );
			}
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
	 * Affiliate sub-id passthrough
	 *
	 * Reads the campaign id from the landing URL (first configured param
	 * that is present) and appends it to every affiliate CTA href. Purely
	 * client-side and storage-free: nothing is persisted, no third-party
	 * request happens until the user clicks the clearly-labelled link.
	 * ----------------------------------------------------------------- */

	function initSubid() {
		var ctas = document.querySelectorAll( 'a[data-hti-fx-cta]' );
		if ( ! ctas.length || 'undefined' === typeof URLSearchParams ) {
			return;
		}

		var params = new URLSearchParams( window.location.search );
		var value = '';
		( cfg.subSources || [] ).some( function ( key ) {
			var v = params.get( key );
			if ( v ) {
				value = v;
				return true;
			}
			return false;
		} );

		value = value.replace( /[^A-Za-z0-9_-]/g, '' ).slice( 0, 64 );
		if ( ! value ) {
			return;
		}

		ctas.forEach( function ( a ) {
			try {
				var u = new URL( a.href );
				u.searchParams.set( cfg.subParam || 'clickid', value );
				a.href = u.toString();
			} catch ( e ) {
				// Malformed href — leave it untouched.
			}
		} );
	}

	/* -------------------------------------------------------------------
	 * Email capture → hti-engine's double-opt-in endpoint
	 * ----------------------------------------------------------------- */

	function initEmail( box ) {
		var form = box.querySelector( '.hti-fx-email__form' );
		var status = box.querySelector( '.hti-fx-email__status' );
		var consent = box.querySelector( '[data-consent]' );
		if ( ! form || ! cfg.subscribeUrl ) {
			return;
		}

		function say( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var email = ( form.querySelector( 'input[name="email"]' ) || {} ).value || '';
			if ( ! email || email.indexOf( '@' ) < 1 ) {
				say( 'Please enter a valid email address.' );
				return;
			}
			if ( consent && ! consent.checked ) {
				say( 'Please tick the consent box first.' );
				return;
			}

			var button = form.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
			}
			say( 'Sending…' );

			fetch( cfg.subscribeUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || ''
				},
				body: JSON.stringify( {
					email: email,
					consent: true,
					hti_hp: ( form.querySelector( 'input[name="hti_hp"]' ) || {} ).value || '',
					locale: 'en',
					source: box.getAttribute( 'data-source' ) || 'forex'
				} )
			} )
				.then( function ( res ) {
					if ( res.ok ) {
						say( 'Almost there — check your inbox and confirm the subscription.' );
						form.reset();
						if ( window.HTITrack ) {
							window.HTITrack.event( 'newsletter_subscribe_submit', {
								source: box.getAttribute( 'data-source' ) || 'forex',
								location: box.getAttribute( 'data-location' ) || 'forex',
								status: 'submitted'
							} );
						}
					} else if ( 429 === res.status ) {
						say( 'Too many attempts — please try again in a while.' );
					} else {
						say( 'That did not work — please check the email address and try again.' );
					}
				} )
				.catch( function () {
					say( 'Network problem — please try again.' );
				} )
				.finally( function () {
					if ( button ) {
						button.disabled = false;
					}
				} );
		} );
	}

	/* -------------------------------------------------------------------
	 * Boot
	 * ----------------------------------------------------------------- */

	document.querySelectorAll( 'form.hti-fx-tool[data-tool]' ).forEach( initCalculator );
	document.querySelectorAll( '.hti-fx-sessions[data-tool="sessions"]' ).forEach( initSessions );
	document.querySelectorAll( '.hti-fx-email[data-email]' ).forEach( initEmail );
	initSubid();
}() );
