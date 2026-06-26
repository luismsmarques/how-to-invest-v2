/**
 * Multi-step questionnaire (E5) — one question per step, progress bar,
 * keyboard + screen-reader friendly, partial state persisted in sessionStorage.
 *
 * All scoring is server-side: on submit this POSTs answers to
 * /wp-json/htinvest/v1/recommend with the nonce, shows a processing state, then
 * hands the response to window.HTIResult.render. Errors fall back gracefully.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var cfg = window.HTI_DATA;
	var mount = document.getElementById( 'hti-app' );
	if ( ! cfg || ! mount ) {
		return;
	}

	var questions = cfg.data.questions;
	var ui = cfg.data.ui;
	var total = questions.length;

	var answers = load( 'hti_answers', {} );
	var step = parseInt( load( 'hti_step', 0 ), 10 ) || 0;
	var processingTimer = null;
	if ( step > total - 1 ) {
		step = total - 1;
	}

	function load( key, fallback ) {
		try {
			var raw = window.sessionStorage.getItem( key );
			return raw === null ? fallback : JSON.parse( raw );
		} catch ( e ) {
			return fallback;
		}
	}

	function save( key, value ) {
		try {
			window.sessionStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {}
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	function sprintf2( tpl, a, b ) {
		return tpl.replace( '%1$s', a ).replace( '%2$s', b );
	}

	// Full-screen takeover while the quiz steps show (E5): hides the site chrome
	// via CSS. Removed before the result (E7), which keeps the header.
	function setQuizFullscreen( on ) {
		try {
			document.documentElement.classList.toggle( 'hti-quiz-active', !! on );
		} catch ( e ) {}
	}

	// Brand mark for the quiz top bar (mirrors the site header logo).
	var QZ_LOGO = '<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none" aria-hidden="true"><circle cx="32" cy="32" r="32" fill="#1E2147"/><circle cx="32" cy="32" r="29.3" stroke="#fff" stroke-opacity=".28" stroke-width=".8"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/><g fill="#7C5CFC"><rect x="20.5" y="22" width="1" height="11"/><rect x="19.2" y="25.5" width="3.6" height="5" rx="1"/><rect x="25.6" y="20" width="1" height="12.5"/><rect x="24.3" y="23.5" width="3.6" height="5.5" rx="1"/><rect x="30.2" y="21.5" width="1" height="11"/><rect x="28.9" y="24.5" width="3.6" height="4.5" rx="1"/><rect x="35.6" y="18" width="1" height="12.5"/><rect x="34.3" y="21" width="3.6" height="5.8" rx="1"/><rect x="20.4" y="40" width="3.6" height="6" rx=".8"/><rect x="25.9" y="37.5" width="3.6" height="8.5" rx=".8"/><rect x="31.4" y="35" width="3.6" height="11" rx=".8"/><rect x="36.9" y="32.5" width="3.6" height="13.5" rx=".8"/></g></svg>';

	function renderStep() {
		stopProcessing();
		setQuizFullscreen( true );
		var q = questions[ step ];
		mount.innerHTML = '';

		var form = el( 'form', { class: 'hti-qz', novalidate: 'novalidate' } );
		var pct = Math.round( ( ( step + 1 ) / total ) * 100 );
		var nextLabel = step === total - 1 ? ui.see_result : ui.next;

		// --- Top bar: brand + exit, then step label + progress. ---
		var top = el( 'div', { class: 'hti-qz__top' } );
		var topin = el( 'div', { class: 'hti-qz__topin' } );
		var brand = el( 'div', { class: 'hti-qz__brand' } );
		var logo = el( 'span', { class: 'hti-qz__logo', 'aria-hidden': 'true' } );
		logo.innerHTML = QZ_LOGO;
		brand.appendChild( logo );
		brand.appendChild( el( 'span', { class: 'hti-qz__brandtext' }, 'HowToInvest' ) );
		topin.appendChild( brand );
		var exit = el( 'a', { class: 'hti-qz__exit', href: cfg.homeUrl || '/' }, ui.exit || 'Exit ✕' );
		topin.appendChild( exit );
		top.appendChild( topin );

		var prog = el( 'div', { class: 'hti-qz__prog' } );
		prog.appendChild( el( 'span', { class: 'hti-qz__steplabel' }, sprintf2( ui.step, step + 1, total ) ) );
		var bar = el( 'div', {
			class: 'hti-progress-bar',
			role: 'progressbar',
			'aria-valuemin': '1',
			'aria-valuemax': String( total ),
			'aria-valuenow': String( step + 1 ),
			'aria-label': sprintf2( ui.step, step + 1, total )
		} );
		bar.appendChild( el( 'span', { class: 'hti-progress-fill', style: 'width:' + pct + '%' } ) );
		prog.appendChild( bar );
		top.appendChild( prog );
		form.appendChild( top );

		// --- Body: question + why + options. ---
		var body = el( 'div', { class: 'hti-qz__body' } );
		var fieldset = el( 'fieldset', { class: 'hti-fieldset' } );
		var legend = el( 'legend', { class: 'hti-question', id: 'hti-q-' + q.id }, q.label );
		fieldset.appendChild( legend );

		var info = el( 'div', { class: 'hti-info' } );
		info.appendChild( el( 'span', { class: 'hti-info-label' }, 'ℹ ' + ui.why_we_ask ) );
		info.appendChild( el( 'p', null, q.info ) );
		fieldset.appendChild( info );

		var current = answers[ q.id ];
		var unknownNote = el( 'p', { class: 'hti-unknown-note', hidden: 'hidden' } );

		q.options.forEach( function ( opt, i ) {
			var id = 'hti-' + q.id + '-' + i;
			var wrap = el( 'label', { class: 'hti-option', for: id } );
			var input = el( 'input', { type: 'radio', name: q.id, id: id, value: opt.value } );
			if ( String( current ) === String( opt.value ) ) {
				input.checked = true;
			}
			input.addEventListener( 'change', function () {
				answers[ q.id ] = opt.value;
				save( 'hti_answers', answers );
				clearError();
				toggleUnknown( q, opt.value, unknownNote );
				setNextEnabled( true );
			} );
			wrap.appendChild( input );
			wrap.appendChild( el( 'span', { class: 'hti-option-label' }, opt.label ) );
			fieldset.appendChild( wrap );
		} );

		fieldset.appendChild( unknownNote );
		if ( current != null ) {
			toggleUnknown( q, current, unknownNote );
		}
		body.appendChild( fieldset );

		var error = el( 'p', { class: 'hti-error', role: 'alert', hidden: 'hidden' }, ui.choose_one );
		body.appendChild( error );
		body.appendChild( el( 'p', { class: 'hti-microcopy' }, ui.short_disclaimer ) );
		form.appendChild( body );

		// --- Fixed footer: previous + continue. ---
		var footer = el( 'div', { class: 'hti-qz__footer' } );
		var footin = el( 'div', { class: 'hti-qz__footin' } );
		if ( step > 0 ) {
			var prev = el( 'button', { type: 'button', class: 'hti-qz__prev' }, ui.previous );
			prev.addEventListener( 'click', function () { go( step - 1 ); } );
			footin.appendChild( prev );
		} else {
			footin.classList.add( 'hti-qz__footin--end' );
		}
		var next = el( 'button', { type: 'submit', class: 'hti-qz__next' }, nextLabel );
		footin.appendChild( next );
		footer.appendChild( footin );
		form.appendChild( footer );

		function setNextEnabled( on ) {
			if ( on ) { next.removeAttribute( 'disabled' ); } else { next.setAttribute( 'disabled', 'disabled' ); }
		}
		setNextEnabled( current != null );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( answers[ q.id ] == null ) {
				error.hidden = false;
				return;
			}
			track( 'quiz_step_complete', { step_index: step + 1, step_total: total } );
			if ( step === total - 1 ) {
				submit();
			} else {
				go( step + 1 );
			}
		} );

		mount.appendChild( form );
		legend.setAttribute( 'tabindex', '-1' );
		legend.focus();

		function clearError() {
			error.hidden = true;
		}
	}

	function toggleUnknown( q, value, node ) {
		if ( q.unknown_info && String( value ) === 'unknown' ) {
			node.textContent = q.unknown_info;
			node.hidden = false;
		} else {
			node.hidden = true;
		}
	}

	function go( to ) {
		step = to;
		save( 'hti_step', step );
		renderStep();
		mount.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function analyticsConsent() {
		try {
			var c = window.HTIConsent && window.HTIConsent.get();
			return !! ( c && c.analytics );
		} catch ( e ) {
			return false;
		}
	}

	function track( name, params ) {
		if ( window.HTITrack ) {
			window.HTITrack.event( name, params );
		}
	}

	function buildPayload() {
		var out = {};
		Object.keys( answers ).forEach( function ( k ) {
			if ( k === 'p6_emergency_fund' ) {
				out[ k ] = answers[ k ] === 'true' || answers[ k ] === true;
			} else {
				out[ k ] = answers[ k ];
			}
		} );
		return out;
	}

	function renderProcessing() {
		mount.innerHTML = '';
		var box = el( 'div', { class: 'hti-processing', role: 'status', 'aria-live': 'polite' } );
		box.appendChild( el( 'div', { class: 'hti-spinner', 'aria-hidden': 'true' } ) );
		box.appendChild( el( 'p', { class: 'hti-processing-main' }, ui.processing ) );
		var rotating = el( 'p', { class: 'hti-processing-sub' }, ui.processing_1 );
		box.appendChild( rotating );
		mount.appendChild( box );
		var lines = [ ui.processing_1, ui.processing_2, ui.processing_3 ].filter( Boolean );
		var i = 0;
		processingTimer = window.setInterval( function () {
			i = ( i + 1 ) % lines.length;
			rotating.textContent = lines[ i ];
		}, 1600 );
	}

	function stopProcessing() {
		if ( processingTimer ) {
			window.clearInterval( processingTimer );
			processingTimer = null;
		}
	}

	function renderError() {
		stopProcessing();
		mount.innerHTML = '';
		var box = el( 'div', { class: 'hti-error-box', role: 'alert' } );
		box.appendChild( el( 'p', null, ui.error ) );
		var retry = el( 'button', { type: 'button', class: 'hti-btn hti-btn-primary' }, ui.retry );
		retry.addEventListener( 'click', function () {
			go( total - 1 );
		} );
		box.appendChild( retry );
		mount.appendChild( box );
	}

	function submit() {
		renderProcessing();
		track( 'quiz_submit', { step_total: total } );

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( {
				locale: cfg.locale,
				answers: buildPayload(),
				consent: { analytics: analyticsConsent() }
			} )
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'http ' + r.status );
				}
				return r.json();
			} )
			.then( function ( res ) {
				try {
					window.sessionStorage.removeItem( 'hti_answers' );
					window.sessionStorage.removeItem( 'hti_step' );
				} catch ( e ) {}
				// Make the result reloadable/shareable via the URL.
				try {
					var u = new URL( window.location.href );
					u.searchParams.set( 'profile', res.profile_id );
					if ( res.session_token ) {
						u.searchParams.set( 'token', res.session_token );
					}
					window.history.replaceState( {}, '', u.toString() );
				} catch ( e ) {}
				setQuizFullscreen( false );
				window.HTIResult.render( mount, res, cfg.data );
			} )
			.catch( function () {
				renderError();
			} );
	}

	// Reload a saved result when the URL carries ?profile=… (and a token, if anonymous).
	function loadSaved( profileId, token ) {
		renderProcessing();
		fetch( cfg.resultUrl + '?profile_id=' + encodeURIComponent( profileId ) + '&session_token=' + encodeURIComponent( token || '' ), {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'http ' + r.status );
				}
				return r.json();
			} )
			.then( function ( res ) {
				stopProcessing();
				setQuizFullscreen( false );
				window.HTIResult.render( mount, res, cfg.data );
			} )
			.catch( function () {
				// Saved result unavailable (expired/forbidden) → start fresh.
				renderStep();
			} );
	}

	var urlParams = new URLSearchParams( window.location.search );
	if ( urlParams.get( 'profile' ) ) {
		loadSaved( urlParams.get( 'profile' ), urlParams.get( 'token' ) || '' );
	} else {
		track( 'quiz_start', { locale: cfg.locale } );
		renderStep();
	}
}() );
