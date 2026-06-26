/**
 * Learn course progress + end-of-chapter quiz + ebook lead-magnet.
 *
 * Progress is two sets of chapter slugs: "done" (a guide was visited) and
 * "passed" (its quiz was answered all-correct). Guests keep both in
 * localStorage (anonymous, RGPD-safe); signed-in accounts also sync to the
 * server — on load we merge the browser sets with the server sets (union) and
 * push the union back, so progress follows them across devices.
 */
( function () {
	'use strict';

	var KEY_DONE = 'hti_learn_done';
	var KEY_PASS = 'hti_learn_passed';
	var cfg = window.HTI_LEARN_CFG || {};

	function load( key ) {
		try { return JSON.parse( window.localStorage.getItem( key ) || '[]' ) || []; }
		catch ( e ) { return []; }
	}
	function save( key, arr ) {
		try { window.localStorage.setItem( key, JSON.stringify( arr ) ); } catch ( e ) {}
	}
	function union( a, b ) {
		var seen = {}, out = [];
		a.concat( b ).forEach( function ( s ) { if ( s && ! seen[ s ] ) { seen[ s ] = 1; out.push( s ); } } );
		return out;
	}
	function addLocal( key, slug ) {
		var arr = load( key );
		if ( arr.indexOf( slug ) < 0 ) { arr.push( slug ); save( key, arr ); }
	}

	/* ---- account sync (signed-in only) ---- */
	function postProgress( patch ) {
		if ( ! cfg.isLoggedIn || ! cfg.progressUrl ) { return Promise.resolve( null ); }
		return fetch( cfg.progressUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( patch )
		} ).then( function ( r ) { return r.ok ? r.json() : null; } ).catch( function () { return null; } );
	}

	function syncThenRun( run ) {
		if ( ! cfg.isLoggedIn || ! cfg.progressUrl ) { run(); return; }
		fetch( cfg.progressUrl, { headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( data ) {
				var done = union( load( KEY_DONE ), ( data && data.done ) || [] );
				var pass = union( load( KEY_PASS ), ( data && data.passed ) || [] );
				save( KEY_DONE, done );
				save( KEY_PASS, pass );
				return postProgress( { done: done, passed: pass } );
			} )
			.catch( function () {} )
			.then( function () { run(); } );
	}

	/* ---- main ---- */
	function run() {
		if ( window.HTI_LEARN_REC && window.HTI_LEARN_REC.slug ) {
			var slug = window.HTI_LEARN_REC.slug;
			if ( load( KEY_DONE ).indexOf( slug ) < 0 ) {
				addLocal( KEY_DONE, slug );
				postProgress( { done: [ slug ] } );
			}
		}

		var root = document.querySelector( '.hti-lh' );
		if ( root && window.HTI_LEARN ) { hydrate( root ); wireEbook( root ); }

		var hp = document.querySelector( '.hti-hp-path' );
		if ( hp ) { hydrateHome( hp ); }

		hydrateNav();
		wireQuiz();
	}

	syncThenRun( run );

	/* ---- hub path ---- */
	function hydrate( root ) {
		var doneSet = {};
		load( KEY_DONE ).forEach( function ( s ) { doneSet[ s ] = 1; } );

		var chaps = Array.prototype.slice.call( root.querySelectorAll( '.hti-lh-chap' ) );
		var total = chaps.length, doneCount = 0;

		chaps.forEach( function ( ch ) {
			var slug = ch.getAttribute( 'data-slug' );
			if ( slug && doneSet[ slug ] ) { ch.setAttribute( 'data-state', 'done' ); doneCount++; }
			else { ch.setAttribute( 'data-state', 'open' ); }
		} );

		var current = null;
		for ( var i = 0; i < chaps.length; i++ ) {
			if ( chaps[ i ].getAttribute( 'data-state' ) !== 'done' && chaps[ i ].getAttribute( 'data-url' ) ) { current = chaps[ i ]; break; }
		}
		if ( ! current ) {
			for ( i = 0; i < chaps.length; i++ ) {
				if ( chaps[ i ].getAttribute( 'data-state' ) !== 'done' ) { current = chaps[ i ]; break; }
			}
		}
		if ( current ) { current.setAttribute( 'data-state', 'current' ); }

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

		var pct = total ? Math.round( doneCount / total * 100 ) : 0;
		var fill = root.querySelector( '.hti-lh-prog__fill' );
		if ( fill ) { fill.style.width = pct + '%'; }
		var dn = root.querySelector( '.hti-lh-prog-done' );
		if ( dn ) { dn.textContent = String( doneCount ); }

		var cont = root.querySelector( '.hti-lh-continue' );
		if ( cont && current && current.getAttribute( 'data-url' ) ) {
			cont.setAttribute( 'href', current.getAttribute( 'data-url' ) );
		}
	}

	/* ---- homepage path block ---- */
	function hydrateHome( hp ) {
		var doneSet = {};
		load( KEY_DONE ).forEach( function ( s ) { doneSet[ s ] = 1; } );

		var data = [];
		var node = hp.querySelector( '.hti-hp-data' );
		try { data = JSON.parse( ( node && node.textContent ) || '[]' ) || []; } catch ( e ) {}

		var total = data.length, doneCount = 0, currentIdx = -1, continueUrl = '';
		data.forEach( function ( c, i ) {
			if ( doneSet[ c.s ] ) { doneCount++; }
			else {
				if ( currentIdx < 0 ) { currentIdx = i; }
				if ( ! continueUrl && c.u ) { continueUrl = c.u; }
			}
		} );

		var firstCurrent = true;
		Array.prototype.forEach.call( hp.querySelectorAll( '.hti-hp-mod' ), function ( m ) {
			var slugs = ( m.getAttribute( 'data-slugs' ) || '' ).split( ',' ).filter( Boolean );
			var allDone = slugs.length > 0 && slugs.every( function ( s ) { return doneSet[ s ]; } );
			if ( allDone ) { m.setAttribute( 'data-state', 'done' ); }
			else if ( firstCurrent ) { m.setAttribute( 'data-state', 'current' ); firstCurrent = false; }
			else { m.setAttribute( 'data-state', 'open' ); }
		} );

		var pct = total ? Math.round( doneCount / total * 100 ) : 0;
		var fill = hp.querySelector( '.hti-hp-path__fill' );
		if ( fill ) { fill.style.width = pct + '%'; }
		var dn = hp.querySelector( '.hti-hp-prog-done' );
		if ( dn ) { dn.textContent = String( doneCount ); }

		var cur = currentIdx >= 0 ? data[ currentIdx ] : null;
		var curRow = hp.querySelector( '.hti-hp-current' );
		var curT = hp.querySelector( '.hti-hp-current__t' );
		if ( curT && cur ) { curT.textContent = cur.t || ''; }
		if ( curRow && ! cur ) { curRow.className += ' is-empty'; }

		var cont = hp.querySelector( '.hti-hp-continue' );
		if ( cont ) {
			cont.setAttribute( 'href', continueUrl || hp.getAttribute( 'data-first' ) || cont.getAttribute( 'data-fallback' ) || '#' );
		}
	}

	/* ---- single-guide course nav: module progress bar ---- */
	function hydrateNav() {
		var el = document.querySelector( '.hti-ln-modprog' );
		if ( ! el ) { return; }
		var doneSet = {};
		load( KEY_DONE ).forEach( function ( s ) { doneSet[ s ] = 1; } );
		var slugs = ( el.getAttribute( 'data-slugs' ) || '' ).split( ',' ).filter( Boolean );
		var d = slugs.filter( function ( s ) { return doneSet[ s ]; } ).length;
		var pct = slugs.length ? Math.round( d / slugs.length * 100 ) : 0;
		var fill = el.querySelector( '.hti-ln-modprog__fill' );
		if ( fill ) { fill.style.width = pct + '%'; }
		var dn = el.querySelector( '.hti-ln-modprog-done' );
		if ( dn ) { dn.textContent = String( d ); }
	}

	/* ---- end-of-chapter quiz ---- */
	function wireQuiz() {
		var quiz = document.querySelector( '.hti-quiz' );
		if ( ! quiz ) { return; }
		var QS = window.HTI_LEARN_QUIZ || {};
		var slug = quiz.getAttribute( 'data-slug' );
		var live = quiz.querySelector( '.hti-quiz__live' );
		var doneEl = quiz.querySelector( '.hti-quiz__done' );

		function showDone() {
			if ( doneEl ) { doneEl.hidden = false; }
			if ( live ) { live.hidden = true; }
		}

		if ( slug && load( KEY_PASS ).indexOf( slug ) >= 0 ) { showDone(); return; }

		var form = quiz.querySelector( '.hti-quiz__form' );
		var btn = quiz.querySelector( '.hti-quiz__check' );
		var result = quiz.querySelector( '.hti-quiz__result' );
		if ( ! form ) { return; }

		function setResult( msg, kind ) {
			if ( ! result ) { return; }
			result.textContent = msg || '';
			result.className = 'hti-quiz__result' + ( kind ? ' is-' + kind : '' );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var qs = form.querySelectorAll( '.hti-quiz__q' );
			var total = qs.length, correct = 0, answeredAll = true;

			Array.prototype.forEach.call( qs, function ( fs ) {
				Array.prototype.forEach.call( fs.querySelectorAll( '.hti-quiz__opt' ), function ( l ) {
					l.classList.remove( 'is-correct', 'is-wrong' );
				} );
				var checked = fs.querySelector( 'input:checked' );
				if ( ! checked ) { answeredAll = false; return; }
				var isC = checked.getAttribute( 'data-correct' ) === '1';
				if ( isC ) { correct++; }
				checked.closest( '.hti-quiz__opt' ).classList.add( isC ? 'is-correct' : 'is-wrong' );
				if ( ! isC ) {
					var corr = fs.querySelector( 'input[data-correct="1"]' );
					if ( corr ) { corr.closest( '.hti-quiz__opt' ).classList.add( 'is-correct' ); }
				}
			} );

			if ( ! answeredAll ) { setResult( QS.pick, 'err' ); return; }

			if ( correct === total ) {
				setResult( '', null );
				if ( slug ) { addLocal( KEY_PASS, slug ); addLocal( KEY_DONE, slug ); postProgress( { done: [ slug ], passed: [ slug ] } ); }
				showDone();
			} else {
				setResult( ( QS.fail || '%1$d / %2$d' ).replace( '%1$d', correct ).replace( '%2$d', total ), 'err' );
				if ( btn && QS.retry ) { btn.textContent = QS.retry; }
			}
		} );
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
