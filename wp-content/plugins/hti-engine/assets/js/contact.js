/**
 * Contact form: posts the message to the REST API and shows inline status,
 * with no page reload. Progressive enhancement over the server-rendered form.
 */
( function () {
	'use strict';

	var cfg = window.HTI_CONTACT || {};
	var form = document.getElementById( 'hti-contact-form' );
	if ( ! form || ! cfg.restUrl ) {
		return;
	}

	var strings = cfg.strings || {};
	var status = form.querySelector( '.hti-contact__status' );
	var submit = form.querySelector( '.hti-contact__submit' );

	function track( name, params ) {
		if ( window.HTITrack ) {
			window.HTITrack.event( name, params );
		}
	}

	function setStatus( message, state ) {
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.className = 'hti-contact__status' + ( state ? ' is-' + state : '' );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var name = ( form.elements.name.value || '' ).trim();
		var email = ( form.elements.email.value || '' ).trim();
		var subject = form.elements.subject ? ( form.elements.subject.value || '' ).trim() : '';
		var message = ( form.elements.message.value || '' ).trim();
		var consent = form.elements.consent ? form.elements.consent.checked : false;
		var honeypot = form.elements.hti_hp ? form.elements.hti_hp.value : '';

		if ( ! name || ! email || ! subject || ! message ) {
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
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( {
				name: name,
				email: email,
				subject: subject,
				message: message,
				consent: consent,
				hti_hp: honeypot,
				locale: cfg.locale || 'en',
			} ),
		} )
			.then( function ( res ) {
				if ( res.ok ) {
					return res.json().then( function () {
						form.reset();
						setStatus( strings.sent, 'success' );
						track( 'contact_submit', { locale: cfg.locale || 'en' } );
					} );
				}
				if ( res.status === 429 ) {
					setStatus( strings.rate, 'error' );
					return null;
				}
				if ( res.status === 422 ) {
					setStatus( strings.invalid, 'error' );
					return null;
				}
				setStatus( strings.error, 'error' );
				return null;
			} )
			.catch( function () {
				setStatus( strings.error, 'error' );
			} )
			.finally( function () {
				submit.disabled = false;
			} );
	} );
} )();
