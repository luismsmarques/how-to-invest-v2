/**
 * Survive the Charts — the daily loop.
 *
 * decide → risk → replay → result, or death. The server decides everything
 * that counts: this file draws the candles, animates the outcome the server
 * already returned, and keeps the interface honest while it does.
 *
 * Three things worth knowing before editing:
 *
 * 1. NO ARITHMETIC LIVES HERE. Stops, targets, the dollars at risk and the
 *    ruin counts all come from window.HTIGamesSTC, the parity-tested mirror of
 *    class-stc-engine.php. A second implementation of any of it is a second
 *    answer to a question that must only have one.
 * 2. drawChart() is a pure function of its state object. It can be called on a
 *    resize, on a frame, or on nothing at all, and it never reads or writes
 *    the game state — which is what makes a chart that redraws forty times a
 *    second something you can reason about.
 * 3. requestAnimationFrame runs during the replay AND NOWHERE ELSE. The decide
 *    and risk phases draw once and redraw on resize; a game that spins a frame
 *    loop while a player reads a warning about position size is burning
 *    somebody's battery to move nothing.
 *
 * Educational, illustrative, virtual money only.
 *
 * @package HTI_Games
 */
( function () {
	'use strict';

	var H = window.HTIGames;
	var E = window.HTIGamesSTC;
	var root = document.querySelector( '[data-hti-game="stc"]' );

	if ( ! H || ! E || ! root ) {
		return;
	}

	var cfg = H.cfg;
	var GAME = 'stc';

	/**
	 * Everything the screen is currently showing. The server owns every field
	 * in it: nothing below computes a capital or decides a day has turned.
	 */
	var state = {
		day: '',
		candles: [],
		outcome: [],
		atr: 0,
		entry: 0,
		scale: cfg.config.tick_scale,
		capital: cfg.config.capital_start,
		streak: 0,
		played: false,
		result: null,
		direction: '',
		riskBp: 200,
		double: false,
		reveal: 0,
		resetIn: 0,
		// The streak going INTO today's decision — which is what "days
		// survived" means on the death screen, since the recorded run carries
		// the post-death streak and that is always zero.
		daysBefore: 0,
		record: 0
	};

	var canvas = H.hook( root, 'canvas' );
	var ctx = canvas ? canvas.getContext( '2d' ) : null;
	var live = H.hook( root, 'live' );
	var region = H.hook( root, 'say' );
	var frame = 0;
	var ticker = 0;
	var store = null;
	var riskGroup = null;
	// The first phase arrives with GET /today, not with a click, and does not
	// take focus. See HTIGames.phase().
	var entered = false;

	/* ------------------------------------------------------------------ */
	/* The chart                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * The palette, read once from the stylesheet.
	 *
	 * A canvas cannot use a CSS custom property, and hardcoding six hex codes
	 * in a second file is how a theme change ends up half-applied. Read from
	 * the element the sheet actually styles, with the design tokens as the
	 * fallback for the moment before CSS lands.
	 *
	 * @return {Object}
	 */
	function palette() {
		var css = window.getComputedStyle( root );
		function token( name, fallback ) {
			var v = css.getPropertyValue( name );
			return v && v.trim() ? v.trim() : fallback;
		}
		return {
			grid: token( '--g-grid', '#161C26' ),
			up: token( '--g-up', '#22C77E' ),
			down: token( '--g-down', '#FF4D5E' ),
			pastUp: token( '--g-past-up', '#2E7D5B' ),
			pastDown: token( '--g-past-down', '#A64A54' ),
			brand: token( '--g-brand', '#FFB020' )
		};
	}

	var colors = palette();

	/**
	 * Draw the whole chart. Pure: everything it needs is in `s`.
	 *
	 * @param {CanvasRenderingContext2D} c 2D context.
	 * @param {Object} s { ticks, visibleCount, entry, stop, target, revealIndex, w, h, dpr, colors }.
	 */
	function drawChart( c, s ) {
		if ( ! c || ! s.w || ! s.h ) {
			return;
		}

		c.setTransform( s.dpr, 0, 0, s.dpr, 0, 0 );
		c.clearRect( 0, 0, s.w, s.h );

		var shown = s.ticks.slice( 0, s.visibleCount + s.revealIndex );
		if ( ! shown.length ) {
			return;
		}

		var lo = Infinity;
		var hi = -Infinity;
		var i;

		for ( i = 0; i < shown.length; i++ ) {
			lo = Math.min( lo, shown[ i ][ 2 ] );
			hi = Math.max( hi, shown[ i ][ 1 ] );
		}
		if ( s.stop ) {
			lo = Math.min( lo, s.stop );
			hi = Math.max( hi, s.stop );
		}
		if ( s.target ) {
			lo = Math.min( lo, s.target );
			hi = Math.max( hi, s.target );
		}

		var pad = ( hi - lo ) * 0.08 || 1;
		lo -= pad;
		hi += pad;

		// The x-scale from the handoff: eighty candles fill the panel, and the
		// window widens as the outcome reveals so the replay walks rightwards
		// instead of squeezing everything that came before it.
		var total = s.visibleCount + Math.max( 8, s.revealIndex + 4 );
		var padX = 8;
		var cw = ( s.w - padX * 2 ) / total;
		var span = hi - lo || 1;

		function y( v ) {
			return s.h - 14 - ( ( v - lo ) / span ) * ( s.h - 28 );
		}

		c.strokeStyle = s.colors.grid;
		c.lineWidth = 1;
		for ( i = 1; i < 4; i++ ) {
			var gy = Math.round( 14 + ( s.h - 28 ) * i / 4 ) + 0.5;
			c.beginPath();
			c.moveTo( 0, gy );
			c.lineTo( s.w, gy );
			c.stroke();
		}

		// The levels only exist once a side has been chosen — a stop drawn
		// before the decision would be telling the player where to look.
		if ( s.stop && s.target ) {
			line( c, s.w, y( s.target ), s.colors.up, [ 4, 4 ] );
			line( c, s.w, y( s.stop ), s.colors.down, [ 4, 4 ] );
			line( c, s.w, y( s.entry ), s.colors.brand, [ 2, 3 ] );
		}

		for ( i = 0; i < shown.length; i++ ) {
			var candle = shown[ i ];
			var future = i >= s.visibleCount;
			var rising = candle[ 3 ] >= candle[ 0 ];
			var x = padX + i * cw + cw / 2;

			c.strokeStyle = rising
				? ( future ? s.colors.up : s.colors.pastUp )
				: ( future ? s.colors.down : s.colors.pastDown );
			c.fillStyle = c.strokeStyle;
			c.lineWidth = 1;

			c.beginPath();
			c.moveTo( x, y( candle[ 1 ] ) );
			c.lineTo( x, y( candle[ 2 ] ) );
			c.stroke();

			var bw = Math.max( 1.5, cw * 0.62 );
			var yo = y( candle[ 0 ] );
			var yc = y( candle[ 3 ] );
			c.fillRect( x - bw / 2, Math.min( yo, yc ), bw, Math.max( 1, Math.abs( yc - yo ) ) );
		}

		if ( s.stop && s.target ) {
			c.fillStyle = s.colors.brand;
			c.beginPath();
			c.arc( padX + ( s.visibleCount - 1 ) * cw + cw / 2, y( s.entry ), 3.2, 0, Math.PI * 2 );
			c.fill();
		}
	}

	/**
	 * One dashed horizontal rule.
	 *
	 * @param {CanvasRenderingContext2D} c    Context.
	 * @param {number}        w     Width.
	 * @param {number}        at    Y position.
	 * @param {string}        color Colour.
	 * @param {Array<number>} dash  Dash pattern.
	 */
	function line( c, w, at, color, dash ) {
		c.save();
		c.strokeStyle = color;
		c.setLineDash( dash );
		c.lineWidth = 1;
		c.beginPath();
		c.moveTo( 0, at );
		c.lineTo( w, at );
		c.stroke();
		c.restore();
	}

	/**
	 * Size the backing store and draw once.
	 *
	 * The canvas is laid out in CSS pixels and backed at the device ratio,
	 * capped at 2 — past that the extra pixels cost memory and buy nothing a
	 * candle wick can show.
	 */
	function draw() {
		if ( ! ctx || ! canvas ) {
			return;
		}

		var dpr = Math.min( window.devicePixelRatio || 1, 2 );
		var w = canvas.clientWidth;
		var h = canvas.clientHeight;

		if ( ! w || ! h ) {
			return;
		}

		if ( canvas.width !== Math.round( w * dpr ) || canvas.height !== Math.round( h * dpr ) ) {
			canvas.width = Math.round( w * dpr );
			canvas.height = Math.round( h * dpr );
		}

		var levels = state.direction && 'pass' !== state.direction
			? E.levels( state.entry, state.atr, state.direction )
			: { stop: 0, target: 0 };

		drawChart( ctx, {
			ticks: state.candles.concat( state.outcome ),
			visibleCount: state.candles.length,
			entry: state.entry,
			stop: levels.stop,
			target: levels.target,
			revealIndex: state.reveal,
			w: w,
			h: h,
			dpr: dpr,
			colors: colors
		} );
	}

	/**
	 * A tick price as a player reads it.
	 *
	 * @param {number} ticks Integer ticks.
	 * @return {string}
	 */
	function price( ticks ) {
		var scale = state.scale || 1;
		var decimals = Math.max( 0, String( scale ).length - 1 );
		return ( ticks / scale ).toFixed( decimals );
	}

	/* ------------------------------------------------------------------ */
	/* The text equivalent                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Keep the visually-hidden table under the canvas in step with it.
	 *
	 * This is the chart, for anybody who is not looking at it — and the only
	 * copy-pasteable record of what the levels actually were.
	 */
	function paintTable() {
		var levels = state.direction && 'pass' !== state.direction
			? E.levels( state.entry, state.atr, state.direction )
			: { stop: 0, target: 0 };

		set( 'tbl-entry', state.entry ? price( state.entry ) : '—' );
		set( 'tbl-stop', levels.stop ? price( levels.stop ) : '—' );
		set( 'tbl-target', levels.target ? price( levels.target ) : '—' );
		set( 'tbl-outcome', state.result ? H.t( outcomeKey( state.result ) ) : '—' );
		set( 'tbl-pnl', state.result ? H.signed( state.result.pnl ) : '—' );
	}

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
	 * Capital, streak, the health bar, and how far from the start it all is.
	 */
	function paintHud() {
		var capital = H.hook( root, 'capital' );
		if ( capital ) {
			capital.textContent = H.money( state.capital );
			capital.classList.toggle( 'is-up', state.capital > cfg.config.capital_start );
			capital.classList.toggle( 'is-down', state.capital < cfg.config.capital_start );
		}

		set( 'streak', String( state.streak ) );

		var survival = E.survival( state.capital );
		var fill = H.hook( root, 'survival' );
		if ( fill ) {
			fill.style.width = Math.round( survival * 100 ) + '%';
			fill.className = 'hti-g__fill ' + ( survival > 0.6 ? 'is-up' : ( survival > 0.3 ? 'is-brand' : 'is-down' ) );
		}

		var delta = state.capital - cfg.config.capital_start;
		set( 'fromstart', H.fmt( H.t( 'stc_from_start' ), H.signed( delta ) ) );
	}

	/**
	 * The full-bleed takeover, on only while a run is on screen.
	 *
	 * Mirrors what questionnaire.js does with `hti-quiz-active`: the site
	 * header, footer and tab bar are hidden by our own stylesheet, and the
	 * moment the result appears they come back — the result is a page anybody
	 * may want to leave from.
	 *
	 * @param {boolean} on Whether a run is on screen.
	 */
	function takeover( on ) {
		try {
			document.documentElement.classList.toggle( 'hti-game-active', !! on );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/* Phases                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Move to a phase and do the housekeeping that goes with it.
	 *
	 * @param {string} name Phase name.
	 */
	function go( name ) {
		var running = 'decide' === name || 'risk' === name || 'replay' === name;
		takeover( running );

		set( 'charttitle', H.t(
			'decide' === name ? 'stc_chart_decide'
				: ( 'risk' === name ? 'stc_chart_levels'
					: ( 'replay' === name ? 'stc_chart_replay' : 'stc_chart_done' ) )
		) );

		if ( live ) {
			live.hidden = 'replay' !== name;
		}

		// The skip control is the first focusable thing in the replay, and the
		// replay is the one phase where focus goes to a button rather than to
		// the heading: the first thing a keyboard user needs there is the way
		// out of the animation.
		H.phase( root, name, 'replay' === name ? '[data-hti="skip"]' : null, entered );
		entered = true;

		if ( store && ( 'decide' === name || 'risk' === name ) ) {
			store.set( {
				phase: name,
				direction: state.direction,
				risk_bp: state.riskBp,
				double: state.double
			} );
		}
	}

	/**
	 * The tier the player is on, from the localized table.
	 *
	 * @return {Object}
	 */
	function tier() {
		var found = null;
		( cfg.risk || [] ).forEach( function ( row ) {
			if ( row.bp === state.riskBp ) {
				found = row;
			}
		} );
		return found || ( cfg.risk || [] )[ 0 ] || { bp: 200, warn: 'stc_warn_200', losses: 0, losses2: 0 };
	}

	/**
	 * Repaint everything the risk screen shows: the per-tile amounts, the
	 * warning for the chosen tier, the money at risk and the confirm label.
	 *
	 * The warning's number is the engine's — losses_to_ruin(), computed
	 * server-side and handed over in HTI_GAMES.risk — never a number typed
	 * into a sentence.
	 */
	function paintRisk() {
		( cfg.risk || [] ).forEach( function ( row ) {
			var node = root.querySelector( '[data-hti-risk-amount="' + row.bp + '"]' );
			if ( node ) {
				node.textContent = '−' + H.money( E.atRisk( state.capital, row.bp, state.double ? cfg.config.double : 1 ) );
			}
		} );

		var row = tier();
		var warn = H.hook( root, 'risk-warn' );
		if ( warn ) {
			// The per-tier sentences describe the tier — "0.5%", "the classic
			// ceiling" — and every one of them is about the wrong position
			// once the stake is doubled. So the doubled stake gets its own
			// line, with the size it really is and the runway to match.
			warn.textContent = state.double
				? H.fmt( H.t( 'stc_warn_double' ), H.pct( row.bp * cfg.config.double ), row.losses2 )
				: H.fmt( H.t( row.warn ), row.losses );
			warn.className = 'hti-g__warn is-' + row.tone;
		}

		set( 'atrisk', '−' + H.money( E.atRisk( state.capital, state.riskBp, state.double ? cfg.config.double : 1 ) ) );

		var confirm = H.hook( root, 'risk-confirm' );
		if ( confirm ) {
			confirm.textContent = H.t( row.grave ? 'stc_confirm_high' : 'stc_confirm' );
			confirm.classList.toggle( 'is-grave', !! row.grave );
		}

		var toggle = H.hook( root, 'double' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-checked', state.double ? 'true' : 'false' );
			toggle.classList.toggle( 'is-on', state.double );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Results                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Which "what happened" string an outcome maps to.
	 *
	 * @param {Object} result Result payload.
	 * @return {string}
	 */
	function outcomeKey( result ) {
		if ( 'pass' === result.decision ) {
			return 'stc_res_pass';
		}
		if ( 'target' === result.outcome ) {
			return 'stc_res_target';
		}
		if ( 'stop' === result.outcome ) {
			return 'stc_res_stop';
		}
		return 'stc_res_timeout';
	}

	/**
	 * And which headline goes above it.
	 *
	 * @param {Object} result Result payload.
	 * @return {string}
	 */
	function titleKey( result ) {
		if ( 'pass' === result.decision ) {
			return result.pass_right ? 'stc_title_pass_good' : 'stc_title_pass';
		}
		return result.pnl >= 0 ? 'stc_title_win' : 'stc_title_loss';
	}

	/**
	 * Show a recorded result: the card, the lesson, the table and the tone.
	 *
	 * Called from three places that must all look identical — the response to
	 * a decision, a 409 saying the decision was already recorded, and a reload
	 * of a day already played — because they are the same run.
	 *
	 * @param {Object} result The server's `result` object.
	 */
	function paintResult( result ) {
		state.result = result;
		state.capital = result.cap_after;
		state.streak = result.streak;
		state.direction = result.decision;
		state.outcome = result.outcome_candles || [];
		state.entry = result.entry || state.entry;
		state.atr = result.atr || state.atr;

		var win = 'pass' !== result.decision && result.pnl > 0;
		var loss = 'pass' !== result.decision && result.pnl < 0;

		var card = H.hook( root, 'result-card' );
		if ( card ) {
			card.className = 'hti-g__card ' + ( win ? 'is-up' : ( loss ? 'is-down' : 'is-flat' ) );
		}

		set( 'result-kicker', H.t( outcomeKey( result ) ) );
		set( 'result-title', H.t( titleKey( result ) ) );

		var pnl = H.hook( root, 'result-pnl' );
		if ( pnl ) {
			pnl.textContent = H.signed( result.pnl );
			pnl.className = 'hti-g__pnl hti-num ' + ( win ? 'is-up' : ( loss ? 'is-down' : 'is-flat' ) );
		}

		set( 'result-capital', H.money( result.cap_after ) );

		// "That came off, at a size where it did not have to." A win at a tier
		// that could have ended the account is the one result the game must
		// not let pass as skill.
		var lucky = card ? card.querySelector( '.hti-g__lucky' ) : null;
		if ( lucky ) {
			lucky.remove();
		}
		if ( win && result.risk_bp >= 1000 && card ) {
			card.appendChild( H.el( 'p', { class: 'hti-g__lucky' }, H.t( 'stc_lucky_win' ) ) );
		}

		paintCrowd( result.crowd );
		paintLesson( result.lesson );
		paintTable();
		paintHud();
		// Redrawn here rather than only at the end of the replay: a reload of a
		// day already played arrives straight at this function with the whole
		// outcome in hand and never animates anything.
		draw();
		countdown( result.reset_in );

		if ( result.died ) {
			paintDeath();
			go( 'dead' );
		} else {
			go( 'result' );
		}
	}

	/**
	 * How the rest of the day went, once this player's own day is recorded.
	 *
	 * The sentence and the percentage are both the server's — see
	 * Leaderboard::crowd(), which picks which of the two comparisons is the
	 * honest one for what this player actually did, and returns a null `pct`
	 * on a day too small for a rate to mean anything. Nothing is computed
	 * here, because the block only ever arrives on a result: the API does not
	 * carry these counts before a decision, and it must not start.
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
	 * The lesson of the day.
	 *
	 * innerHTML, and only here: the value is editorial copy that went through
	 * wp_kses_post() on the way out of the CPT, and it carries paragraphs.
	 * Everything else on this screen is written with textContent.
	 *
	 * @param {string} html Sanitised HTML.
	 */
	function paintLesson( html ) {
		var block = H.hook( root, 'lesson-block' );
		var body = H.hook( root, 'lesson' );
		if ( ! block || ! body ) {
			return;
		}
		if ( ! html ) {
			block.hidden = true;
			return;
		}
		block.hidden = false;
		body.innerHTML = html;
	}

	/**
	 * The death report.
	 *
	 * The average risk comes from the profile endpoint rather than from the
	 * run — a single day cannot tell you what somebody's habit was — and the
	 * rows that need it stay blank until it arrives, or forever if it does
	 * not. Nothing here guesses.
	 *
	 */
	function paintDeath() {
		set( 'dead-title', H.t( 'stc_dead_title' ) );
		set( 'dead-days', String( state.daysBefore || 0 ) );
		set( 'dead-record', String( state.record || state.daysBefore || 0 ) );
		set( 'dead-counter', H.fmt( H.t( 'stc_dead_counter' ), cfg.ruin2 ) );
		H.track( 'game_death', GAME );

		H.api( '/profile', { query: { lang: cfg.lang } } ).then( function ( data ) {
			var block = ( data.games || {} )[ GAME ] || {};
			if ( block.average_risk_bp ) {
				set( 'dead-avg', H.pct( block.average_risk_bp ) );
			}
			var player = ( data.player || {} ).stc || {};
			if ( player.best_streak ) {
				set( 'dead-record', String( player.best_streak ) );
			}
		} ).catch( function () {} );
	}

	/**
	 * The countdown to the next challenge, ticking once a second.
	 *
	 * Digits only — "03:12:44" needs no translation, and the sentence around
	 * it comes from the copy table.
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
	/* The replay                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Walk the outcome candles onto the chart.
	 *
	 * Driven by performance.now() rather than by a frame counter or an
	 * interval: a backgrounded tab throttles rAF, and a counter would come
	 * back to a replay that is minutes behind. Elapsed time cannot drift.
	 *
	 * Under prefers-reduced-motion there is no animation at all — the final
	 * frame is drawn and the result shows. That is WCAG 2.2.1 answered by
	 * removing the timing rather than by offering to adjust it.
	 *
	 * @param {Function} done Called when the outcome is fully revealed.
	 */
	function replay( done ) {
		var stopAt = state.result && state.result.touch_idx > 0
			? Math.min( state.result.touch_idx, state.outcome.length )
			: state.outcome.length;

		if ( H.reducedMotion() || ! stopAt ) {
			state.reveal = stopAt;
			draw();
			done();
			return;
		}

		var start = window.performance.now();

		function step( now ) {
			state.reveal = Math.min( stopAt, Math.floor( ( now - start ) / cfg.config.replay_ms ) );
			draw();
			if ( state.reveal >= stopAt ) {
				frame = 0;
				done();
				return;
			}
			frame = window.requestAnimationFrame( step );
		}

		frame = window.requestAnimationFrame( step );
	}

	/**
	 * Stop the replay wherever it is and show the end of it.
	 */
	function skip() {
		if ( frame ) {
			window.cancelAnimationFrame( frame );
			frame = 0;
		}
		if ( ! state.result ) {
			return;
		}
		state.reveal = state.result && state.result.touch_idx > 0
			? Math.min( state.result.touch_idx, state.outcome.length )
			: state.outcome.length;
		draw();
		paintResult( state.result );
	}

	/* ------------------------------------------------------------------ */
	/* The write path                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Post a decision and play out whatever comes back.
	 *
	 * The announcement fires the moment the response lands — not when the
	 * animation ends — because a screen reader user should not have to wait
	 * out an animation they cannot see to be told what happened.
	 *
	 * @param {string} decision 'buy', 'sell' or 'pass'.
	 */
	function decide( decision ) {
		var body = {
			decision: decision,
			day: state.day,
			lang: cfg.lang
		};

		if ( 'pass' !== decision ) {
			body.risk_bp = state.riskBp;
			body.double = state.double;
		}

		state.direction = decision;
		state.daysBefore = state.streak;
		state.reveal = 0;
		lockActions( true );
		H.track( 'game_decision', GAME + '_' + decision );

		H.api( '/' + GAME + '/decision', { method: 'POST', body: body } ).then( function ( data ) {
			lockActions( false );
			store.clear();
			landed( data.result );
		} ).catch( function ( err ) {
			lockActions( false );

			// Already recorded — a double tap, a retry, a second tab. From the
			// player's side that is not an error, it is the same run.
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

			state.direction = '';
			H.say( region, H.errorText( err ) );
			go( 'decide' );
		} );
	}

	/**
	 * A result has arrived: say it, then animate towards it.
	 *
	 * @param {Object} result Result payload.
	 */
	function landed( result ) {
		state.result = result;
		state.outcome = result.outcome_candles || [];

		// One announcement, in the order the screen reads it: what happened,
		// what it was worth, and the two HUD figures it moved. Capital and
		// streak change with no page load (WCAG 4.1.3) and are spoken here.
		H.say( region, H.t( outcomeKey( result ) ) + ' — ' + H.t( titleKey( result ) )
			+ ' ' + H.signed( result.pnl ) + '. ' + H.t( 'capital_label' ) + ' ' + H.money( result.cap_after )
			+ '. ' + H.t( 'streak_label' ) + ' ' + result.streak + '.' );

		H.track( 'game_result', GAME + '_' + result.outcome );

		if ( 'pass' === result.decision ) {
			// A pass has no position to walk, so there is nothing to animate:
			// the outcome is simply revealed.
			state.reveal = state.outcome.length;
			draw();
			paintResult( result );
			return;
		}

		// Reduced motion removes the replay, so it removes the replay PHASE
		// too: entering it would focus a skip button for one frame and then
		// move focus again — two focus moves to show nothing.
		if ( H.reducedMotion() ) {
			state.reveal = result.touch_idx > 0
				? Math.min( result.touch_idx, state.outcome.length )
				: state.outcome.length;
			draw();
			paintResult( result );
			return;
		}

		go( 'replay' );
		set( 'position', H.t( 'buy' === result.decision ? 'stc_buy' : 'stc_sell' ) );
		set( 'position-risk', '−' + H.money( E.atRisk( result.cap_before, result.risk_bp, result.multiplier ) ) );
		replay( function () {
			paintResult( result );
		} );
	}

	/**
	 * Disable the buttons that would post a second decision.
	 *
	 * @param {boolean} locked Whether a request is in flight.
	 */
	function lockActions( locked ) {
		var buttons = root.querySelectorAll( '[data-hti-decide], [data-hti="risk-confirm"]' );
		var i;
		// Disabling the button somebody just pressed drops focus to <body> for
		// as long as the network takes. Park it on the phase heading; the next
		// go() takes it from there.
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
					var choice = button.getAttribute( 'data-hti-decide' );
					if ( 'pass' === choice ) {
						decide( 'pass' );
						return;
					}
					state.direction = choice;
					paintRisk();
					paintTable();
					draw();
					go( 'risk' );
				} );
			}( buttons[ i ] ) );
		}

		var group = H.hook( root, 'risk-group' );
		if ( group ) {
			riskGroup = H.radiogroup( group, function ( node ) {
				state.riskBp = parseInt( node.getAttribute( 'data-hti-risk' ), 10 ) || 200;
				paintRisk();
				if ( store ) {
					store.set( { phase: 'risk', direction: state.direction, risk_bp: state.riskBp, double: state.double } );
				}
			} );
		}

		var double = H.hook( root, 'double' );
		if ( double ) {
			double.addEventListener( 'click', function () {
				state.double = ! state.double;
				paintRisk();
			} );
		}

		var back = H.hook( root, 'risk-back' );
		if ( back ) {
			back.addEventListener( 'click', function () {
				state.direction = '';
				draw();
				paintTable();
				go( 'decide' );
			} );
		}

		var confirm = H.hook( root, 'risk-confirm' );
		if ( confirm ) {
			confirm.addEventListener( 'click', function () {
				decide( state.direction );
			} );
		}

		var skipButton = H.hook( root, 'skip' );
		if ( skipButton ) {
			skipButton.addEventListener( 'click', skip );
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
				// There is exactly one challenge a day, so "next day" asks the
				// server whether the day has turned rather than inventing one.
				load();
			} );
		}

		var redraw = 0;
		window.addEventListener( 'resize', function () {
			if ( redraw ) {
				return;
			}
			redraw = window.requestAnimationFrame( function () {
				redraw = 0;
				draw();
			} );
		} );

		window.addEventListener( 'pagehide', function () {
			takeover( false );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch today's challenge and put the screen where it belongs.
	 */
	function load() {
		H.say( region, H.t( 'st_loading' ) );

		H.api( '/' + GAME + '/today', { query: { lang: cfg.lang } } ).then( function ( data ) {
			state.day = data.day;
			state.candles = data.candles || [];
			state.atr = data.atr || 0;
			state.scale = data.tick_scale || cfg.config.tick_scale;
			state.entry = state.candles.length ? state.candles[ state.candles.length - 1 ][ 3 ] : 0;
			state.capital = data.capital;
			state.streak = data.streak;
			state.daysBefore = data.streak;
			state.record = ( ( data.player || {} ).stc || {} ).best_streak || 0;
			state.outcome = [];
			state.reveal = 0;
			state.result = null;
			state.direction = '';
			store = H.draft( GAME, state.day );

			colors = palette();
			paintHud();
			paintRisk();
			paintTable();
			draw();
			countdown( data.reset_in );
			H.say( region, '' );

			var player = data.player || {};
			if ( ! player.onboarded ) {
				// The cards replace the game while they are up, rather than
				// sitting above a live decide screen somebody could reach past
				// them with the keyboard.
				var phases = root.querySelector( '.hti-g__phases' );
				takeover( true );
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
			takeover( false );
			H.say( region, H.errorText( err ) );
			go( 'decide' );
			lockActions( true );
		} );
	}

	/**
	 * Where the screen goes once the challenge is in hand.
	 *
	 * A day already played is replayed FROM THE SERVER — the recorded run, not
	 * something the browser remembered — so a refresh after a decision shows
	 * the decision that was actually recorded.
	 *
	 * @param {Object} data The /today payload.
	 */
	function resume( data ) {
		if ( data.played && data.result ) {
			// The same final frame the replay would have stopped on: up to the
			// candle that touched a level, or the whole window when neither
			// was reached. A reload must not show more chart than the run did.
			var candles = ( data.result.outcome_candles || [] ).length;
			state.reveal = data.result.touch_idx > 0 ? Math.min( data.result.touch_idx, candles ) : candles;
			paintResult( data.result );
			return;
		}

		// Not played: restore only the in-flight interface state — which tile
		// was selected, which side was chosen — and nothing that could look
		// like a decision.
		var saved = store.get();
		if ( 'risk' === saved.phase && ( 'buy' === saved.direction || 'sell' === saved.direction ) ) {
			state.direction = saved.direction;
			state.double = !! saved.double;
			if ( saved.risk_bp && riskGroup ) {
				riskGroup.items.forEach( function ( node, i ) {
					if ( parseInt( node.getAttribute( 'data-hti-risk' ), 10 ) === saved.risk_bp ) {
						riskGroup.select( i, false );
					}
				} );
			}
			paintRisk();
			paintTable();
			draw();
			go( 'risk' );
			return;
		}

		go( 'decide' );
	}

	H.track( 'game_view', GAME );
	wire();
	load();
}() );
