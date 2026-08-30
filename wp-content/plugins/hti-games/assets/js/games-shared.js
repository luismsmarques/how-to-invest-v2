/**
 * Everything both games need and neither owns: the API client, the copy
 * table, money formatting, the radiogroup, the onboarding cards, the share
 * card, and the leaderboard and profile screens.
 *
 * Exposed as window.HTIGames. stc.js and reveal.js are the game loops; this is
 * the furniture around them, and the two board screens which belong to neither
 * game.
 *
 * Three rules this file exists to keep:
 *
 * 1. NO COPY IS WRITTEN HERE. Every word comes from HTI_GAMES.strings, which
 *    is Strings::table() for the page's language. A sentence typed into a
 *    JavaScript file is a sentence that renders in English on the Portuguese
 *    site, silently, forever.
 * 2. The server is authoritative. Capital, streak, the outcome and the day are
 *    whatever the last response said; nothing here computes a balance and
 *    nothing here decides a day has changed.
 * 3. Every control is a real element with a real role. The canvas is an image.
 *
 * Educational, illustrative, virtual money only.
 *
 * @package HTI_Games
 */
( function () {
	'use strict';

	var cfg = window.HTI_GAMES;
	if ( ! cfg ) {
		return;
	}

	var S = cfg.strings || {};
	var L = cfg.labels || {};
	var PT = 'pt' === cfg.lang;

	/* ------------------------------------------------------------------ */
	/* Copy                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * One string from the table. Missing keys return '' rather than the key —
	 * an empty label is a gap somebody reports, a raw key is a bug shipped.
	 *
	 * @param {string} key Copy key.
	 * @return {string}
	 */
	function t( key ) {
		return S[ key ] || '';
	}

	/**
	 * Fill the %d / %s placeholders in a string, left to right.
	 *
	 * Several warnings carry the same number twice ("%d losses in a row and it
	 * is over. %d happens.") and one carries none at all, so a token past the
	 * end of the arguments repeats the last value rather than printing
	 * "undefined". `%%` is a literal percent — rev_confirm is "Commit %d%%".
	 *
	 * @param {string} str Template.
	 * @param {...*}   args Values.
	 * @return {string}
	 */
	function fmt( str ) {
		var args = [].slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%[ds%]/g, function ( token ) {
			if ( '%%' === token ) {
				return '%';
			}
			var v = i < args.length ? args[ i ] : args[ args.length - 1 ];
			i++;
			return null == v ? '' : String( v );
		} );
	}

	/**
	 * A whole-dollar amount, written the way the page's language writes it.
	 *
	 * Mirrors Frontend::money() and Seeder::money() exactly — the grouping is
	 * done by hand rather than with Intl so the separator is the same
	 * character PHP used, and a figure rendered here cannot differ from the
	 * same figure rendered server-side.
	 *
	 * @param {number} value Dollars.
	 * @return {string}
	 */
	function money( value ) {
		var n = Math.round( Math.abs( Number( value ) || 0 ) );
		var grouped = String( n ).replace( /\B(?=(\d{3})+(?!\d))/g, PT ? ' ' : ',' );
		var body = PT ? grouped + ' $' : '$' + grouped;
		return ( Number( value ) < 0 ? '−' : '' ) + body;
	}

	/**
	 * The same amount with an explicit sign, which is what a P&L needs.
	 * Zero is written plainly: "+$0" reads like a win.
	 *
	 * @param {number} value Dollars.
	 * @return {string}
	 */
	function signed( value ) {
		var n = Math.round( Number( value ) || 0 );
		if ( 0 === n ) {
			return money( 0 );
		}
		return ( n > 0 ? '+' : '−' ) + money( Math.abs( n ) );
	}

	/**
	 * A basis-point figure as the percentage a player reads. Mirrors
	 * Frontend::pct_label().
	 *
	 * @param {number} bp Basis points.
	 * @return {string}
	 */
	function pct( bp ) {
		var v = Math.round( Number( bp ) || 0 );
		if ( 0 === v % 100 ) {
			return ( v / 100 ) + '%';
		}
		return ( v / 100 ).toFixed( 1 ).replace( '.', PT ? ',' : '.' ) + '%';
	}

	/**
	 * Seconds as hh:mm:ss — digits only, so the countdown needs no words and
	 * reads the same in both languages.
	 *
	 * @param {number} seconds Seconds remaining.
	 * @return {string}
	 */
	function clock( seconds ) {
		var s = Math.max( 0, Math.floor( Number( seconds ) || 0 ) );
		var h = Math.floor( s / 3600 );
		var m = Math.floor( ( s % 3600 ) / 60 );
		var pad = function ( v ) {
			return ( v < 10 ? '0' : '' ) + v;
		};
		return pad( h ) + ':' + pad( m ) + ':' + pad( s % 60 );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	var UUID_KEY = 'hti_games_uuid';

	/**
	 * Read a key from a storage that may not exist, may be full, or may throw
	 * outright (Safari private mode). Never let that break a game.
	 *
	 * @param {Storage} store Storage object.
	 * @param {string}  key   Key.
	 * @return {string}
	 */
	function read( store, key ) {
		try {
			return store.getItem( key ) || '';
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Write a key, or quietly do nothing.
	 *
	 * @param {Storage} store Storage object.
	 * @param {string}  key   Key.
	 * @param {string}  value Value.
	 */
	function write( store, key, value ) {
		try {
			store.setItem( key, value );
		} catch ( e ) {}
	}

	/**
	 * The player uuid mirror.
	 *
	 * The identity cookie is HttpOnly and is the real one; this copy exists
	 * only so the X-HTI-Player header can carry it where a browser has thrown
	 * the cookie away (Safari's storage policy caps script-set cookies at
	 * seven days, and a daily game is exactly the thing that breaks). Nothing
	 * is decided from it: the server looks a player up by it, never trusts it
	 * to say who somebody is beyond that.
	 *
	 * @return {string}
	 */
	function uuid() {
		return read( window.localStorage, UUID_KEY );
	}

	/**
	 * Remember the uuid the server just handed back.
	 *
	 * @param {Object} data Any response carrying a `player`.
	 */
	function remember( data ) {
		if ( data && data.player && data.player.uuid ) {
			write( window.localStorage, UUID_KEY, data.player.uuid );
		}
	}

	/**
	 * The in-flight UI state, keyed by game and day.
	 *
	 * Only what the interface would lose on a refresh: which phase, which tile
	 * was selected. Never capital, never a result — a refresh mid-decision
	 * should not lose the tile, and must not be able to invent a run.
	 *
	 * @param {string} game Game id.
	 * @param {string} day  Day key.
	 * @return {{get:Function,set:Function,clear:Function}}
	 */
	function draft( game, day ) {
		var key = 'hti_g_' + game + '_' + day;
		return {
			get: function () {
				try {
					return JSON.parse( read( window.sessionStorage, key ) || '{}' ) || {};
				} catch ( e ) {
					return {};
				}
			},
			set: function ( value ) {
				write( window.sessionStorage, key, JSON.stringify( value || {} ) );
			},
			clear: function () {
				try {
					window.sessionStorage.removeItem( key );
				} catch ( e ) {}
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* Network                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * One request to the games API.
	 *
	 * Rejects with an Error carrying `status`, `code` and `payload` so callers
	 * can tell a 409 "you already played" (which carries the recorded result
	 * and is not an error at all from the player's side) from a 409 "the day
	 * moved" and from a genuine failure.
	 *
	 * @param {string} path Path under the games root, e.g. '/stc/today'.
	 * @param {Object} opts { method, body, query }.
	 * @return {Promise<Object>}
	 */
	function api( path, opts ) {
		opts = opts || {};

		var url = cfg.root + path;
		if ( opts.query ) {
			var parts = [];
			Object.keys( opts.query ).forEach( function ( k ) {
				parts.push( encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] ) );
			} );
			if ( parts.length ) {
				url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + parts.join( '&' );
			}
		}

		var headers = { 'X-WP-Nonce': cfg.nonce };
		var id = uuid();
		if ( id ) {
			headers[ 'X-HTI-Player' ] = id;
		}
		if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		return window.fetch( url, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( response.ok ) {
					remember( data );
					return data;
				}
				var err = new Error( 'hti_games_http_' + response.status );
				err.status = response.status;
				err.code = data && data.code ? data.code : '';
				err.serverMessage = data && data.message ? data.message : '';
				err.payload = ( data && data.data ) || {};
				throw err;
			} );
		}, function () {
			// A network that is not there. Distinguished from every HTTP
			// answer by status 0, because "the board is offline" and "the
			// server said no" are different sentences to the player.
			var err = new Error( 'hti_games_offline' );
			err.status = 0;
			err.code = 'offline';
			err.payload = {};
			throw err;
		} );
	}

	/**
	 * The sentence to show for a failed request.
	 *
	 * @param {Error} err Rejection from api().
	 * @return {string}
	 */
	function errorText( err ) {
		if ( ! err ) {
			return t( 'st_error' );
		}
		if ( 0 === err.status ) {
			return t( 'st_offline_body' );
		}
		if ( 429 === err.status ) {
			return t( 'st_rate_limited' );
		}
		if ( 503 === err.status ) {
			return t( 'st_no_content' );
		}
		if ( 'hti_game_already_played' === err.code ) {
			return t( 'already_played' );
		}
		if ( 'hti_game_day_moved' === err.code ) {
			return t( 'day_moved' );
		}
		return err.serverMessage || t( 'st_error' );
	}

	/* ------------------------------------------------------------------ */
	/* DOM                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Build an element.
	 *
	 * @param {string} tag   Tag name.
	 * @param {Object} attrs Attributes, or null.
	 * @param {string} text  Text content, or null.
	 * @return {Element}
	 */
	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( null != text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * A span only a screen reader reads.
	 *
	 * @param {string} text Text.
	 * @return {Element}
	 */
	function sr( text ) {
		return el( 'span', { class: 'hti-g__sr' }, text );
	}

	/**
	 * Every `data-hti` hook inside a root, by name.
	 *
	 * @param {Element} root Root element.
	 * @param {string}  name Hook name.
	 * @return {Element|null}
	 */
	function hook( root, name ) {
		return root ? root.querySelector( '[data-hti="' + name + '"]' ) : null;
	}

	/**
	 * Say something in a polite live region.
	 *
	 * Cleared first and set on the next tick, because setting a region to the
	 * text it already holds announces nothing — and "stopped out" twice in a
	 * row is a thing that happens.
	 *
	 * @param {Element} region Live region.
	 * @param {string}  text   What to say.
	 */
	function say( region, text ) {
		if ( ! region ) {
			return;
		}
		// One pending announcement per region: without this, a "loading"
		// scheduled 60ms ago still lands after the result that replaced it.
		if ( region.htiSayTimer ) {
			window.clearTimeout( region.htiSayTimer );
		}
		region.textContent = '';
		region.htiSayTimer = window.setTimeout( function () {
			region.htiSayTimer = 0;
			region.textContent = text;
		}, 60 );
	}

	/**
	 * Whether the visitor asked the system not to animate.
	 *
	 * @return {boolean}
	 */
	function reducedMotion() {
		try {
			return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Show one phase, hide the rest, and move focus into it.
	 *
	 * Focus management is not decoration here: a phase change replaces
	 * everything below the chart, and a keyboard or screen-reader user whose
	 * focus stayed on a button that no longer exists is returned to the top of
	 * the document by the browser.
	 *
	 * `moveFocus: false` is how the FIRST phase is entered: that one happens
	 * when GET /today answers, not because anybody asked, and a page that
	 * yanks focus out of the header an unpredictable moment after load is one
	 * you cannot use a keyboard on. The live region announces it instead.
	 * Every later phase change is something the player did, and takes focus.
	 *
	 * @param {Element} root      The game section.
	 * @param {string}  name      Phase name.
	 * @param {string}  focusSel  Optional selector for what to focus.
	 * @param {boolean} moveFocus Whether to move focus into the phase.
	 * @return {Element|null} The phase that is now showing.
	 */
	function phase( root, name, focusSel, moveFocus ) {
		var all = root.querySelectorAll( '[data-hti-phase]' );
		var i;
		for ( i = 0; i < all.length; i++ ) {
			all[ i ].hidden = all[ i ].getAttribute( 'data-hti-phase' ) !== name;
		}

		var target = root.querySelector( '[data-hti-phase="' + name + '"]' );
		if ( ! target ) {
			return null;
		}

		var focusable = false === moveFocus
			? null
			: ( focusSel ? target.querySelector( focusSel ) : target.querySelector( '[tabindex="-1"]' ) );
		if ( focusable ) {
			try {
				focusable.focus( { preventScroll: true } );
			} catch ( e ) {
				focusable.focus();
			}
		}

		return target;
	}

	/**
	 * Turn a set of `role="radio"` buttons into a radiogroup with roving
	 * tabindex — the pattern hti-engine's questionnaire.js already uses.
	 *
	 * Arrow keys move and select, Home and End jump to the ends, Space and
	 * Enter select. One tab stop for the whole group, which is what a radio
	 * group is.
	 *
	 * @param {Element}  group  Container with role="radiogroup".
	 * @param {Function} onPick Called with ( element, index ).
	 * @return {{select:Function,items:Array,index:Function}}
	 */
	function radiogroup( group, onPick ) {
		var items = [].slice.call( group.querySelectorAll( '[role="radio"]' ) );
		var current = 0;
		items.forEach( function ( node, i ) {
			if ( 'true' === node.getAttribute( 'aria-checked' ) ) {
				current = i;
			}
		} );

		function select( index, moveFocus ) {
			current = Math.max( 0, Math.min( items.length - 1, index ) );
			items.forEach( function ( node, i ) {
				var on = i === current;
				node.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				node.tabIndex = on ? 0 : -1;
				node.classList.toggle( 'is-on', on );
			} );
			if ( moveFocus && items[ current ] ) {
				items[ current ].focus();
			}
			if ( onPick ) {
				onPick( items[ current ], current );
			}
		}

		items.forEach( function ( node, i ) {
			node.addEventListener( 'click', function () {
				select( i, false );
			} );
			node.addEventListener( 'keydown', function ( event ) {
				var next = null;
				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					next = ( i + 1 ) % items.length;
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					next = ( i - 1 + items.length ) % items.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = items.length - 1;
				} else if ( ' ' === event.key || 'Enter' === event.key ) {
					event.preventDefault();
					select( i, true );
					return;
				}
				if ( null !== next ) {
					event.preventDefault();
					select( next, true );
				}
			} );
		} );

		return {
			select: select,
			items: items,
			index: function () {
				return current;
			}
		};
	}

	/**
	 * Count a game event, if hti-engine's tracker is around.
	 *
	 * Only `location` survives the beacon, so that is the only param sent.
	 *
	 * @param {string} name     Registered event name.
	 * @param {string} location Fixed detail label.
	 */
	function track( name, location ) {
		if ( window.HTITrack ) {
			window.HTITrack.event( name, { location: location } );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Onboarding                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * The three cards, and the acknowledgement that has to be ticked.
	 *
	 * Worded as an acknowledgement and never as consent: a box you must tick
	 * to play is not freely given, so it is not a lawful basis and does not
	 * pretend to be one. The newsletter box beside it is the opposite —
	 * separate, unticked, and genuinely optional — and only appears when the
	 * owner has switched it on.
	 *
	 * @param {Element}  root   Game section.
	 * @param {string}   game   Game id.
	 * @param {Function} onDone Called once the session exists.
	 */
	function onboarding( root, game, onDone ) {
		var stc = 'stc' === game;
		var prefix = stc ? 'stc_ob' : 'rev_ob';
		var ruleKeys = stc
			? [ 'stc_ob2_r1', 'stc_ob2_r2', 'stc_ob2_r3', 'stc_ob2_r4' ]
			: [ 'rev_ob2_r1', 'rev_ob2_r2', 'rev_ob2_r3', 'rev_ob2_r4' ];

		var cards = [
			{ kicker: prefix + '1_kicker', title: prefix + '1_title', body: prefix + '1_body' },
			{ kicker: prefix + '2_kicker', title: prefix + '2_title', rules: ruleKeys },
			// Survive the Charts has no third body of its own; the full
			// disclaimer is exactly the paragraph that card is for.
			{ kicker: prefix + '3_kicker', title: prefix + '3_title', body: stc ? 'disclaimer_full' : 'rev_ob3_body', ack: true }
		];

		var index = 0;
		var agreed = false;
		var sending = false;

		var panel = el( 'div', { class: 'hti-ob', role: 'group' } );
		var dots = el( 'p', { class: 'hti-ob__dots', 'aria-hidden': 'true' } );
		var kicker = el( 'p', { class: 'hti-g__kicker' } );
		var title = el( 'h2', { class: 'hti-ob__title', tabindex: '-1' } );
		var bodyNode = el( 'p', { class: 'hti-ob__body' } );
		var list = el( 'ol', { class: 'hti-g__ruleslist' } );
		var ackWrap = el( 'p', { class: 'hti-g__check' } );
		var ackLabel = el( 'label' );
		var ackBox = el( 'input', { type: 'checkbox' } );
		var newsWrap = el( 'p', { class: 'hti-g__check' } );
		var status = el( 'p', { class: 'hti-g__err', role: 'alert' } );
		var actions = el( 'div', { class: 'hti-g__actions' } );
		var back = el( 'button', { type: 'button', class: 'hti-g__btn hti-g__btn--ghost' }, t( 'cta_back' ) );
		var next = el( 'button', { type: 'button', class: 'hti-g__btn hti-g__btn--primary' } );
		var newsBox = null;

		ackLabel.appendChild( ackBox );
		ackLabel.appendChild( el( 'span', null, t( 'ob_ack' ) ) );
		ackWrap.appendChild( ackLabel );

		if ( cfg.flags && cfg.flags.newsletter ) {
			newsBox = el( 'input', { type: 'checkbox' } );
			var newsLabel = el( 'label' );
			newsLabel.appendChild( newsBox );
			newsLabel.appendChild( el( 'span', null, t( 'news_optin' ) ) );
			newsWrap.appendChild( newsLabel );
		}

		actions.appendChild( back );
		actions.appendChild( next );

		panel.appendChild( dots );
		panel.appendChild( kicker );
		panel.appendChild( title );
		panel.appendChild( bodyNode );
		panel.appendChild( list );
		panel.appendChild( ackWrap );
		panel.appendChild( newsWrap );
		panel.appendChild( status );
		panel.appendChild( actions );

		ackBox.addEventListener( 'change', function () {
			agreed = ackBox.checked;
			paint();
		} );
		back.addEventListener( 'click', function () {
			index = Math.max( 0, index - 1 );
			paint( true );
		} );
		next.addEventListener( 'click', function () {
			if ( index < cards.length - 1 ) {
				index++;
				paint( true );
				return;
			}
			if ( ! agreed || sending ) {
				return;
			}
			start();
		} );

		function paint( moveFocus ) {
			var card = cards[ index ];

			dots.textContent = cards.map( function ( unused, i ) {
				return i === index ? '●' : '○';
			} ).join( ' ' );

			kicker.textContent = t( card.kicker );
			title.textContent = t( card.title );
			bodyNode.textContent = card.body ? t( card.body ) : '';
			bodyNode.hidden = ! card.body;

			list.textContent = '';
			list.hidden = ! card.rules;
			( card.rules || [] ).forEach( function ( key ) {
				list.appendChild( el( 'li', null, t( key ) ) );
			} );

			ackWrap.hidden = ! card.ack;
			newsWrap.hidden = ! card.ack || ! newsBox;
			back.hidden = 0 === index;

			if ( index < cards.length - 1 ) {
				next.textContent = t( 'ob_next' );
				next.disabled = false;
			} else {
				next.textContent = agreed ? t( 'ob_accept' ) : t( 'ob_ack_gate' );
				next.disabled = ! agreed;
			}

			if ( moveFocus ) {
				title.focus();
			}
		}

		function start() {
			sending = true;
			status.textContent = t( 'st_loading' );

			api( '/session', {
				method: 'POST',
				body: {
					ack: true,
					lang: cfg.lang,
					newsletter: !! ( newsBox && newsBox.checked )
				}
			} ).then( function ( data ) {
				track( 'game_start', game );
				panel.remove();
				onDone( data );
			} ).catch( function ( err ) {
				sending = false;
				status.textContent = errorText( err );
			} );
		}

		paint();
		root.insertBefore( panel, root.querySelector( '.hti-g__phases' ) );
		// Announced, not focused: these cards arrive with GET /today, not at a
		// moment the reader chose, and the panel is next in the tab order
		// anyway. Turning a card IS their doing, and that moves focus.
		say( hook( root, 'say' ), t( cards[ 0 ].kicker ) + '. ' + t( cards[ 0 ].title ) );

		return panel;
	}

	/* ------------------------------------------------------------------ */
	/* Share                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * The share card.
	 *
	 * Deliberately no emoji grid. The handoff asks for a card with "no spoiler
	 * of direction or outcome" and then draws one out of green and red
	 * squares, which is the outcome — so the card carries the day, the
	 * balance and the streak, and gives today's chart away to nobody.
	 *
	 * @param {Element} root  Game section, for focus restoration.
	 * @param {Object}  card  { game, day, capital, streak, dead }.
	 */
	function share( root, card ) {
		if ( ! cfg.flags || ! cfg.flags.share ) {
			return;
		}

		var lines = [
			t( 'stc' === card.game ? 'stc_name' : 'rev_name' ),
			card.dead ? fmt( t( 'share_blown' ), card.day ) : fmt( t( 'share_day_done' ), card.day ),
			t( 'capital_label' ) + ': ' + money( card.capital ),
			t( 'streak_label' ) + ': ' + card.streak,
			t( 'share_no_spoiler' ),
			t( 'share_footer' )
		];

		var text = lines.join( '\n' );
		var previous = document.activeElement;

		var overlay = el( 'div', { class: 'hti-g__overlay' } );
		var dialog = el( 'div', {
			class: 'hti-g__dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-label': t( 'cta_share' )
		} );
		var pre = el( 'pre', { class: 'hti-g__sharecard', tabindex: '-1' }, text );
		var actions = el( 'div', { class: 'hti-g__actions' } );
		var close = el( 'button', { type: 'button', class: 'hti-g__btn hti-g__btn--ghost' }, t( 'cta_back' ) );
		var copy = el( 'button', { type: 'button', class: 'hti-g__btn hti-g__btn--primary' }, t( 'cta_copy_card' ) );
		var live = el( 'p', { class: 'hti-g__sr', role: 'status', 'aria-live': 'polite' } );

		actions.appendChild( close );
		actions.appendChild( copy );
		dialog.appendChild( pre );
		dialog.appendChild( actions );
		dialog.appendChild( live );
		overlay.appendChild( dialog );

		function dismiss() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKey, true );
			if ( previous && previous.focus ) {
				previous.focus();
			}
		}

		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				dismiss();
				return;
			}
			if ( 'Tab' !== event.key ) {
				return;
			}
			// A modal that lets Tab wander behind it is a modal in name only.
			var focusables = [ pre, close, copy ];
			var at = focusables.indexOf( document.activeElement );
			var to = event.shiftKey ? at - 1 : at + 1;
			if ( to < 0 || to >= focusables.length || at < 0 ) {
				event.preventDefault();
				focusables[ to < 0 ? focusables.length - 1 : 0 ].focus();
			}
		}

		close.addEventListener( 'click', dismiss );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				dismiss();
			}
		} );
		copy.addEventListener( 'click', function () {
			copyText( text ).then( function () {
				copy.textContent = t( 'copied' );
				say( live, t( 'copied' ) );
			} ).catch( function () {
				// Nothing to apologise for: the text is on screen and
				// selectable, which is the fallback.
				pre.focus();
			} );
		} );

		document.addEventListener( 'keydown', onKey, true );
		root.appendChild( overlay );
		pre.focus();
		track( 'game_share', card.game + ( card.dead ? '_dead' : '_day' ) );
	}

	/**
	 * Put text on the clipboard, with the old-fashioned fallback.
	 *
	 * @param {string} text Text.
	 * @return {Promise}
	 */
	function copyText( text ) {
		if ( window.navigator && window.navigator.clipboard ) {
			return window.navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = el( 'textarea', { class: 'hti-g__hp', readonly: 'readonly' } );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			area.remove();
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy_failed' ) );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Account forms (nickname, magic link, deletion)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Wire the nickname form inside a root, if there is one.
	 *
	 * @param {Element}  root     Section holding the form.
	 * @param {Object}   player   Player payload, may be null.
	 * @param {Function} onChange Called with the new nickname.
	 */
	function nicknameForm( root, player, onChange ) {
		var form = hook( root, 'nick-form' );
		if ( ! form ) {
			return;
		}

		var input = hook( root, 'nick-input' );
		var err = hook( root, 'nick-err' );

		form.hidden = false;
		if ( player && player.nickname ) {
			input.value = player.nickname;
		}

		// The board reloads its rows on every tab change and calls back in
		// here each time; without this the same submit would fire five times.
		if ( form.dataset.htiWired ) {
			return;
		}
		form.dataset.htiWired = '1';

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			err.textContent = '';

			api( '/nickname', {
				method: 'POST',
				body: { nickname: input.value }
			} ).then( function ( data ) {
				track( 'game_nickname_set', 'profile' );
				input.value = data.nickname || input.value;
				// A save that says nothing has not told anybody. Through the
				// error paragraph, which is already role="alert".
				err.classList.add( 'is-ok' );
				err.textContent = t( 'nick_saved' );
				if ( onChange ) {
					onChange( data );
				}
			} ).catch( function ( error ) {
				err.classList.remove( 'is-ok' );
				err.textContent = errorText( error );
				input.focus();
			} );
		} );
	}

	/**
	 * Wire the magic-link form.
	 *
	 * `consent` is sent as true because submitting this form IS the request
	 * for that one email — it is the transactional message the visitor asked
	 * for, not a marketing list. The marketing list is the separate, unticked
	 * checkbox beside it.
	 *
	 * @param {Element} root Section holding the form.
	 */
	function linkForm( root ) {
		var form = hook( root, 'link-form' );
		if ( ! form ) {
			return;
		}

		var input = hook( root, 'link-input' );
		var err = hook( root, 'link-err' );
		var news = hook( root, 'link-news' );
		var honey = hook( root, 'link-hp' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			err.textContent = '';
			track( 'game_link_request', 'profile' );

			api( '/link', {
				method: 'POST',
				body: {
					email: input.value,
					consent: true,
					newsletter: !! ( news && news.checked ),
					lang: cfg.lang,
					hti_hp: honey ? honey.value : ''
				}
			} ).then( function () {
				err.classList.add( 'is-ok' );
				err.textContent = t( 'link_sent' ) + ' — ' + t( 'link_sent_body' );
			} ).catch( function ( error ) {
				err.classList.remove( 'is-ok' );
				err.textContent = errorText( error );
				input.focus();
			} );
		} );
	}

	/**
	 * Wire the "delete my game data" button.
	 *
	 * @param {Element} root   Section holding the button.
	 * @param {Element} region Live region for the confirmation.
	 */
	function forgetForm( root, region ) {
		var button = hook( root, 'forget' );
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;

			api( '/me', { method: 'DELETE' } ).then( function () {
				try {
					window.localStorage.removeItem( UUID_KEY );
					window.sessionStorage.clear();
				} catch ( e ) {}
				say( region, t( 'forget_note' ) );
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			} ).catch( function ( error ) {
				button.disabled = false;
				say( region, errorText( error ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Leaderboard                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The board screen: two boards, two games, one pinned row.
	 *
	 * @param {Element} root Board section.
	 */
	function mountBoard( root ) {
		var rows = hook( root, 'board-rows' );
		var empty = hook( root, 'board-empty' );
		var me = hook( root, 'board-me' );
		var head = hook( root, 'board-head' );
		var status = hook( root, 'board-status' );
		var tabs = [].slice.call( root.querySelectorAll( '[data-hti-board]' ) );
		var gtabs = [].slice.call( root.querySelectorAll( '[data-hti-bgame]' ) );

		var board = 'daily';
		var game = gtabs.length ? gtabs[ 0 ].getAttribute( 'data-hti-bgame' ) : 'stc';

		function paintTabs() {
			var panel = root.querySelector( '#hti-board-panel' );
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-hti-board' ) === board;
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.tabIndex = on ? 0 : -1;
				tab.classList.toggle( 'is-on', on );
				// The panel has to name the tab that is actually selected, or a
				// screen reader announces the wrong board over the right rows.
				if ( on && panel ) {
					panel.setAttribute( 'aria-labelledby', tab.id );
				}
			} );
			gtabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-hti-bgame' ) === game;
				tab.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				tab.classList.toggle( 'is-on', on );
			} );
			// The survival board is one account across the section, not a
			// per-game ranking, so the game switch has nothing to switch.
			gtabs.forEach( function ( tab ) {
				tab.disabled = 'survival' === board;
			} );
			head.textContent = 'survival' === board ? t( 'board_survival' ) : t( 'board_score_head' );
		}

		function row( entry, isMe ) {
			var li = el( 'li', { class: 'hti-board__row' + ( isMe ? ' is-me' : '' ) } );

			var rank = el( 'span', { class: 'hti-board__rank hti-num' } );
			rank.appendChild( sr( L.lbl_rank + ' ' ) );
			rank.appendChild( document.createTextNode( entry.rank > 0 ? String( entry.rank ) : '—' ) );

			var name = el( 'span', { class: 'hti-board__name' } );
			name.appendChild( sr( L.lbl_player + ' ' ) );
			name.appendChild( document.createTextNode( entry.nickname || t( 'board_you' ) ) );
			if ( isMe ) {
				name.appendChild( sr( ' — ' + t( 'board_you' ) ) );
			}

			var value = el( 'span', { class: 'hti-board__value hti-num' } );
			if ( 'survival' === board ) {
				value.appendChild( sr( L.lbl_capital + ' ' ) );
				value.appendChild( document.createTextNode( money( entry.capital ) ) );
				value.appendChild( sr( ' · ' + t( 'streak_label' ) + ' ' ) );
				value.appendChild( el( 'span', { class: 'hti-board__streak' }, String( entry.streak || 0 ) ) );
			} else {
				value.appendChild( sr( t( 'board_score_head' ) + ' ' ) );
				value.appendChild( document.createTextNode( signed( entry.board_score ) ) );
				value.classList.add( entry.board_score > 0 ? 'is-up' : ( entry.board_score < 0 ? 'is-down' : 'is-flat' ) );
			}

			li.appendChild( rank );
			li.appendChild( name );
			li.appendChild( value );
			return li;
		}

		function load() {
			paintTabs();
			status.textContent = t( 'st_loading' );
			rows.textContent = '';
			me.textContent = '';
			me.hidden = true;

			api( '/leaderboard', { query: { game: game, board: board, lang: cfg.lang } } ).then( function ( data ) {
				var list = data.rows || [];
				// WCAG 4.1.3: a tab swaps every row with no page load and no
				// focus move, so the panel has to say what arrived.
				say( status, t( 'daily' === board ? 'board_today' : 'board_survival' )
					+ ' — ' + ( list.length ? fmt( t( 'st_rows' ), list.length ) : t( 'board_empty' ) ) );
				empty.hidden = list.length > 0;
				list.forEach( function ( entry ) {
					rows.appendChild( row( entry, false ) );
				} );
				if ( data.me ) {
					me.hidden = false;
					var ol = el( 'ol', { class: 'hti-board__rows' } );
					ol.appendChild( row( data.me, true ) );
					me.appendChild( ol );
				}
				nicknameForm( root, data.me && data.me.nickname ? { nickname: data.me.nickname } : null, load );
			} ).catch( function ( error ) {
				say( status, t( 'st_offline' ) + ' — ' + errorText( error ) );
				empty.hidden = true;
				var retry = el( 'button', { type: 'button', class: 'hti-g__btn hti-g__btn--ghost' }, t( 'st_retry' ) );
				retry.addEventListener( 'click', load );
				var slot = el( 'li', { class: 'hti-board__row hti-board__row--retry' } );
				slot.appendChild( retry );
				rows.appendChild( slot );
			} );
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				board = tab.getAttribute( 'data-hti-board' );
				track( 'game_board_view', game + '_' + board );
				load();
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;
				if ( 'ArrowRight' === event.key ) {
					next = ( i + 1 ) % tabs.length;
				} else if ( 'ArrowLeft' === event.key ) {
					next = ( i - 1 + tabs.length ) % tabs.length;
				}
				if ( null !== next ) {
					event.preventDefault();
					tabs[ next ].focus();
					tabs[ next ].click();
				}
			} );
		} );

		gtabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				game = tab.getAttribute( 'data-hti-bgame' );
				track( 'game_board_view', game + '_' + board );
				load();
			} );
		} );

		track( 'game_board_view', game + '_' + board );
		load();
	}

	/* ------------------------------------------------------------------ */
	/* Profile                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * The profile screen: the run, the learning metric, the calendar, and the
	 * two controls that let somebody leave.
	 *
	 * @param {Element} root Profile section.
	 */
	function mountProfile( root ) {
		var status = hook( root, 'profile-status' );
		var riskBox = hook( root, 'profile-risk' );
		var calBox = hook( root, 'profile-cal' );
		var badgeBlock = hook( root, 'profile-badgeblock' );
		var badgeBox = hook( root, 'profile-badges' );
		var gtabs = [].slice.call( root.querySelectorAll( '[data-hti-pgame]' ) );
		var game = gtabs.length ? gtabs[ 0 ].getAttribute( 'data-hti-pgame' ) : 'stc';
		var latest = null;

		function stat( name, value ) {
			var node = hook( root, 'stat-' + name );
			if ( node ) {
				node.textContent = value;
			}
		}

		/**
		 * The risk-per-week bars — the one chart in the whole section, and the
		 * only metric the game is actually trying to move.
		 *
		 * Weeks with no position are skipped rather than drawn at zero: a
		 * fortnight away from the game is not a collapse in risk appetite.
		 *
		 * @param {Array} weeks Scoring::risk_by_week() rows.
		 */
		function paintRisk( weeks ) {
			riskBox.textContent = '';
			var played = ( weeks || [] ).filter( function ( week ) {
				return week.runs > 0;
			} );
			if ( ! played.length ) {
				return;
			}

			var top = played.reduce( function ( max, week ) {
				return Math.max( max, week.average_bp );
			}, 1 );

			var list = el( 'ol', { class: 'hti-profile__bars' } );
			played.forEach( function ( week ) {
				var item = el( 'li', { class: 'hti-profile__bar' } );
				item.appendChild( el( 'span', { class: 'hti-profile__barvalue hti-num' }, pct( week.average_bp ) ) );
				var fill = el( 'span', { class: 'hti-profile__barfill' } );
				fill.style.height = Math.max( 4, Math.round( ( week.average_bp / top ) * 100 ) ) + '%';
				fill.classList.add( week.average_bp <= 100 ? 'is-up' : ( week.average_bp <= 200 ? 'is-brand' : ( week.average_bp <= 500 ? 'is-warn' : 'is-down' ) ) );
				item.appendChild( fill );
				item.appendChild( el( 'span', { class: 'hti-profile__barweek hti-num' }, week.to ) );
				list.appendChild( item );
			} );
			riskBox.appendChild( list );
		}

		/**
		 * Twenty-eight cells.
		 *
		 * Each cell's accessible name is its date plus what happened, and what
		 * happened is a number or an existing string — never a word invented
		 * here. A day with no run says only its date.
		 *
		 * @param {Array} days Scoring::calendar() rows.
		 */
		function paintCalendar( days ) {
			calBox.textContent = '';
			// Green and red alone leave this grid unreadable to anybody who
			// cannot separate them (WCAG 1.4.1), so each cell carries the
			// same sign signed() writes everywhere else. aria-hidden: the
			// cell already says its date and its figure in words.
			var marks = { won: '+', lost: '−', passed: '·', flat: '=' };
			( days || [] ).forEach( function ( day ) {
				var cell = el( 'li', { class: 'hti-profile__cell is-' + day.state } );
				var label = day.day;
				if ( 'passed' === day.state ) {
					label += ' — ' + t( 'stc_res_pass' );
				} else if ( 'missed' !== day.state ) {
					label += ' — ' + signed( day.pnl );
				}
				if ( marks[ day.state ] ) {
					cell.appendChild( el( 'span', { class: 'hti-profile__mark', 'aria-hidden': 'true' }, marks[ day.state ] ) );
				}
				cell.appendChild( sr( label ) );
				calBox.appendChild( cell );
			} );
		}

		/**
		 * The badges, but only the ones the copy table can name.
		 *
		 * Scoring::badges() returns keys and Strings carries a `badge_<key>`
		 * name for each of the eight, so in practice the filter keeps all of
		 * them and the block is shown. It stays because the two lists are
		 * maintained in different files: a ninth badge added to Scoring before
		 * anybody writes its Portuguese name would otherwise render as a blank
		 * chip, and no copy is ever invented in a JavaScript file.
		 *
		 * The one-line note each badge has in Strings (`badge_<key>_note`) is
		 * not shown: the chip is a name and a progress figure, and the note
		 * has no place in it that the design settled on.
		 *
		 * @param {Array} badges Scoring::badges() rows.
		 */
		function paintBadges( badges ) {
			badgeBox.textContent = '';
			var named = ( badges || [] ).filter( function ( badge ) {
				return !! t( 'badge_' + badge.key );
			} );
			badgeBlock.hidden = 0 === named.length;
			named.forEach( function ( badge ) {
				var item = el( 'li', { class: 'hti-profile__badge' + ( badge.earned ? ' is-on' : '' ) } );
				item.appendChild( el( 'span', { class: 'hti-profile__badgename' }, t( 'badge_' + badge.key ) ) );
				item.appendChild( el( 'span', { class: 'hti-profile__badgeprog hti-num' }, badge.progress + '/' + badge.target ) );
				badgeBox.appendChild( item );
			} );
		}

		function paint( data ) {
			latest = data;
			var player = data.player || {};
			var state = player[ 'stc' === game ? 'stc' : 'reveal' ] || {};
			var block = ( data.games || {} )[ game ] || {};
			var runs = block.runs || [];

			var staked = runs.filter( function ( run ) {
				return 'pass' !== run.decision;
			} );
			var won = staked.filter( function ( run ) {
				return run.pnl > 0;
			} );

			stat( 'capital', money( state.capital ) );
			stat( 'streak', String( state.streak || 0 ) );
			stat( 'record', String( state.best_streak || 0 ) );
			stat( 'winrate', staked.length ? Math.round( ( won.length / staked.length ) * 100 ) + '%' : '—' );

			paintRisk( block.risk_by_week );
			paintCalendar( block.calendar );
			paintBadges( block.badges );

			gtabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-hti-pgame' ) === game;
				tab.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				tab.classList.toggle( 'is-on', on );
			} );
		}

		function load() {
			status.textContent = t( 'st_loading' );

			api( '/profile', { query: { lang: cfg.lang } } ).then( function ( data ) {
				paint( data );
				nicknameForm( root, data.player, load );
				// Same as the board: every figure here just changed.
				say( status, t( 'profile_title' ) + ' — ' + t( 'st_updated' ) );
			} ).catch( function ( error ) {
				say( status, errorText( error ) );
			} );
		}

		gtabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				game = tab.getAttribute( 'data-hti-pgame' );
				if ( latest ) {
					paint( latest );
					say( status, tab.textContent + ' — ' + t( 'st_updated' ) );
				}
			} );
		} );

		linkForm( root );
		forgetForm( root, status );
		load();
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function boot() {
		var hub = document.querySelector( '[data-hti-hub]' );
		if ( hub ) {
			track( 'game_view', 'hub' );
		}

		var board = document.querySelector( '[data-hti-board-mount]' );
		if ( board ) {
			mountBoard( board );
		}

		var profile = document.querySelector( '[data-hti-profile-mount]' );
		if ( profile ) {
			mountProfile( profile );
		}
	}

	window.HTIGames = {
		cfg: cfg,
		t: t,
		fmt: fmt,
		money: money,
		signed: signed,
		pct: pct,
		clock: clock,
		el: el,
		sr: sr,
		hook: hook,
		say: say,
		phase: phase,
		radiogroup: radiogroup,
		reducedMotion: reducedMotion,
		track: track,
		api: api,
		errorText: errorText,
		draft: draft,
		onboarding: onboarding,
		share: share,
		labels: L
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
