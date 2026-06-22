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
		// Animated captions replace the fixed overlay caption.
		var effCaption = st.showCaption && ! st.animCaptions;
		html = html.replace( /\{\{#cap\}\}([\s\S]*?)\{\{\/cap\}\}/g, effCaption ? '$1' : '' );
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

	// Full-screen closing CTA card.
	var END_HTML =
		'<div style="width:1080px;height:1920px;background:linear-gradient(160deg,#FF7A6B,#F2503F);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:120px 90px;font-family:\'Plus Jakarta Sans\',sans-serif;color:#fff;box-sizing:border-box;">' +
			'<span style="width:120px;height:120px;display:flex;margin-bottom:40px;">{{logo}}</span>' +
			'<h2 style="margin:0;font:800 92px Poppins,sans-serif;line-height:1.05;letter-spacing:-.02em;color:#fff;">{{endTitle}}</h2>' +
			'<div style="margin-top:50px;background:#fff;color:#F2503F;font:700 44px Poppins,sans-serif;padding:30px 56px;border-radius:24px;">{{endCta}}</div>' +
			'<div style="margin-top:46px;font:700 36px \'Plus Jakarta Sans\',sans-serif;color:#fff;">@{{handle}}</div>' +
			'{{#legal}}<p style="margin:40px 0 0;font:400 22px \'Plus Jakarta Sans\',sans-serif;color:#FFCFC7;line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
		'</div>';

	var END_SECONDS = 3;

	// Force the canvas fonts to load so ctx.fillText uses Poppins/Jakarta.
	function ensureCanvasFonts() {
		if ( ! document.fonts || ! document.fonts.load ) {
			return Promise.resolve();
		}
		return Promise.all( [
			document.fonts.load( "800 84px Poppins" ),
			document.fonts.load( "700 72px 'Plus Jakarta Sans'" )
		] ).catch( function () {} );
	}

	function captionWords( text ) {
		return ( text || '' ).trim().split( /\s+/ ).filter( Boolean );
	}

	function captionWindow( words, t, dur ) {
		if ( ! words.length || ! dur ) {
			return null;
		}
		var per = dur / words.length;
		var idx = Math.min( words.length - 1, Math.max( 0, Math.floor( t / per ) ) );
		return { prev: words[ idx - 1 ] || '', cur: words[ idx ] || '', next: words[ idx + 1 ] || '' };
	}

	// Draw the word-by-word caption (prev dim · current coral · next dim).
	function drawCaptions( ctx, words, t, dur ) {
		var win = captionWindow( words, t, dur );
		if ( ! win || ! win.cur ) {
			return;
		}
		var y = H * 0.7;
		var sideFont = "700 64px 'Plus Jakarta Sans', sans-serif";
		var curFont = '800 86px Poppins, sans-serif';
		var gap = 28;
		ctx.textAlign = 'left';
		ctx.textBaseline = 'middle';

		ctx.font = sideFont;
		var wPrev = win.prev ? ctx.measureText( win.prev ).width : 0;
		var wNext = win.next ? ctx.measureText( win.next ).width : 0;
		ctx.font = curFont;
		var wCur = ctx.measureText( win.cur ).width;

		var total = ( wPrev ? wPrev + gap : 0 ) + wCur + ( wNext ? gap + wNext : 0 );
		var x = ( W - total ) / 2;

		if ( win.prev ) {
			ctx.font = sideFont;
			ctx.fillStyle = 'rgba(255,255,255,.5)';
			ctx.fillText( win.prev, x, y );
			x += wPrev + gap;
		}
		ctx.font = curFont;
		ctx.lineWidth = 10;
		ctx.strokeStyle = 'rgba(0,0,0,.5)';
		ctx.strokeText( win.cur, x, y );
		ctx.fillStyle = '#FF6B5E';
		ctx.fillText( win.cur, x, y );
		x += wCur + gap;
		if ( win.next ) {
			ctx.font = sideFont;
			ctx.fillStyle = 'rgba(255,255,255,.5)';
			ctx.fillText( win.next, x, y );
		}
		ctx.textAlign = 'left';
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

	// ---- Optional MP4 conversion (ffmpeg.wasm, lazy-loaded) ---------------

	function loadScript( src ) {
		return new Promise( function ( res, rej ) {
			var s = document.createElement( 'script' );
			s.src = src;
			s.onload = res;
			s.onerror = function () {
				rej( new Error( 'load ' + src ) );
			};
			document.head.appendChild( s );
		} );
	}

	var ffmpegReady = null;

	function getFfmpeg() {
		if ( ffmpegReady ) {
			return ffmpegReady;
		}
		var urls = CFG.ffmpeg || {};
		ffmpegReady = loadScript( urls.ffmpeg )
			.then( function () {
				return loadScript( urls.util );
			} )
			.then( function () {
				var FFmpeg = window.FFmpegWASM && window.FFmpegWASM.FFmpeg;
				var util = window.FFmpegUtil;
				if ( ! FFmpeg || ! util ) {
					throw new Error( 'ffmpeg unavailable' );
				}
				var ff = new FFmpeg();
				return Promise.all( [
					util.toBlobURL( urls.core, 'text/javascript' ),
					util.toBlobURL( urls.wasm, 'application/wasm' )
				] ).then( function ( b ) {
					return ff.load( { coreURL: b[ 0 ], wasmURL: b[ 1 ] } ).then( function () {
						return { ff: ff, util: util };
					} );
				} );
			} );
		return ffmpegReady;
	}

	function convertToMp4( webmBlob, onProgress ) {
		return getFfmpeg().then( function ( o ) {
			var ff = o.ff,
				util = o.util;
			if ( onProgress && ff.on ) {
				ff.on( 'progress', function ( e ) {
					onProgress( e && e.progress );
				} );
			}
			return util.fetchFile( webmBlob ).then( function ( data ) {
				return ff.writeFile( 'in.webm', data );
			} ).then( function () {
				return ff.exec( [ '-i', 'in.webm', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-c:a', 'aac', '-b:a', '128k', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', 'out.mp4' ] );
			} ).then( function () {
				return ff.readFile( 'out.mp4' );
			} ).then( function ( out ) {
				return new Blob( [ out.buffer ], { type: 'video/mp4' } );
			} );
		} );
	}

	function downloadBlob( blob, name ) {
		var a = el( 'a', { href: URL.createObjectURL( blob ), download: name } );
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( a.href );
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
			showCaption: true,
			lang: L,
			aiOn: false,
			aiBrief: '',
			aiDesc: '',
			animCaptions: false,
			toMp4: false,
			endCard: false,
			endTitle: 'pt' === L ? 'Queres aprender mais?' : 'Want to learn more?',
			endCta: 'pt' === L ? 'Faz o teste — link na bio' : 'Take the quiz — link in bio'
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
		var capPreview = el( 'div', { class: 'hti-reels-cap' } );
		var endPreview = el( 'img', { class: 'hti-reels-endcard', alt: '' } );
		stage.appendChild( videoEl );
		stage.appendChild( overlayImg );
		stage.appendChild( capPreview );
		stage.appendChild( endPreview );
		stageBox.appendChild( stage );
		previewWrap.appendChild( stageBox );
		var renderLabel = el( 'div', { class: 'hti-reels-renderlabel' } );
		previewWrap.appendChild( renderLabel );

		// Live preview of the time-based layers (captions + end card).
		videoEl.addEventListener( 'timeupdate', function () {
			var dur = videoEl.duration || 0;
			var t = videoEl.currentTime || 0;
			if ( st.animCaptions ) {
				var win = captionWindow( captionWords( st.fields.caption ), t, dur );
				if ( win && win.cur ) {
					capPreview.innerHTML =
						( win.prev ? '<span class="dim">' + escapeHtml( win.prev ) + '</span> ' : '' ) +
						'<span class="cur">' + escapeHtml( win.cur ) + '</span>' +
						( win.next ? ' <span class="dim">' + escapeHtml( win.next ) + '</span>' : '' );
					capPreview.style.display = 'block';
				} else {
					capPreview.style.display = 'none';
				}
			} else {
				capPreview.style.display = 'none';
			}
			if ( st.endCard && dur ) {
				var left = dur - t;
				endPreview.style.opacity = left < END_SECONDS ? String( Math.min( 1, ( END_SECONDS - left ) / 0.5 ) ) : '0';
			} else {
				endPreview.style.opacity = '0';
			}
		} );

		function buildEndImage() {
			var tmp = {
				fields: { endTitle: st.endTitle, endCta: st.endCta },
				handle: st.handle,
				showLegal: st.showLegal,
				showCaption: true,
				animCaptions: false,
				lang: st.lang
			};
			return buildOverlayImage( { html: END_HTML }, tmp );
		}

		function refreshEndPreview() {
			if ( ! st.endCard ) {
				endPreview.removeAttribute( 'src' );
				return;
			}
			buildEndImage().then( function ( img ) {
				endPreview.src = img.src;
			} ).catch( function () {} );
		}

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

			// AI assistant (optional; server-side Gemini).
			var aiWrap = el( 'div', { class: 'hti-social-section' } );
			aiWrap.appendChild( el( 'h3', null, I.ai ) );
			var aiRow = el( 'label', { class: 'hti-social-check' } );
			var aiToggle = el( 'input', { type: 'checkbox' } );
			aiToggle.checked = st.aiOn;
			if ( ! CFG.aiEnabled ) {
				aiToggle.disabled = true;
			}
			aiToggle.addEventListener( 'change', function () {
				st.aiOn = aiToggle.checked;
				renderControls();
			} );
			aiRow.appendChild( aiToggle );
			aiRow.appendChild( el( 'span', null, I.ai_on ) );
			aiWrap.appendChild( aiRow );

			if ( ! CFG.aiEnabled ) {
				aiWrap.appendChild( el( 'p', { class: 'hti-social-note' }, I.ai_off_note ) );
			} else if ( st.aiOn ) {
				var brief = el( 'textarea', { rows: '2', placeholder: I.ai_brief } );
				brief.value = st.aiBrief;
				brief.addEventListener( 'input', function () {
					st.aiBrief = brief.value;
				} );
				aiWrap.appendChild( brief );

				var status = el( 'span', { class: 'hti-social-note' } );
				var descLabel = el( 'label', { class: 'hti-social-field' } );
				descLabel.appendChild( el( 'span', null, I.ai_desc ) );
				var descBox = el( 'textarea', { rows: '4' } );
				descBox.value = st.aiDesc;
				descBox.addEventListener( 'input', function () {
					st.aiDesc = descBox.value;
				} );
				descLabel.appendChild( descBox );

				var goBtn = el( 'button', { type: 'button', class: 'button' }, I.ai_go );
				goBtn.addEventListener( 'click', function () {
					runAi( goBtn, status, descBox );
				} );
				aiWrap.appendChild( goBtn );
				aiWrap.appendChild( status );
				aiWrap.appendChild( descLabel );

				var copyBtn = el( 'button', { type: 'button', class: 'button' }, I.ai_copy );
				copyBtn.addEventListener( 'click', function () {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( descBox.value );
					}
					copyBtn.textContent = I.ai_copied;
					setTimeout( function () {
						copyBtn.textContent = I.ai_copy;
					}, 1500 );
				} );
				aiWrap.appendChild( copyBtn );
			}
			controls.appendChild( aiWrap );

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

			var capRow = el( 'label', { class: 'hti-social-check' } );
			var capChk = el( 'input', { type: 'checkbox' } );
			capChk.checked = st.showCaption;
			capChk.addEventListener( 'change', function () {
				st.showCaption = capChk.checked;
				refreshOverlaySoon();
			} );
			capRow.appendChild( capChk );
			capRow.appendChild( el( 'span', null, I.show_caption ) );
			bWrap.appendChild( capRow );

			// Animated captions.
			var animRow = el( 'label', { class: 'hti-social-check' } );
			var animChk = el( 'input', { type: 'checkbox' } );
			animChk.checked = st.animCaptions;
			animChk.addEventListener( 'change', function () {
				st.animCaptions = animChk.checked;
				renderControls();
				refreshOverlay();
			} );
			animRow.appendChild( animChk );
			animRow.appendChild( el( 'span', null, I.anim_caps ) );
			bWrap.appendChild( animRow );
			if ( st.animCaptions ) {
				bWrap.appendChild( el( 'p', { class: 'hti-social-note' }, I.anim_hint ) );
			}

			// End card.
			var endRow = el( 'label', { class: 'hti-social-check' } );
			var endChk = el( 'input', { type: 'checkbox' } );
			endChk.checked = st.endCard;
			endChk.addEventListener( 'change', function () {
				st.endCard = endChk.checked;
				renderControls();
				refreshEndPreview();
			} );
			endRow.appendChild( endChk );
			endRow.appendChild( el( 'span', null, I.end_card ) );
			bWrap.appendChild( endRow );

			if ( st.endCard ) {
				var etRow = el( 'label', { class: 'hti-social-field' } );
				etRow.appendChild( el( 'span', null, I.end_title ) );
				var etInput = el( 'input', { type: 'text' } );
				etInput.value = st.endTitle;
				etInput.addEventListener( 'input', function () {
					st.endTitle = etInput.value;
					refreshEndPreview();
				} );
				etRow.appendChild( etInput );
				bWrap.appendChild( etRow );

				var ecRow = el( 'label', { class: 'hti-social-field' } );
				ecRow.appendChild( el( 'span', null, I.end_cta ) );
				var ecInput = el( 'input', { type: 'text' } );
				ecInput.value = st.endCta;
				ecInput.addEventListener( 'input', function () {
					st.endCta = ecInput.value;
					refreshEndPreview();
				} );
				ecRow.appendChild( ecInput );
				bWrap.appendChild( ecRow );
			}

			controls.appendChild( bWrap );

			// Render.
			var rWrap = el( 'div', { class: 'hti-social-section hti-reels-render' } );
			var mp4Row = el( 'label', { class: 'hti-social-check' } );
			var mp4Chk = el( 'input', { type: 'checkbox' } );
			mp4Chk.checked = st.toMp4;
			mp4Chk.addEventListener( 'change', function () {
				st.toMp4 = mp4Chk.checked;
				renderControls();
			} );
			mp4Row.appendChild( mp4Chk );
			mp4Row.appendChild( el( 'span', null, I.mp4 ) );
			rWrap.appendChild( mp4Row );
			if ( st.toMp4 ) {
				rWrap.appendChild( el( 'p', { class: 'hti-social-note' }, I.mp4_note ) );
			}
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

		// ---- AI caption generation (server-side Gemini) ----
		function runAi( button, status, descBox ) {
			var brief = ( st.aiBrief || '' ).trim();
			if ( ! brief ) {
				return;
			}
			var orig = button.textContent;
			button.disabled = true;
			button.textContent = I.ai_working;
			status.textContent = '';
			fetch( CFG.restCaption, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				body: JSON.stringify( { brief: brief, lang: st.lang } )
			} ).then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: r.ok, body: j };
				} );
			} ).then( function ( res ) {
				button.disabled = false;
				button.textContent = orig;
				if ( ! res.ok ) {
					status.textContent = ( res.body && res.body.message ) || I.ai_error;
					return;
				}
				var d = res.body || {};
				if ( d.title ) {
					st.fields.title = d.title;
				}
				if ( d.caption ) {
					st.fields.caption = d.caption;
				}
				var desc = d.description || '';
				if ( d.hashtags && d.hashtags.length ) {
					desc += ( desc ? '\n\n' : '' ) + d.hashtags.join( ' ' );
				}
				st.aiDesc = desc;
				descBox.value = desc;
				renderControls();
				refreshOverlay();
			} ).catch( function () {
				button.disabled = false;
				button.textContent = orig;
				status.textContent = I.ai_error;
			} );
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
			Promise.all( [
				buildOverlayImage( curTpl(), st ),
				ensureCanvasFonts(),
				st.endCard ? buildEndImage() : Promise.resolve( null )
			] ).then( function ( parts ) {
				var overlay = parts[ 0 ];
				var endImg = parts[ 2 ];
				var capWords = captionWords( st.fields.caption );
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
					var base = 'reel-' + curTpl().id + '-1080x1920';
					if ( ! st.toMp4 ) {
						downloadBlob( blob, base + '.webm' );
						stop();
						return;
					}
					renderLabel.textContent = I.mp4_loading;
					convertToMp4( blob, function ( p ) {
						renderLabel.textContent = I.mp4_doing + ' ' + Math.round( ( p || 0 ) * 100 ) + '%';
					} ).then( function ( mp4 ) {
						downloadBlob( mp4, base + '.mp4' );
						stop();
					} ).catch( function () {
						downloadBlob( blob, base + '.webm' );
						stop();
						window.alert( I.mp4_fail );
					} );
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
					var dur = videoEl.duration || 0;
					var t = videoEl.currentTime || 0;
					drawCover( ctx, videoEl );
					ctx.drawImage( overlay, 0, 0, W, H );
					if ( st.animCaptions ) {
						drawCaptions( ctx, capWords, t, dur );
					}
					if ( endImg && dur ) {
						var left = dur - t;
						if ( left < END_SECONDS ) {
							ctx.globalAlpha = Math.min( 1, ( END_SECONDS - left ) / 0.5 );
							ctx.drawImage( endImg, 0, 0, W, H );
							ctx.globalAlpha = 1;
						}
					}
					var p = dur ? t / dur : 0;
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
