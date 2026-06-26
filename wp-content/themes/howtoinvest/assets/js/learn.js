/**
 * Learn hub progress + ebook lead-magnet.
 *
 * Progress is anonymous and client-side only (localStorage, RGPD-safe): a
 * chapter counts as "done" once its Learn guide has been visited. On the hub we
 * hydrate the path states + progress bar from that; on a single guide we record
 * the visit. The ebook form posts to the newsletter subscribe endpoint.
 */
( function () {
	'use strict';

	var KEY = 'hti_learn_done';

	function load() {
		try { return JSON.parse( window.localStorage.getItem( KEY ) || '[]' ) || []; }
		catch ( e ) { return []; }
	}
	function save( arr ) {
		try { window.localStorage.setItem( KEY, JSON.stringify( arr ) ); } catch ( e ) {}
	}

	/* ---- record a visit on a single Learn guide ---- */
	if ( window.HTI_LEARN_REC && window.HTI_LEARN_REC.slug ) {
		var seen = load();
		if ( seen.indexOf( window.HTI_LEARN_REC.slug ) < 0 ) {
			seen.push( window.HTI_LEARN_REC.slug );
			save( seen );
		}
	}

	/* ---- hydrate the hub ---- */
	var root = document.querySelector( '.hti-lh' );
	if ( root && window.HTI_LEARN ) {
		hydrate( root );
		wireEbook( root );
	}

	function hydrate( root ) {
		var doneArr = load();
		var doneSet = {};
		doneArr.forEach( function ( s ) { doneSet[ s ] = 1; } );

		var chaps = Array.prototype.slice.call( root.querySelectorAll( '.hti-lh-chap' ) );
		var total = chaps.length;
		var doneCount = 0;

		chaps.forEach( function ( ch ) {
			var slug = ch.getAttribute( 'data-slug' );
			if ( slug && doneSet[ slug ] ) {
				ch.setAttribute( 'data-state', 'done' );
				doneCount++;
			} else {
				ch.setAttribute( 'data-state', 'open' );
			}
		} );

		// Current = first not-done chapter that is published; else first not-done.
		var current = null;
		for ( var i = 0; i < chaps.length; i++ ) {
			if ( chaps[ i ].getAttribute( 'data-state' ) !== 'done' && chaps[ i ].getAttribute( 'data-url' ) ) {
				current = chaps[ i ];
				break;
			}
		}
		if ( ! current ) {
			for ( i = 0; i < chaps.length; i++ ) {
				if ( chaps[ i ].getAttribute( 'data-state' ) !== 'done' ) { current = chaps[ i ]; break; }
			}
		}
		if ( current ) { current.setAttribute( 'data-state', 'current' ); }

		// Module states from their chapters.
		Array.prototype.forEach.call( root.querySelectorAll( '.hti-lh-mod' ), function ( m ) {
			var cs = m.querySelectorAll( '.hti-lh-chap' );
			var all = cs.length > 0, anyCurrent = false;
			Array.prototype.forEach.call( cs, function ( c ) {
				var st = c.getAttribute( 'data-state' );
				if ( st !== 'done' ) { all = false; }
				if ( st === 'current' ) { anyCurrent = true; }
			} );
			m.setAttribute( 'data-state', all ? 'done' : ( anyCurrent ? 'current' : 'open' ) );
		} );

		// Progress bar + counter.
		var pct = total ? Math.round( doneCount / total * 100 ) : 0;
		var fill = root.querySelector( '.hti-lh-prog__fill' );
		if ( fill ) { fill.style.width = pct + '%'; }
		var dn = root.querySelector( '.hti-lh-prog-done' );
		if ( dn ) { dn.textContent = String( doneCount ); }

		// "Continue the path" → current chapter (else its server fallback).
		var cont = root.querySelector( '.hti-lh-continue' );
		if ( cont && current && current.getAttribute( 'data-url' ) ) {
			cont.setAttribute( 'href', current.getAttribute( 'data-url' ) );
		}
	}

	/* ---- ebook lead-magnet ---- */
	function wireEbook( root ) {
		var form = root.querySelector( '.hti-lh-ebook__form' );
		if ( ! form ) { return; }
		var S = window.HTI_LEARN.strings || {};
		var status = form.querySelector( '.hti-lh-ebook__status' );

		function set( msg, kind ) {
			if ( ! status ) { return; }
			status.textContent = msg || '';
			status.className = 'hti-lh-ebook__status' + ( kind ? ' is-' + kind : '' );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var email = ( form.querySelector( '.hti-lh-ebook__email' ).value || '' ).trim();
			var consent = form.querySelector( '.hti-lh-ebook__cons' ).checked;
			var hp = form.querySelector( '.hti-lh-ebook__hp' ).value;

			if ( ! email || email.indexOf( '@' ) < 0 ) { set( S.err, 'err' ); return; }
			if ( ! consent ) { set( S.consent, 'err' ); return; }
			set( '…', null );

			fetch( window.HTI_LEARN.subscribeUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.HTI_LEARN.nonce },
				body: JSON.stringify( {
					email: email,
					consent: true,
					locale: window.HTI_LEARN.locale,
					source: 'ebook-learn',
					hti_hp: hp
				} )
			} ).then( function ( r ) {
				if ( ! r.ok ) { throw new Error( 'http' ); }
				return r.json();
			} ).then( function () {
				var row = form.querySelector( '.hti-lh-ebook__row' );
				var cons = form.querySelector( '.hti-lh-ebook__consent' );
				if ( row ) { row.style.display = 'none'; }
				if ( cons ) { cons.style.display = 'none'; }
				set( S.ok, 'ok' );
			} ).catch( function () {
				set( S.err, 'err' );
			} );
		} );
	}
}() );
