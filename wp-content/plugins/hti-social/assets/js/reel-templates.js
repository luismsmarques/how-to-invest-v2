/**
 * HowToInvest — reel overlay templates (data only; the engine is in reels.js).
 *
 * Each overlay is a full-size 1080×1920 HTML layer drawn ON TOP of the video
 * frame. The middle is transparent (the video shows through); only the top/
 * bottom branding bars are painted. Same {{token}} system as the cards:
 *   {{logo}}  {{disclaimer}}  {{#legal}}…{{/legal}}  {{field}}
 *
 * The animated progress bar at the very bottom is drawn by reels.js on the
 * canvas (cheaper than re-rendering the overlay each frame).
 */
window.HTI_REEL_TEMPLATES = ( function () {
	'use strict';

	var POPPINS = 'Poppins,sans-serif';
	var JAKARTA = "'Plus Jakarta Sans',sans-serif";

	// --- Caption reel: title top, caption lower-third --------------------
	var caption = {
		id: 'reel-caption',
		label: { en: 'Caption', pt: 'Legenda' },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Em foco' },
			{ key: 'title', label: { en: 'Title', pt: 'Título' }, type: 'textarea', default: 'O segredo dos juros compostos' },
			{ key: 'caption', label: { en: 'Caption', pt: 'Legenda' }, type: 'textarea', default: 'Pequenas quantias, investidas cedo, podem crescer muito ao longo do tempo.' }
		],
		html:
			'<div style="width:1080px;height:1920px;position:relative;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;top:0;left:0;right:0;height:360px;background:linear-gradient(180deg,rgba(6,8,26,.82),rgba(6,8,26,0));"></div>' +
				'<div style="position:absolute;top:90px;left:0;right:0;padding:0 70px;display:flex;align-items:center;justify-content:space-between;">' +
					'<div style="display:flex;align-items:center;gap:14px;"><span style="width:60px;height:60px;display:flex;flex:none;">{{logo}}</span><span style="font:700 34px ' + POPPINS + ';color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.5);">HowToInvest</span></div>' +
					'<span style="font:700 22px ' + JAKARTA + ';letter-spacing:.14em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:14px 28px;border-radius:999px;">{{badge}}</span>' +
				'</div>' +
				'<div style="position:absolute;top:210px;left:0;right:0;padding:0 70px;">' +
					'<h2 style="margin:0;font:800 74px ' + POPPINS + ';line-height:1.04;letter-spacing:-.02em;color:#fff;text-shadow:0 3px 18px rgba(0,0,0,.6);">{{title}}</h2>' +
				'</div>' +
				'<div style="position:absolute;left:0;right:0;bottom:0;height:820px;background:linear-gradient(180deg,rgba(6,8,26,0),rgba(6,8,26,.86) 58%,#06081A);"></div>' +
				'<div style="position:absolute;left:0;right:0;bottom:0;padding:0 70px 150px;">' +
					'<p style="margin:0;font:600 42px ' + JAKARTA + ';color:#fff;line-height:1.4;text-shadow:0 2px 10px rgba(0,0,0,.5);">{{caption}}</p>' +
					'<div style="margin-top:34px;"><span style="font:700 30px ' + JAKARTA + ';color:#FF9A8F;">@{{handle}}</span></div>' +
					'{{#legal}}<p style="margin:20px 0 0;font:400 21px ' + JAKARTA + ';color:rgba(255,255,255,.62);line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	// --- Quote reel: big quote + attribution ----------------------------
	var quote = {
		id: 'reel-quote',
		label: { en: 'Quote', pt: 'Citação' },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Citação' },
			{ key: 'title', label: { en: 'Quote', pt: 'Citação' }, type: 'textarea', default: 'O preço é o que pagas; o valor é o que recebes.' },
			{ key: 'author', label: { en: 'Attribution', pt: 'Atribuição' }, type: 'text', default: 'Warren Buffett' },
			{ key: 'caption', label: { en: 'Caption', pt: 'Legenda' }, type: 'textarea', default: 'Uma ideia simples que muda a forma de olhar para o mercado.' }
		],
		html:
			'<div style="width:1080px;height:1920px;position:relative;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;top:0;left:0;right:0;height:320px;background:linear-gradient(180deg,rgba(6,8,26,.8),rgba(6,8,26,0));"></div>' +
				'<div style="position:absolute;top:90px;left:0;right:0;padding:0 70px;display:flex;align-items:center;justify-content:space-between;">' +
					'<div style="display:flex;align-items:center;gap:14px;"><span style="width:60px;height:60px;display:flex;flex:none;">{{logo}}</span><span style="font:700 34px ' + POPPINS + ';color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.5);">HowToInvest</span></div>' +
					'<span style="font:700 22px ' + JAKARTA + ';letter-spacing:.14em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:14px 28px;border-radius:999px;">{{badge}}</span>' +
				'</div>' +
				'<div style="position:absolute;left:0;right:0;bottom:0;height:980px;background:linear-gradient(180deg,rgba(6,8,26,0),rgba(6,8,26,.9) 52%,#06081A);"></div>' +
				'<div style="position:absolute;left:0;right:0;bottom:0;padding:0 70px 150px;">' +
					'<div style="font:800 150px ' + POPPINS + ';line-height:.7;color:#FF6B5E;height:90px;">&#8220;</div>' +
					'<h2 style="margin:0;font:800 78px ' + POPPINS + ';line-height:1.08;letter-spacing:-.02em;color:#fff;text-shadow:0 3px 18px rgba(0,0,0,.6);">{{title}}</h2>' +
					'<div style="margin-top:28px;display:flex;align-items:center;gap:16px;">' +
						'<span style="width:46px;height:4px;background:#FF6B5E;border-radius:2px;"></span>' +
						'<span style="font:700 34px ' + JAKARTA + ';color:#FF9A8F;">{{author}}</span>' +
					'</div>' +
					'<p style="margin:26px 0 0;font:600 36px ' + JAKARTA + ';color:#fff;line-height:1.4;text-shadow:0 2px 10px rgba(0,0,0,.5);">{{caption}}</p>' +
					'{{#legal}}<p style="margin:20px 0 0;font:400 21px ' + JAKARTA + ';color:rgba(255,255,255,.62);line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	return [ caption, quote ];
}() );
