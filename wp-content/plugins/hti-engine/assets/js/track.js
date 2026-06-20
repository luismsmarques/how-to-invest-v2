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
		if ( ! consentOk() || ! ready() ) {
			return;
		}
		while ( buffer.length ) {
			var e = buffer.shift();
			emit( e.n, e.p );
		}
	}

	function event( name, params ) {
		if ( ! name ) {
			return;
		}
		// Anonymous first-party count (always), independent of GA consent.
		beacon( name, params );

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
}() );
