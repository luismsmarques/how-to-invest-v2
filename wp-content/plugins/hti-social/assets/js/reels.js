/**
 * HowToInvest — reel generator (in-browser, no dependency).
 *
 * Upload a video, type a title + caption, pick a branded overlay, and render a
 * vertical 1080×1920 reel: each frame is the video (cover-cropped) plus the
 * overlay (an SVG <foreignObject> with embedded fonts) plus a coral progress
 * bar, captured with the canvas + the video's audio track via MediaRecorder.
 * Output is WebM (Instagram wants MP4 — a quick converter does that).
 */
( function () {
	'use strict';

	var CFG = window.HTI_SOCIAL;
	var TEMPLATES = window.HTI_REEL_TEMPLATES || [];
	if ( ! CFG || ! TEMPLATES.length ) {
		return;
	}
	var L = 'pt' === CFG.locale ? 'pt' : 'en';
	var I = CFG.i18n;
	var W = 1080;
	var H = 1920;

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

	// ---- Overlay rendering (shared with the card export technique) --------

	function renderOverlayHtml( tpl, st ) {
		var html = tpl.html.replace( /\{\{#legal\}\}([\s\S]*?)\{\{\/legal\}\}/g, st.showLegal ? '$1' : '' );
		var values = {};
		Object.keys( st.fields ).forEach( function ( k ) {
			values[ k ] = st.fields[ k ];
		} );
		values.handle = st.handle;
		values.disclaimer = CFG.disclaimers[ st.lang ] || CFG.disclaimers.en;
		return html.replace( /\{\{(\w+)\}\}/g, function ( m, k ) {
			if ( 'logo' === k ) {
				return CFG.logoSvg;
			}
			var v = values[ k ];
			if ( null == v ) {
				v = '';
			}
			return escapeHtml( v ).replace( /\n/g, '<br/>' );
		} );
	}

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
					return "@font-face{font-family:'" + f.family + "';font-style:normal;font-weight:" + f.weight + ";src:url(data:font/woff2;base64," + arrayBufferToBase64( buf ) + ") format('woff2');}";
				} )
				.catch( function () {
					return '';
				} );
		} ) ).then( function ( parts ) {
			return parts.join( '' );
		} );
		return fontCssPromise;
	}

	function buildOverlayImage( tpl, st ) {
		return getFontCss().then( function ( fontCss ) {
			var inner = renderOverlayHtml( tpl, st );
			var svg =
				'<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '">' +
				'<foreignObject x="0" y="0" width="100%" height="100%">' +
				'<div xmlns="http://www.w3.org/1999/xhtml" style="width:' + W + 'px;height:' + H + 'px;">' +
				'<style>' + fontCss + '</style>' + inner + '</div></foreignObject></svg>';
			return new Promise( function ( res, rej ) {
				var img = new Image();
				img.onload = function () {
					res( img );
				};
				img.onerror = rej;
				img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
			} );
		} );
	}

	// ---- Canvas drawing ---------------------------------------------------

	function drawCover( ctx, video ) {
		ctx.fillStyle = '#000';
		ctx.fillRect( 0, 0, W, H );
		var vw = video.videoWidth,
			vh = video.videoHeight;
		if ( ! vw || ! vh ) {
			return;
		}
		var scale = Math.max( W / vw, H / vh );
		var dw = vw * scale,
			dh = vh * scale;
		ctx.drawImage( video, ( W - dw ) / 2, ( H - dh ) / 2, dw, dh );
	}

	function drawProgress( ctx, p ) {
		var y = H - 14;
		ctx.fillStyle = 'rgba(255,255,255,.18)';
		ctx.fillRect( 0, y, W, 14 );
		ctx.fillStyle = '#FF6B5E';
		ctx.fillRect( 0, y, Math.max( 0, Math.min( 1, p ) ) * W, 14 );
	}

	// ---- Recording --------------------------------------------------------

	function pickMime() {
		var c = [ 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm' ];
		for ( var i = 0; i < c.length; i++ ) {
			if ( window.MediaRecorder && MediaRecorder.isTypeSupported( c[ i ] ) ) {
				return c[ i ];
			}
		}
		return '';
	}

	function captureStream( canvas, video ) {
		var cs = canvas.captureStream( 30 );
		var vcap = null;
		try {
			vcap = video.captureStream ? video.captureStream() : ( video.mozCaptureStream ? video.mozCaptureStream() : null );
		} catch ( e ) {
			vcap = null;
		}
		if ( vcap ) {
			vcap.getAudioTracks().forEach( function ( t ) {
				cs.addTrack( t );
			} );
		}
		return cs;
	}

	// ---- Editor -----------------------------------------------------------

	function mount( root ) {
		var st = {
			templateId: TEMPLATES[ 0 ].id,
			perTpl: {},
			handle: CFG.brand.handle,
			showLegal: true,
			lang: L
		};
		var videoEl = null;
		var videoReady = false;
		var rendering = false;
		var raf = 0;

		function tplById( id ) {
			return TEMPLATES.filter( function ( t ) {
				return t.id === id;
			} )[ 0 ] || TEMPLATES[ 0 ];
		}
		function curTpl() {
			return tplById( st.templateId );
		}
		function ensure( tpl ) {
			if ( ! st.perTpl[ tpl.id ] ) {
				var f = {};
				tpl.fields.forEach( function ( fl ) {
					f[ fl.key ] = fl.default || '';
				} );
				st.perTpl[ tpl.id ] = f;
			}
			st.fields = st.perTpl[ tpl.id ];
			return st.fields;
		}

		root.classList.add( 'hti-reels' );
		var controls = el( 'div', { class: 'hti-reels-controls' } );
		var previewWrap = el( 'div', { class: 'hti-reels-previewwrap' } );
		root.appendChild( controls );
		root.appendChild( previewWrap );

		// Preview stage (scaled): video + overlay image, inside a sized box.
		var stageBox = el( 'div', { class: 'hti-reels-stagebox' } );
		var stage = el( 'div', { class: 'hti-reels-stage' } );
		videoEl = el( 'video', { playsinline: '', controls: '', class: 'hti-reels-video' } );
		videoEl.muted = true; // muted so the preview autoplays quietly; unmuted at render.
		var overlayImg = el( 'img', { class: 'hti-reels-overlay', alt: '' } );
		stage.appendChild( videoEl );
		stage.appendChild( overlayImg );
		stageBox.appendChild( stage );
		previewWrap.appendChild( stageBox );
		var renderLabel = el( 'div', { class: 'hti-reels-renderlabel' } );
		previewWrap.appendChild( renderLabel );

		function sizeStage() {
			var avail = previewWrap.clientWidth || 360;
			var scale = Math.min( ( avail - 32 ) / W, 560 / H );
			scale = Math.max( 0.1, scale );
			stage.style.width = W + 'px';
			stage.style.height = H + 'px';
			stage.style.transform = 'scale(' + scale + ')';
			stageBox.style.width = Math.round( W * scale ) + 'px';
			stageBox.style.height = Math.round( H * scale ) + 'px';
		}

		function refreshOverlay() {
			buildOverlayImage( curTpl(), st ).then( function ( img ) {
				overlayImg.src = img.src;
			} ).catch( function () {} );
		}

		var refreshTimer = null;
		function refreshOverlaySoon() {
			if ( refreshTimer ) {
				clearTimeout( refreshTimer );
			}
			refreshTimer = setTimeout( refreshOverlay, 120 );
		}

		// ---- Controls ----
		function renderControls() {
			var tpl = curTpl();
			ensure( tpl );
			controls.innerHTML = '';

			// Video upload.
			var vWrap = el( 'div', { class: 'hti-social-section' } );
			vWrap.appendChild( el( 'h3', null, I.video ) );
			var file = el( 'input', { type: 'file', accept: 'video/*' } );
			file.addEventListener( 'change', function () {
				if ( file.files && file.files[ 0 ] ) {
					loadVideo( file.files[ 0 ] );
				}
			} );
			vWrap.appendChild( file );
			controls.appendChild( vWrap );

			// Template picker.
			var tWrap = el( 'div', { class: 'hti-social-section' } );
			tWrap.appendChild( el( 'h3', null, I.pick ) );
			var picker = el( 'div', { class: 'hti-reels-templates' } );
			TEMPLATES.forEach( function ( t ) {
				var b = el( 'button', { type: 'button', class: 'hti-social-thumb' + ( t.id === st.templateId ? ' is-active' : '' ) }, tr( t.label ) );
				b.addEventListener( 'click', function () {
					st.templateId = t.id;
					renderControls();
					refreshOverlay();
				} );
				picker.appendChild( b );
			} );
			tWrap.appendChild( picker );
			controls.appendChild( tWrap );

			// Text fields.
			var fWrap = el( 'div', { class: 'hti-social-section' } );
			fWrap.appendChild( el( 'h3', null, I.fields ) );
			tpl.fields.forEach( function ( fl ) {
				var rowEl = el( 'label', { class: 'hti-social-field' } );
				rowEl.appendChild( el( 'span', null, tr( fl.label ) ) );
				var input = 'textarea' === fl.type ? el( 'textarea', { rows: '2' } ) : el( 'input', { type: 'text' } );
				input.value = st.fields[ fl.key ] || '';
				input.addEventListener( 'input', function () {
					st.fields[ fl.key ] = input.value;
					refreshOverlaySoon();
				} );
				rowEl.appendChild( input );
				fWrap.appendChild( rowEl );
			} );
			controls.appendChild( fWrap );

			// Brand + legal.
			var bWrap = el( 'div', { class: 'hti-social-section' } );
			var hRow = el( 'label', { class: 'hti-social-field' } );
			hRow.appendChild( el( 'span', null, I.handle ) );
			var hInput = el( 'input', { type: 'text' } );
			hInput.value = st.handle;
			hInput.addEventListener( 'input', function () {
				st.handle = hInput.value;
				refreshOverlaySoon();
			} );
			hRow.appendChild( hInput );
			bWrap.appendChild( hRow );

			var lRow = el( 'label', { class: 'hti-social-field' } );
			lRow.appendChild( el( 'span', null, I.lang ) );
			var lSel = el( 'select' );
			[ [ 'pt', 'Português' ], [ 'en', 'English' ] ].forEach( function ( o ) {
				var opt = el( 'option', { value: o[ 0 ] }, o[ 1 ] );
				if ( st.lang === o[ 0 ] ) {
					opt.setAttribute( 'selected', 'selected' );
				}
				lSel.appendChild( opt );
			} );
			lSel.addEventListener( 'change', function () {
				st.lang = lSel.value;
				refreshOverlaySoon();
			} );
			lRow.appendChild( lSel );
			bWrap.appendChild( lRow );

			var legalRow = el( 'label', { class: 'hti-social-check' } );
			var legal = el( 'input', { type: 'checkbox' } );
			legal.checked = st.showLegal;
			legal.addEventListener( 'change', function () {
				st.showLegal = legal.checked;
				refreshOverlaySoon();
			} );
			legalRow.appendChild( legal );
			legalRow.appendChild( el( 'span', null, I.legal ) );
			bWrap.appendChild( legalRow );
			controls.appendChild( bWrap );

			// Render.
			var rWrap = el( 'div', { class: 'hti-social-section hti-reels-render' } );
			var renderBtn = el( 'button', { type: 'button', class: 'button button-primary button-hero' }, I.render );
			renderBtn.addEventListener( 'click', function () {
				record( renderBtn );
			} );
			rWrap.appendChild( renderBtn );
			rWrap.appendChild( el( 'p', { class: 'hti-social-note' }, I.render_hint ) );
			rWrap.appendChild( el( 'p', { class: 'hti-social-note' }, I.webm_note ) );
			controls.appendChild( rWrap );
		}

		function loadVideo( fileObj ) {
			var url = URL.createObjectURL( fileObj );
			videoEl.src = url;
			videoEl.onloadedmetadata = function () {
				videoReady = true;
				sizeStage();
			};
			videoEl.load();
		}

		// ---- Record ----
		function record( button ) {
			if ( rendering ) {
				return;
			}
			if ( ! videoReady ) {
				window.alert( I.need_video );
				return;
			}
			if ( ! window.MediaRecorder ) {
				window.alert( I.no_support );
				return;
			}
			buildOverlayImage( curTpl(), st ).then( function ( overlay ) {
				var canvas = document.createElement( 'canvas' );
				canvas.width = W;
				canvas.height = H;
				var ctx = canvas.getContext( '2d' );
				var mime = pickMime();
				var stream;
				try {
					stream = captureStream( canvas, videoEl );
				} catch ( e ) {
					window.alert( I.no_support );
					return;
				}
				var rec;
				try {
					rec = mime ? new MediaRecorder( stream, { mimeType: mime } ) : new MediaRecorder( stream );
				} catch ( e ) {
					window.alert( I.no_support );
					return;
				}
				var chunks = [];
				rec.ondataavailable = function ( e ) {
					if ( e.data && e.data.size ) {
						chunks.push( e.data );
					}
				};
				rec.onstop = function () {
					var blob = new Blob( chunks, { type: mime || 'video/webm' } );
					var a = el( 'a', { href: URL.createObjectURL( blob ), download: 'reel-' + curTpl().id + '-1080x1920.webm' } );
					document.body.appendChild( a );
					a.click();
					document.body.removeChild( a );
					URL.revokeObjectURL( a.href );
					stop();
				};

				rendering = true;
				button.disabled = true;
				button.textContent = I.rendering;
				videoEl.pause();
				videoEl.currentTime = 0;
				videoEl.muted = false;
				videoEl.volume = 1;

				videoEl.onended = function () {
					if ( rec.state && 'inactive' !== rec.state ) {
						rec.stop();
					}
				};

				function loop() {
					drawCover( ctx, videoEl );
					ctx.drawImage( overlay, 0, 0, W, H );
					var p = videoEl.duration ? videoEl.currentTime / videoEl.duration : 0;
					drawProgress( ctx, p );
					renderLabel.textContent = I.rendering + ' ' + Math.round( p * 100 ) + '%';
					if ( ! videoEl.ended ) {
						raf = requestAnimationFrame( loop );
					}
				}

				function begin() {
					rec.start();
					raf = requestAnimationFrame( loop );
				}

				function stop() {
					rendering = false;
					button.disabled = false;
					button.textContent = I.render;
					renderLabel.textContent = '';
					if ( raf ) {
						cancelAnimationFrame( raf );
					}
					videoEl.muted = true;
				}

				var play = videoEl.play();
				if ( play && play.then ) {
					play.then( begin ).catch( begin );
				} else {
					begin();
				}
			} ).catch( function () {
				window.alert( I.no_support );
			} );
		}

		window.addEventListener( 'resize', sizeStage );

		renderControls();
		sizeStage();
		refreshOverlay();
	}

	function boot() {
		var node = document.getElementById( 'hti-reels-app' );
		if ( node ) {
			mount( node );
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
