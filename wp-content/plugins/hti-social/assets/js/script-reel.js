/**
 * Script → Reel generator.
 *
 * Paste a timed script (Hook + timestamped lines + Caption) → the browser
 * narrates each segment with server-side Gemini TTS, draws one branded scene
 * per segment on a 1080×1920 canvas, and records canvas + narration audio with
 * MediaRecorder (WebM; optional MP4 via ffmpeg.wasm). No uploaded footage.
 *
 * Reuses the Reels engine's techniques (captureStream + MediaRecorder, the
 * ffmpeg MP4 path, brand fonts) but is self-contained: the visual source is a
 * generated canvas and the audio source is the TTS clips (Web Audio), not a
 * <video> element.
 *
 * @package HTI_Social
 */
( function () {
	'use strict';

	var CFG = window.HTI_SOCIAL || {};
	var SR = window.HTI_SREEL || {};
	var T = SR.i18n || {};
	var L = CFG.locale || 'en';

	var W = 1080, H = 1920;
	var PAD = 0.35;          // Breath added after each narrated clip (s).
	var MIN_SCENE = 2.2;     // Minimum scene length when there is no audio (s).
	var END_SECONDS = 3.2;   // End-card duration (s).
	var INTRO = 0.45;        // Text fade-in per scene (s).

	// Brand scene palette — cycles across segments (dark / cream / coral / navy).
	var PALETTE = [
		{ bg: '#141631', fg: '#ffffff', accent: '#FF6B5E', kicker: '#A9A4C4', dark: true },
		{ bg: '#FFF6F1', fg: '#2A2438', accent: '#FF6B5E', kicker: '#6E6680', dark: false },
		{ bg: '#FF6B5E', fg: '#ffffff', accent: '#1E2147', kicker: 'rgba(255,255,255,.85)', dark: true },
		{ bg: '#1E2147', fg: '#ffffff', accent: '#7C5CFC', kicker: '#A9A4C4', dark: true }
	];

	var LOGO_BARS = '<g fill="#7C5CFC"><rect x="20.4" y="40" width="3.6" height="6" rx=".8"/><rect x="25.9" y="37.5" width="3.6" height="8.5" rx=".8"/><rect x="31.4" y="35" width="3.6" height="11" rx=".8"/><rect x="36.9" y="32.5" width="3.6" height="13.5" rx=".8"/></g>';
	var LOGO_DARK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><circle cx="32" cy="32" r="32" fill="#fff"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#1E2147"/>' + LOGO_BARS + '</svg>';
	var LOGO_LIGHT_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><circle cx="32" cy="32" r="32" fill="#1E2147"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/>' + LOGO_BARS + '</svg>';
	var logoDark = null, logoLight = null;

	/* ---- tiny DOM helpers ------------------------------------------------ */
	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( 'class' === k ) { n.className = attrs[ k ]; }
				else { n.setAttribute( k, attrs[ k ] ); }
			} );
		}
		if ( null != text ) { n.textContent = text; }
		return n;
	}
	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}
	function loadImage( svg ) {
		return new Promise( function ( res ) {
			var img = new Image();
			img.onload = function () { res( img ); };
			img.onerror = function () { res( null ); };
			img.src = 'data:image/svg+xml;base64,' + btoa( svg );
		} );
	}
	function logEvent( level, event, message, context ) {
		if ( ! CFG.restLog ) { return; }
		try {
			fetch( CFG.restLog, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				body: JSON.stringify( { level: level, event: event, message: message || '', context: context || {} } )
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	/* ---- script parser --------------------------------------------------- */
	function cleanHook( s ) {
		return String( s || '' ).replace( /\[[^\]]*\]/g, '' ).replace( /^[\s"'“”]+|[\s"'“”]+$/g, '' ).trim();
	}
	function parseScript( raw ) {
		var lines = String( raw || '' ).split( /\r?\n/ );
		var hook = '', caption = '', segments = [];
		var inCaption = false;
		lines.forEach( function ( line ) {
			var l = line.trim();
			if ( ! l ) { return; }
			var mHook = l.match( /^hook\b[^:]*:\s*(.*)$/i );
			if ( mHook ) { hook = cleanHook( mHook[ 1 ] ); inCaption = false; return; }
			var mCap = l.match( /^caption\b[^:]*:\s*(.*)$/i );
			if ( mCap ) { caption = mCap[ 1 ].trim(); inCaption = true; return; }
			if ( /^script\s*:?\s*$/i.test( l ) ) { inCaption = false; return; }
			var mSeg = l.match( /^\[?\s*(\d{1,2})(?::(\d{2}))?\s*[-–—]\s*(\d{1,2})(?::(\d{2}))?\s*s?\s*\]?\s*:\s*(.+)$/i );
			if ( mSeg ) {
				var start = null != mSeg[ 2 ] ? ( +mSeg[ 1 ] * 60 + +mSeg[ 2 ] ) : +mSeg[ 1 ];
				var end = null != mSeg[ 4 ] ? ( +mSeg[ 3 ] * 60 + +mSeg[ 4 ] ) : +mSeg[ 3 ];
				segments.push( { start: start, end: end, text: mSeg[ 5 ].trim() } );
				inCaption = false;
				return;
			}
			if ( inCaption ) { caption += '\n' + l; return; }
		} );
		return { hook: hook, caption: caption.trim(), segments: segments };
	}

	// Build the scene list from a parsed script (hook first, then each line).
	function buildScenes( parsed ) {
		var scenes = [];
		if ( parsed.hook ) {
			scenes.push( { kind: 'hook', text: parsed.hook, narrate: parsed.hook, label: L === 'pt' ? 'Gancho' : 'Hook' } );
		}
		parsed.segments.forEach( function ( s ) {
			scenes.push( { kind: 'line', text: s.text, narrate: s.text, label: fmtRange( s.start, s.end ) } );
		} );
		return scenes;
	}
	function fmtRange( a, b ) {
		return a + '–' + b + 's';
	}

	/* ---- Web Audio: TTS fetch + decode ----------------------------------- */
	var ac = null;
	function audioCtx() {
		if ( ! ac ) {
			var AC = window.AudioContext || window.webkitAudioContext;
			ac = new AC();
		}
		if ( ac.state === 'suspended' ) { ac.resume(); }
		return ac;
	}
	function b64ToArrayBuffer( b64 ) {
		var bin = atob( b64 );
		var len = bin.length;
		var bytes = new Uint8Array( len );
		for ( var i = 0; i < len; i++ ) { bytes[ i ] = bin.charCodeAt( i ); }
		return bytes.buffer;
	}
	function delay( ms ) {
		return new Promise( function ( r ) { setTimeout( r, ms ); } );
	}
	function ttsBuffer( text, voice, tries ) {
		tries = tries == null ? 2 : tries;
		return fetch( CFG.restTts, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify( { text: text, voice: voice } )
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'tts http ' + r.status ); }
			return r.json();
		} ).then( function ( j ) {
			var buf = b64ToArrayBuffer( j.wav );
			return audioCtx().decodeAudioData( buf );
		} ).catch( function ( err ) {
			// One more retry after a short pause (server already retries 5xx).
			if ( tries > 0 ) {
				return delay( 1200 ).then( function () { return ttsBuffer( text, voice, tries - 1 ); } );
			}
			throw err;
		} );
	}

	/* ---- canvas drawing -------------------------------------------------- */
	function ensureFonts() {
		if ( ! document.fonts || ! document.fonts.load ) { return Promise.resolve(); }
		return Promise.all( [
			document.fonts.load( '800 96px Poppins' ),
			document.fonts.load( '700 40px Poppins' ),
			document.fonts.load( "600 34px 'Plus Jakarta Sans'" ),
			document.fonts.load( "500 30px 'Plus Jakarta Sans'" )
		] ).catch( function () {} );
	}
	function easeOut( t ) { return 1 - Math.pow( 1 - t, 3 ); }

	function wrapLines( ctx, text, maxW ) {
		var words = String( text || '' ).split( /\s+/ );
		var lines = [], cur = '';
		for ( var i = 0; i < words.length; i++ ) {
			var test = cur ? cur + ' ' + words[ i ] : words[ i ];
			if ( ctx.measureText( test ).width > maxW && cur ) {
				lines.push( cur );
				cur = words[ i ];
			} else {
				cur = test;
			}
		}
		if ( cur ) { lines.push( cur ); }
		return lines;
	}

	function radial( ctx, x, y, r, color ) {
		var g = ctx.createRadialGradient( x, y, 0, x, y, r );
		g.addColorStop( 0, color );
		g.addColorStop( 1, 'rgba(0,0,0,0)' );
		ctx.fillStyle = g;
		ctx.beginPath();
		ctx.arc( x, y, r, 0, Math.PI * 2 );
		ctx.fill();
	}

	function drawLogo( ctx, pal, x, y, size ) {
		var img = pal.dark ? logoDark : logoLight;
		if ( img ) { ctx.drawImage( img, x, y, size, size ); }
		ctx.fillStyle = pal.fg;
		ctx.font = "600 34px 'Plus Jakarta Sans'";
		ctx.textBaseline = 'middle';
		ctx.textAlign = 'left';
		ctx.fillText( 'HowToInvest', x + size + 20, y + size / 2 + 2 );
	}

	// One segment scene. `tIn` = seconds elapsed within this scene.
	function drawScene( ctx, scene, idx, total, tIn, overallP ) {
		var pal = scene.kind === 'hook' ? PALETTE[ 0 ] : PALETTE[ ( idx ) % PALETTE.length ];
		scene._pal = pal;

		ctx.fillStyle = pal.bg;
		ctx.fillRect( 0, 0, W, H );

		// Ambient glows.
		if ( pal.dark ) {
			radial( ctx, W + 40, 220, 620, 'rgba(124,92,252,.22)' );
			radial( ctx, -60, H - 180, 560, 'rgba(255,107,94,.16)' );
		} else {
			radial( ctx, W - 40, 260, 520, 'rgba(255,209,199,.55)' );
		}

		drawLogo( ctx, pal, 96, 120, 66 );

		var alpha = Math.max( 0, Math.min( 1, tIn / INTRO ) );
		var rise = ( 1 - easeOut( alpha ) ) * 46;

		// Kicker: hook tag or timecode pill.
		ctx.save();
		ctx.globalAlpha = alpha;
		ctx.textAlign = 'left';
		var kickY = 470 + rise;
		if ( scene.kind === 'hook' ) {
			ctx.fillStyle = '#FF3B30';
			roundRect( ctx, 96, kickY - 34, 190, 64, 32 );
			ctx.fill();
			ctx.fillStyle = '#fff';
			ctx.font = '700 30px Poppins';
			ctx.textBaseline = 'middle';
			ctx.fillText( 'MYTH', 128, kickY );
		} else {
			// A small accent bar (not the script timecode — that stays in the
			// editor only, never burned onto the video).
			ctx.fillStyle = pal.accent;
			roundRect( ctx, 96, kickY - 5, 88, 10, 5 );
			ctx.fill();
		}
		ctx.restore();

		// Headline (wrapped, big).
		ctx.save();
		ctx.globalAlpha = alpha;
		ctx.fillStyle = pal.fg;
		var size = scene.kind === 'hook' ? 100 : 84;
		var lh = size * 1.06;
		ctx.font = '800 ' + size + 'px Poppins';
		ctx.textAlign = 'left';
		ctx.textBaseline = 'alphabetic';
		var maxW = W - 192;
		var lines = wrapLines( ctx, scene.text, maxW );
		// Shrink if too tall.
		while ( lines.length * lh > 900 && size > 52 ) {
			size -= 6; lh = size * 1.06;
			ctx.font = '800 ' + size + 'px Poppins';
			lines = wrapLines( ctx, scene.text, maxW );
		}
		var blockH = lines.length * lh;
		var startY = ( H - blockH ) / 2 + size * 0.8 + rise;
		lines.forEach( function ( ln, i ) {
			ctx.fillText( ln, 96, startY + i * lh );
		} );
		ctx.restore();

		// Footer: index + handle + progress.
		ctx.globalAlpha = 1;
		ctx.fillStyle = pal.kicker;
		ctx.font = "600 30px 'Plus Jakarta Sans'";
		ctx.textAlign = 'left';
		ctx.textBaseline = 'alphabetic';
		ctx.fillText( pad2( idx + 1 ) + ' / ' + pad2( total ), 96, H - 150 );
		ctx.textAlign = 'right';
		ctx.fillText( '@' + ( CFG.brand && CFG.brand.handle ? CFG.brand.handle : 'howtoinvest.pro' ), W - 96, H - 150 );

		// Progress bar.
		ctx.fillStyle = pal.dark ? 'rgba(255,255,255,.18)' : 'rgba(42,36,56,.14)';
		roundRect( ctx, 96, H - 118, W - 192, 8, 4 );
		ctx.fill();
		ctx.fillStyle = pal.accent;
		roundRect( ctx, 96, H - 118, ( W - 192 ) * Math.max( 0, Math.min( 1, overallP ) ), 8, 4 );
		ctx.fill();
	}

	function drawEndCard( ctx, opacity, opts ) {
		ctx.save();
		ctx.globalAlpha = Math.max( 0, Math.min( 1, opacity ) );
		ctx.fillStyle = '#1E2147';
		ctx.fillRect( 0, 0, W, H );
		radial( ctx, W / 2, 360, 700, 'rgba(124,92,252,.28)' );

		if ( logoDark ) { ctx.drawImage( logoDark, W / 2 - 60, 520, 120, 120 ); }

		ctx.fillStyle = '#fff';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'alphabetic';
		ctx.font = '800 78px Poppins';
		var lines = wrapLines( ctx, opts.title, W - 220 );
		var y = 820;
		lines.forEach( function ( ln ) { ctx.fillText( ln, W / 2, y ); y += 92; } );

		// CTA pill.
		ctx.font = "700 40px 'Plus Jakarta Sans'";
		var cw = ctx.measureText( opts.cta ).width + 96;
		var cx = ( W - cw ) / 2;
		ctx.fillStyle = '#FF6B5E';
		roundRect( ctx, cx, y + 6, cw, 92, 46 );
		ctx.fill();
		ctx.fillStyle = '#fff';
		ctx.textBaseline = 'middle';
		ctx.fillText( opts.cta, W / 2, y + 6 + 48 );

		ctx.textBaseline = 'alphabetic';
		ctx.fillStyle = '#A9A4C4';
		ctx.font = "600 32px 'Plus Jakarta Sans'";
		ctx.fillText( '@' + ( CFG.brand && CFG.brand.handle ? CFG.brand.handle : 'howtoinvest.pro' ), W / 2, y + 190 );

		// Disclaimer.
		if ( opts.disclaimer ) {
			ctx.fillStyle = 'rgba(169,164,196,.8)';
			ctx.font = "400 24px 'Plus Jakarta Sans'";
			var dl = wrapLines( ctx, opts.disclaimer, W - 240 );
			var dy = H - 150 - ( dl.length - 1 ) * 32;
			dl.forEach( function ( ln ) { ctx.fillText( ln, W / 2, dy ); dy += 32; } );
		}
		ctx.restore();
	}

	function roundRect( ctx, x, y, w, h, r ) {
		r = Math.min( r, h / 2, w / 2 );
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}
	function pad2( n ) { return ( n < 10 ? '0' : '' ) + n; }

	/* ---- MediaRecorder + optional MP4 ------------------------------------ */
	function pickMime( preferMp4 ) {
		// WebM/Opus is the reliable baseline. When the user wants MP4 and the
		// browser can record it natively (Chrome 130+, Safari), record straight
		// to MP4 with audio — far more reliable than the ffmpeg.wasm conversion.
		var webm = [ 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm' ];
		var mp4 = [ 'video/mp4;codecs=avc1.640028,mp4a.40.2', 'video/mp4;codecs=h264,aac', 'video/mp4' ];
		var list = preferMp4 ? mp4.concat( webm ) : webm.concat( mp4 );
		for ( var i = 0; i < list.length; i++ ) {
			if ( window.MediaRecorder && MediaRecorder.isTypeSupported( list[ i ] ) ) { return list[ i ]; }
		}
		return '';
	}
	function downloadBlob( blob, name ) {
		var a = el( 'a', { href: URL.createObjectURL( blob ), download: name } );
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( a.href );
	}

	// Compact ffmpeg.wasm loader (mirrors reels.js).
	var ffmpegReady = null;
	function loadScript( src ) {
		return new Promise( function ( res, rej ) {
			var s = document.createElement( 'script' );
			s.src = src;
			s.onload = res;
			s.onerror = function () { rej( new Error( 'load ' + src ) ); };
			document.head.appendChild( s );
		} );
	}
	function getFfmpeg() {
		if ( ffmpegReady ) { return ffmpegReady; }
		var urls = CFG.ffmpeg || {};
		var p = fetch( CFG.restFfmpeg, { method: 'POST', headers: { 'X-WP-Nonce': CFG.nonce } } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				if ( ! res.ok || ! res.body || ! res.body.core ) { throw new Error( ( res.body && res.body.message ) || 'ffmpeg assets failed' ); }
				var local = res.body; // { worker, core, wasm } same-origin URLs.
				return loadScript( urls.ffmpeg )
					.then( function () { return loadScript( urls.util ); } )
					.then( function () {
						var FFmpeg = window.FFmpegWASM && window.FFmpegWASM.FFmpeg;
						var util = window.FFmpegUtil;
						if ( ! FFmpeg ) { throw new Error( 'FFmpegWASM global missing' ); }
						if ( ! util || ! util.fetchFile || ! util.toBlobURL ) { throw new Error( 'FFmpegUtil global missing' ); }
						// Convert the (same-origin) mirrored files to blob URLs. A
						// classic Worker cannot importScripts a plain URL core — blob
						// URLs are what make importScripts succeed. Passing raw URLs is
						// exactly what triggers "failed to import ffmpeg-core.js".
						return Promise.all( [
							util.toBlobURL( local.core, 'text/javascript' ),
							util.toBlobURL( local.wasm, 'application/wasm' ),
							local.worker ? util.toBlobURL( local.worker, 'text/javascript' ) : Promise.resolve( '' )
						] ).then( function ( b ) {
							var ff = new FFmpeg();
							var cfg = { coreURL: b[ 0 ], wasmURL: b[ 1 ] };
							if ( b[ 2 ] ) { cfg.classWorkerURL = b[ 2 ]; }
							return ff.load( cfg ).then( function () { return { ff: ff, util: util }; } );
						} );
					} );
			} );
		ffmpegReady = p;
		p.catch( function ( err ) {
			logEvent( 'error', 'ffmpeg_load_error', err && err.message ? err.message : String( err ) );
			if ( ffmpegReady === p ) { ffmpegReady = null; }
		} );
		return p;
	}
	function convertToMp4( webmBlob, onProgress ) {
		return getFfmpeg().then( function ( o ) {
			var ff = o.ff, util = o.util;
			if ( onProgress && ff.on ) {
				ff.on( 'progress', function ( e ) { onProgress( e && e.progress ); } );
			}
			return util.fetchFile( webmBlob ).then( function ( data ) {
				return ff.writeFile( 'in.webm', data );
			} ).then( function () {
				return ff.exec( [ '-i', 'in.webm', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-c:a', 'aac', '-b:a', '160k', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', 'out.mp4' ] );
			} ).then( function () {
				return ff.readFile( 'out.mp4' );
			} ).then( function ( out ) {
				return new Blob( [ out ], { type: 'video/mp4' } );
			} );
		} );
	}

	/* ---- render + record ------------------------------------------------- */
	// scenes must already carry `buffer` (AudioBuffer|null) and `dur` (s).
	function renderReel( canvas, scenes, opts, onStatus ) {
		var actx = audioCtx();
		return ensureFonts().then( function () {
			return actx.resume().catch( function () {} ); // Must be running before we schedule/record.
		} ).then( function () {
			var ctx = canvas.getContext( '2d' );
			var dest = actx.createMediaStreamDestination();

			// Timeline offsets.
			var total = 0;
			scenes.forEach( function ( s ) { s.offset = total; total += s.dur; } );
			var endLen = opts.endCard ? END_SECONDS : 0;
			var grand = total + endLen;

			var startAt = actx.currentTime + 0.2;
			// Schedule narration.
			scenes.forEach( function ( s ) {
				if ( s.buffer ) {
					var src = actx.createBufferSource();
					src.buffer = s.buffer;
					src.connect( dest );
					src.connect( actx.destination );
					src.start( startAt + s.offset );
				}
			} );
			// Optional background music (looped, low gain).
			if ( opts.musicBuffer ) {
				var m = actx.createBufferSource();
				var g = actx.createGain();
				g.gain.value = 0.16;
				m.buffer = opts.musicBuffer;
				m.loop = true;
				m.connect( g );
				g.connect( dest );
				g.connect( actx.destination );
				m.start( startAt );
				m.stop( startAt + grand );
			}

			var stream = canvas.captureStream( 30 );
			dest.stream.getAudioTracks().forEach( function ( t ) { stream.addTrack( t ); } );

			var mime = pickMime( opts.preferMp4 );
			logEvent( 'info', 'sreel_tracks', 'Recording stream ready', {
				audio: stream.getAudioTracks().length,
				video: stream.getVideoTracks().length,
				state: actx.state,
				mime: mime
			} );
			if ( ! window.MediaRecorder ) { return Promise.reject( new Error( 'no MediaRecorder' ) ); }
			var rec = new MediaRecorder( stream, mime ? { mimeType: mime, videoBitsPerSecond: 9000000 } : undefined );
			var chunks = [];
			rec.ondataavailable = function ( e ) { if ( e.data && e.data.size ) { chunks.push( e.data ); } };

			return new Promise( function ( resolve, reject ) {
				rec.onstop = function () {
					var blob = new Blob( chunks, { type: mime && mime.indexOf( 'mp4' ) !== -1 ? 'video/mp4' : 'video/webm' } );
					resolve( { blob: blob, mime: blob.type } );
				};
				rec.onerror = function ( e ) { reject( e.error || new Error( 'record error' ) ); };

				rec.start();
				var raf = 0;
				function frame() {
					var elapsed = actx.currentTime - startAt;
					if ( elapsed < 0 ) { elapsed = 0; }

					if ( elapsed < total ) {
						// Find active scene.
						var idx = 0;
						for ( var i = 0; i < scenes.length; i++ ) {
							if ( elapsed >= scenes[ i ].offset ) { idx = i; }
						}
						var sc = scenes[ idx ];
						drawScene( ctx, sc, idx, scenes.length, elapsed - sc.offset, elapsed / grand );
						if ( onStatus ) { onStatus( elapsed, grand ); }
					}
					if ( opts.endCard && elapsed >= total - 0.4 ) {
						var op = ( elapsed - ( total - 0.4 ) ) / 0.5;
						drawEndCard( ctx, op, opts );
					}

					if ( elapsed >= grand ) {
						cancelAnimationFrame( raf );
						try { rec.stop(); } catch ( e ) {}
						return;
					}
					raf = requestAnimationFrame( frame );
				}
				raf = requestAnimationFrame( frame );
			} );
		} );
	}

	/* ---- UI -------------------------------------------------------------- */
	function mount( root ) {
		Promise.all( [ loadImage( LOGO_DARK_SVG ), loadImage( LOGO_LIGHT_SVG ) ] ).then( function ( imgs ) {
			logoDark = imgs[ 0 ];
			logoLight = imgs[ 1 ];
			if ( state.scenes.length ) { previewFirst(); } // Redraw now the logos exist.
		} );

		var state = {
			parsed: null,
			scenes: [],
			voiceReady: false,
			musicBuffer: null,
			voice: ( SR.voices && SR.voices[ 0 ] ) ? SR.voices[ 0 ].id : 'Kore',
			endCard: true,
			toMp4: false,
			endTitle: L === 'pt' ? 'Queres aprender a investir?' : 'Want to learn to invest?',
			endCta: L === 'pt' ? 'Faz o teste — link na bio' : 'Take the quiz — link in bio'
		};

		root.classList.add( 'hti-sreel' );
		var grid = el( 'div', { class: 'hti-sreel-grid' } );
		var left = el( 'div', { class: 'hti-sreel-left' } );
		var right = el( 'div', { class: 'hti-sreel-right' } );
		grid.appendChild( left );
		grid.appendChild( right );
		root.appendChild( grid );

		/* --- left: script + controls --- */
		left.appendChild( el( 'label', { class: 'hti-sreel-lbl' }, T.script || 'Script' ) );
		var ta = el( 'textarea', { class: 'hti-sreel-script', rows: '12', spellcheck: 'false' } );
		ta.value = SR.sample || '';
		left.appendChild( ta );
		left.appendChild( el( 'p', { class: 'hti-sreel-hint' }, T.script_hint || '' ) );

		var parseBtn = el( 'button', { type: 'button', class: 'button button-secondary' }, T.parse || 'Parse script' );
		left.appendChild( parseBtn );

		var scenesBox = el( 'div', { class: 'hti-sreel-scenes' } );
		left.appendChild( scenesBox );

		// Voice row.
		var voiceRow = el( 'div', { class: 'hti-sreel-row' } );
		if ( SR.aiVoice ) {
			voiceRow.appendChild( el( 'label', { class: 'hti-sreel-lbl' }, T.voice || 'Voice (AI)' ) );
			var vsel = el( 'select', { class: 'hti-sreel-voice' } );
			( SR.voices || [] ).forEach( function ( v ) {
				var o = el( 'option', { value: v.id }, v.label );
				vsel.appendChild( o );
			} );
			vsel.addEventListener( 'change', function () { state.voice = vsel.value; state.voiceReady = false; updateButtons(); } );
			voiceRow.appendChild( vsel );
		} else {
			voiceRow.appendChild( el( 'p', { class: 'hti-sreel-warn' }, T.no_voice || '' ) );
		}
		left.appendChild( voiceRow );

		// End card fields.
		var endWrap = el( 'div', { class: 'hti-sreel-row' } );
		var endToggle = el( 'label', { class: 'hti-sreel-check' } );
		var endCb = el( 'input', { type: 'checkbox' } );
		endCb.checked = true;
		endCb.addEventListener( 'change', function () { state.endCard = endCb.checked; } );
		endToggle.appendChild( endCb );
		endToggle.appendChild( el( 'span', null, T.endcard || 'End card (CTA)' ) );
		endWrap.appendChild( endToggle );
		var endTitleIn = el( 'input', { type: 'text', class: 'hti-sreel-input', placeholder: T.end_title || '' } );
		endTitleIn.value = state.endTitle;
		endTitleIn.addEventListener( 'input', function () { state.endTitle = endTitleIn.value; } );
		var endCtaIn = el( 'input', { type: 'text', class: 'hti-sreel-input', placeholder: T.end_cta || '' } );
		endCtaIn.value = state.endCta;
		endCtaIn.addEventListener( 'input', function () { state.endCta = endCtaIn.value; } );
		endWrap.appendChild( endTitleIn );
		endWrap.appendChild( endCtaIn );
		left.appendChild( endWrap );

		// MP4 toggle.
		var mp4Wrap = el( 'label', { class: 'hti-sreel-check' } );
		var mp4Cb = el( 'input', { type: 'checkbox' } );
		mp4Cb.addEventListener( 'change', function () { state.toMp4 = mp4Cb.checked; } );
		mp4Wrap.appendChild( mp4Cb );
		mp4Wrap.appendChild( el( 'span', null, T.mp4 || 'Export as MP4' ) );
		left.appendChild( mp4Wrap );

		// Action buttons.
		var actions = el( 'div', { class: 'hti-sreel-actions' } );
		var voiceBtn = el( 'button', { type: 'button', class: 'button button-secondary' }, T.voice_gen || 'Generate narration' );
		var renderBtn = el( 'button', { type: 'button', class: 'button button-primary' }, T.render || 'Render reel' );
		actions.appendChild( voiceBtn );
		actions.appendChild( renderBtn );
		left.appendChild( actions );
		var status = el( 'p', { class: 'hti-sreel-status' } );
		left.appendChild( status );

		/* --- right: preview + caption --- */
		var stageBox = el( 'div', { class: 'hti-sreel-stagebox' } );
		var canvas = el( 'canvas', { class: 'hti-sreel-canvas', width: String( W ), height: String( H ) } );
		stageBox.appendChild( canvas );
		right.appendChild( stageBox );

		right.appendChild( el( 'label', { class: 'hti-sreel-lbl' }, T.caption || 'Post caption' ) );
		var capBox = el( 'textarea', { class: 'hti-sreel-caption', rows: '4', readonly: 'readonly' } );
		right.appendChild( capBox );
		var copyBtn = el( 'button', { type: 'button', class: 'button button-secondary' }, T.copy || 'Copy' );
		copyBtn.addEventListener( 'click', function () {
			capBox.removeAttribute( 'readonly' );
			capBox.select();
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			capBox.setAttribute( 'readonly', 'readonly' );
			copyBtn.textContent = T.copied || 'Copied ✓';
			setTimeout( function () { copyBtn.textContent = T.copy || 'Copy'; }, 1500 );
		} );
		right.appendChild( copyBtn );
		right.appendChild( el( 'p', { class: 'hti-sreel-hint' }, T.disclaimer_note || '' ) );

		function setStatus( msg, kind ) {
			status.textContent = msg || '';
			status.className = 'hti-sreel-status' + ( kind ? ' is-' + kind : '' );
		}
		function updateButtons() {
			var has = state.scenes.length > 0;
			voiceBtn.disabled = ! has || ! SR.aiVoice;
			renderBtn.disabled = ! has || ( SR.aiVoice && ! state.voiceReady );
		}

		function renderScenesList() {
			scenesBox.innerHTML = '';
			if ( ! state.scenes.length ) { return; }
			var head = el( 'div', { class: 'hti-sreel-sceneshead' }, ( T.segments || 'Scenes' ) + ' (' + state.scenes.length + ')' );
			scenesBox.appendChild( head );
			state.scenes.forEach( function ( s, i ) {
				var row = el( 'div', { class: 'hti-sreel-scene' } );
				row.appendChild( el( 'span', { class: 'hti-sreel-scene__n' }, pad2( i + 1 ) ) );
				row.appendChild( el( 'span', { class: 'hti-sreel-scene__lbl' }, s.label ) );
				row.appendChild( el( 'span', { class: 'hti-sreel-scene__txt' }, s.text ) );
				scenesBox.appendChild( row );
			} );
		}

		// Draw a static preview of the first scene.
		function previewFirst() {
			if ( ! state.scenes.length ) { return; }
			ensureFonts().then( function () {
				drawScene( canvas.getContext( '2d' ), state.scenes[ 0 ], 0, state.scenes.length, INTRO, 0 );
			} );
		}

		parseBtn.addEventListener( 'click', function () {
			var parsed = parseScript( ta.value );
			if ( ! parsed.segments.length && ! parsed.hook ) {
				setStatus( T.need_script || 'Paste and parse a script first.', 'err' );
				return;
			}
			state.parsed = parsed;
			state.scenes = buildScenes( parsed );
			state.voiceReady = false;
			capBox.value = parsed.caption || '';
			renderScenesList();
			previewFirst();
			setStatus( '' );
			updateButtons();
		} );

		voiceBtn.addEventListener( 'click', function () {
			if ( ! state.scenes.length ) { setStatus( T.need_script, 'err' ); return; }
			voiceBtn.disabled = true;
			renderBtn.disabled = true;
			var seq = Promise.resolve();
			var i = 0;
			setStatus( ( T.voice_wait || 'Generating voice…' ) + ' 0/' + state.scenes.length );
			state.scenes.forEach( function ( sc, si ) {
				seq = seq.then( function () {
					// Small gap between calls eases the preview model's rate limit.
					return ( si > 0 ? delay( 500 ) : Promise.resolve() ).then( function () {
						return ttsBuffer( sc.narrate, state.voice );
					} ).then( function ( buf ) {
						sc.buffer = buf;
						sc.dur = buf.duration + PAD;
						i++;
						setStatus( ( T.voice_wait || 'Generating voice…' ) + ' ' + i + '/' + state.scenes.length );
					} );
				} );
			} );
			seq.then( function () {
				state.voiceReady = true;
				setStatus( T.voice_ready || 'Narration ready ✓', 'ok' );
				updateButtons();
			} ).catch( function ( err ) {
				logEvent( 'error', 'tts_client', err && err.message ? err.message : String( err ) );
				setStatus( T.err_voice || 'Could not generate the voice.', 'err' );
				updateButtons();
			} );
		} );

		renderBtn.addEventListener( 'click', function () {
			if ( ! state.scenes.length ) { setStatus( T.need_script, 'err' ); return; }
			if ( SR.aiVoice && ! state.voiceReady ) { setStatus( T.need_voice_first || 'Generate the narration first.', 'err' ); return; }
			if ( ! window.MediaRecorder ) { setStatus( T.no_support, 'err' ); return; }

			// Scenes without audio (voice off) get a text-length-based duration.
			state.scenes.forEach( function ( s ) {
				if ( ! s.dur ) {
					var words = String( s.text || '' ).split( /\s+/ ).length;
					s.dur = Math.max( MIN_SCENE, Math.min( 7, words / 2.6 ) );
				}
			} );

			renderBtn.disabled = true;
			voiceBtn.disabled = true;
			setStatus( T.rendering || 'Recording…', 'work' );
			logEvent( 'info', 'sreel_render_start', 'Script reel render', { scenes: state.scenes.length } );

			var opts = {
				endCard: state.endCard,
				title: state.endTitle,
				cta: state.endCta,
				disclaimer: ( CFG.disclaimers && CFG.disclaimers[ L ] ) || '',
				musicBuffer: state.musicBuffer,
				preferMp4: state.toMp4
			};

			renderReel( canvas, state.scenes, opts, function ( t, g ) {
				setStatus( ( T.rendering || 'Recording…' ) + ' ' + Math.round( t ) + 's / ' + Math.round( g ) + 's', 'work' );
			} ).then( function ( out ) {
				var stamp = new Date().toISOString().slice( 0, 10 );
				// Native MP4 (Chrome 130+/Safari): the recorder already produced an
				// MP4 with audio — no conversion needed.
				if ( out.mime.indexOf( 'mp4' ) !== -1 ) {
					downloadBlob( out.blob, 'howtoinvest-reel-' + stamp + '.mp4' );
					return { ok: true };
				}
				// Wanted MP4 but the browser can only record WebM → try ffmpeg.
				if ( state.toMp4 ) {
					setStatus( ( T.mp4_doing || 'Converting to MP4…' ) + ' 0%', 'work' );
					return convertToMp4( out.blob, function ( p ) {
						if ( p != null && p >= 0 && p <= 1 ) {
							setStatus( ( T.mp4_doing || 'Converting to MP4…' ) + ' ' + Math.round( p * 100 ) + '%', 'work' );
						}
					} ).then( function ( mp4 ) {
						downloadBlob( mp4, 'howtoinvest-reel-' + stamp + '.mp4' );
						return { ok: true };
					} ).catch( function ( err ) {
						// Surface the real reason (don't fall back silently), then
						// still hand over the WebM so the work isn't lost.
						var msg = err && err.message ? err.message : String( err );
						logEvent( 'error', 'sreel_mp4', msg );
						setStatus( ( T.mp4_fail || 'MP4 conversion failed — saved WebM instead.' ) + ' (' + msg + ')', 'err' );
						downloadBlob( out.blob, 'howtoinvest-reel-' + stamp + '.webm' );
						return { ok: false };
					} );
				}
				var ext = out.mime.indexOf( 'mp4' ) !== -1 ? 'mp4' : 'webm';
				downloadBlob( out.blob, 'howtoinvest-reel-' + stamp + '.' + ext );
				return { ok: true };
			} ).then( function ( r ) {
				if ( r && r.ok ) { setStatus( '✓', 'ok' ); }
				renderBtn.disabled = false;
				voiceBtn.disabled = false;
				logEvent( 'info', 'sreel_render_done', 'Script reel done' );
			} ).catch( function ( err ) {
				logEvent( 'error', 'sreel_render_error', err && err.message ? err.message : String( err ) );
				setStatus( ( err && err.message ) || 'Error', 'err' );
				renderBtn.disabled = false;
				voiceBtn.disabled = false;
			} );
		} );

		// Kick off with the sample parsed for immediate preview.
		parseBtn.click();
		updateButtons();
	}

	var rootEl = document.getElementById( 'hti-sreel-app' );
	if ( rootEl ) { mount( rootEl ); }
}() );
