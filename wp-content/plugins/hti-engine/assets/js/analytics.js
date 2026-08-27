/**
 * Google Analytics (GA4) loader — two modes, both privacy-first.
 *
 * HARD BLOCK (default, cfg.consentMode falsy): gtag.js is never injected until
 * the visitor grants analytics consent. Nothing reaches Google before that.
 *
 * CONSENT MODE v2 (cfg.consentMode true): gtag.js loads for everyone, but with
 * every storage signal denied, so GA sets no cookies and sends no identifiers
 * until consent arrives — cookieless pings only, which GA4 uses for modelled
 * reporting. Accepting the banner sends a `consent update` that grants
 * analytics_storage. This is what gives us audience data from traffic that
 * never touches the banner (the /forex/ campaigns), without inventing a
 * per-country consent regime.
 *
 * The advertising signals stay denied in BOTH modes for the life of the page:
 * the banner has two categories, essential and analytics, and there is no
 * marketing category that could legitimise ad storage.
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
	var granted = false;

	/**
	 * The Consent Mode v2 default signals. Pure, so tests can assert the exact
	 * payload without a browser: all four v2 signals denied, security storage
	 * granted (it is exempt — it carries no analytics), ad data redacted, and a
	 * short window for the update so a returning visitor's granted consent is
	 * applied before the first hit is sent.
	 */
	function consentDefaults() {
		return {
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
			analytics_storage: 'denied',
			security_storage: 'granted',
			wait_for_update: 500
		};
	}

	function gtagStub() {
		window.dataLayer = window.dataLayer || [];
		if ( ! window.gtag ) {
			window.gtag = function () {
				window.dataLayer.push( arguments );
			};
		}
	}

	function load() {
		if ( loaded ) {
			return;
		}
		loaded = true;

		gtagStub();

		// Consent Mode: the defaults MUST be queued before gtag.js runs, or the
		// first hit goes out under the library's own assumptions.
		if ( cfg.consentMode ) {
			window.gtag( 'set', 'ads_data_redaction', true );
			window.gtag( 'consent', 'default', consentDefaults() );
			if ( granted ) {
				update();
			}
		}

		var s = document.createElement( 'script' );
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( cfg.id );
		document.head.appendChild( s );

		window.gtag( 'js', new Date() );
		window.gtag( 'config', cfg.id, { anonymize_ip: true } );

		// Let the tracking helper (track.js) flush any buffered events now that
		// gtag exists.
		document.dispatchEvent( new Event( 'hti-ga-ready' ) );
	}

	/**
	 * Raise analytics storage to granted. Only analytics — the ad signals are
	 * never granted, because no one consented to advertising storage.
	 */
	function update() {
		gtagStub();
		window.gtag( 'consent', 'update', { analytics_storage: 'granted' } );
	}

	function analyticsAllowed() {
		try {
			var c = window.HTIConsent && window.HTIConsent.get();
			return !! ( c && c.analytics );
		} catch ( e ) {
			return false;
		}
	}

	granted = analyticsAllowed();

	// Consent Mode loads for everyone (denied by default); the hard block waits
	// for a decision.
	if ( cfg.consentMode || granted ) {
		load();
	}

	document.addEventListener( 'hti-consent-changed', function ( e ) {
		if ( ! e || ! e.detail || ! e.detail.analytics ) {
			return;
		}
		granted = true;
		if ( cfg.consentMode ) {
			if ( loaded ) {
				update();
			} else {
				load();
			}
			return;
		}
		load();
	} );

	// Exposed for the test harness; also lets a site tweak the defaults before
	// the script runs without forking the file.
	window.HTIAnalytics = { consentDefaults: consentDefaults };
}() );
