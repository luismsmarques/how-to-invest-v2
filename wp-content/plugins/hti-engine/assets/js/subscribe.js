/**
 * Newsletter subscribe form: posts to the REST endpoint and shows inline
 * status. The double opt-in email does the rest.
 *
 * Every [hti_subscribe] instance on the page is wired independently. It used to
 * bind a single getElementById( 'hti-subscribe-form' ), which meant a second
 * form on the same page was inert — and that is exactly what a calculator page
 * needs now that the tools carry their own opt-in.
 */
( function () {
	'use strict';

	var cfg = window.HTI_SUBSCRIBE || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	var strings = cfg.strings || {};

	function track( name, params ) {
		if ( window.HTITrack ) {
			window.HTITrack.event( name, params );
		}
	}

	function init( form ) {
		var status = form.querySelector( '.hti-subscribe__status' );

		// Match on the type, not a class: the digest variant styles its button
		// as .hti-digest__submit, so a class-only lookup returned null and the
		// handler threw before ever reaching the fetch.
		var submit = form.querySelector( 'button[type="submit"], input[type="submit"]' );

		// Where this opt-in came from ('' when the shortcode set no source).
		// The REST endpoint has always accepted it — the form just never sent
		// one, so no opt-in outside the ebook gate was ever attributed, and the
		// hti_lead_magnet filter could not fire.
		var source = form.getAttribute( 'data-source' ) || '';

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

			if ( submit ) {
				submit.disabled = true;
			}
			setStatus( strings.sending, 'pending' );

			var body = {
				email: email,
				consent: consent,
				hti_hp: honeypot,
				locale: cfg.locale || 'en'
			};
			if ( source ) {
				body.source = source;
			}

			fetch( cfg.restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: JSON.stringify( body )
			} )
				.then( function ( r ) {
					if ( r.ok ) {
						form.reset();
						setStatus( strings.sent, 'success' );
						track( 'newsletter_subscribe_submit', {
							source: source || 'newsletter',
							status: 'submitted',
							locale: cfg.locale || 'en'
						} );
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
					if ( submit ) {
						submit.disabled = false;
					}
				} );
		} );
	}

	var forms = document.querySelectorAll( '[data-hti-subscribe]' );
	Array.prototype.forEach.call( forms, init );
} )();
