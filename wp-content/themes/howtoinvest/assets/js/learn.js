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

		hydrateBadges( root, doneSet, doneCount );

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

	/* ---- achievements: module + course badges ---- */
	// A chapter is "mastered" when its quiz is passed; chapters without a quiz
	// count as mastered once visited. A module badge is earned when every chapter
	// in it is mastered; the course badge when every module is earned.
	function hydrateBadges( root, doneSet, doneCount ) {
		var ach = root.querySelector( '.hti-lh-ach' );
		if ( ! ach ) { return; }

		var passedSet = {};
		load( KEY_PASS ).forEach( function ( s ) { passedSet[ s ] = 1; } );

		var modState = {}, earned = 0;
		Array.prototype.forEach.call( root.querySelectorAll( '.hti-lh-mod' ), function ( m ) {
			var num = m.getAttribute( 'data-mod' );
			var cs = m.querySelectorAll( '.hti-lh-chap' );
			var totalC = cs.length, masteredC = 0;
			Array.prototype.forEach.call( cs, function ( c ) {
				var slug = c.getAttribute( 'data-slug' );
				var hasQuiz = c.getAttribute( 'data-quiz' ) === '1';
				if ( slug && ( passedSet[ slug ] || ( ! hasQuiz && doneSet[ slug ] ) ) ) { masteredC++; }
			} );
			var st = ( totalC > 0 && masteredC === totalC ) ? 'earned' : ( masteredC > 0 ? 'progress' : 'locked' );
			if ( 'earned' === st ) { earned++; }
			if ( num ) { modState[ num ] = st; }
		} );

		var L = {
			earned: ach.getAttribute( 'data-l-earned' ) || '',
			progress: ach.getAttribute( 'data-l-progress' ) || '',
			locked: ach.getAttribute( 'data-l-locked' ) || ''
		};
		Array.prototype.forEach.call( ach.querySelectorAll( '.hti-lh-ach__mod' ), function ( el ) {
			var st = modState[ el.getAttribute( 'data-mod' ) ] || 'locked';
			el.setAttribute( 'data-state', st );
			var lbl = el.querySelector( '.hti-lh-ach__mstate' );
			if ( lbl ) { lbl.textContent = L[ st ] || ''; }
		} );

		var totalMods = parseInt( ach.getAttribute( 'data-total' ), 10 ) || 0;
		var course = ach.querySelector( '.hti-lh-ach__course' );
		if ( course ) {
			course.setAttribute( 'data-state', ( earned > 0 && earned === totalMods ) ? 'earned' : ( earned > 0 ? 'progress' : 'locked' ) );
		}
		var en = ach.querySelector( '.hti-lh-ach-earned' );
		if ( en ) { en.textContent = String( earned ); }

		var nudge = ach.querySelector( '.hti-lh-ach__nudge' );
		var isGuest = ! ( window.HTI_LEARN_CFG && window.HTI_LEARN_CFG.isLoggedIn );
		var hasProgress = doneCount > 0 || Object.keys( passedSet ).length > 0;
		if ( nudge && isGuest && hasProgress ) { nudge.hidden = false; }
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

	/* ---- end-of-chapter quiz (state machine, mirrors the Quiz Card design) ---- */
	function wireQuiz() {
		var quiz = document.querySelector( '.hti-quiz' );
		if ( ! quiz ) { return; }
		var slug = quiz.getAttribute( 'data-slug' );

		// Labels come from the server-rendered data-* attributes (always present),
		// falling back to the localized global. This keeps the UI text correct
		// regardless of when wp_localize_script runs relative to the block.
		var G = window.HTI_LEARN_QUIZ || {};
		function lbl( key, fb ) { var v = quiz.getAttribute( 'data-l-' + key ); return v != null ? v : ( fb || '' ); }
		var Q = {
			check: lbl( 'check', G.check ),
			retry: lbl( 'retry', G.retry ),
			partial: lbl( 'partial', G.partial ),
			tagCorrect: lbl( 'tagcorrect', G.tagCorrect ),
			tagYour: lbl( 'tagyour', G.tagYour ),
			passedSub: lbl( 'passedsub', G.passedSub ),
			returningSub: lbl( 'returningsub', G.returningSub ),
			review: lbl( 'review', G.review ),
			returnReview: lbl( 'returnreview', G.returnReview )
		};

		var quizview = quiz.querySelector( '.hti-quiz__quizview' );
		var complete = quiz.querySelector( '.hti-quiz__complete' );
		var qEls = Array.prototype.slice.call( quiz.querySelectorAll( '.hti-quiz__q' ) );
		var opts = Array.prototype.slice.call( quiz.querySelectorAll( '.hti-quiz__opt' ) );
		var primary = quiz.querySelector( '.hti-quiz__primary' );
		var review = quiz.querySelector( '.hti-quiz__review' );
		var alertEl = quiz.querySelector( '.hti-quiz__alert' );
		var resultEl = quiz.querySelector( '.hti-quiz__result' );
		var resultIc = quiz.querySelector( '.hti-quiz__result-ic' );
		var resultTx = quiz.querySelector( '.hti-quiz__result-txt' );
		var csub = quiz.querySelector( '.hti-quiz__csub' );

		var n = qEls.length;
		var state = { sel: {}, checked: false, completed: false, returning: false, emptyErr: false };

		var BAD_IC = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16h.01"/></svg>';

		// Returning reader who already passed → straight to the complete view.
		if ( slug && load( KEY_PASS ).indexOf( slug ) >= 0 ) {
			state.completed = true;
			state.returning = true;
		}

		function correctCount() {
			var c = 0;
			for ( var i = 0; i < n; i++ ) {
				var sel = state.sel[ i ];
				if ( sel != null ) {
					var el = quiz.querySelector( '.hti-quiz__opt[data-q="' + i + '"][data-o="' + sel + '"]' );
					if ( el && el.getAttribute( 'data-correct' ) === '1' ) { c++; }
				}
			}
			return c;
		}

		function render() {
			if ( complete ) { complete.hidden = ! state.completed; complete.classList.toggle( 'is-returning', state.returning ); }
			if ( quizview ) { quizview.hidden = state.completed; }

			if ( state.completed ) {
				if ( csub ) { csub.textContent = state.returning ? ( Q.returningSub || '' ) : ( Q.passedSub || '' ); }
				if ( review ) { review.textContent = state.returning ? ( Q.returnReview || '' ) : ( Q.review || '' ); }
				return;
			}

			// Options.
			opts.forEach( function ( opt ) {
				var qi = opt.getAttribute( 'data-q' );
				var oi = opt.getAttribute( 'data-o' );
				var chosen = String( state.sel[ qi ] ) === String( oi );
				var isCorrect = opt.getAttribute( 'data-correct' ) === '1';
				var tag = opt.querySelector( '.hti-quiz__tag' );
				opt.classList.remove( 'is-chosen', 'is-correct', 'is-wrong', 'is-faded' );
				opt.setAttribute( 'aria-checked', chosen ? 'true' : 'false' );
				opt.setAttribute( 'tabindex', state.checked ? '-1' : '0' );
				if ( tag ) { tag.textContent = ''; }

				if ( ! state.checked ) {
					if ( chosen ) { opt.classList.add( 'is-chosen' ); }
				} else if ( isCorrect ) {
					opt.classList.add( 'is-correct' );
					if ( tag ) { tag.textContent = Q.tagCorrect || ''; }
				} else if ( chosen ) {
					opt.classList.add( 'is-wrong' );
					if ( tag ) { tag.textContent = Q.tagYour || ''; }
				} else {
					opt.classList.add( 'is-faded' );
				}
			} );

			// Per-question "answer this" highlight.
			qEls.forEach( function ( qel, i ) {
				qel.classList.toggle( 'is-unanswered', state.emptyErr && state.sel[ i ] == null );
			} );

			if ( alertEl ) { alertEl.hidden = ! state.emptyErr; }

			// Result line (only when checked and not all correct).
			if ( resultEl ) {
				if ( state.checked ) {
					resultEl.hidden = false;
					resultEl.className = 'hti-quiz__result is-bad';
					if ( resultIc ) { resultIc.innerHTML = BAD_IC; }
					if ( resultTx ) { resultTx.textContent = ( Q.partial || '%1$d / %2$d' ).replace( '%1$d', correctCount() ).replace( '%2$d', n ); }
				} else {
					resultEl.hidden = true;
				}
			}

			if ( primary ) { primary.textContent = state.checked ? ( Q.retry || '' ) : ( Q.check || '' ); }
		}

		function pick( qi, oi ) {
			if ( state.completed ) { return; }
			state.sel[ qi ] = oi;
			state.emptyErr = false;
			state.checked = false;
			render();
		}

		opts.forEach( function ( opt ) {
			opt.addEventListener( 'click', function () { pick( opt.getAttribute( 'data-q' ), opt.getAttribute( 'data-o' ) ); } );
			opt.addEventListener( 'keydown', function ( e ) {
				if ( e.key === ' ' || e.key === 'Enter' || e.key === 'Spacebar' ) { e.preventDefault(); pick( opt.getAttribute( 'data-q' ), opt.getAttribute( 'data-o' ) ); }
			} );
		} );

		if ( primary ) {
			primary.addEventListener( 'click', function () {
				if ( state.checked ) { state.checked = false; state.emptyErr = false; render(); return; } // Try again
				var answered = true;
				for ( var i = 0; i < n; i++ ) { if ( state.sel[ i ] == null ) { answered = false; break; } }
				if ( ! answered ) { state.emptyErr = true; render(); return; }
				state.checked = true;
				state.emptyErr = false;
				if ( correctCount() === n ) {
					state.completed = true;
					state.returning = false;
					if ( slug ) { addLocal( KEY_PASS, slug ); addLocal( KEY_DONE, slug ); postProgress( { done: [ slug ], passed: [ slug ] } ); }
				}
				render();
			} );
		}

		if ( review ) {
			review.addEventListener( 'click', function () { state.completed = false; state.checked = true; state.emptyErr = false; render(); } );
		}

		render();
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
