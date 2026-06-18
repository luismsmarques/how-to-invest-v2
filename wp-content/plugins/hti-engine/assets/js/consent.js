/**
 * Cookie consent banner (E8) — privacy-first.
 *
 * Shows until the visitor makes a choice. Records {analytics, ts} in the
 * hti_consent cookie. Non-essential analytics stays OFF until opted in.
 * Exposes window.HTIConsent = { get, open } and dispatches
 * 'hti-consent-changed' so analytics loaders can react.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var cfg = window.HTI_CONSENT;
	if ( ! cfg ) {
		return;
	}
	var s = cfg.strings;

	function readCookie() {
		var match = document.cookie.match(
			new RegExp( '(?:^|; )' + cfg.cookie + '=([^;]*)' )
		);
		if ( ! match ) {
			return null;
		}
		try {
			return JSON.parse( decodeURIComponent( match[ 1 ] ) );
		} catch ( e ) {
			return null;
		}
	}

	function writeCookie( analytics ) {
		var value = encodeURIComponent(
			JSON.stringify( { analytics: !! analytics, ts: new Date().toISOString() } )
		);
		var expires = new Date(
			Date.now() + cfg.expiryDays * 864e5
		).toUTCString();
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie =
			cfg.cookie + '=' + value + '; path=/; expires=' + expires + '; SameSite=Lax' + secure;
	}

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

	var banner = null;

	function close( analytics ) {
		writeCookie( analytics );
		window.HTIConsent.analytics = !! analytics;
		if ( banner && banner.parentNode ) {
			banner.parentNode.removeChild( banner );
		}
		banner = null;
		document.dispatchEvent(
			new CustomEvent( 'hti-consent-changed', { detail: { analytics: !! analytics } } )
		);
	}

	function render() {
		if ( banner ) {
			return;
		}
		banner = el( 'div', {
			class: 'hti-consent',
			role: 'region',
			'aria-label': s.aria
		} );

		var inner = el( 'div', { class: 'hti-consent-inner' } );

		var msg = el( 'p', { class: 'hti-consent-msg' } );
		msg.appendChild( document.createTextNode( s.message + ' ' ) );
		msg.appendChild( el( 'a', { href: cfg.privacyUrl, class: 'hti-consent-link' }, s.privacy ) );
		inner.appendChild( msg );

		// Customize panel (hidden until requested).
		var panel = el( 'div', { class: 'hti-consent-panel', hidden: 'hidden' } );
		var essential = el( 'label', { class: 'hti-consent-opt' } );
		var essBox = el( 'input', { type: 'checkbox', checked: 'checked', disabled: 'disabled' } );
		essential.appendChild( essBox );
		essential.appendChild( el( 'span', null, s.essential ) );
		var analytics = el( 'label', { class: 'hti-consent-opt' } );
		var anaBox = el( 'input', { type: 'checkbox' } ); // Unchecked = privacy-first.
		analytics.appendChild( anaBox );
		analytics.appendChild( el( 'span', null, s.analytics ) );
		panel.appendChild( essential );
		panel.appendChild( analytics );
		inner.appendChild( panel );

		// Actions.
		var actions = el( 'div', { class: 'hti-consent-actions' } );

		var refuse = el( 'button', { type: 'button', class: 'hti-consent-btn hti-consent-ghost' }, s.refuse );
		refuse.addEventListener( 'click', function () {
			close( false );
		} );

		var customize = el( 'button', { type: 'button', class: 'hti-consent-btn hti-consent-ghost' }, s.customize );
		var accept = el( 'button', { type: 'button', class: 'hti-consent-btn hti-consent-primary' }, s.accept );
		accept.addEventListener( 'click', function () {
			close( true );
		} );

		customize.addEventListener( 'click', function () {
			var showing = ! panel.hidden;
			panel.hidden = showing;
			if ( showing ) {
				accept.textContent = s.accept;
				accept.onclick = null;
			} else {
				// In customize mode the primary button saves the toggles.
				accept.textContent = s.save;
				accept.onclick = function ( e ) {
					e.stopImmediatePropagation();
					close( anaBox.checked );
				};
				anaBox.focus();
			}
		} );

		actions.appendChild( refuse );
		actions.appendChild( customize );
		actions.appendChild( accept );
		inner.appendChild( actions );

		banner.appendChild( inner );
		document.body.appendChild( banner );
		banner.setAttribute( 'tabindex', '-1' );
		banner.focus();
	}

	var existing = readCookie();

	window.HTIConsent = {
		analytics: !! ( existing && existing.analytics ),
		get: function () {
			return readCookie();
		},
		open: render
	};

	if ( existing ) {
		// Already decided — let any analytics loader know the current state.
		document.dispatchEvent(
			new CustomEvent( 'hti-consent-changed', {
				detail: { analytics: !! existing.analytics }
			} )
		);
	} else {
		if ( document.body ) {
			render();
		} else {
			document.addEventListener( 'DOMContentLoaded', render );
		}
	}
}() );
