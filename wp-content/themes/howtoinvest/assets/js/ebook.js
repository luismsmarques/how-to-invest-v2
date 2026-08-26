/**
 * Ebook landing — email gate.
 *
 * Posts the email + consent to /subscribe with an "ebook-page" source. The
 * server starts the newsletter double opt-in and emails the ebook PDF right
 * away. Progressive enhancement: without JS the form simply does nothing.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var cfg = window.HTI_EBOOK;
	var form = document.querySelector( '.hti-ebp__form' );
	if ( ! cfg || ! form ) {
		return;
	}
	var S = cfg.strings || {};
	var status = form.querySelector( '.hti-ebp__status' );

	function set( msg, kind ) {
		if ( ! status ) { return; }
		status.textContent = msg || '';
		status.className = 'hti-ebp__status' + ( kind ? ' is-' + kind : '' );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var email = ( form.querySelector( '.hti-ebp__email' ).value || '' ).trim();
		var consent = form.querySelector( '.hti-ebp__cons' ).checked;
		var hp = form.querySelector( '.hti-ebp__hp' ).value;

		if ( ! email || email.indexOf( '@' ) < 1 ) { set( S.err, 'err' ); return; }
		if ( ! consent ) { set( S.consent, 'err' ); return; }
		set( S.sending, null );

		fetch( cfg.subscribeUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( {
				email: email,
				consent: true,
				locale: cfg.locale,
				source: 'ebook-page',
				hti_hp: hp
			} )
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'http' ); }
			return r.json();
		} ).then( function () {
			var row = form.querySelector( '.hti-ebp__row' );
			var cons = form.querySelector( '.hti-ebp__consent' );
			if ( row ) { row.style.display = 'none'; }
			if ( cons ) { cons.style.display = 'none'; }
			set( S.ok, 'ok' );
			if ( window.HTITrack ) { window.HTITrack.event( 'ebook_lead', { source: 'ebook-page', status: 'submitted' } ); }
		} ).catch( function () {
			set( S.err, 'err' );
		} );
	} );
}() );
