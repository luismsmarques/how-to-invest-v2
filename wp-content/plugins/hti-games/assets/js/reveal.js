/**
 * The Reveal — the daily loop.
 *
 * dossier → size → the reveal → the three lines, or death. Same contract as
 * Survive the Charts: the server decides, this file renders, and every number
 * that means anything comes from window.HTIGamesReveal (the parity-tested
 * mirror of class-reveal-engine.php) or from the response itself.
 *
 * The reveal sequence is theatre over a decision that is already made — the
 * server answered before the first frame — so it is skippable at any moment,
 * skipped outright under prefers-reduced-motion, and the result is announced
 * to a screen reader when the response lands rather than when the animation
 * finishes.
 *
 * Educational, illustrative, virtual money only. The companies are real, the
 * cases are at least five years old, and nothing here is a view on any of them
 * today.
 *
 * @package HTI_Games
 */
( function () {
	'use strict';

	var H = window.HTIGames;
	var R = window.HTIGamesReveal;
	var root = document.querySelector( '[data-hti-game="reveal"]' );

	if ( ! H || ! R || ! root ) {
		return;
	}

	var cfg = H.cfg;
	var GAME = 'reveal';

	var state = {
		day: '',
		ref: '',
		capital: cfg.config.capital_start,
		indexCap: cfg.config.capital_start,
		streak: 0,
		size: 10,
		result: null,
		resetIn: 0,
		daysBefore: 0,
		record: 0
	};

	var region = H.hook( root, 'say' );
	var store = null;
	var sizeGroup = null;
	var timers = [];
	var frame = 0;
	var ticker = 0;
	// See stc.js: the first phase arrives with GET /today, and takes no focus.
	var entered = false;

	/**
	 * Write text into a `data-hti` hook.
	 *
	 * @param {string} name Hook name.
	 * @param {string} text Text.
	 */
	function set( name, text ) {
		var node = H.hook( root, name );
		if ( node ) {
			node.textContent = text;
		}
	}

	/* ------------------------------------------------------------------ */
	/* HUD                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Capital, the index player's capital beside it, and the streak.
	 *
	 * The index line is not decoration. It is the whole argument of the game:
	 * the player who does nothing is usually winning, and putting their
	 * balance next to yours every single day is the only way to make that
	 * land.
	 */
	function paintHud() {
		var capital = H.hook( root, 'capital' );
		if ( capital ) {
			capital.textContent = H.money( state.capital );
			capital.classList.toggle( 'is-up', state.capital > state.indexCap );
			capital.classList.toggle( 'is-down', state.capital < cfg.config.capital_start );
		}
		set( 'index', H.money( state.indexCap ) );
		set( 'streak', String( state.streak ) );
	}

	/**
	 * The countdown to the next dossier. Digits only.
	 *
	 * @param {number} seconds Seconds until the reset.
	 */
	function countdown( seconds ) {
		state.resetIn = seconds || 0;
		var node = H.hook( root, 'reset' );
		if ( ! node ) {
			return;
		}
		if ( ticker ) {
			window.clearInterval( ticker );
		}
		function paint() {
			node.textContent = H.fmt( H.t( 'next_reset' ), H.clock( state.resetIn ) );
			state.resetIn = Math.max( 0, state.resetIn - 1 );
		}
		paint();
		ticker = window.setInterval( paint, 1000 );
	}

	/* ------------------------------------------------------------------ */
	/* The dossier                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Fill the file: sector, revenue band, the six fundamentals and the three
	 * headlines of the year.
	 *
	 * The dossier number is the day's opaque handle, not a counter — the
	 * server never hands out a post id, and a sequential dossier number would
	 * be one by another name.
	 *
	 * @param {Object} data The /today payload.
	 */
	function paintDossier( data ) {
		set( 'dossier', H.fmt( H.t( 'rev_dossier' ), String( data.ref || '' ).slice( 0, 6 ).toUpperCase() ) );
		set( 'sector', data.sector || '—' );
		set( 'revenue', data.revenue_band || '—' );

		var body = H.hook( root, 'fundamentals' );
		if ( body ) {
			body.textContent = '';
			// The tint is the editor's judgement about the figure, and it used
			// to live in the colour of the figure and nowhere else (WCAG
			// 1.4.1). The mark carries it for the eye, the span for a screen
			// reader, both keyed on the vocabulary REST already validates.
			var marks = { good: '✓', warn: '~', bad: '!' };
			( data.fundamentals || [] ).forEach( function ( row ) {
				var tr = H.el( 'tr', { class: 'is-' + row.tint } );
				tr.appendChild( H.el( 'th', { scope: 'row' }, row.label ) );
				var value = H.el( 'td', { class: 'hti-num hti-rv__value' } );
				if ( marks[ row.tint ] ) {
					value.appendChild( H.el( 'span', { class: 'hti-rv__mark', 'aria-hidden': 'true' }, marks[ row.tint ] ) );
					value.appendChild( H.sr( H.t( 'rev_tint_' + row.tint ) + ' — ' ) );
				}
				value.appendChild( document.createTextNode( row.value ) );
				tr.appendChild( value );
				var avg = H.el( 'td', { class: 'hti-num hti-rv__avg' } );
				avg.appendChild( H.sr( H.t( 'rev_sector_avg' ) + ' ' ) );
				avg.appendChild( document.createTextNode( row.sector_avg ) );
				tr.appendChild( avg );
				body.appendChild( tr );
			} );
		}

		var heads = H.hook( root, 'headlines' );
		if ( heads ) {
			heads.textContent = '';
			( data.headlines || [] ).forEach( function ( text ) {
				var item = H.el( 'li', { class: 'hti-rv__head' } );
				item.appendChild( H.el( 'blockquote', null, text ) );
				heads.appendChild( item );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Size                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * The tier the player is on.
	 *
	 * @return {Object}
	 */
	function tier() {
		var found = null;
		( cfg.sizes || [] ).forEach( function ( row ) {
			if ( row.pct === state.size ) {
				found = row;
			}
		} );
		return found || ( cfg.sizes || [] )[ 0 ] || { pct: 10, warn: 'rev_warn_10', losses: 0 };
	}

	/**
	 * Repaint the size screen: what each share commits, the warning for the
	 * one selected, and the confirm label.
	 */
	function paintSize() {
		( cfg.sizes || [] ).forEach( function ( row ) {
			var node = root.querySelector( '[data-hti-size-amount="' + row.pct + '"]' );
			if ( node ) {
				node.textContent = H.money( R.committed( state.capital, row.pct ) );
			}
		} );

		var row = tier();
		var warn = H.hook( root, 'size-warn' );
		if ( warn ) {
			warn.textContent = H.fmt( H.t( row.warn ), row.losses );
			warn.className = 'hti-g__warn is-' + row.tone;
		}

		var confirm = H.hook( root, 'size-confirm' );
		if ( confirm ) {
			confirm.textContent = H.fmt( H.t( 'rev_confirm' ), row.pct );
			confirm.classList.toggle( 'is-grave', !! row.grave );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Results                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Which headline goes above a recorded decision.
	 *
	 * A pass is judged against what the company actually went on to do:
	 * passing on a wipeout is a good decision, passing on a compounder is
	 * simply staying out. The game says which, because a pass that is never
	 * praised teaches players to always be in.
	 *
	 * @param {Object} result Result payload.
	 * @return {string}
	 */
	function titleKey( result ) {
		if ( 'pass' === result.decision ) {
			return result.return_5y_bp < 0 ? 'rev_title_pass_ok' : 'rev_title_pass';
		}
		return result.pnl >= 0 ? 'rev_title_win' : 'rev_title_loss';
	}

	/**
	 * The three lines: what you did, what doing nothing would have done, and
	 * what the index did.
	 *
	 * @param {Object} result Result payload.
	 */
	function paintLines( result ) {
		var box = H.hook( root, 'lines' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';

		var labels = { you: 'rev_line_you', pass: 'rev_line_passed', index: 'rev_line_index' };
		var notes = { pass: 'rev_intact', index: 'rev_line_index_ft' };

		( result.lines || [] ).forEach( function ( line ) {
			var wrap = H.el( 'div', { class: 'hti-rv__line is-' + line.key } );
			var dt = H.el( 'dt' );
			dt.appendChild( H.el( 'span', { class: 'hti-rv__linelabel' }, H.t( labels[ line.key ] || '' ) ) );
			if ( notes[ line.key ] ) {
				dt.appendChild( H.el( 'span', { class: 'hti-rv__linenote' }, H.t( notes[ line.key ] ) ) );
			}
			var dd = H.el( 'dd', { class: 'hti-num' }, H.signed( line.pnl ) );
			dd.classList.add( line.pnl > 0 ? 'is-up' : ( line.pnl < 0 ? 'is-down' : 'is-flat' ) );
			wrap.appendChild( dt );
			wrap.appendChild( dd );
			box.appendChild( wrap );
		} );
	}

	/**
	 * What the figures on this case were, in one line.
	 *
	 * Exactly one line shows: a verified case credits its document, an
	 * illustrative one says the figures and headlines were reconstructed to
	 * show the pattern. A screen that shows both, or neither, has stopped
	 * telling the reader which of the two they just played.
	 *
	 * @param {Object} result Result payload.
	 */
	function paintProvenance( result ) {
		var illustrative = 'illustrative' === result.provenance;
		set( 'provenance', illustrative ? H.t( 'rev_illustrative' ) : '' );
		paintSource( illustrative ? null : result.source );
	}

	/**
	 * The published source, revealed with the answer.
	 *
	 * Kept out of the dossier because a URL names the company in its own slug.
	 * It is the thing that makes the case checkable rather than a story, so it
	 * is a real link, not a citation nobody can follow.
	 *
	 * @param {Object} source { url, label, accessed }.
	 */
	function paintSource( source ) {
		var node = H.hook( root, 'source' );
		if ( ! node ) {
			return;
		}
		node.textContent = '';
		if ( ! source || ! source.url ) {
			return;
		}

		node.appendChild( document.createTextNode( H.t( 'rev_source' ) + ': ' ) );
		var link = H.el( 'a', { href: source.url, rel: 'noopener' }, source.label || source.url );
		node.appendChild( link );

		if ( source.accessed ) {
			node.appendChild( document.createTextNode( ' — ' + H.fmt( H.t( 'rev_source_note' ), source.accessed ) ) );
		}
	}

	/**
	 * How the rest of the day went, once this player's own day is recorded.
	 *
	 * Same contract as Survive the Charts: Leaderboard::crowd() chooses the
	 * sentence — how many stayed out, or how many put money behind it — from
	 * what this player did, and returns a null `pct` on a day too small for a
	 * rate to mean anything.
	 *
	 * @param {Object} crowd The result's `crowd` block.
	 */
	function paintCrowd( crowd ) {
		var wrap = H.hook( root, 'crowd-row' );
		if ( ! wrap ) {
			return;
		}
		if ( ! crowd || ! crowd.players || ! crowd.key ) {
			wrap.hidden = true;
			return;
		}
		wrap.hidden = false;
		set( 'crowd-label', H.t( crowd.key ) );
		set( 'crowd-value', null === crowd.pct ? String( crowd.players ) : crowd.pct + '%' );
	}

	/**
	 * Show a recorded result.
	 *
	 * @param {Object} result The server's `result` object.
	 */
	function paintResult( result ) {
		state.result = result;
		state.capital = result.cap_after;
		state.indexCap = result.idx_after;
		state.streak = result.streak;

		var win = 'pass' !== result.decision && result.pnl > 0;
		var loss = 'pass' !== result.decision && result.pnl < 0;

		// The kicker is the company and the year — a proper noun and a number,
		// which is all the reveal has to say and the only part of this screen
		// that is not in the copy table.
		set( 'result-kicker', result.company ? result.company + ' · ' + result.year : '' );
		set( 'result-title', H.t( titleKey( result ) ) );

		var pnl = H.hook( root, 'result-pnl' );
		if ( pnl ) {
			pnl.textContent = H.signed( result.pnl );
			pnl.className = 'hti-g__pnl hti-num ' + ( win ? 'is-up' : ( loss ? 'is-down' : 'is-flat' ) );
		}

		var context = H.hook( root, 'context' );
		if ( context ) {
			// innerHTML, and only here and on the lesson: both are editorial
			// copy that went through wp_kses_post() on the way out of the CPT.
			context.innerHTML = result.context || '';
		}

		paintLines( result );
		paintCrowd( result.crowd );
		paintProvenance( result );

		var block = H.hook( root, 'lesson-block' );
		var lesson = H.hook( root, 'lesson' );
		if ( block && lesson ) {
			block.hidden = ! result.lesson;
			lesson.innerHTML = result.lesson || '';
		}

		paintHud();
		countdown( result.reset_in );

		if ( result.died ) {
			paintDeath();
			go( 'dead' );
		} else {
			go( 'result' );
		}
	}

	/**
	 * The death report.
	 */
	function paintDeath() {
		set( 'dead-title', H.t( 'rev_dead_title' ) );
		set( 'dead-days', String( state.daysBefore || 0 ) );
		set( 'dead-index', H.money( state.indexCap ) );
		set( 'dead-record', String( state.record || state.daysBefore || 0 ) );
		H.track( 'game_death', GAME );

		H.api( '/profile', { query: { lang: cfg.lang } } ).then( function ( data ) {
			var block = ( data.games || {} )[ GAME ] || {};
			if ( block.average_risk_bp ) {
				set( 'dead-avg', H.pct( block.average_risk_bp ) );
			}
			var player = ( data.player || {} ).reveal || {};
			if ( player.best_streak ) {
				set( 'dead-record', String( player.best_streak ) );
			}
		} ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* The reveal sequence                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Stop every timer and frame the sequence owns.
	 */
	function stopSequence() {
		timers.forEach( window.clearTimeout );
		timers = [];
		if ( frame ) {
			window.cancelAnimationFrame( frame );
			frame = 0;
		}
	}

	/**
	 * Open the dossier: the name, then the year, then the number counting up.
	 *
	 * @param {Object}   result Result payload.
	 * @param {Function} done   Called when the sequence is over.
	 */
	function sequence( result, done ) {
		stopSequence();

		set( 'reveal-name', '' );
		set( 'reveal-year', '' );
		set( 'reveal-count', '' );

		if ( H.reducedMotion() ) {
			done();
			return;
		}

		set( 'reveal-name', result.company || '' );

		timers.push( window.setTimeout( function () {
			set( 'reveal-year', String( result.year || '' ) );
		}, 700 ) );

		timers.push( window.setTimeout( function () {
			count( result.pnl, 900, function () {
				timers.push( window.setTimeout( done, 700 ) );
			} );
		}, 1400 ) );
	}

	/**
	 * Count a figure up to its real value.
	 *
	 * Driven by performance.now() rather than a frame count, for the same
	 * reason the chart replay is: a backgrounded tab throttles rAF, and a
	 * counter would come back from it still counting.
	 *
	 * @param {number}   target Final value.
	 * @param {number}   ms     Duration.
	 * @param {Function} done   Called at the end.
	 */
	function count( target, ms, done ) {
		var start = window.performance.now();
		var node = H.hook( root, 'reveal-count' );

		function step( now ) {
			var progress = Math.min( 1, ( now - start ) / ms );
			var eased = 1 - Math.pow( 1 - progress, 3 );
			if ( node ) {
				node.textContent = H.signed( target * eased );
				node.className = 'hti-rv__count hti-num ' + ( target > 0 ? 'is-up' : ( target < 0 ? 'is-down' : 'is-flat' ) );
			}
			if ( progress >= 1 ) {
				frame = 0;
				done();
				return;
			}
			frame = window.requestAnimationFrame( step );
		}

		frame = window.requestAnimationFrame( step );
	}

	/* ------------------------------------------------------------------ */
	/* Phases                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Move to a phase.
	 *
	 * The Reveal takes over nothing: the cream shell is close enough to the
	 * site's own background that it reads as a tinted card, so the header and
	 * the footer stay exactly where they were.
	 *
	 * @param {string} name Phase name.
	 */
	function go( name ) {
		H.phase( root, name, 'reveal' === name ? '[data-hti="skip"]' : null, entered );
		entered = true;

		if ( store && ( 'dossier' === name || 'size' === name ) ) {
			store.set( { phase: name, size: state.size } );
		}
	}

	/**
	 * Post a decision and reveal whatever comes back.
	 *
	 * @param {string} decision 'invest' or 'pass'.
	 */
	function decide( decision ) {
		var body = {
			decision: decision,
			day: state.day,
			lang: cfg.lang
		};

		if ( 'pass' !== decision ) {
			body.size = state.size;
		}

		state.daysBefore = state.streak;
		lockActions( true );
		H.track( 'game_decision', GAME + '_' + decision );

		H.api( '/' + GAME + '/decision', { method: 'POST', body: body } ).then( function ( data ) {
			lockActions( false );
			store.clear();
			landed( data.result );
		} ).catch( function ( err ) {
			lockActions( false );

			if ( 409 === err.status && err.payload && err.payload.result ) {
				H.say( region, H.t( 'already_played' ) );
				landed( err.payload.result );
				return;
			}
			if ( 'hti_game_day_moved' === err.code ) {
				H.say( region, H.t( 'day_moved' ) );
				load();
				return;
			}

			H.say( region, H.errorText( err ) );
			go( 'dossier' );
		} );
	}

	/**
	 * A result has arrived: say it first, then play the theatre.
	 *
	 * @param {Object} result Result payload.
	 */
	function landed( result ) {
		state.result = result;

		// One announcement carrying every figure the screen just changed: the
		// verdict, what it was worth, and the three HUD numbers. WCAG 4.1.3 —
		// nothing here is focused and nothing reloads the page.
		H.say( region, H.t( titleKey( result ) ) + ' ' + H.signed( result.pnl )
			+ '. ' + H.t( 'capital_label' ) + ' ' + H.money( result.cap_after )
			+ '. ' + H.t( 'rev_index_label' ) + ' ' + H.money( result.idx_after )
			+ '. ' + H.t( 'streak_label' ) + ' ' + result.streak + '.' );

		H.track( 'game_result', GAME + '_' + result.outcome );

		// Reduced motion removes the sequence, so it removes the stage rather
		// than flashing an empty one and moving focus twice to leave it.
		if ( H.reducedMotion() ) {
			paintResult( result );
			return;
		}

		go( 'reveal' );
		sequence( result, function () {
			paintResult( result );
		} );
	}

	/**
	 * Disable everything that would post a second decision.
	 *
	 * @param {boolean} locked Whether a request is in flight.
	 */
	function lockActions( locked ) {
		var buttons = root.querySelectorAll( '[data-hti-decide], [data-hti="size-confirm"]' );
		var i;
		// Disabling the focused button hands focus to <body> until the answer
		// lands. Park it on the phase heading; go() takes it from there.
		if ( locked ) {
			for ( i = 0; i < buttons.length; i++ ) {
				if ( buttons[ i ] === document.activeElement ) {
					var head = root.querySelector( '[data-hti-phase]:not([hidden]) [tabindex="-1"]' );
					if ( head ) {
						head.focus();
					}
					break;
				}
			}
		}
		for ( i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = !! locked;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function wire() {
		var buttons = root.querySelectorAll( '[data-hti-decide]' );
		var i;
		for ( i = 0; i < buttons.length; i++ ) {
			( function ( button ) {
				button.addEventListener( 'click', function () {
					if ( 'pass' === button.getAttribute( 'data-hti-decide' ) ) {
						decide( 'pass' );
						return;
					}
					paintSize();
					go( 'size' );
				} );
			}( buttons[ i ] ) );
		}

		var group = H.hook( root, 'size-group' );
		if ( group ) {
			sizeGroup = H.radiogroup( group, function ( node ) {
				state.size = parseInt( node.getAttribute( 'data-hti-size' ), 10 ) || 10;
				paintSize();
				if ( store ) {
					store.set( { phase: 'size', size: state.size } );
				}
			} );
		}

		var back = H.hook( root, 'size-back' );
		if ( back ) {
			back.addEventListener( 'click', function () {
				go( 'dossier' );
			} );
		}

		var confirm = H.hook( root, 'size-confirm' );
		if ( confirm ) {
			confirm.addEventListener( 'click', function () {
				decide( 'invest' );
			} );
		}

		var skip = H.hook( root, 'skip' );
		if ( skip ) {
			skip.addEventListener( 'click', function () {
				stopSequence();
				if ( state.result ) {
					paintResult( state.result );
				}
			} );
		}

		var shareButtons = root.querySelectorAll( '[data-hti="share"]' );
		for ( i = 0; i < shareButtons.length; i++ ) {
			shareButtons[ i ].addEventListener( 'click', function () {
				var died = !! ( state.result && state.result.died );
				H.share( root, {
					game: GAME,
					day: died ? state.daysBefore : state.streak,
					capital: state.capital,
					streak: state.streak,
					dead: died
				} );
			} );
		}

		var nextButtons = root.querySelectorAll( '[data-hti="next"]' );
		for ( i = 0; i < nextButtons.length; i++ ) {
			nextButtons[ i ].addEventListener( 'click', function () {
				load();
			} );
		}

		window.addEventListener( 'pagehide', stopSequence );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch today's dossier and put the screen where it belongs.
	 */
	function load() {
		H.say( region, H.t( 'st_loading' ) );

		H.api( '/' + GAME + '/today', { query: { lang: cfg.lang } } ).then( function ( data ) {
			state.day = data.day;
			state.ref = data.ref;
			state.capital = data.capital;
			state.indexCap = data.index_cap;
			state.streak = data.streak;
			state.daysBefore = data.streak;
			state.record = ( ( data.player || {} ).reveal || {} ).best_streak || 0;
			state.result = null;
			store = H.draft( GAME, state.day );

			paintDossier( data );
			paintHud();
			paintSize();
			countdown( data.reset_in );
			H.say( region, '' );

			var player = data.player || {};
			if ( ! player.onboarded ) {
				var phases = root.querySelector( '.hti-g__phases' );
				if ( phases ) {
					phases.hidden = true;
				}
				H.onboarding( root, GAME, function () {
					if ( phases ) {
						phases.hidden = false;
					}
					resume( data );
				} );
				return;
			}

			resume( data );
		} ).catch( function ( err ) {
			H.say( region, H.errorText( err ) );
			go( 'dossier' );
			lockActions( true );
		} );
	}

	/**
	 * Where the screen goes once the dossier is in hand.
	 *
	 * @param {Object} data The /today payload.
	 */
	function resume( data ) {
		if ( data.played && data.result ) {
			paintResult( data.result );
			return;
		}

		var saved = store.get();
		if ( 'size' === saved.phase ) {
			if ( saved.size && sizeGroup ) {
				sizeGroup.items.forEach( function ( node, i ) {
					if ( parseInt( node.getAttribute( 'data-hti-size' ), 10 ) === saved.size ) {
						sizeGroup.select( i, false );
					}
				} );
			}
			paintSize();
			go( 'size' );
			return;
		}

		go( 'dossier' );
	}

	H.track( 'game_view', GAME );
	wire();
	load();
}() );
