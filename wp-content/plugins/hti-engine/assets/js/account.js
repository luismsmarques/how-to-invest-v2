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

		if ( ctx.lostUrl && s.forgot ) {
			var forgot = el( 'p', { class: 'hti-auth-forgot' } );
			forgot.appendChild( el( 'a', { href: ctx.lostUrl }, s.forgot ) );
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
		var box = el( 'section', { class: 'hti-save' } );
		box.appendChild( el( 'h3', null, s.save_profile ) );

		function claim() {
			var status = el( 'p', { class: 'hti-save-status', role: 'status' }, s.working );
			box.appendChild( status );
			request( '/claim-profile', 'POST', { session_token: sessionToken } ).then( function ( res ) {
				box.innerHTML = '';
				if ( res.ok ) {
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

		root.appendChild( el( 'h2', null, s.my_profiles ) );

		if ( ! loggedIn ) {
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

		var listWrap = el( 'div', { class: 'hti-account-list' } );
		listWrap.appendChild( el( 'p', { role: 'status' }, s.working ) );
		root.appendChild( listWrap );

		request( '/my-profiles', 'GET' ).then( function ( res ) {
			listWrap.innerHTML = '';
			var profiles = ( res.data && res.data.profiles ) || [];
			if ( ! profiles.length ) {
				listWrap.appendChild( el( 'p', null, s.no_profiles ) );
			} else {
				var ul = el( 'ul', { class: 'hti-profile-list' } );
				profiles.forEach( function ( p ) {
					var li = el( 'li', { class: 'hti-profile-item' } );
					var label = ( p.archetype && p.archetype.label ) || ( s.archetype + ' ' + ( p.archetype && p.archetype.id ) );
					// Link to the saved result (owner is authorized server-side).
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
				listWrap.appendChild( ul );
			}
		} ).catch( function () {
			listWrap.innerHTML = '';
			listWrap.appendChild( el( 'p', { class: 'hti-error' }, s.error ) );
		} );

		// Account email + change-email form.
		root.appendChild( emailSection() );

		// Email preferences.
		root.appendChild( prefsSection() );

		// RGPD actions.
		var actions = el( 'div', { class: 'hti-account-actions' } );

		var exportBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-secondary' }, s.export_data );
		exportBtn.addEventListener( 'click', function () {
			request( '/export', 'GET' ).then( function ( res ) {
				if ( ! res.ok ) {
					return;
				}
				var blob = new Blob( [ JSON.stringify( res.data, null, 2 ) ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = el( 'a', { href: url, download: 'howtoinvest-data-export.json' } );
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
			} );
		} );

		var deleteStatus = el( 'p', { class: 'hti-account-email__status', role: 'status' } );

		function renderDeletion( dateStr ) {
			actions.querySelectorAll( '.hti-deletion' ).forEach( function ( n ) { n.remove(); } );
			if ( dateStr ) {
				var wrap = el( 'div', { class: 'hti-deletion' } );
				wrap.appendChild( el( 'p', { class: 'hti-error', role: 'alert' }, s.delete_scheduled.replace( '%s', dateStr ) ) );
				var cancelBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-secondary hti-deletion__cancel' }, s.cancel_deletion );
				cancelBtn.addEventListener( 'click', function () {
					cancelBtn.disabled = true;
					request( '/cancel-deletion', 'POST', {} ).then( function ( res ) {
						if ( res.ok ) {
							renderDeletion( '' );
							deleteStatus.textContent = s.deletion_off;
							deleteBtn.style.display = '';
						} else {
							cancelBtn.disabled = false;
						}
					} );
				} );
				wrap.appendChild( cancelBtn );
				actions.appendChild( wrap );
				deleteBtn.style.display = 'none';
			}
		}

		var deleteBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost hti-btn-danger' }, s.delete_account );
		deleteBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( s.delete_confirm ) ) {
				return;
			}
			request( '/account', 'DELETE', { confirm: true } ).then( function ( res ) {
				if ( res.ok && res.data ) {
					deleteStatus.textContent = s.deletion_set;
					renderDeletion( res.data.date || '' );
				}
			} );
		} );

		actions.appendChild( exportBtn );
		actions.appendChild( deleteBtn );
		actions.appendChild( deleteStatus );
		root.appendChild( actions );

		// Reflect an already-scheduled deletion (from a prior request/email).
		if ( ctx.deleteAt ) {
			renderDeletion( ctx.deleteAt );
		}

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
