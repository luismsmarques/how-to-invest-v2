/**
 * Consent-gated event tracking helper (GA4).
 *
 * Exposes window.HTITrack.event(name, params). Events are sent through gtag
 * ONLY when the visitor has granted analytics consent AND gtag is loaded.
 * Until then they are buffered in memory and flushed once both are true
 * (e.g. the moment the visitor accepts analytics in the banner). Nothing is
 * persisted and no personal data is ever sent — names, emails and answers are
 * never passed; archetype id/label and counts are aggregate.
 *
 * Also provides declarative tracking: any element with `data-hti-track="name"`
 * fires that event on click; `data-htip-*` attributes become event params
 * (e.g. data-htip-location="hero" → { location: "hero" }). Lets the theme tag
 * CTAs without bundling JS.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	var buffer = [];

	// First-party anonymous counter (self-hosted funnel panel). Fires for every
	// event regardless of consent — it sends no cookies, no IP, no identifiers,
	// only an event name + a couple of low-cardinality params, stored as
	// aggregate daily counts. Independent of GA.
	function beacon( name, params ) {
		var cfg = window.HTI_TRACK;
		if ( ! cfg || ! cfg.beacon ) {
			return;
		}
		var body = { name: name };
		if ( params ) {
			if ( params.step_index != null ) {
				body.step = params.step_index;
			}
			if ( params.archetype != null ) {
				body.archetype = params.archetype;
			}
			if ( params.location != null ) {
				body.location = params.location;
			}
			if ( params.path != null ) {
				body.path = params.path;
			}
			if ( params.lang != null ) {
				body.lang = params.lang;
			}
			if ( params.campaign != null ) {
				body.campaign = params.campaign;
			}
			if ( params.ref != null ) {
				body.ref = params.ref;
			}
		}
		try {
			window.fetch( cfg.beacon, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
				keepalive: true,
				credentials: 'omit'
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	function consentOk() {
		try {
			var c = window.HTIConsent && window.HTIConsent.get();
			return !! ( c && c.analytics );
		} catch ( e ) {
			return false;
		}
	}

	function ready() {
		return typeof window.gtag === 'function';
	}

	function emit( name, params ) {
		try {
			window.gtag( 'event', name, params || {} );
		} catch ( e ) {}
	}

	function flush() {
		if ( ! consentOk() ) {
			return;
		}
		// Push any pre-consent buffered events to GTM's dataLayer (once each,
		// marked with _dl), regardless of whether HTI's own gtag is ready.
		for ( var i = 0; i < buffer.length; i++ ) {
			if ( ! buffer[ i ]._dl ) {
				pushDataLayer( buffer[ i ].n, buffer[ i ].p );
				buffer[ i ]._dl = true;
			}
		}
		// Drain to HTI's own gtag only once it has loaded; otherwise keep them
		// buffered (already dataLayer-sent) for the later hti-ga-ready flush.
		if ( ready() ) {
			while ( buffer.length ) {
				var e = buffer.shift();
				emit( e.n, e.p );
			}
		}
	}

	// GTM-friendly dataLayer push. A GTM "Custom Event" trigger fires on a push
	// shaped like { event: 'name', … } — which gtag('event', …) does NOT create —
	// so we push it explicitly. Consent-gated (only after analytics consent), so
	// GTM's GA4 tags fire only when allowed. Independent of HTI's own gtag, so it
	// still works when GA4 is managed entirely by GTM (ga_id left blank here).
	function pushDataLayer( name, params ) {
		if ( ! consentOk() ) {
			return;
		}
		try {
			window.dataLayer = window.dataLayer || [];
			var o = { event: name };
			if ( params ) {
				for ( var k in params ) {
					if ( Object.prototype.hasOwnProperty.call( params, k ) ) {
						o[ k ] = params[ k ];
					}
				}
			}
			window.dataLayer.push( o );
		} catch ( e ) {}
	}

	function event( name, params ) {
		if ( ! name ) {
			return;
		}
		// Anonymous first-party count (always), independent of GA consent.
		beacon( name, params );

		// GTM (consent-gated). Fires whether or not HTI loads its own gtag.
		pushDataLayer( name, params );

		if ( consentOk() && ready() ) {
			emit( name, params );
			return;
		}
		// Hold (capped) until consent + gtag are both available.
		if ( buffer.length < 50 ) {
			buffer.push( { n: name, p: params || {} } );
		}
	}

	window.HTITrack = { event: event, flush: flush };

	// Flush when GA becomes ready or when consent is granted.
	document.addEventListener( 'hti-ga-ready', flush );
	document.addEventListener( 'hti-consent-changed', function () {
		window.setTimeout( flush, 0 );
	} );

	// Declarative CTA tracking via data attributes (event delegation, capture
	// phase so it still fires when the click also navigates).
	document.addEventListener( 'click', function ( ev ) {
		var node = ev.target && ev.target.closest ? ev.target.closest( '[data-hti-track]' ) : null;
		if ( ! node ) {
			return;
		}
		var name = node.getAttribute( 'data-hti-track' );
		var params = {};
		for ( var i = 0; i < node.attributes.length; i++ ) {
			var a = node.attributes[ i ];
			if ( a.name.indexOf( 'data-htip-' ) === 0 ) {
				params[ a.name.slice( 10 ) ] = a.value;
			}
		}
		event( name, params );
	}, true );

	// Campaign attribution for paid traffic, without cookies or identifiers.
	//
	// Ad networks land visitors with a campaign parameter on the URL; GA4 will
	// not show that traffic at all while consent is denied, so the first-party
	// counter is the only place it can be measured. We keep the campaign NAME
	// only — a label shared by everyone in the same campaign, never a per-click
	// id — and hold it in sessionStorage so pages after the landing one stay
	// attributed. sessionStorage is per tab and dies with it: it identifies a
	// campaign, not a person, which is why this needs no consent.
	// Campaign-NAME parameters only. A per-click id (clickid, gclid, msclkid…)
	// is deliberately NOT read here: it is unique per visitor, and this counter
	// promises to hold no identifiers. Tag paid landing URLs with
	// utm_campaign=<name> to get them attributed.
	var CAMPAIGN_PARAMS = [ 'utm_campaign', 'campaign', 'utm_source' ];
	var CAMPAIGN_KEY = 'hti_campaign';

	function cleanCampaign( value ) {
		return String( value || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' ).slice( 0, 32 );
	}

	function campaign() {
		var found = '';
		try {
			if ( 'undefined' !== typeof URLSearchParams ) {
				var qs = new URLSearchParams( window.location.search );
				for ( var i = 0; i < CAMPAIGN_PARAMS.length && ! found; i++ ) {
					found = cleanCampaign( qs.get( CAMPAIGN_PARAMS[ i ] ) );
				}
			}
		} catch ( e ) {}

		try {
			if ( found ) {
				window.sessionStorage.setItem( CAMPAIGN_KEY, found );
			} else {
				found = cleanCampaign( window.sessionStorage.getItem( CAMPAIGN_KEY ) );
			}
		} catch ( e ) {
			// Private mode or storage disabled: the landing page is still
			// attributed, the rest of the visit is not. Never fatal.
		}

		return found;
	}

	// Anonymous first-party page view — fired on every load, always, cookieless.
	// Routed straight to the beacon (not through event()): GA already records its
	// own page_view via `gtag('config', …)`, so sending it there too would
	// double-count. Only the pathname is sent (no query string, no referrer, no
	// identifiers); the server normalises and aggregates it.
	( function () {
		var path = '/';
		try { path = window.location.pathname || '/'; } catch ( e ) {}

		// Page language (pt/en/…), from <html lang> — non-identifying.
		var lang = '';
		try { lang = document.documentElement.getAttribute( 'lang' ) || ''; } catch ( e ) {}

		// Acquisition source: the referrer HOST only (never the full URL) — e.g.
		// "google.com". Empty referrer → "direct"; same-site → "internal". Hosts
		// are not personal data; the path/query of the referrer is dropped.
		var ref = 'direct';
		try {
			if ( document.referrer ) {
				var a = document.createElement( 'a' );
				a.href = document.referrer;
				ref = a.host || 'direct';
				if ( ref === window.location.host ) { ref = 'internal'; }
			}
		} catch ( e ) {}

		var camp = campaign();
		var payload = { path: path, lang: lang, ref: ref };
		if ( camp ) {
			payload.campaign = camp;
		}
		beacon( 'page_view', payload );
	}() );
}() );
