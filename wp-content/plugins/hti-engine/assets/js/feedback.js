/**
 * Feedback survey enhancement: star/NPS interaction + AJAX submit.
 *
 * Progressive enhancement over the server-rendered [hti_feedback] form. The
 * radios remain the source of truth (accessible, keyboard-operable); this only
 * adds visual fill and posts the answers to the REST endpoint without a reload.
 */
( function () {
	'use strict';

	var cfg = window.HTI_FEEDBACK || {};
	var form = document.getElementById( 'hti-feedback-form' );
	if ( ! form || ! cfg.restUrl ) {
		return;
	}

	var S = cfg.strings || {};

	/* ---------- star rating ---------- */
	var starGroups = form.querySelectorAll( '.hti-fb-stars' );
	Array.prototype.forEach.call( starGroups, function ( group ) {
		var stars = group.querySelectorAll( '.hti-fb-star' );

		function paint( upto ) {
			Array.prototype.forEach.call( stars, function ( star, i ) {
				star.classList.toggle( 'is-filled', i <= upto );
			} );
		}
		function selected() {
			var checked = group.querySelector( 'input:checked' );
			return checked ? parseInt( checked.value, 10 ) - 1 : -1;
		}

		Array.prototype.forEach.call( stars, function ( star, i ) {
			var input = star.querySelector( 'input' );
			star.addEventListener( 'mouseenter', function () { paint( i ); } );
			input.addEventListener( 'focus', function () { paint( i ); } );
			input.addEventListener( 'change', function () {
				paint( i );
				if ( S.star ) { input.setAttribute( 'aria-label', S.star.replace( '%d', String( i + 1 ) ) ); }
			} );
		} );
		group.addEventListener( 'mouseleave', function () { paint( selected() ); } );
	} );

	/* ---------- NPS buttons ---------- */
	var nps = form.querySelector( '.hti-fb-nps' );
	if ( nps ) {
		var btns = nps.querySelectorAll( '.hti-fb-nps__btn' );
		Array.prototype.forEach.call( btns, function ( btn ) {
			var input = btn.querySelector( 'input' );
			input.addEventListener( 'change', function () {
				Array.prototype.forEach.call( btns, function ( b ) {
					b.classList.toggle( 'is-selected', b.querySelector( 'input' ).checked );
				} );
			} );
		} );
	}

	/* ---------- archetype context (anonymous, non-PII) ---------- */
	try {
		var arche = window.localStorage.getItem( 'hti_last_archetype' );
		if ( arche ) {
			var hidden = form.querySelector( 'input[name="archetype"]' );
			if ( hidden ) { hidden.value = arche; }
		}
	} catch ( e ) {}

	/* ---------- submit ---------- */
	var status = form.querySelector( '.hti-fb__status' );
	var submit = form.querySelector( '.hti-fb__submit' );

	function setStatus( msg, kind ) {
		if ( ! status ) { return; }
		status.textContent = msg || '';
		status.className = 'hti-fb__status' + ( kind ? ' is-' + kind : '' );
	}

	function val( name ) {
		var f = form.elements[ name ];
		if ( ! f ) { return ''; }
		if ( f.length && typeof f.value === 'undefined' ) {
			for ( var i = 0; i < f.length; i++ ) { if ( f[ i ].checked ) { return f[ i ].value; } }
			return '';
		}
		return f.value || '';
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var payload = {
			satisfaction: val( 'satisfaction' ),
			ease: val( 'ease' ),
			helped: val( 'helped' ),
			portfolio: val( 'portfolio' ),
			trust: val( 'trust' ),
			nps: val( 'nps' ),
			most_valuable: val( 'most_valuable' ),
			improve: val( 'improve' ),
			comments: val( 'comments' ),
			archetype: val( 'archetype' ),
			locale: cfg.locale || 'en',
			hti_hp: val( 'hti_hp' )
		};

		// Client-side required check (mirrors the server).
		if ( ! payload.satisfaction || ! payload.ease || ! payload.helped ||
			! payload.portfolio || payload.nps === '' || ! payload.most_valuable.trim() ) {
			setStatus( S.invalid, 'error' );
			return;
		}

		if ( submit ) { submit.disabled = true; }
		setStatus( S.sending, null );

		fetch( cfg.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( payload )
		} ).then( function ( res ) {
			if ( res.status === 429 ) { throw new Error( 'rate' ); }
			if ( ! res.ok ) { throw new Error( 'error' ); }
			return res.json();
		} ).then( function () {
			form.classList.add( 'is-done' );
			setStatus( S.sent, 'ok' );
		} ).catch( function ( err ) {
			if ( submit ) { submit.disabled = false; }
			setStatus( err && err.message === 'rate' ? S.rate : S.error, 'error' );
		} );
	} );
}() );
