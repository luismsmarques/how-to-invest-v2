/**
 * Newsletter subscribe form: posts to the REST endpoint and shows inline
 * status. The double opt-in email does the rest.
 */
( function () {
	'use strict';

	var cfg = window.HTI_SUBSCRIBE || {};
	var form = document.getElementById( 'hti-subscribe-form' );
	if ( ! form || ! cfg.restUrl ) {
		return;
	}

	var strings = cfg.strings || {};
	var status = form.querySelector( '.hti-subscribe__status' );
	var submit = form.querySelector( '.hti-subscribe__submit' );

	function setStatus( msg, state ) {
		if ( status ) {
			status.textContent = msg || '';
			status.className = 'hti-subscribe__status' + ( state ? ' is-' + state : '' );
		}
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var email = ( form.elements.email.value || '' ).trim();
		var consent = form.elements.consent ? form.elements.consent.checked : false;
		var honeypot = form.elements.hti_hp ? form.elements.hti_hp.value : '';

		if ( ! email || email.indexOf( '@' ) < 1 ) {
			setStatus( strings.invalid, 'error' );
			return;
		}
		if ( ! consent ) {
			setStatus( strings.consent, 'error' );
			return;
		}

		submit.disabled = true;
		setStatus( strings.sending, 'pending' );

		fetch( cfg.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify( {
				email: email,
				consent: consent,
				hti_hp: honeypot,
				locale: cfg.locale || 'en'
			} )
		} )
			.then( function ( r ) {
				if ( r.ok ) {
					form.reset();
					setStatus( strings.sent, 'success' );
					return;
				}
				if ( r.status === 429 ) {
					setStatus( strings.rate, 'error' );
				} else if ( r.status === 422 ) {
					setStatus( strings.invalid, 'error' );
				} else {
					setStatus( strings.error, 'error' );
				}
			} )
			.catch( function () {
				setStatus( strings.error, 'error' );
			} )
			.finally( function () {
				submit.disabled = false;
			} );
	} );
} )();
