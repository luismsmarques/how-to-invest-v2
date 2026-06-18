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

	function renderStep() {
		var q = questions[ step ];
		mount.innerHTML = '';

		var form = el( 'form', { class: 'hti-step', novalidate: 'novalidate' } );

		// Progress.
		var progress = el( 'div', { class: 'hti-progress' } );
		var pct = Math.round( ( ( step + 1 ) / total ) * 100 );
		var bar = el( 'div', {
			class: 'hti-progress-bar',
			role: 'progressbar',
			'aria-valuemin': '1',
			'aria-valuemax': String( total ),
			'aria-valuenow': String( step + 1 ),
			'aria-label': sprintf2( ui.step, step + 1, total )
		} );
		bar.appendChild( el( 'span', { class: 'hti-progress-fill', style: 'width:' + pct + '%' } ) );
		progress.appendChild( bar );
		progress.appendChild( el( 'p', { class: 'hti-step-count' }, sprintf2( ui.step, step + 1, total ) ) );
		form.appendChild( progress );

		// Question as a fieldset/legend.
		var fieldset = el( 'fieldset', { class: 'hti-fieldset' } );
		var legendId = 'hti-q-' + q.id;
		var legend = el( 'legend', { class: 'hti-question', id: legendId }, q.label );
		fieldset.appendChild( legend );

		// "Why we ask" micro-explanation.
		var info = el( 'div', { class: 'hti-info' } );
		info.appendChild( el( 'span', { class: 'hti-info-label' }, 'ℹ ' + ui.why_we_ask ) );
		info.appendChild( el( 'p', null, q.info ) );
		fieldset.appendChild( info );

		// Options.
		var current = answers[ q.id ];
		var unknownNote = el( 'p', { class: 'hti-unknown-note', hidden: 'hidden' } );

		q.options.forEach( function ( opt, i ) {
			var id = 'hti-' + q.id + '-' + i;
			var wrap = el( 'label', { class: 'hti-option', for: id } );
			var input = el( 'input', {
				type: 'radio',
				name: q.id,
				id: id,
				value: opt.value
			} );
			if ( String( current ) === String( opt.value ) ) {
				input.checked = true;
			}
			input.addEventListener( 'change', function () {
				answers[ q.id ] = opt.value;
				save( 'hti_answers', answers );
				clearError();
				toggleUnknown( q, opt.value, unknownNote );
			} );
			wrap.appendChild( input );
			wrap.appendChild( el( 'span', { class: 'hti-option-label' }, opt.label ) );
			fieldset.appendChild( wrap );
		} );

		fieldset.appendChild( unknownNote );
		if ( current != null ) {
			toggleUnknown( q, current, unknownNote );
		}
		form.appendChild( fieldset );

		// Error slot.
		var error = el( 'p', { class: 'hti-error', role: 'alert', hidden: 'hidden' }, ui.choose_one );
		form.appendChild( error );

		// Navigation.
		var nav = el( 'div', { class: 'hti-nav' } );
		if ( step > 0 ) {
			var prev = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost' }, ui.previous );
			prev.addEventListener( 'click', function () {
				go( step - 1 );
			} );
			nav.appendChild( prev );
		}
		var nextLabel = step === total - 1 ? ui.see_result : ui.next;
		var next = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-primary' }, nextLabel );
		nav.appendChild( next );
		form.appendChild( nav );

		// Short disclaimer.
		form.appendChild( el( 'p', { class: 'hti-microcopy' }, ui.short_disclaimer ) );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( answers[ q.id ] == null ) {
				error.hidden = false;
				return;
			}
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
		setTimeout( function () {
			rotating.textContent = ui.processing_2;
		}, 1500 );
	}

	function renderError() {
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
		renderStep();
	}
}() );
