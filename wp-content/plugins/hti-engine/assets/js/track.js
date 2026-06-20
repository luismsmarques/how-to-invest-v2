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
