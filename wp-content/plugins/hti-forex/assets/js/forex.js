/**
 * HTI Forex — DOM layer for the unified tool composition. Reads [data-field]
 * inputs, computes via HTIForex (forex-core.js) and writes [data-out] values
 * with en-IN formatting (lakh/crore grouping). Runs the live IST session
 * clock, the email-capture states and the affiliate sub-id passthrough.
 * Recalculates on every input — no submit button, no network for the maths.
 *
 * States implemented per the design handoff: skeleton before the first
 * calculation (never a misleading 0.00), inline input errors, the
 * below-micro box replacing the result, and the risk bar on a 0–2% scale.
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
	var fmtInt = new Intl.NumberFormat( 'en-IN' );

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

	function write( form, key, value ) {
		var el = form.querySelector( '[data-out="' + key + '"]' );
		if ( el ) {
			el.textContent = 'string' === typeof value ? value : fmt( value, el.getAttribute( 'data-format' ) );
		}
	}

	/* -------------------------------------------------------------------
	 * Field access + validation
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

	// Inline error state (design: coral-red border + actionable message).
	// A form with an invalid field keeps its previous result rather than
	// computing nonsense.
	//
	// Only out-of-range and unparseable values count as invalid. `step` is the
	// spinner increment, not a rule: a ₹8,500 balance against step="1000" is a
	// perfectly valid amount, and treating that mismatch as an error froze the
	// whole calculator on any non-round input.
	function validateFields( form ) {
		var ok = true;
		form.querySelectorAll( '.hti-fx-field input[type="number"]' ).forEach( function ( input ) {
			var field = input.closest( '.hti-fx-field' );
			var err = field ? field.querySelector( '[data-err]' ) : null;
			var state = input.validity;
			var invalid = '' !== input.value && !! state &&
				( state.rangeUnderflow || state.rangeOverflow || state.badInput );
			if ( field ) {
				field.classList.toggle( 'is-invalid', invalid );
			}
			if ( err ) {
				err.hidden = ! invalid;
			}
			if ( invalid ) {
				ok = false;
			}
		} );
		return ok;
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

	function toggleConditionalFields( form ) {
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

	// First successful calculation swaps the skeleton for the result body.
	function reveal( form ) {
		var skeleton = form.querySelector( '[data-skeleton]' );
		var body = form.querySelector( '[data-panelbody]' );
		if ( skeleton ) {
			skeleton.hidden = true;
		}
		if ( body ) {
			body.hidden = false;
		}
	}

	function showTooSmall( form, on ) {
		var box = form.querySelector( '[data-toosmall]' );
		var body = form.querySelector( '[data-panelbody]' );
		if ( box ) {
			box.hidden = ! on;
		}
		if ( body && on ) {
			body.hidden = true;
		}
	}

	/* -------------------------------------------------------------------
	 * Calculators
	 * ----------------------------------------------------------------- */

	function computePositionSize( form ) {
		var balance = num( form, 'balance' );
		var riskPct = num( form, 'risk' );
		var result = core.positionSize( balance, riskPct, num( form, 'stop' ), str( form, 'pair' ), readRates( form ) );

		if ( ! result ) {
			return false;
		}

		if ( result.tooSmall ) {
			reveal( form );
			showTooSmall( form, true );
			return true;
		}
		showTooSmall( form, false );
		reveal( form );

		write( form, 'lots', result.lots );
		write( form, 'units', result.units );
		write( form, 'risk_inr', result.actualRiskINR );
		write( form, 'pip_inr', result.pipInrPerLot );
		write( form, 'risk_pip', result.pipInrPerLot * result.lots );

		// Risk bar: rupees actually at risk on a 0–2%-of-balance scale.
		var fill = form.querySelector( '[data-riskfill]' );
		if ( fill && balance > 0 ) {
			var pct = ( result.actualRiskINR / balance ) * 100;
			fill.style.width = Math.max( 2, Math.min( 100, ( pct / 2 ) * 100 ) ) + '%';
		}
		var of = form.querySelector( '[data-risk-of]' );
		if ( of ) {
			of.textContent = fmtINR0.format( balance );
		}
		var target = form.querySelector( '[data-risk-target]' );
		if ( target && isFinite( riskPct ) ) {
			target.textContent = 'target ' + riskPct.toFixed( 1 ) + '%';
		}

		// Margin variant: notional + margin for the suggested position.
		if ( form.querySelector( '[data-field="leverage"]' ) ) {
			var margin = core.marginRequired(
				str( form, 'pair' ),
				result.lots,
				num( form, 'price' ),
				num( form, 'leverage' ),
				readRates( form )
			);
			write( form, 'notional_inr', margin ? margin.notionalINR : '—' );
			write( form, 'margin_inr', margin ? margin.marginINR : '—' );
		}
		return true;
	}

	function computePipValue( form ) {
		var pair = str( form, 'pair' );
		var rates = readRates( form );
		var atSize = core.pipValue( pair, num( form, 'lots' ), rates );
		var perLot = core.pipValue( pair, 1, rates );

		if ( ! atSize || ! perLot ) {
			return false;
		}
		reveal( form );
		write( form, 'pip_inr', atSize.inr );
		write( form, 'pip_usd', atSize.usd );
		write( form, 'standard', perLot.inr );
		write( form, 'mini', perLot.inr / 10 );
		write( form, 'micro', perLot.inr / 100 );
		return true;
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

		if ( ! result ) {
			return false;
		}
		reveal( form );
		write( form, 'pl_inr', result.inr );
		write( form, 'pl_usd', result.usd );
		write( form, 'pips', result.pips );

		var primary = form.querySelector( '.hti-fx-primary' );
		if ( primary ) {
			primary.classList.toggle( 'is-neg', result.inr < 0 );
		}
		return true;
	}

	// Plausible per-pair price defaults for the profit/loss tool and the
	// margin variant. Prefill only — the user always types their own prices.
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

	function initCalculator( form ) {
		var name = form.getAttribute( 'data-tool' );
		var compute = computePositionSize;
		if ( 'pip_value' === name ) {
			compute = computePipValue;
		} else if ( 'profit_loss' === name ) {
			compute = computeProfitLoss;
		}

		function run() {
			toggleConditionalFields( form );
			if ( ! validateFields( form ) ) {
				return;
			}
			compute( form );
		}

		if ( 'profit_loss' === name ) {
			var plPair = form.querySelector( '[data-field="pair"]' );
			if ( plPair ) {
				plPair.addEventListener( 'change', function () {
					prefillPrices( form );
				} );
			}
		}

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
		var hm = box.querySelector( '[data-clock-hm]' );
		var sec = box.querySelector( '[data-clock-s]' );
		var pill = box.querySelector( '[data-overlap-pill]' );
		var overlapLine = box.querySelector( '[data-overlap]' );

		function tickClock() {
			var now = new Date( Date.now() + 5.5 * 3600000 );
			var h = now.getUTCHours();
			var m = now.getUTCMinutes();
			var s = now.getUTCSeconds();
			if ( hm ) {
				hm.textContent = ( h < 10 ? '0' + h : h ) + ':' + ( m < 10 ? '0' + m : m );
			}
			if ( sec ) {
				sec.textContent = ':' + ( s < 10 ? '0' + s : s );
			}
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
					status.textContent = w.isOpen ? '● Open' : '— Closed';
					status.className = 'hti-fx-status ' + ( w.isOpen ? 'hti-fx-status--open' : 'hti-fx-status--closed' );
				}
				row.classList.toggle( 'is-open', w.isOpen );
			} );

			var overlap = core.overlapLondonNY( now );
			if ( pill ) {
				pill.hidden = ! overlap.active;
			}
			if ( overlapLine ) {
				overlapLine.textContent = overlap.startIST + '–' + overlap.endIST + ' IST · busiest hours';
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
		var button = form ? form.querySelector( 'button[type="submit"]' ) : null;
		var label = form ? form.querySelector( '.hti-fx-email__btnlabel' ) : null;
		if ( ! form || ! cfg.subscribeUrl ) {
			return;
		}

		function say( message, kind ) {
			if ( status ) {
				status.textContent = message;
				status.classList.toggle( 'is-ok', 'ok' === kind );
				status.classList.toggle( 'is-err', 'err' === kind );
			}
		}

		function loading( on ) {
			box.classList.toggle( 'is-loading', on );
			if ( button ) {
				button.disabled = on;
			}
			if ( label ) {
				label.textContent = on ? 'Sending…' : 'Subscribe';
			}
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var email = ( form.querySelector( 'input[name="email"]' ) || {} ).value || '';
			if ( ! email || email.indexOf( '@' ) < 1 ) {
				say( "That email doesn't look right — check the address and try again.", 'err' );
				return;
			}
			if ( consent && ! consent.checked ) {
				say( 'Please tick the consent box first.', 'err' );
				return;
			}

			loading( true );
			say( '', '' );

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
						say( 'Check your inbox — confirm the subscription to finish.', 'ok' );
						form.reset();
						if ( window.HTITrack ) {
							window.HTITrack.event( 'newsletter_subscribe_submit', {
								source: box.getAttribute( 'data-source' ) || 'forex',
								location: box.getAttribute( 'data-location' ) || 'forex',
								status: 'submitted'
							} );
						}
					} else if ( 429 === res.status ) {
						say( 'Too many attempts — please try again in a while.', 'err' );
					} else {
						say( "That didn't work — check the email address and try again.", 'err' );
					}
				} )
				.catch( function () {
					say( 'Network problem — please try again.', 'err' );
				} )
				.finally( function () {
					loading( false );
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
