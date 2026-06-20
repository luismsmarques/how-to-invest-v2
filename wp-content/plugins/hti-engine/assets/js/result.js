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

	// Asset-class colours from the HowToInvest design system.
	var COLORS = {
		global_equity: '#FF6B5E',
		bonds: '#7C5CFC',
		reits_alt: '#D69A1E',
		crypto: '#22C3A6',
		cash: '#B7AEC4'
	};

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

	/**
	 * Build the donut as a conic-gradient ring with a hollow centre label.
	 * Decorative (aria-hidden) — the text list below is the accessible view.
	 *
	 * @param {Array}  allocation Server allocation slices.
	 * @param {Object} ui         UI strings (for the centre label).
	 */
	function donut( allocation, ui ) {
		var stops = [],
			cumulative = 0;
		allocation.forEach( function ( slice ) {
			var color = COLORS[ slice.class ] || '#B7AEC4';
			var end = cumulative + slice.pct;
			stops.push( color + ' ' + cumulative + '% ' + end + '%' );
			cumulative = end;
		} );

		var wrap = el( 'div', {
			class: 'hti-donut',
			'aria-hidden': 'true',
			style: 'background:conic-gradient(' + stops.join( ',' ) + ');'
		} );
		var hole = el( 'div', { class: 'hti-donut__hole' } );
		hole.appendChild( el( 'span', { class: 'hti-donut__cap' }, ui.example_label || 'Exemplo' ) );
		hole.appendChild( el( 'span', { class: 'hti-donut__sub' }, ui.by_classes || 'por classes' ) );
		wrap.appendChild( hole );
		return wrap;
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
	 * Build the inline "email me my result" form (email input + send + status).
	 *
	 * @param {Object} res Server response (profile_id, session_token).
	 * @param {Object} ui  UI strings.
	 * @param {Object} cfg HTI_DATA (emailUrl, nonce, locale).
	 */
	function buildEmailForm( res, ui, cfg ) {
		var form = el( 'form', { class: 'hti-email-result', novalidate: 'novalidate' } );
		var input = el( 'input', { type: 'email', class: 'hti-email-result__input', placeholder: ui.email_placeholder, 'aria-label': ui.email_result, required: 'required' } );
		var send = el( 'button', { type: 'submit', class: 'hti-btn hti-btn-secondary' }, ui.email_send );
		var status = el( 'p', { class: 'hti-email-result__status', role: 'status', 'aria-live': 'polite' } );

		form.appendChild( input );
		form.appendChild( send );
		form.appendChild( status );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var email = ( input.value || '' ).trim();
			if ( ! email || email.indexOf( '@' ) < 1 ) {
				status.textContent = ui.email_invalid;
				return;
			}
			send.disabled = true;
			status.textContent = ui.email_sending;

			fetch( cfg.emailUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: JSON.stringify( {
					profile_id: res.profile_id,
					session_token: res.session_token || '',
					email: email,
					locale: cfg.locale || 'en'
				} )
			} )
				.then( function ( r ) {
					if ( r.ok ) {
						form.innerHTML = '';
						status.textContent = ui.email_sent;
						form.appendChild( status );
						return;
					}
					status.textContent = r.status === 422 ? ui.email_invalid : ui.email_error;
					send.disabled = false;
				} )
				.catch( function () {
					status.textContent = ui.email_error;
					send.disabled = false;
				} );
		} );

		return form;
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

		// Heading. Eyebrow + bare archetype name + short illustrative subtitle.
		if ( trap && exp.safety_message ) {
			root.appendChild( el( 'p', { class: 'hti-result-pretitle' }, ui.before_portfolios ) );
			var safety = el( 'div', { class: 'hti-safety', role: 'note' } );
			safety.appendChild( el( 'p', null, exp.safety_message ) );
			root.appendChild( safety );
		}
		root.appendChild( el( 'p', { class: 'hti-result-eyebrow' }, ui.result_heading ) );
		root.appendChild( el( 'h2', { class: 'hti-archetype' }, res.archetype.label ) );
		if ( res.archetype.description ) {
			root.appendChild( el( 'p', { class: 'hti-archetype-desc' }, res.archetype.description ) );
		}

		// Disclaimer — prominent, not dismissible.
		var disc = el( 'div', { class: 'hti-disclaimer', role: 'note' } );
		disc.appendChild( el( 'p', null, res.disclaimer ) );
		root.appendChild( disc );

		// Allocation: chart + text-equivalent list.
		var allocSection = el( 'section', { class: 'hti-alloc', 'aria-label': ui.chart_label } );
		allocSection.appendChild( el( 'h3', null, ui.example_structure ) );
		var grid = el( 'div', { class: 'hti-alloc-grid' } );
		grid.appendChild( donut( res.allocation, ui ) );
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

		// Email my result — POST to the REST endpoint (anonymous, nothing kept).
		var emailCfg = window.HTI_DATA || {};
		if ( emailCfg.emailUrl && res.profile_id ) {
			var emailBtn = el( 'button', { type: 'button', class: 'hti-btn hti-btn-secondary' }, ui.email_result );
			emailBtn.addEventListener( 'click', function () {
				emailBtn.style.display = 'none';
				root.appendChild( buildEmailForm( res, ui, emailCfg ) );
			} );
			actions.appendChild( emailBtn );
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
