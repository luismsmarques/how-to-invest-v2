/**
 * Account UI — "Save my profile" flow on the result, and the [hti_account]
 * dashboard (saved profiles, data export, account deletion).
 *
 * Native accounts via /register and /login; linking via /claim-profile;
 * RGPD via /export and /account. All requests carry the (refreshing) nonce.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var ctx = window.HTI_ACCT;
	if ( ! ctx ) {
		return;
	}
	var s = ctx.strings;
	var nonce = ctx.nonce;
	var loggedIn = !! ctx.isLoggedIn;

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	function track( name, params ) {
		if ( window.HTITrack ) {
			window.HTITrack.event( name, params );
		}
	}

	function request( path, method, body ) {
		return fetch( ctx.restBase + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			} );
		} );
	}

	/* ---------- password recovery (in-app) ---------- */

	// Styled "recover access" panel: email → POST /recover → "check your email".
	// Always shows the sent state (never reveals whether the account exists).
	function recoverPanel( prefillEmail, onBack ) {
		var panel = el( 'div', { class: 'hti-recover' } );
		var back = el( 'button', { type: 'button', class: 'hti-linkbtn hti-recover__back' }, s.rec_back );
		back.addEventListener( 'click', onBack );
		panel.appendChild( back );
		var inner = el( 'div', { class: 'hti-recover__inner' } );
		panel.appendChild( inner );

		function showSent( em ) {
			inner.innerHTML = '';
			var check = el( 'span', { class: 'hti-recover__check', 'aria-hidden': 'true' }, '✓' );
			inner.appendChild( check );
			inner.appendChild( el( 'h2', { class: 'hti-recover__title' }, s.rec_sent_title ) );
			inner.appendChild( el( 'p', { class: 'hti-recover__body' }, ( s.rec_sent_body || '' ).replace( '%s', em ) ) );
			var done = el( 'button', { type: 'button', class: 'hti-btn hti-btn-primary hti-recover__send' }, s.rec_back_login );
			done.addEventListener( 'click', onBack );
			inner.appendChild( done );
		}

		function showForm() {
			inner.innerHTML = '';
			inner.appendChild( el( 'h2', { class: 'hti-recover__title' }, s.rec_title ) );
			inner.appendChild( el( 'p', { class: 'hti-recover__body' }, s.rec_body ) );
			var input = el( 'input', { type: 'email', class: 'hti-recover__input', placeholder: s.rec_ph, 'aria-label': s.rec_email } );
			if ( prefillEmail ) {
				input.value = prefillEmail;
			}
			inner.appendChild( input );
			var send = el( 'button', { type: 'button', class: 'hti-btn hti-btn-primary hti-recover__send' }, s.rec_send );
			var status = el( 'p', { class: 'hti-recover__status', role: 'status', 'aria-live': 'polite' } );
			inner.appendChild( send );
			inner.appendChild( status );
			send.addEventListener( 'click', function () {
				var em = ( input.value || '' ).trim();
				if ( ! em || em.indexOf( '@' ) < 1 ) {
					status.textContent = s.rec_invalid;
					return;
				}
				send.disabled = true;
				status.textContent = s.working;
				request( '/recover', 'POST', { email: em } ).then( function () {
					showSent( em );
				} ).catch( function () {
					showSent( em );
				} );
			} );
			input.focus();
		}

		showForm();
		return panel;
	}

	/* ---------- shared auth form ---------- */

	function googleButton( extraRegister ) {
		if ( ! ctx.google || ! ctx.google.enabled ) {
			return null;
		}
		var wrap = el( 'div', { class: 'hti-google-wrap' } );
		var btn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost hti-google-btn' }, s.google );
		btn.addEventListener( 'click', function () {
			var token = ( extraRegister && extraRegister.session_token ) || '';
			window.location.href = ctx.google.start
				+ '&token=' + encodeURIComponent( token )
				+ '&locale=' + encodeURIComponent( ctx.locale || 'en' );
		} );
		wrap.appendChild( btn );
		wrap.appendChild( el( 'p', { class: 'hti-auth-or' }, s.or ) );
		return wrap;
	}

	// callbacks: { onLogin, onPending }. extraRegister is merged into /register.
	function authForm( callbacks, extraRegister ) {
		var container = el( 'div', { class: 'hti-auth-wrap' } );
		var google = googleButton( extraRegister );
		if ( google ) {
			container.appendChild( google );
		}

		var form = el( 'form', { class: 'hti-auth' } );

		var emailId = 'hti-email-' + Math.random().toString( 36 ).slice( 2 );
		var passId = 'hti-pass-' + Math.random().toString( 36 ).slice( 2 );

		form.appendChild( el( 'label', { for: emailId }, s.email ) );
		var email = el( 'input', { type: 'email', id: emailId, required: 'required', autocomplete: 'email' } );
		form.appendChild( email );

		form.appendChild( el( 'label', { for: passId }, s.password ) );
		var pass = el( 'input', { type: 'password', id: passId, required: 'required', autocomplete: 'current-password', minlength: '8' } );
		form.appendChild( pass );

		var err = el( 'p', { class: 'hti-error', role: 'alert', hidden: 'hidden' } );
		form.appendChild( err );

		var actions = el( 'div', { class: 'hti-auth-actions' } );
		var create = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-primary' }, s.create_account );
		var signin = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-ghost' }, s.sign_in );
		actions.appendChild( create );
		actions.appendChild( signin );
		form.appendChild( actions );

		if ( s.forgot ) {
			var forgot = el( 'p', { class: 'hti-auth-forgot' } );
			var fbtn = el( 'button', { type: 'button', class: 'hti-linkbtn' }, s.forgot );
			fbtn.addEventListener( 'click', function () {
				form.style.display = 'none';
				container.appendChild( recoverPanel( email.value, function () {
					var p = container.querySelector( '.hti-recover' );
					if ( p ) {
						container.removeChild( p );
					}
					form.style.display = '';
				} ) );
			} );
			forgot.appendChild( fbtn );
			form.appendChild( forgot );
		}

		var mode = 'register';
		create.addEventListener( 'click', function () { mode = 'register'; } );
		signin.addEventListener( 'click', function () { mode = 'login'; } );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			err.hidden = true;
			if ( ! email.value || pass.value.length < 8 ) {
				err.textContent = s.error;
				err.hidden = false;
				return;
			}

			if ( mode === 'register' ) {
				var body = { email: email.value, password: pass.value };
				if ( extraRegister ) {
					Object.keys( extraRegister ).forEach( function ( k ) { body[ k ] = extraRegister[ k ]; } );
				}
				request( '/register', 'POST', body ).then( function ( res ) {
					if ( res.ok ) {
						track( 'sign_up', { method: 'email' } );
						// Double opt-in: no sign-in yet — the user confirms by email.
						callbacks.onPending( ( res.data && res.data.message ) || s.check_email );
					} else {
						err.textContent = ( res.data && res.data.message ) || s.error;
						err.hidden = false;
					}
				} ).catch( function () {
					err.textContent = s.error;
					err.hidden = false;
				} );
				return;
			}

			request( '/login', 'POST', { login: email.value, password: pass.value } ).then( function ( res ) {
				if ( res.ok && res.data && res.data.nonce ) {
					nonce = res.data.nonce;
					loggedIn = true;
					track( 'login', { method: 'email' } );
					callbacks.onLogin();
				} else {
					err.textContent = ( res.data && res.data.message ) || s.error;
					err.hidden = false;
				}
			} ).catch( function () {
				err.textContent = s.error;
				err.hidden = false;
			} );
		} );

		container.appendChild( form );
		return container;
	}

	/* ---------- save flow (on the result) ---------- */

	function mountSave( container, sessionToken ) {
		container.innerHTML = '';
		track( 'save_profile_start', { logged_in: loggedIn ? 1 : 0 } );
		var box = el( 'section', { class: 'hti-save' } );
		box.appendChild( el( 'h3', null, s.save_profile ) );

		function claim() {
			var status = el( 'p', { class: 'hti-save-status', role: 'status' }, s.working );
			box.appendChild( status );
			request( '/claim-profile', 'POST', { session_token: sessionToken } ).then( function ( res ) {
				box.innerHTML = '';
				if ( res.ok ) {
					track( 'save_profile', {} );
					box.appendChild( el( 'p', { class: 'hti-save-done' }, s.saved ) );
					box.appendChild( el( 'a', { class: 'hti-btn hti-btn-secondary', href: ctx.accountUrl }, s.view_profiles ) );
				} else {
					box.appendChild( el( 'p', { class: 'hti-error', role: 'alert' }, ( res.data && res.data.message ) || s.error ) );
				}
			} ).catch( function () {
				status.textContent = s.error;
			} );
		}

		function showPending( msg ) {
			box.innerHTML = '';
			box.appendChild( el( 'h3', null, s.save_profile ) );
			box.appendChild( el( 'p', { class: 'hti-save-done', role: 'status' }, msg || s.check_email ) );
		}

		if ( loggedIn ) {
			var btn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-primary' }, s.save_profile );
			btn.addEventListener( 'click', function () {
				box.removeChild( btn );
				claim();
			} );
			box.appendChild( btn );
		} else {
			box.appendChild( el( 'p', { class: 'hti-save-intro' }, s.save_intro ) );
			box.appendChild( authForm(
				{ onLogin: claim, onPending: showPending },
				{ session_token: sessionToken, locale: ctx.locale }
			) );
		}

		container.appendChild( box );
	}

	/* ---------- account email section ---------- */

	function emailSection() {
		var box = el( 'div', { class: 'hti-account-email' } );
		box.appendChild( el( 'h3', null, s.account_email ) );

		var row = el( 'div', { class: 'hti-account-email__row' } );
		row.appendChild( el( 'span', { class: 'hti-account-email__current' }, ctx.email || '' ) );
		var changeBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost' }, s.change_email );
		row.appendChild( changeBtn );
		box.appendChild( row );

		var status = el( 'p', { class: 'hti-account-email__status', role: 'status' } );
		box.appendChild( status );

		changeBtn.addEventListener( 'click', function () {
			if ( box.querySelector( '.hti-account-email__form' ) ) {
				return;
			}
			changeBtn.style.display = 'none';
			var form = el( 'form', { class: 'hti-account-email__form' } );
			var input = el( 'input', { type: 'email', class: 'hti-input', placeholder: s.new_email, 'aria-label': s.new_email, required: 'required' } );
			var save = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-secondary' }, s.save );
			var cancel = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost' }, s.cancel );
			form.appendChild( input );
			form.appendChild( save );
			form.appendChild( cancel );
			box.insertBefore( form, status );

			cancel.addEventListener( 'click', function () {
				form.remove();
				changeBtn.style.display = '';
			} );

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var email = ( input.value || '' ).trim();
				if ( ! email || email.indexOf( '@' ) < 1 ) {
					status.textContent = s.error;
					return;
				}
				save.disabled = true;
				status.textContent = s.working;
				request( '/change-email', 'POST', { new_email: email } ).then( function ( res ) {
					if ( res.ok ) {
						form.remove();
						status.textContent = s.email_pending;
					} else {
						status.textContent = ( res.data && res.data.message ) || s.error;
						save.disabled = false;
					}
				} ).catch( function () {
					status.textContent = s.error;
					save.disabled = false;
				} );
			} );
		} );

		return box;
	}

	/* ---------- email preferences section ---------- */

	function prefsSection() {
		var prefs = ctx.prefs || { newsletter: false, frequency: 'weekly', categories: [] };
		var cats = ctx.categories || [];

		var box = el( 'div', { class: 'hti-account-prefs' } );
		box.appendChild( el( 'h3', null, s.preferences ) );
		var form = el( 'form', { class: 'hti-account-prefs__form' } );

		// Newsletter toggle.
		var nlLabel = el( 'label', { class: 'hti-account-prefs__check' } );
		var nl = el( 'input', { type: 'checkbox' } );
		if ( prefs.newsletter ) { nl.checked = true; }
		nlLabel.appendChild( nl );
		nlLabel.appendChild( el( 'span', null, ' ' + s.pref_newsletter ) );
		form.appendChild( nlLabel );

		// Frequency.
		var freqWrap = el( 'label', { class: 'hti-account-prefs__field' }, s.pref_frequency + ' ' );
		var freq = el( 'select', null );
		[ [ 'weekly', s.pref_weekly ], [ 'daily', s.pref_daily ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ] }, o[ 1 ] );
			if ( prefs.frequency === o[ 0 ] ) { opt.selected = true; }
			freq.appendChild( opt );
		} );
		freqWrap.appendChild( freq );
		form.appendChild( freqWrap );

		// Categories.
		var checks = [];
		if ( cats.length ) {
			var catWrap = el( 'fieldset', { class: 'hti-account-prefs__cats' } );
			catWrap.appendChild( el( 'legend', null, s.pref_categories ) );
			cats.forEach( function ( c ) {
				var lab = el( 'label', { class: 'hti-account-prefs__check' } );
				var cb = el( 'input', { type: 'checkbox', value: c.slug } );
				if ( prefs.categories && prefs.categories.indexOf( c.slug ) > -1 ) { cb.checked = true; }
				checks.push( cb );
				lab.appendChild( cb );
				lab.appendChild( el( 'span', null, ' ' + c.name ) );
				catWrap.appendChild( lab );
			} );
			form.appendChild( catWrap );
		}

		var save = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-secondary' }, s.save );
		form.appendChild( save );
		var status = el( 'p', { class: 'hti-account-email__status', role: 'status' } );
		form.appendChild( status );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save.disabled = true;
			status.textContent = s.working;
			var selected = checks.filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } );
			request( '/preferences', 'POST', {
				newsletter: nl.checked,
				frequency: freq.value,
				categories: selected
			} ).then( function ( res ) {
				status.textContent = res.ok ? s.prefs_saved : s.error;
				save.disabled = false;
			} ).catch( function () {
				status.textContent = s.error;
				save.disabled = false;
			} );
		} );

		box.appendChild( form );
		return box;
	}

	/* ---------- onboarding ---------- */

	function onboardingPanel( mount ) {
		var box = el( 'div', { class: 'hti-onboarding' } );
		box.appendChild( el( 'h2', { class: 'hti-onboarding__title' }, s.onb_title ) );
		var form = el( 'form', { class: 'hti-onboarding__form' } );

		// Language.
		var langField = el( 'fieldset', { class: 'hti-onboarding__field' } );
		langField.appendChild( el( 'legend', null, s.onb_lang ) );
		var current = ( ctx.pageLocale === 'pt' ) ? 'pt' : 'en';
		[ [ 'en', s.onb_en ], [ 'pt', s.onb_pt ] ].forEach( function ( o ) {
			var lab = el( 'label', { class: 'hti-onboarding__radio' } );
			var r = el( 'input', { type: 'radio', name: 'hti_lang', value: o[ 0 ] } );
			if ( o[ 0 ] === current ) { r.checked = true; }
			lab.appendChild( r );
			lab.appendChild( el( 'span', null, ' ' + o[ 1 ] ) );
			langField.appendChild( lab );
		} );
		form.appendChild( langField );

		// Newsletter + frequency.
		var nlLabel = el( 'label', { class: 'hti-onboarding__check' } );
		var nl = el( 'input', { type: 'checkbox' } );
		nl.checked = true;
		nlLabel.appendChild( nl );
		nlLabel.appendChild( el( 'span', null, ' ' + s.onb_nl ) );
		form.appendChild( nlLabel );

		var freqWrap = el( 'label', { class: 'hti-onboarding__field' }, s.pref_frequency + ' ' );
		var freq = el( 'select', null );
		[ [ 'weekly', s.pref_weekly ], [ 'daily', s.pref_daily ] ].forEach( function ( o ) {
			freq.appendChild( el( 'option', { value: o[ 0 ] }, o[ 1 ] ) );
		} );
		freqWrap.appendChild( freq );
		form.appendChild( freqWrap );

		// Open question.
		var qWrap = el( 'div', { class: 'hti-onboarding__field' } );
		qWrap.appendChild( el( 'label', { for: 'hti-onb-q' }, s.onb_q_label ) );
		var q = el( 'textarea', { id: 'hti-onb-q', rows: '3', placeholder: s.onb_q_ph } );
		qWrap.appendChild( q );
		form.appendChild( qWrap );

		var save = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-primary' }, s.onb_finish );
		form.appendChild( save );
		var status = el( 'p', { class: 'hti-account-email__status', role: 'status' } );
		form.appendChild( status );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save.disabled = true;
			status.textContent = s.working;
			var lang = ( form.querySelector( 'input[name="hti_lang"]:checked' ) || {} ).value || 'en';
			request( '/onboarding', 'POST', {
				language: lang,
				newsletter: nl.checked,
				frequency: freq.value,
				question: q.value
			} ).then( function ( res ) {
				if ( res.ok ) {
					track( 'onboarding_complete', {
						chosen_language: lang,
						newsletter: nl.checked ? freq.value : 'none'
					} );
					ctx.onboarded = true;
					var go = res.data && res.data.redirect;
					if ( go && lang !== ctx.pageLocale ) {
						window.location.href = go;
					} else {
						renderDashboard( mount );
					}
				} else {
					status.textContent = s.error;
					save.disabled = false;
				}
			} ).catch( function () {
				status.textContent = s.error;
				save.disabled = false;
			} );
		} );

		box.appendChild( form );
		return box;
	}

	/* ---------- dashboard ([hti_account]) ---------- */

	function verifyBanner() {
		var params = new URLSearchParams( window.location.search );
		if ( params.get( 'verified' ) === '1' ) {
			return el( 'div', { class: 'hti-save-done', role: 'status' }, s.verified );
		}
		if ( params.get( 'verify_error' ) === '1' ) {
			return el( 'div', { class: 'hti-error', role: 'alert' }, s.verify_error );
		}
		if ( params.get( 'email_changed' ) === '1' ) {
			return el( 'div', { class: 'hti-save-done', role: 'status' }, s.email_changed );
		}
		if ( params.get( 'email_error' ) === '1' ) {
			return el( 'div', { class: 'hti-error', role: 'alert' }, s.email_error );
		}
		if ( params.get( 'delete_cancelled' ) === '1' ) {
			return el( 'div', { class: 'hti-save-done', role: 'status' }, s.deletion_off );
		}
		if ( params.get( 'delete_error' ) === '1' ) {
			return el( 'div', { class: 'hti-error', role: 'alert' }, s.email_error );
		}
		return null;
	}

	// Benefit panel shown next to the sign-in / create-account form for guests:
	// "sell" the value of a free account (handoff_9 "Conta gratuita" aside).
	function guestValuePanel() {
		var aside = el( 'aside', { class: 'hti-acct-value', 'aria-label': s.acc_guest_title } );
		aside.appendChild( el( 'span', { class: 'hti-acct-value__eyebrow' }, s.acc_guest_eyebrow ) );
		aside.appendChild( el( 'h2', { class: 'hti-acct-value__h' }, s.acc_guest_title ) );
		aside.appendChild( el( 'p', { class: 'hti-acct-value__intro' }, s.acc_guest_intro ) );

		var rows = el( 'div', { class: 'hti-acct-value__rows' } );
		var benefits = [
			{ tone: 'coral', t: s.acc_guest_b1_t, d: s.acc_guest_b1_d, svg: '<path d="M12 21a9 9 0 1 0-9-9"/><path d="M12 12V3a9 9 0 0 1 9 9z"/>' },
			{ tone: 'green', t: s.acc_guest_b2_t, d: s.acc_guest_b2_d, svg: '<circle cx="12" cy="9" r="6"/><path d="M9 14.5 8 22l4-2 4 2-1-7.5"/>' },
			{ tone: 'purple', t: s.acc_guest_b3_t, d: s.acc_guest_b3_d, svg: '<path d="M21 12a9 9 0 0 1-9 9"/><path d="M3 12a9 9 0 0 1 9-9"/><path d="M21 5v4h-4M3 19v-4h4"/>' },
			{ tone: 'blue', t: s.acc_guest_b4_t, d: s.acc_guest_b4_d, svg: '<path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/>' }
		];
		benefits.forEach( function ( b ) {
			var row = el( 'div', { class: 'hti-acct-value__row' } );
			var ic = el( 'span', { class: 'hti-acct-value__ic is-' + b.tone, 'aria-hidden': 'true' } );
			ic.innerHTML = '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + b.svg + '</svg>';
			row.appendChild( ic );
			var body = el( 'div', { class: 'hti-acct-value__txt' } );
			body.appendChild( el( 'div', { class: 'hti-acct-value__t' }, b.t ) );
			body.appendChild( el( 'div', { class: 'hti-acct-value__d' }, b.d ) );
			row.appendChild( body );
			rows.appendChild( row );
		} );
		aside.appendChild( rows );
		return aside;
	}

	function renderDashboard( mount ) {
		mount.innerHTML = '';
		var root = el( 'div', { class: 'hti-account' } );

		var banner = verifyBanner();
		if ( banner ) {
			root.appendChild( banner );
		}

		// First-run onboarding (language + newsletter + open question).
		// Note: wp_localize_script stringifies booleans, so false arrives as "".
		if ( loggedIn && ! ctx.onboarded ) {
			root.appendChild( onboardingPanel( mount ) );
			mount.appendChild( root );
			return;
		}

		if ( ! loggedIn ) {
			var guest = el( 'div', { class: 'hti-acct-guest' } );

			var gAuth = el( 'div', { class: 'hti-acct-guest__form' } );
			gAuth.appendChild( el( 'h2', { class: 'hti-acct-guest__h' }, s.my_profiles ) );
			gAuth.appendChild( el( 'p', { class: 'hti-acct-guest__sub' }, s.signin_to_view ) );
			gAuth.appendChild( authForm(
				{
					onLogin: function () { renderDashboard( mount ); },
					onPending: function ( msg ) {
						gAuth.appendChild( el( 'p', { class: 'hti-save-done', role: 'status' }, msg || s.check_email ) );
					}
				},
				{ locale: ctx.locale }
			) );
			guest.appendChild( gAuth );
			guest.appendChild( guestValuePanel() );

			root.appendChild( guest );
			mount.appendChild( root );
			return;
		}

		// --- Live region for async status (export, save, delete) ---
		var live = el( 'div', { class: 'hti-acct-live', role: 'status', 'aria-live': 'polite' } );
		root.appendChild( live );

		// --- Account header: avatar (email initial) + identity ---
		var email = ctx.email || '';
		var display = email ? email.split( '@' )[ 0 ] : '';
		var initial = ( display || email || '?' ).charAt( 0 ).toUpperCase();
		var head = el( 'div', { class: 'hti-acct-head' } );
		head.appendChild( el( 'span', { class: 'hti-acct-avatar', 'aria-hidden': 'true' }, initial ) );
		var headId = el( 'div', { class: 'hti-acct-head__id' } );
		if ( display ) { headId.appendChild( el( 'h1', { class: 'hti-acct-head__name' }, display ) ); }
		headId.appendChild( el( 'div', { class: 'hti-acct-head__email' }, email ) );
		head.appendChild( headId );
		root.appendChild( head );

		var colors = ctx.allocColors || {};
		var classLabels = ctx.classLabels || {};
		function donutConic( alloc ) {
			var acc = 0, stops = [];
			( alloc || [] ).forEach( function ( sl ) {
				var c = colors[ sl.class ] || '#D9CFE8';
				var to = acc + ( Number( sl.pct ) || 0 );
				stops.push( c + ' ' + acc + '% ' + to + '%' );
				acc = to;
			} );
			return acc > 0 ? 'conic-gradient(' + stops.join( ',' ) + ')' : '#F2E4DD';
		}
		function allocList( alloc ) {
			var ul = el( 'ul', { class: 'hti-acct-alloc' } );
			( alloc || [] ).forEach( function ( sl ) {
				var li = el( 'li', { class: 'hti-acct-alloc__i' } );
				var sw = el( 'span', { class: 'hti-acct-alloc__sw', 'aria-hidden': 'true' } );
				sw.style.background = colors[ sl.class ] || '#D9CFE8';
				li.appendChild( sw );
				li.appendChild( el( 'span', { class: 'hti-acct-alloc__l' }, classLabels[ sl.class ] || sl.class ) );
				li.appendChild( el( 'span', { class: 'hti-acct-alloc__p' }, ( Number( sl.pct ) || 0 ) + '%' ) );
				ul.appendChild( li );
			} );
			return ul;
		}

		var hub = el( 'div', { class: 'hti-acct-hub' } );

		// ===== ZONE 1 · investor profile =====
		var z1 = el( 'div', { class: 'hti-acct-zone' } );
		z1.appendChild( el( 'h2', { class: 'hti-acct-eyebrow' }, s.acc_profile_eyebrow ) );
		var pcard = el( 'div', { class: 'hti-acct-card hti-acct-pcard' } );
		pcard.appendChild( el( 'p', { role: 'status', class: 'hti-acct-muted' }, s.working ) );
		z1.appendChild( pcard );
		hub.appendChild( z1 );

		request( '/my-profiles', 'GET' ).then( function ( res ) {
			pcard.innerHTML = '';
			var profiles = ( res.data && res.data.profiles ) || [];
			if ( ! profiles.length ) {
				pcard.className = 'hti-acct-card hti-acct-pcard hti-acct-empty';
				var t = el( 'div' );
				t.appendChild( el( 'div', { class: 'hti-acct-empty__t' }, s.acc_noprofile_t ) );
				t.appendChild( el( 'p', { class: 'hti-acct-empty__b' }, s.acc_noprofile_b ) );
				pcard.appendChild( t );
				pcard.appendChild( el( 'a', { href: ctx.resultBase || '#', class: 'hti-acct-btn hti-acct-btn--primary' }, s.acc_discover ) );
				return;
			}
			var p = profiles[ 0 ];
			var alloc = p.allocation || [];
			var prow = el( 'div', { class: 'hti-acct-pcard__row' } );
			var donut = el( 'div', { class: 'hti-acct-donut', 'aria-hidden': 'true' } );
			donut.style.background = donutConic( alloc );
			donut.appendChild( el( 'span', { class: 'hti-acct-donut__c' }, s.acc_by_class ) );
			prow.appendChild( donut );
			var body = el( 'div', { class: 'hti-acct-pcard__body' } );
			if ( p.generated_at ) { body.appendChild( el( 'span', { class: 'hti-acct-pcard__saved' }, s.acc_saved + ' · ' + String( p.generated_at ).slice( 0, 10 ) ) ); }
			var label = ( p.archetype && p.archetype.label ) || ( s.archetype + ' ' + ( p.archetype && p.archetype.id ) );
			body.appendChild( el( 'div', { class: 'hti-acct-pcard__name' }, label ) );
			var desc = ctx.archDesc && p.archetype && ctx.archDesc[ p.archetype.id ];
			if ( desc ) { body.appendChild( el( 'p', { class: 'hti-acct-pcard__desc' }, desc ) ); }
			body.appendChild( allocList( alloc ) );
			prow.appendChild( body );
			pcard.appendChild( prow );
			pcard.appendChild( el( 'p', { class: 'hti-acct-illus' }, s.acc_illustrative ) );
			var acts = el( 'div', { class: 'hti-acct-pcard__acts' } );
			if ( ctx.resultBase && p.profile_id ) { acts.appendChild( el( 'a', { href: ctx.resultBase + '?profile=' + encodeURIComponent( p.profile_id ), class: 'hti-acct-btn hti-acct-btn--primary' }, s.acc_view_result ) ); }
			acts.appendChild( el( 'a', { href: ctx.resultBase || '#', class: 'hti-acct-btn hti-acct-btn--outline' }, s.acc_redo ) );
			pcard.appendChild( acts );
		} ).catch( function () {
			pcard.innerHTML = '';
			pcard.appendChild( el( 'p', { class: 'hti-error' }, s.error ) );
		} );

		// ===== ZONE 2 · learning =====
		var L = ctx.learn;
		if ( L && L.enabled ) {
			var z2 = el( 'div', { class: 'hti-acct-zone' } );
			z2.appendChild( el( 'h2', { class: 'hti-acct-eyebrow' }, s.acc_learn_eyebrow ) );
			var lc = el( 'div', { class: 'hti-acct-card hti-acct-learn' } );
			var ltop = el( 'div', { class: 'hti-acct-learn__top' } );
			var lti = el( 'div' );
			lti.appendChild( el( 'span', { class: 'hti-acct-learn__path' }, s.acc_learn_path ) );
			lti.appendChild( el( 'div', { class: 'hti-acct-learn__count' }, ( s.acc_chapters_done || '%1$s / %2$s' ).replace( '%1$s', L.done ).replace( '%2$s', L.total ) ) );
			ltop.appendChild( lti );
			ltop.appendChild( el( 'span', { class: 'hti-acct-learn__pct' }, L.pct + '%' ) );
			lc.appendChild( ltop );
			var lbar = el( 'div', { class: 'hti-acct-learn__bar', role: 'progressbar', 'aria-label': s.acc_learn_eyebrow, 'aria-valuenow': String( L.pct ), 'aria-valuemin': '0', 'aria-valuemax': '100' } );
			var lfill = el( 'span', { class: 'hti-acct-learn__fill' } );
			lfill.style.width = L.pct + '%';
			lbar.appendChild( lfill );
			lc.appendChild( lbar );
			var brow = el( 'div', { class: 'hti-acct-badges' } );
			var CHECK = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
			var LOCK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
			( L.badges || [] ).forEach( function ( b ) {
				var item = el( 'div', { class: 'hti-acct-badge is-' + b.state } );
				var med = el( 'span', { class: 'hti-acct-badge__m', title: b.title } );
				if ( 'earned' === b.state ) { med.innerHTML = CHECK; }
				else if ( 'inprog' === b.state ) { med.textContent = b.num; }
				else { med.innerHTML = LOCK; }
				item.appendChild( med );
				item.appendChild( el( 'span', { class: 'hti-acct-badge__t' }, b.title ) );
				brow.appendChild( item );
			} );
			var course = el( 'div', { class: 'hti-acct-badge is-course' } );
			var cmed = el( 'span', { class: 'hti-acct-badge__m', title: s.acc_course } );
			cmed.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0z"/><path d="M5 5H3v1a3 3 0 0 0 3 3M19 5h2v1a3 3 0 0 1-3 3"/></svg>';
			course.appendChild( cmed );
			course.appendChild( el( 'span', { class: 'hti-acct-badge__t' }, s.acc_course ) );
			brow.appendChild( course );
			lc.appendChild( brow );
			var cont = el( 'a', { href: L.nextUrl || L.hubUrl || '#', class: 'hti-acct-continue' } );
			cont.appendChild( document.createTextNode( s.acc_continue_learning ) );
			if ( L.nextTitle ) { cont.appendChild( el( 'span', { class: 'hti-acct-continue__ch' }, L.nextTitle ) ); }
			lc.appendChild( cont );
			z2.appendChild( lc );
			hub.appendChild( z2 );
		}

		// ===== ZONE 3 · discover =====
		var z3 = el( 'div', { class: 'hti-acct-zone' } );
		z3.appendChild( el( 'h2', { class: 'hti-acct-eyebrow' }, s.acc_discover_eyebrow ) );
		var dgrid = el( 'div', { class: 'hti-acct-discover' } );
		var D = ctx.discover || {};
		var DISC = [
			{ url: D.comparador, ic: 'is-blue', svg: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>', t: s.acc_dc_comp_t, d: s.acc_dc_comp_d },
			{ url: D.glossary, ic: 'is-coral', svg: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 5.5V20.5"/></svg>', t: s.acc_dc_gloss_t, d: s.acc_dc_gloss_d },
			{ url: D.news, ic: 'is-purple', svg: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/></svg>', t: s.acc_dc_news_t, d: s.acc_dc_news_d },
			{ url: D.ebook, ic: 'is-green', svg: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/></svg>', t: s.acc_dc_ebook_t, d: s.acc_dc_ebook_d }
		];
		DISC.forEach( function ( c ) {
			var a = el( 'a', { href: c.url || '#', class: 'hti-acct-dc' } );
			var dic = el( 'span', { class: 'hti-acct-dc__ic ' + c.ic, 'aria-hidden': 'true' } );
			dic.innerHTML = c.svg;
			a.appendChild( dic );
			var dt = el( 'span', { class: 'hti-acct-dc__t' } );
			dt.appendChild( el( 'span', { class: 'hti-acct-dc__title' }, c.t ) );
			dt.appendChild( el( 'span', { class: 'hti-acct-dc__d' }, c.d ) );
			a.appendChild( dt );
			a.appendChild( el( 'span', { class: 'hti-acct-dc__open', 'aria-hidden': 'true' }, s.acc_dc_open ) );
			dgrid.appendChild( a );
		} );
		z3.appendChild( dgrid );
		hub.appendChild( z3 );

		// ===== ZONE 4 · data & settings =====
		var z4 = el( 'div', { class: 'hti-acct-zone' } );
		z4.appendChild( el( 'h2', { class: 'hti-acct-eyebrow' }, s.acc_data_settings ) );
		var rows = el( 'div', { class: 'hti-acct-rows' } );

		function dataRow( tag, attrs, svg, icMod, title, sub, rowMod ) {
			var b = el( tag, attrs );
			b.className = 'hti-acct-row' + ( rowMod ? ' ' + rowMod : '' );
			var ic = el( 'span', { class: 'hti-acct-row__ic ' + icMod, 'aria-hidden': 'true' } );
			ic.innerHTML = svg;
			b.appendChild( ic );
			var t = el( 'span', { class: 'hti-acct-row__t' } );
			t.appendChild( el( 'span', { class: 'hti-acct-row__title' }, title ) );
			if ( sub ) { t.appendChild( el( 'span', { class: 'hti-acct-row__sub' }, sub ) ); }
			b.appendChild( t );
			b.appendChild( el( 'span', { class: 'hti-acct-row__arrow', 'aria-hidden': 'true' }, '→' ) );
			return b;
		}

		var IC_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>';
		var IC_DL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7C5CFC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/></svg>';
		var IC_SHIELD = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/></svg>';
		var IC_TRASH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C0392B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/></svg>';

		// Newsletter — scrolls to the preferences card below.
		var nlRow = dataRow( 'button', { type: 'button' }, IC_MAIL, 'is-coral', s.preferences, s.pref_newsletter );
		nlRow.addEventListener( 'click', function () {
			var pc = root.querySelector( '.hti-account-prefs' );
			if ( pc && pc.scrollIntoView ) { pc.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
		} );
		rows.appendChild( nlRow );

		// Export (download JSON).
		var exportRow = dataRow( 'button', { type: 'button' }, IC_DL, 'is-purple', s.export_data, s.acc_export_sub );
		exportRow.addEventListener( 'click', function () {
			live.textContent = s.working;
			request( '/export', 'GET' ).then( function ( res ) {
				if ( ! res.ok ) { live.textContent = ''; return; }
				var blob = new Blob( [ JSON.stringify( res.data, null, 2 ) ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = el( 'a', { href: url, download: 'howtoinvest-data-export.json' } );
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
				live.textContent = '';
			} );
		} );
		rows.appendChild( exportRow );

		if ( ctx.policiesUrl ) {
			rows.appendChild( dataRow( 'a', { href: ctx.policiesUrl }, IC_SHIELD, 'is-coral', s.acc_privacy, s.acc_privacy_sub ) );
		}

		var deleteStatus = el( 'p', { class: 'hti-account-email__status', role: 'status' } );
		var deleteRow = dataRow( 'button', { type: 'button' }, IC_TRASH, 'is-danger', s.delete_account, s.acc_delete_sub, 'is-danger' );

		function renderDeletion( dateStr ) {
			z4.querySelectorAll( '.hti-deletion' ).forEach( function ( n ) { n.remove(); } );
			if ( dateStr ) {
				var wrap = el( 'div', { class: 'hti-deletion' } );
				wrap.appendChild( el( 'p', { class: 'hti-error', role: 'alert' }, s.delete_scheduled.replace( '%s', dateStr ) ) );
				var cancelBtn = el( 'button', { type: 'button', class: 'hti-acct-btn hti-acct-btn--outline hti-deletion__cancel' }, s.cancel_deletion );
				cancelBtn.addEventListener( 'click', function () {
					cancelBtn.disabled = true;
					request( '/cancel-deletion', 'POST', {} ).then( function ( res ) {
						if ( res.ok ) { renderDeletion( '' ); deleteStatus.textContent = s.deletion_off; live.textContent = s.deletion_off; deleteRow.style.display = ''; }
						else { cancelBtn.disabled = false; }
					} );
				} );
				wrap.appendChild( cancelBtn );
				z4.insertBefore( wrap, deleteStatus );
				deleteRow.style.display = 'none';
			}
		}

		deleteRow.addEventListener( 'click', function () {
			if ( ! window.confirm( s.delete_confirm ) ) { return; }
			request( '/account', 'DELETE', { confirm: true } ).then( function ( res ) {
				if ( res.ok && res.data ) {
					track( 'account_delete_request', {} );
					deleteStatus.textContent = s.deletion_set;
					live.textContent = s.deletion_set;
					renderDeletion( res.data.date || '' );
				}
			} );
		} );
		rows.appendChild( deleteRow );

		z4.appendChild( rows );
		z4.appendChild( deleteStatus );
		if ( ctx.deleteAt ) { renderDeletion( ctx.deleteAt ); }

		var settings = el( 'div', { class: 'hti-acct-settings' } );
		settings.appendChild( emailSection() );
		settings.appendChild( prefsSection() );
		z4.appendChild( settings );

		if ( ctx.logoutUrl ) {
			z4.appendChild( el( 'a', { href: ctx.logoutUrl, class: 'hti-acct-logout' }, s.sign_out ) );
		}
		hub.appendChild( z4 );

		root.appendChild( hub );
		mount.appendChild( root );
	}

	window.HTIAccount = { mountSave: mountSave };

	function boot() {
		var mount = document.getElementById( 'hti-account' );
		if ( mount ) {
			renderDashboard( mount );
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
