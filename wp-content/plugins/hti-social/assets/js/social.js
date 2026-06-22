/**
 * HowToInvest — social card editor + exporter.
 *
 * Renders the design templates (templates.js) at full size, lets you edit the
 * text fields and drop in a photo, and exports a pixel-faithful PNG with no
 * heavy dependency: the card is serialised into an SVG <foreignObject>, with
 * the self-hosted fonts embedded as base64, then drawn to a canvas.
 *
 * Mounts on the standalone generator page (data-mode="full") and inside the
 * News/Glossary meta box (data-mode="post", pre-filled via HTI_SOCIAL_POST).
 */
( function () {
	'use strict';

	var CFG = window.HTI_SOCIAL;
	var TEMPLATES = window.HTI_SOCIAL_TEMPLATES || [];
	if ( ! CFG || ! TEMPLATES.length ) {
		return;
	}
	var L = 'pt' === CFG.locale ? 'pt' : 'en';
	var I = CFG.i18n;

	function tr( o ) {
		return o ? ( o[ L ] || o.en || '' ) : '';
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( 'class' === k ) {
					node.className = attrs[ k ];
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		if ( null != text ) {
			node.textContent = text;
		}
		return node;
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ---- Rendering --------------------------------------------------------

	function imageMarkup( tpl, id, st ) {
		var meta = ( tpl.images && tpl.images[ id ] ) || { h: 280, radius: 12, placeholder: '' };
		var h = 'number' === typeof meta.h ? meta.h + 'px' : ( meta.h || '100%' );
		var r = ( meta.radius || 0 ) + 'px';
		var src = st.images[ id ];
		if ( src ) {
			return '<img src="' + src + '" style="width:100%;height:' + h + ';object-fit:cover;border-radius:' + r + ';display:block;" />';
		}
		return '<div style="width:100%;height:' + h + ';border-radius:' + r + ';background:rgba(255,255,255,.07);display:flex;align-items:center;justify-content:center;text-align:center;color:rgba(255,255,255,.55);font:500 22px \'Plus Jakarta Sans\',sans-serif;padding:0 40px;box-sizing:border-box;">' + escapeHtml( meta.placeholder || '' ) + '</div>';
	}

	function renderTemplate( tpl, st ) {
		var html = tpl.html;
		// Legal section (kept only when "show disclaimer" is on).
		html = html.replace( /\{\{#legal\}\}([\s\S]*?)\{\{\/legal\}\}/g, st.showLegal ? '$1' : '' );
		// Conditional sections kept only when a field is non-empty.
		html = html.replace( /\{\{#has:(\w+)\}\}([\s\S]*?)\{\{\/has:\1\}\}/g, function ( m, k, inner ) {
			var v = st.fields[ k ];
			return v && String( v ).trim() ? inner : '';
		} );
		// Chip lists: a comma/newline field expanded into pills.
		html = html.replace( /\{\{chips:(\w+)\}\}/g, function ( m, k ) {
			var raw = st.fields[ k ] || '';
			return raw.split( /[,\n]/ ).map( function ( s ) {
				return s.trim();
			} ).filter( Boolean ).map( function ( s ) {
				return '<span style="background:#fff;border:1px solid #F2E4DD;border-radius:999px;padding:11px 22px;font:600 22px \'Plus Jakarta Sans\',sans-serif;color:#5A5270;">' + escapeHtml( s ) + '</span>';
			} ).join( '' );
		} );
		// Image slots.
		html = html.replace( /\{\{img:([\w-]+)\}\}/g, function ( m, id ) {
			return imageMarkup( tpl, id, st );
		} );
		// Tokens.
		var values = {};
		Object.keys( st.fields ).forEach( function ( k ) {
			values[ k ] = st.fields[ k ];
		} );
		values.handle = st.handle;
		values.handleTw = st.handleTw;
		values.domain = st.domain;
		values.disclaimer = CFG.disclaimers[ st.lang ] || CFG.disclaimers.en;
		// Decorative big initial = first letter of the term.
		values.initial = ( st.fields.term || '' ).trim().charAt( 0 ).toUpperCase();
		// Raw inline SVG tokens (never escaped).
		var raw = { logo: CFG.logoSvg, illoShip: CFG.illoShip || '', illoGold: CFG.illoGold || '' };
		html = html.replace( /\{\{(\w+)\}\}/g, function ( m, k ) {
			if ( k in raw ) {
				return raw[ k ];
			}
			var v = values[ k ];
			if ( null == v ) {
				v = '';
			}
			return escapeHtml( v ).replace( /\n/g, '<br/>' );
		} );
		return html;
	}

	// ---- Font embedding (for export fidelity) -----------------------------

	var fontCssPromise = null;

	function arrayBufferToBase64( buf ) {
		var bytes = new Uint8Array( buf );
		var binary = '';
		var chunk = 0x8000;
		for ( var i = 0; i < bytes.length; i += chunk ) {
			binary += String.fromCharCode.apply( null, bytes.subarray( i, i + chunk ) );
		}
		return window.btoa( binary );
	}

	function getFontCss() {
		if ( fontCssPromise ) {
			return fontCssPromise;
		}
		fontCssPromise = Promise.all( ( CFG.fontFaces || [] ).map( function ( f ) {
			return fetch( f.url )
				.then( function ( r ) {
					return r.arrayBuffer();
				} )
				.then( function ( buf ) {
					var b64 = arrayBufferToBase64( buf );
					return "@font-face{font-family:'" + f.family + "';font-style:normal;font-weight:" + f.weight + ";src:url(data:font/woff2;base64," + b64 + ") format('woff2');}";
				} )
				.catch( function () {
					return '';
				} );
		} ) ).then( function ( parts ) {
			return parts.join( '' );
		} );
		return fontCssPromise;
	}

	// ---- Export -----------------------------------------------------------

	function exportPng( tpl, st, button ) {
		var orig = button.textContent;
		button.disabled = true;
		button.textContent = I.exporting;

		getFontCss().then( function ( fontCss ) {
			var inner = renderTemplate( tpl, st );
			var svg =
				'<svg xmlns="http://www.w3.org/2000/svg" width="' + tpl.w + '" height="' + tpl.h + '">' +
				'<foreignObject x="0" y="0" width="100%" height="100%">' +
				'<div xmlns="http://www.w3.org/1999/xhtml" style="width:' + tpl.w + 'px;height:' + tpl.h + 'px;">' +
				'<style>' + fontCss + '</style>' +
				inner +
				'</div></foreignObject></svg>';

			var img = new Image();
			img.onload = function () {
				var canvas = document.createElement( 'canvas' );
				canvas.width = tpl.w;
				canvas.height = tpl.h;
				var ctx = canvas.getContext( '2d' );
				ctx.drawImage( img, 0, 0 );
				canvas.toBlob( function ( blob ) {
					if ( ! blob ) {
						fail();
						return;
					}
					var a = document.createElement( 'a' );
					a.href = URL.createObjectURL( blob );
					a.download = tpl.id + '-' + tpl.w + 'x' + tpl.h + '.png';
					document.body.appendChild( a );
					a.click();
					document.body.removeChild( a );
					URL.revokeObjectURL( a.href );
					done();
				}, 'image/png' );
			};
			img.onerror = fail;
			img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );

			function done() {
				button.disabled = false;
				button.textContent = orig;
			}
			function fail() {
				done();
				window.alert( 'Export failed. Try Chrome or Firefox.' );
			}
		} );
	}

	// ---- Image loading (always store data URLs to avoid canvas taint) ------

	function fileToDataUrl( file, cb ) {
		var reader = new FileReader();
		reader.onload = function () {
			cb( reader.result );
		};
		reader.readAsDataURL( file );
	}

	function urlToDataUrl( url, cb ) {
		fetch( url )
			.then( function ( r ) {
				return r.blob();
			} )
			.then( function ( blob ) {
				var reader = new FileReader();
				reader.onload = function () {
					cb( reader.result );
				};
				reader.readAsDataURL( blob );
			} )
			.catch( function () {
				cb( '' );
			} );
	}

	// ---- Editor -----------------------------------------------------------

	function mount( root ) {
		var mode = root.getAttribute( 'data-mode' ) || 'full';
		var catFilter = ( root.getAttribute( 'data-categories' ) || '' ).split( ',' ).filter( Boolean );
		var prefill = window.HTI_SOCIAL_POST || null;

		var templates = TEMPLATES.filter( function ( tpl ) {
			return ! catFilter.length || catFilter.indexOf( tpl.category ) !== -1;
		} );
		if ( ! templates.length ) {
			root.appendChild( el( 'p', null, 'No templates for this content type yet.' ) );
			return;
		}

		// Shared state.
		var st = {
			handle: CFG.brand.handle,
			handleTw: CFG.brand.handleTw,
			domain: CFG.brand.domain,
			showLegal: true,
			lang: L,
			perTpl: {},
			images: {},
			templateId: templates[ 0 ].id
		};

		var prefillImg = '';
		if ( prefill && prefill.image ) {
			urlToDataUrl( prefill.image, function ( data ) {
				prefillImg = data || '';
				applyImageToCurrent();
				rerender();
			} );
		}

		function tplById( id ) {
			return templates.filter( function ( t ) {
				return t.id === id;
			} )[ 0 ] || templates[ 0 ];
		}

		function ensure( tpl ) {
			if ( st.perTpl[ tpl.id ] ) {
				return st.perTpl[ tpl.id ];
			}
			var f = {};
			tpl.fields.forEach( function ( fl ) {
				f[ fl.key ] = fl.default || '';
			} );
			// Overlay post prefill where keys match.
			if ( prefill && prefill.fields ) {
				Object.keys( prefill.fields ).forEach( function ( k ) {
					if ( k in f && prefill.fields[ k ] ) {
						f[ k ] = prefill.fields[ k ];
					}
				} );
			}
			st.perTpl[ tpl.id ] = f;
			// Seed the first image slot from the post image, if any.
			var slotIds = tpl.images ? Object.keys( tpl.images ) : [];
			if ( slotIds.length && prefillImg && ! st.images[ slotIds[ 0 ] ] ) {
				st.images[ slotIds[ 0 ] ] = prefillImg;
			}
			return f;
		}

		function applyImageToCurrent() {
			var tpl = tplById( st.templateId );
			var slotIds = tpl.images ? Object.keys( tpl.images ) : [];
			if ( slotIds.length && prefillImg && ! st.images[ slotIds[ 0 ] ] ) {
				st.images[ slotIds[ 0 ] ] = prefillImg;
			}
		}

		// Build DOM skeleton.
		root.classList.add( 'hti-social-editor' );
		root.classList.add( 'hti-social-editor--' + mode );

		var gallery = el( 'div', { class: 'hti-social-gallery' } );
		var stageWrap = el( 'div', { class: 'hti-social-stagewrap' } );
		var stageBox = el( 'div', { class: 'hti-social-stagebox' } );
		stageWrap.appendChild( stageBox );
		var controls = el( 'div', { class: 'hti-social-controls' } );

		root.appendChild( gallery );
		root.appendChild( stageWrap );
		root.appendChild( controls );

		// Gallery thumbnails.
		templates.forEach( function ( tpl ) {
			var card = el( 'button', { type: 'button', class: 'hti-social-thumb', 'data-id': tpl.id } );
			card.appendChild( el( 'span', { class: 'hti-social-thumb__label' }, tr( tpl.label ) ) );
			card.addEventListener( 'click', function () {
				st.templateId = tpl.id;
				rebuild();
			} );
			gallery.appendChild( card );
		} );

		function renderStage() {
			var tpl = tplById( st.templateId );
			st.fields = ensure( tpl );

			stageBox.innerHTML = '';
			var avail = stageWrap.clientWidth || 520;
			var maxW = Math.min( avail - 4, 520 );
			var scale = maxW / tpl.w;
			var stage = el( 'div', { class: 'hti-social-stage' } );
			stage.style.width = tpl.w + 'px';
			stage.style.height = tpl.h + 'px';
			stage.style.transform = 'scale(' + scale + ')';
			stage.innerHTML = renderTemplate( tpl, st );
			stageBox.style.width = Math.round( tpl.w * scale ) + 'px';
			stageBox.style.height = Math.round( tpl.h * scale ) + 'px';
			stageBox.appendChild( stage );
		}

		function renderControls() {
			var tpl = tplById( st.templateId );
			st.fields = ensure( tpl );
			controls.innerHTML = '';

			// Text fields.
			var fieldsWrap = el( 'div', { class: 'hti-social-section' } );
			fieldsWrap.appendChild( el( 'h3', null, I.fields ) );
			tpl.fields.forEach( function ( fl ) {
				var row = el( 'label', { class: 'hti-social-field' } );
				row.appendChild( el( 'span', null, tr( fl.label ) ) );
				var input = 'textarea' === fl.type ? el( 'textarea', { rows: '2' } ) : el( 'input', { type: 'text' } );
				input.value = st.fields[ fl.key ] || '';
				input.addEventListener( 'input', function () {
					st.fields[ fl.key ] = input.value;
					renderStage();
				} );
				row.appendChild( input );
				fieldsWrap.appendChild( row );
			} );
			controls.appendChild( fieldsWrap );

			// Image slots.
			var slotIds = tpl.images ? Object.keys( tpl.images ) : [];
			if ( slotIds.length ) {
				var imgWrap = el( 'div', { class: 'hti-social-section' } );
				imgWrap.appendChild( el( 'h3', null, I.image ) );
				slotIds.forEach( function ( id ) {
					var row = el( 'div', { class: 'hti-social-imgrow' } );
					var file = el( 'input', { type: 'file', accept: 'image/*', class: 'hti-social-file' } );
					file.addEventListener( 'change', function () {
						if ( file.files && file.files[ 0 ] ) {
							fileToDataUrl( file.files[ 0 ], function ( data ) {
								st.images[ id ] = data;
								renderStage();
							} );
						}
					} );
					row.appendChild( file );
					if ( st.images[ id ] ) {
						var rm = el( 'button', { type: 'button', class: 'button hti-social-rmimg' }, I.remove_image );
						rm.addEventListener( 'click', function () {
							delete st.images[ id ];
							renderControls();
							renderStage();
						} );
						row.appendChild( rm );
					}
					imgWrap.appendChild( row );
				} );
				controls.appendChild( imgWrap );
			}

			// Brand + legal.
			var brandWrap = el( 'div', { class: 'hti-social-section' } );
			var handleRow = el( 'label', { class: 'hti-social-field' } );
			handleRow.appendChild( el( 'span', null, I.handle ) );
			var handleInput = el( 'input', { type: 'text' } );
			handleInput.value = st.handle;
			handleInput.addEventListener( 'input', function () {
				st.handle = handleInput.value;
				st.domain = handleInput.value;
				renderStage();
			} );
			handleRow.appendChild( handleInput );
			brandWrap.appendChild( handleRow );

			var langRow = el( 'label', { class: 'hti-social-field' } );
			langRow.appendChild( el( 'span', null, I.lang ) );
			var langSel = el( 'select' );
			[ [ 'pt', 'Português' ], [ 'en', 'English' ] ].forEach( function ( o ) {
				var opt = el( 'option', { value: o[ 0 ] }, o[ 1 ] );
				if ( st.lang === o[ 0 ] ) {
					opt.setAttribute( 'selected', 'selected' );
				}
				langSel.appendChild( opt );
			} );
			langSel.addEventListener( 'change', function () {
				st.lang = langSel.value;
				renderStage();
			} );
			langRow.appendChild( langSel );
			brandWrap.appendChild( langRow );

			var legalRow = el( 'label', { class: 'hti-social-check' } );
			var legal = el( 'input', { type: 'checkbox' } );
			legal.checked = st.showLegal;
			legal.addEventListener( 'change', function () {
				st.showLegal = legal.checked;
				renderStage();
			} );
			legalRow.appendChild( legal );
			legalRow.appendChild( el( 'span', null, I.legal ) );
			brandWrap.appendChild( legalRow );

			controls.appendChild( brandWrap );

			// Export.
			var exportBtn = el( 'button', { type: 'button', class: 'button button-primary button-hero hti-social-export' }, I.export );
			exportBtn.addEventListener( 'click', function () {
				exportPng( tplById( st.templateId ), st, exportBtn );
			} );
			controls.appendChild( exportBtn );
		}

		function syncGallery() {
			gallery.querySelectorAll( '.hti-social-thumb' ).forEach( function ( c ) {
				c.classList.toggle( 'is-active', c.getAttribute( 'data-id' ) === st.templateId );
			} );
		}

		function rerender() {
			renderStage();
		}

		function rebuild() {
			syncGallery();
			renderControls();
			renderStage();
		}

		// Drag & drop a photo onto the stage → first image slot.
		stageWrap.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			stageWrap.classList.add( 'is-drop' );
		} );
		stageWrap.addEventListener( 'dragleave', function () {
			stageWrap.classList.remove( 'is-drop' );
		} );
		stageWrap.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			stageWrap.classList.remove( 'is-drop' );
			var tpl = tplById( st.templateId );
			var slotIds = tpl.images ? Object.keys( tpl.images ) : [];
			if ( ! slotIds.length || ! e.dataTransfer || ! e.dataTransfer.files || ! e.dataTransfer.files[ 0 ] ) {
				return;
			}
			fileToDataUrl( e.dataTransfer.files[ 0 ], function ( data ) {
				st.images[ slotIds[ 0 ] ] = data;
				renderControls();
				renderStage();
			} );
		} );

		window.addEventListener( 'resize', function () {
			renderStage();
		} );

		rebuild();
	}

	function boot() {
		var roots = document.querySelectorAll( '#hti-social-app' );
		Array.prototype.forEach.call( roots, mount );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
