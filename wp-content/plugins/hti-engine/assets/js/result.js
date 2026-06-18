/**
 * Result rendering (E7 / E7b) — draws the profile, the allocation as an
 * accessible SVG donut plus a text-equivalent list, the "why" text, the per-
 * class notes, the non-dismissible disclaimer and the closing actions.
 *
 * Renders only from the server response — never recomputes anything.
 * Exposed as window.HTIResult for questionnaire.js to call.
 *
 * @package HTI_Engine
 */
( function () {
	'use strict';

	// Calm, distinct, accessible colours per asset class.
	var COLORS = {
		global_equity: '#1b7a5a',
		bonds: '#2a6f97',
		reits_alt: '#b07d2b',
		cash: '#5b6b64',
		crypto: '#6d4aff'
	};

	var SVGNS = 'http://www.w3.org/2000/svg';

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

	function svgEl( tag, attrs ) {
		var node = document.createElementNS( SVGNS, tag );
		Object.keys( attrs ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		return node;
	}

	/**
	 * Build the donut SVG. Decorative (aria-hidden) — the list is the a11y view.
	 */
	function donut( allocation ) {
		var size = 200,
			r = 80,
			cx = 100,
			cy = 100,
			c = 2 * Math.PI * r,
			cumulative = 0;

		var svg = svgEl( 'svg', {
			viewBox: '0 0 ' + size + ' ' + size,
			class: 'hti-donut',
			'aria-hidden': 'true',
			focusable: 'false'
		} );

		// Track ring.
		svg.appendChild( svgEl( 'circle', {
			cx: cx, cy: cy, r: r, fill: 'none',
			stroke: '#e7ecea', 'stroke-width': 34
		} ) );

		allocation.forEach( function ( slice ) {
			var len = ( slice.pct / 100 ) * c;
			var seg = svgEl( 'circle', {
				cx: cx, cy: cy, r: r, fill: 'none',
				stroke: COLORS[ slice.class ] || '#999',
				'stroke-width': 34,
				'stroke-dasharray': len + ' ' + ( c - len ),
				'stroke-dashoffset': String( -cumulative ),
				transform: 'rotate(-90 ' + cx + ' ' + cy + ')'
			} );
			svg.appendChild( seg );
			cumulative += len;
		} );

		return svg;
	}

	/**
	 * Text-equivalent allocation list (the accessible representation).
	 */
	function allocationList( allocation, classes ) {
		var list = el( 'ul', { class: 'hti-alloc-list' } );
		allocation.forEach( function ( slice ) {
			var li = el( 'li', { class: 'hti-alloc-item' } );
			li.appendChild( el( 'span', {
				class: 'hti-swatch',
				style: 'background:' + ( COLORS[ slice.class ] || '#999' ),
				'aria-hidden': 'true'
			} ) );
			li.appendChild( el( 'span', { class: 'hti-alloc-name' }, classes[ slice.class ] || slice.class ) );
			li.appendChild( el( 'span', { class: 'hti-alloc-pct' }, slice.pct + '%' ) );
			list.appendChild( li );
		} );
		return list;
	}

	/**
	 * Render the full result into the mount.
	 *
	 * @param {HTMLElement} mount   Container.
	 * @param {Object}      res     Server response.
	 * @param {Object}      payload HTI_DATA.data (classes, ui).
	 */
	function render( mount, res, payload ) {
		var ui = payload.ui,
			classes = payload.classes,
			exp = res.explanation || {},
			trap = res.safety_flags && res.safety_flags.length > 0;

		mount.innerHTML = '';
		var root = el( 'div', { class: 'hti-result' } );

		// Heading.
		if ( trap && exp.safety_message ) {
			root.appendChild( el( 'h2', { class: 'hti-result-pretitle' }, ui.before_portfolios ) );
			var safety = el( 'div', { class: 'hti-safety', role: 'note' } );
			safety.appendChild( el( 'p', null, exp.safety_message ) );
			root.appendChild( safety );
		}
		root.appendChild( el( 'h2', { class: 'hti-archetype' }, ui.result_heading + ': ' + res.archetype.label ) );

		// Disclaimer — prominent, not dismissible.
		var disc = el( 'div', { class: 'hti-disclaimer', role: 'note' } );
		disc.appendChild( el( 'p', null, res.disclaimer ) );
		root.appendChild( disc );

		// Allocation: chart + text-equivalent list.
		var allocSection = el( 'section', { class: 'hti-alloc', 'aria-label': ui.chart_label } );
		allocSection.appendChild( el( 'h3', null, ui.example_structure ) );
		var grid = el( 'div', { class: 'hti-alloc-grid' } );
		grid.appendChild( donut( res.allocation ) );
		grid.appendChild( allocationList( res.allocation, classes ) );
		allocSection.appendChild( grid );
		root.appendChild( allocSection );

		// Why this archetype.
		if ( exp.why_archetype ) {
			var why = el( 'section', { class: 'hti-why' } );
			why.appendChild( el( 'h3', null, ui.why_archetype ) );
			why.appendChild( el( 'p', null, exp.why_archetype ) );
			root.appendChild( why );
		}

		// What each class means.
		if ( exp.class_notes && Object.keys( exp.class_notes ).length ) {
			var notes = el( 'section', { class: 'hti-notes' } );
			notes.appendChild( el( 'h3', null, ui.what_classes_mean ) );
			res.allocation.forEach( function ( slice ) {
				var note = exp.class_notes[ slice.class ];
				if ( ! note ) {
					return;
				}
				var det = el( 'details', { class: 'hti-note' } );
				det.appendChild( el( 'summary', null, classes[ slice.class ] || slice.class ) );
				det.appendChild( el( 'p', null, note ) );
				notes.appendChild( det );
			} );
			root.appendChild( notes );
		}

		// Closing actions (educational only — never execution/brokerage).
		var actions = el( 'div', { class: 'hti-actions' } );

		// Export PDF — POST to admin-post.php (keeps the token out of the URL).
		var pdfCfg = window.HTI_DATA && window.HTI_DATA.pdf;
		if ( pdfCfg && res.profile_id ) {
			var pdfBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-secondary' }, ui.export_pdf );
			pdfBtn.addEventListener( 'click', function () {
				var form = el( 'form', { method: 'POST', action: pdfCfg.url, target: '_blank' } );
				var fields = {
					action: 'hti_pdf',
					_wpnonce: pdfCfg.nonce,
					profile_id: String( res.profile_id ),
					session_token: res.session_token || ''
				};
				Object.keys( fields ).forEach( function ( k ) {
					form.appendChild( el( 'input', { type: 'hidden', name: k, value: fields[ k ] } ) );
				} );
				document.body.appendChild( form );
				form.submit();
				document.body.removeChild( form );
			} );
			actions.appendChild( pdfBtn );
		}

		var readMore = el( 'a', { class: 'hti-btn hti-btn-secondary', href: '/investing-glossary/' }, ui.read_more );
		var retake = el( 'button', { type: 'button', class: 'hti-btn hti-btn-ghost' }, ui.retake );
		retake.addEventListener( 'click', function () {
			try {
				window.sessionStorage.removeItem( 'hti_answers' );
				window.sessionStorage.removeItem( 'hti_step' );
			} catch ( e ) {}
			window.location.reload();
		} );
		actions.appendChild( readMore );
		actions.appendChild( retake );
		root.appendChild( actions );
		root.appendChild( el( 'p', { class: 'hti-fineprint' }, ui.start_over_note ) );

		// "Save my profile" flow (register/login → claim-profile).
		if ( window.HTIAccount && res.session_token ) {
			var save = el( 'div', { class: 'hti-save-mount' } );
			root.appendChild( save );
			window.HTIAccount.mountSave( save, res.session_token );
		}

		mount.appendChild( root );
		root.setAttribute( 'tabindex', '-1' );
		root.focus();
	}

	window.HTIResult = { render: render };
}() );
