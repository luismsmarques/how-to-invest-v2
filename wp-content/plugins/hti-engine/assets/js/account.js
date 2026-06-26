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
			root.appendChild( el( 'h2', null, s.my_profiles ) );
			root.appendChild( el( 'p', null, s.signin_to_view ) );
			root.appendChild( authForm(
				{
					onLogin: function () { renderDashboard( mount ); },
					onPending: function ( msg ) {
						root.appendChild( el( 'p', { class: 'hti-save-done', role: 'status' }, msg || s.check_email ) );
					}
				},
				{ locale: ctx.locale }
			) );
			mount.appendChild( root );
			return;
		}

		// --- Account header: avatar (email initial) + identity (E "Conta") ---
		var email = ctx.email || '';
		var display = email ? email.split( '@' )[ 0 ] : '';
		var initial = ( display || email || '?' ).charAt( 0 ).toUpperCase();
		var head = el( 'div', { class: 'hti-acct-head' } );
		head.appendChild( el( 'span', { class: 'hti-acct-avatar', 'aria-hidden': 'true' }, initial ) );
		var headId = el( 'div', { class: 'hti-acct-head__id' } );
		if ( display ) {
			headId.appendChild( el( 'h1', { class: 'hti-acct-head__name' }, display ) );
		}
		headId.appendChild( el( 'div', { class: 'hti-acct-head__email' }, email ) );
		head.appendChild( headId );
		root.appendChild( head );

		// --- Two-column grid: profile (left) · data/GDPR (right) ---
		var grid = el( 'div', { class: 'hti-acct-grid' } );

		// LEFT — saved investor profile.
		var pcol = el( 'div', { class: 'hti-acct-col' } );
		pcol.appendChild( el( 'span', { class: 'hti-acct-eyebrow' }, s.acc_profile_eyebrow ) );
		var pcard = el( 'div', { class: 'hti-acct-card hti-acct-pcard' } );
		pcard.appendChild( el( 'p', { role: 'status', class: 'hti-acct-muted' }, s.working ) );
		pcol.appendChild( pcard );
		grid.appendChild( pcol );

		request( '/my-profiles', 'GET' ).then( function ( res ) {
			pcard.innerHTML = '';
			var profiles = ( res.data && res.data.profiles ) || [];
			if ( ! profiles.length ) {
				pcard.className = 'hti-acct-card hti-acct-pcard is-empty';
				pcard.appendChild( el( 'p', { class: 'hti-acct-muted' }, s.acc_no_profile ) );
				pcard.appendChild( el( 'a', { href: ctx.resultBase || '#', class: 'hti-acct-btn hti-acct-btn--primary' }, s.acc_discover ) );
				return;
			}
			var ul = el( 'ul', { class: 'hti-profile-list' } );
			profiles.forEach( function ( p ) {
				var li = el( 'li', { class: 'hti-profile-item' } );
				var label = ( p.archetype && p.archetype.label ) || ( s.archetype + ' ' + ( p.archetype && p.archetype.id ) );
				if ( ctx.resultBase && p.profile_id ) {
					li.appendChild( el( 'a', { href: ctx.resultBase + '?profile=' + encodeURIComponent( p.profile_id ), class: 'hti-profile-link' }, label ) );
				} else {
					li.appendChild( el( 'strong', null, label ) );
				}
				if ( p.generated_at ) {
					li.appendChild( el( 'span', { class: 'hti-profile-date' }, ' · ' + String( p.generated_at ).slice( 0, 10 ) ) );
				}
				ul.appendChild( li );
			} );
			pcard.appendChild( ul );
			var acts = el( 'div', { class: 'hti-acct-pcard__acts' } );
			var first = profiles[ 0 ];
			if ( ctx.resultBase && first && first.profile_id ) {
				acts.appendChild( el( 'a', { href: ctx.resultBase + '?profile=' + encodeURIComponent( first.profile_id ), class: 'hti-acct-btn hti-acct-btn--primary' }, s.acc_view_result ) );
			}
			acts.appendChild( el( 'a', { href: ctx.resultBase || '#', class: 'hti-acct-btn hti-acct-btn--outline' }, s.acc_redo ) );
			pcard.appendChild( acts );
		} ).catch( function () {
			pcard.innerHTML = '';
			pcard.appendChild( el( 'p', { class: 'hti-error' }, s.error ) );
		} );

		// RIGHT — my data (GDPR): tidy icon rows + sign out.
		var dcol = el( 'div', { class: 'hti-acct-col' } );
		dcol.appendChild( el( 'span', { class: 'hti-acct-eyebrow' }, s.acc_data_eyebrow ) );
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

		var IC_DL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7C5CFC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/></svg>';
		var IC_SHIELD = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/></svg>';
		var IC_TRASH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C0392B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/></svg>';

		// Export (download JSON).
		var exportRow = dataRow( 'button', { type: 'button' }, IC_DL, 'is-purple', s.export_data, s.acc_export_sub );
		exportRow.addEventListener( 'click', function () {
			request( '/export', 'GET' ).then( function ( res ) {
				if ( ! res.ok ) { return; }
				var blob = new Blob( [ JSON.stringify( res.data, null, 2 ) ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = el( 'a', { href: url, download: 'howtoinvest-data-export.json' } );
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
			} );
		} );
		rows.appendChild( exportRow );

		// Privacy & terms (link).
		if ( ctx.policiesUrl ) {
			rows.appendChild( dataRow( 'a', { href: ctx.policiesUrl }, IC_SHIELD, 'is-coral', s.acc_privacy, s.acc_privacy_sub ) );
		}

		// Delete account (danger) + scheduled-deletion handling.
		var deleteStatus = el( 'p', { class: 'hti-account-email__status', role: 'status' } );
		var deleteRow = dataRow( 'button', { type: 'button' }, IC_TRASH, 'is-danger', s.delete_account, s.acc_delete_sub, 'is-danger' );

		function renderDeletion( dateStr ) {
			dcol.querySelectorAll( '.hti-deletion' ).forEach( function ( n ) { n.remove(); } );
			if ( dateStr ) {
				var wrap = el( 'div', { class: 'hti-deletion' } );
				wrap.appendChild( el( 'p', { class: 'hti-error', role: 'alert' }, s.delete_scheduled.replace( '%s', dateStr ) ) );
				var cancelBtn = el( 'button', { type: 'button', class: 'hti-acct-btn hti-acct-btn--outline hti-deletion__cancel' }, s.cancel_deletion );
				cancelBtn.addEventListener( 'click', function () {
					cancelBtn.disabled = true;
					request( '/cancel-deletion', 'POST', {} ).then( function ( res ) {
						if ( res.ok ) {
							renderDeletion( '' );
							deleteStatus.textContent = s.deletion_off;
							deleteRow.style.display = '';
						} else {
							cancelBtn.disabled = false;
						}
					} );
				} );
				wrap.appendChild( cancelBtn );
				dcol.insertBefore( wrap, deleteStatus );
				deleteRow.style.display = 'none';
			}
		}

		deleteRow.addEventListener( 'click', function () {
			if ( ! window.confirm( s.delete_confirm ) ) { return; }
			request( '/account', 'DELETE', { confirm: true } ).then( function ( res ) {
				if ( res.ok && res.data ) {
					track( 'account_delete_request', {} );
					deleteStatus.textContent = s.deletion_set;
					renderDeletion( res.data.date || '' );
				}
			} );
		} );
		rows.appendChild( deleteRow );

		dcol.appendChild( rows );
		dcol.appendChild( deleteStatus );
		if ( ctx.deleteAt ) {
			renderDeletion( ctx.deleteAt );
		}
		if ( ctx.logoutUrl ) {
			dcol.appendChild( el( 'a', { href: ctx.logoutUrl, class: 'hti-acct-logout' }, s.sign_out ) );
		}
		grid.appendChild( dcol );
		root.appendChild( grid );

		// --- Settings (full width): account email + email preferences ---
		var settings = el( 'div', { class: 'hti-acct-settings' } );
		settings.appendChild( emailSection() );
		settings.appendChild( prefsSection() );
		root.appendChild( settings );

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
