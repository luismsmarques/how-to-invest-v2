/**
 * Consent-gated Google Analytics (GA4) loader.
 *
 * Loads gtag.js ONLY after the visitor grants analytics consent (privacy-first).
 * Loads immediately when consent is granted via the banner (no reload), by
 * listening for the `hti-consent-changed` event dispatched by consent.js.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var cfg = window.HTI_GA;
	if ( ! cfg || ! cfg.id ) {
		return;
	}

	var loaded = false;

	function load() {
		if ( loaded ) {
			return;
		}
		loaded = true;

		var s = document.createElement( 'script' );
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( cfg.id );
		document.head.appendChild( s );

		window.dataLayer = window.dataLayer || [];
		window.gtag = function () {
			window.dataLayer.push( arguments );
		};
		window.gtag( 'js', new Date() );
		window.gtag( 'config', cfg.id, { anonymize_ip: true } );

		// Let the tracking helper (track.js) flush any buffered events now that
		// gtag exists.
		document.dispatchEvent( new Event( 'hti-ga-ready' ) );
	}

	function analyticsAllowed() {
		try {
			var c = window.HTIConsent && window.HTIConsent.get();
			return !! ( c && c.analytics );
		} catch ( e ) {
			return false;
		}
	}

	// Load now if consent was already given; otherwise wait for the decision.
	if ( analyticsAllowed() ) {
		load();
	}
	document.addEventListener( 'hti-consent-changed', function ( e ) {
		if ( e && e.detail && e.detail.analytics ) {
			load();
		}
	} );
}() );
