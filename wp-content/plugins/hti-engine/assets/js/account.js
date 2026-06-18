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

	function authForm( onAuthed, onError ) {
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
		var create = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-primary', 'data-mode': 'register' }, s.create_account );
		var signin = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-ghost', 'data-mode': 'login' }, s.sign_in );
		actions.appendChild( create );
		actions.appendChild( signin );
		form.appendChild( actions );

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
			var path = mode === 'register' ? '/register' : '/login';
			var body = mode === 'register'
				? { email: email.value, password: pass.value }
				: { login: email.value, password: pass.value };

			request( path, 'POST', body ).then( function ( res ) {
				if ( res.ok && res.data && res.data.nonce ) {
					nonce = res.data.nonce;
					loggedIn = true;
					onAuthed();
				} else {
					err.textContent = ( res.data && res.data.message ) || s.error;
					err.hidden = false;
					if ( onError ) {
						onError( res );
					}
				}
			} ).catch( function () {
				err.textContent = s.error;
				err.hidden = false;
			} );
		} );

		return form;
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

		if ( loggedIn ) {
			var btn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-primary' }, s.save_profile );
			btn.addEventListener( 'click', function () {
				box.removeChild( btn );
				claim();
			} );
			box.appendChild( btn );
		} else {
			box.appendChild( el( 'p', { class: 'hti-save-intro' }, s.save_intro ) );
			box.appendChild( authForm( claim ) );
		}

		container.appendChild( box );
	}

	/* ---------- dashboard ([hti_account]) ---------- */

	function renderDashboard( mount ) {
		mount.innerHTML = '';
		var root = el( 'div', { class: 'hti-account' } );
		root.appendChild( el( 'h2', null, s.my_profiles ) );

		if ( ! loggedIn ) {
			root.appendChild( el( 'p', null, s.signin_to_view ) );
			root.appendChild( authForm( function () { renderDashboard( mount ); } ) );
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
					li.appendChild( el( 'strong', null, label ) );
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

		var deleteBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost hti-btn-danger' }, s.delete_account );
		deleteBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( s.delete_confirm ) ) {
				return;
			}
			request( '/account', 'DELETE', { confirm: true } ).then( function ( res ) {
				if ( res.ok ) {
					window.alert( s.deleted );
					window.location.href = ctx.homeUrl;
				}
			} );
		} );

		actions.appendChild( exportBtn );
		actions.appendChild( deleteBtn );
		root.appendChild( actions );

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
