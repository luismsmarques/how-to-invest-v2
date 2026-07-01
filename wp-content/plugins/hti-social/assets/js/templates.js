/**
 * HowToInvest — social card templates (data only; the engine lives in social.js).
 *
 * Each template is full-size HTML (the real export size) with {{tokens}}:
 *   {{logo}}                 brand logo SVG (raw)
 *   {{disclaimer}}           legal text (derived from the chosen language)
 *   {{#legal}}…{{/legal}}    section kept only when "show disclaimer" is on
 *   {{img:slotId}}           image slot (placeholder, or the chosen photo)
 *   {{anyField}}             an editable text field (escaped)
 *
 * Faithful to "HowToInvest Social Templates" (handoff 9). Educational only:
 * the disclaimer and asset-class framing are part of the brand, not optional.
 */
window.HTI_SOCIAL_TEMPLATES = ( function () {
	'use strict';

	var POPPINS = "Poppins,sans-serif";
	var JAKARTA = "'Plus Jakarta Sans',sans-serif";

	// Two logo treatments used by the Myth carousel (handoff 10): the shield
	// inverts for dark vs light backgrounds. The purple bar-chart stays constant.
	var LOGO_BARS = '<g fill="#7C5CFC"><rect x="20.4" y="40" width="3.6" height="6" rx=".8"/><rect x="25.9" y="37.5" width="3.6" height="8.5" rx=".8"/><rect x="31.4" y="35" width="3.6" height="11" rx=".8"/><rect x="36.9" y="32.5" width="3.6" height="13.5" rx=".8"/></g>';
	// White disc + navy shield → for dark backgrounds.
	var LOGO_DARK = '<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none"><circle cx="32" cy="32" r="32" fill="#fff"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#1E2147"/>' + LOGO_BARS + '</svg>';
	// Navy disc + white shield → for light / coral backgrounds.
	var LOGO_LIGHT = '<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none"><circle cx="32" cy="32" r="32" fill="#1E2147"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/>' + LOGO_BARS + '</svg>';

	// One "zero-minimum broker" checklist row for the Myth · 04 Proof slide.
	function mythBrokerRow( name, note, min ) {
		return '<div style="display:flex;align-items:center;gap:28px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:28px;padding:34px 40px;">' +
			'<span style="flex:none;width:76px;height:76px;border-radius:50%;background:#22C3A6;display:flex;align-items:center;justify-content:center;"><svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="#0E2A24" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
			'<div style="flex:1;"><div style="font:700 48px ' + POPPINS + ';letter-spacing:-.02em;">' + name + '</div><div style="font:500 24px ' + JAKARTA + ';color:#A9A4C4;">' + note + '</div></div>' +
			'<span style="font:700 26px ' + JAKARTA + ';color:#3FE0BF;">' + min + '</span>' +
		'</div>';
	}

	// --- News · Square 1080×1080 -------------------------------------------
	var newsSquare = {
		id: 'news-square',
		category: 'news',
		label: { en: 'News · Square', pt: 'Notícias · Quadrado' },
		w: 1080,
		h: 1080,
		images: { 'news-sq': { h: 296, radius: 14, placeholder: 'Arrasta uma imagem — gráfico, edifício financeiro…' } },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Notícias' },
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'Atualização de mercado · 16 jun' },
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Banco Central mantém as taxas de juro pela terceira vez.' }
		],
		html:
			'<div style="width:1080px;height:1080px;background:linear-gradient(158deg,#1C2150,#0F1130);display:flex;flex-direction:column;padding:72px;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;justify-content:space-between;">' +
					'<div style="display:flex;align-items:center;gap:16px;">' +
						'<span style="width:60px;height:60px;display:flex;flex:none;">{{logo}}</span>' +
						'<span style="font:700 30px ' + POPPINS + ';color:#fff;letter-spacing:-.01em;">HowToInvest</span>' +
					'</div>' +
					'<span style="font:700 18px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:11px 24px;border-radius:999px;">{{badge}}</span>' +
				'</div>' +
				'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;padding:34px 0;">' +
					'<div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">' +
						'<span style="width:44px;height:4px;background:#FF6B5E;border-radius:2px;"></span>' +
						'<span style="font:700 19px ' + JAKARTA + ';letter-spacing:.13em;text-transform:uppercase;color:#9BA7E8;">{{kicker}}</span>' +
					'</div>' +
					'<h2 style="margin:0;font:800 64px ' + POPPINS + ';line-height:1.05;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
				'</div>' +
				'<div style="border:2px solid rgba(255,255,255,.13);border-radius:24px;padding:12px;background:rgba(255,255,255,.04);">{{img:news-sq}}</div>' +
				'<div style="margin-top:30px;border-top:1px solid rgba(255,255,255,.12);padding-top:22px;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
						'<span style="font:600 20px ' + JAKARTA + ';color:#9BA7E8;">@{{handle}}</span>' +
						'<span style="font:600 18px ' + JAKARTA + ';color:#6E76A8;">{{domain}}</span>' +
					'</div>' +
					'{{#legal}}<p style="margin:14px 0 0;font:400 14.5px ' + JAKARTA + ';color:#6E76A8;line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	// --- News · Story 1080×1920 --------------------------------------------
	var newsStory = {
		id: 'news-story',
		category: 'news',
		label: { en: 'News · Story', pt: 'Notícias · Story' },
		w: 1080,
		h: 1920,
		images: { 'news-story': { h: 560, radius: 16, placeholder: 'Arrasta uma imagem — gráfico, edifício…' } },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Notícias' },
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'Atualização · 16 jun' },
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Banco Central mantém as taxas de juro.' },
			{ key: 'dek', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'O que muda — e o que não muda — para quem poupa e investe a pensar em anos.' },
			{ key: 'swipe', label: { en: 'Swipe label', pt: 'Texto de deslize' }, type: 'text', default: 'Desliza para cima ↑' }
		],
		html:
			'<div style="width:1080px;height:1920px;background:linear-gradient(168deg,#1C2150,#0F1130);display:flex;flex-direction:column;padding:120px 80px;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;gap:18px;">' +
					'<span style="width:66px;height:66px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 34px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
				'</div>' +
				'<div style="margin-top:80px;display:flex;align-items:center;gap:16px;">' +
					'<span style="font:700 22px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:13px 28px;border-radius:999px;">{{badge}}</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';color:#9BA7E8;">{{kicker}}</span>' +
				'</div>' +
				'<h2 style="margin:40px 0 0;font:800 84px ' + POPPINS + ';line-height:1.04;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
				'<p style="margin:34px 0 0;font:500 36px ' + JAKARTA + ';color:#B6BFEC;line-height:1.45;">{{dek}}</p>' +
				'<div style="flex:1;display:flex;align-items:center;padding:56px 0;">' +
					'<div style="width:100%;border:2px solid rgba(255,255,255,.13);border-radius:28px;padding:14px;background:rgba(255,255,255,.04);">{{img:news-story}}</div>' +
				'</div>' +
				'<div style="border-top:1px solid rgba(255,255,255,.12);padding-top:28px;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
						'<span style="font:600 26px ' + JAKARTA + ';color:#9BA7E8;">@{{handle}}</span>' +
						'<span style="font:600 24px ' + JAKARTA + ';color:#6E76A8;">{{swipe}}</span>' +
					'</div>' +
					'{{#legal}}<p style="margin:18px 0 0;font:400 17px ' + JAKARTA + ';color:#6E76A8;line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	// --- News · Twitter / X 1600×900 ---------------------------------------
	var newsX = {
		id: 'news-x',
		category: 'news',
		label: { en: 'News · X', pt: 'Notícias · X' },
		w: 1600,
		h: 900,
		images: { 'news-tw': { h: '100%', radius: 20, placeholder: 'Arrasta uma imagem' } },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Notícias' },
			{ key: 'date', label: { en: 'Date', pt: 'Data' }, type: 'text', default: '16 jun 2026' },
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Banco Central mantém as taxas de juro.' },
			{ key: 'dek', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'Uma leitura calma do que a decisão significa para quem poupa.' }
		],
		html:
			'<div style="width:1600px;height:900px;background:linear-gradient(120deg,#1C2150,#0F1130);display:flex;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="flex:1;display:flex;flex-direction:column;padding:72px;">' +
					'<div style="display:flex;align-items:center;gap:16px;">' +
						'<span style="width:58px;height:58px;display:flex;flex:none;">{{logo}}</span>' +
						'<span style="font:700 30px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
					'</div>' +
					'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
						'<div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">' +
							'<span style="font:700 17px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:10px 22px;border-radius:999px;">{{badge}}</span>' +
							'<span style="font:600 20px ' + JAKARTA + ';color:#9BA7E8;">{{date}}</span>' +
						'</div>' +
						'<h2 style="margin:0;font:800 60px ' + POPPINS + ';line-height:1.04;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
						'<p style="margin:24px 0 0;font:500 27px ' + JAKARTA + ';color:#B6BFEC;line-height:1.4;">{{dek}}</p>' +
					'</div>' +
					'<div>' +
						'<span style="font:600 22px ' + JAKARTA + ';color:#9BA7E8;">@{{handleTw}}</span>' +
						'{{#legal}}<p style="margin:12px 0 0;font:400 14px ' + JAKARTA + ';color:#6E76A8;line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
				'<div style="flex:none;width:620px;padding:48px 48px 48px 0;">{{img:news-tw}}</div>' +
			'</div>'
	};

	// --- Glossary · Facebook 1080×1080 -------------------------------------
	var glossaryFb = {
		id: 'glossary-fb',
		category: 'glossary',
		label: { en: 'Glossary · Facebook', pt: 'Glossário · Facebook' },
		w: 1080,
		h: 1080,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Glossário' },
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'Conceito · 4 min' },
			{ key: 'term', label: { en: 'Term', pt: 'Termo' }, type: 'text', default: 'ETF' },
			{ key: 'definition', label: { en: 'What it is', pt: 'O que é' }, type: 'textarea', default: 'Um fundo que junta muitos investimentos num só e que se compra e vende em bolsa, como se fosse uma ação.' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1080px;overflow:hidden;background:#FFF6F1;font-family:' + JAKARTA + ';color:#2A2438;box-sizing:border-box;">' +
				'<span style="position:absolute;top:-130px;right:-30px;font:800 560px ' + POPPINS + ';color:#F8E7E1;line-height:1;z-index:0;">{{initial}}</span>' +
				'<div style="position:relative;z-index:1;height:100%;display:flex;flex-direction:column;padding:72px;box-sizing:border-box;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;">' +
						'<div style="display:flex;align-items:center;gap:14px;"><span style="width:54px;height:54px;display:flex;flex:none;">{{logo}}</span><span style="font:700 28px ' + POPPINS + ';color:#2A2438;">HowToInvest</span></div>' +
						'<span style="font:700 17px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#FF6B5E;background:#FFEDE9;padding:11px 22px;border-radius:999px;">{{badge}}</span>' +
					'</div>' +
					'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
						'<span style="font:700 21px ' + JAKARTA + ';letter-spacing:.04em;text-transform:uppercase;color:#7C5CFC;">{{kicker}}</span>' +
						'<h2 style="margin:8px 0 0;font:800 108px ' + POPPINS + ';line-height:.95;letter-spacing:-.03em;color:#2A2438;">{{term}}</h2>' +
						'<span style="display:block;width:120px;height:8px;background:#FF6B5E;border-radius:4px;margin-top:18px;"></span>' +
						'<div style="margin-top:34px;background:#fff;border:1px solid #F2E4DD;border-left:6px solid #FF6B5E;border-radius:18px;padding:28px 32px;">' +
							'<span style="font:700 16px ' + JAKARTA + ';letter-spacing:.08em;text-transform:uppercase;color:#FF6B5E;">O que é?</span>' +
							'<p style="margin:10px 0 0;font:500 31px ' + JAKARTA + ';color:#3A3450;line-height:1.4;">{{definition}}</p>' +
						'</div>' +
					'</div>' +
					'<div style="border-top:1px solid #F2E4DD;padding-top:22px;display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
						'<span style="font:600 21px ' + JAKARTA + ';color:#A89FB5;">@{{handle}}</span>' +
						'{{#legal}}<p style="margin:0;font:400 14px ' + JAKARTA + ';color:#B7AEC4;line-height:1.4;max-width:50ch;text-align:right;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- Glossary · Instagram feed 1080×1350 -------------------------------
	var glossaryFeed = {
		id: 'glossary-feed',
		category: 'glossary',
		label: { en: 'Glossary · Feed', pt: 'Glossário · Feed' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Glossário' },
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'Conceito · 4 min' },
			{ key: 'term', label: { en: 'Term', pt: 'Termo' }, type: 'text', default: 'ETF' },
			{ key: 'definition', label: { en: 'What it is', pt: 'O que é' }, type: 'textarea', default: 'Um fundo que junta muitos investimentos num só e que se compra e vende em bolsa, como se fosse uma ação.' },
			{ key: 'simpleWords', label: { en: 'In simple words', pt: 'Em palavras simples' }, type: 'textarea', default: 'Em vez de comprares 500 empresas uma a uma, um ETF do índice dá-te exposição às 500 de uma só vez — normalmente com um custo baixo.' },
			{ key: 'related', label: { en: 'Related terms (comma)', pt: 'Termos relacionados (vírgulas)' }, type: 'text', default: 'Diversificação, Ações globais, Liquidez' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1350px;overflow:hidden;background:#FFF6F1;font-family:' + JAKARTA + ';color:#2A2438;box-sizing:border-box;">' +
				'<span style="position:absolute;top:-160px;right:-40px;font:800 640px ' + POPPINS + ';color:#F8E7E1;line-height:1;z-index:0;">{{initial}}</span>' +
				'<div style="position:relative;z-index:1;height:100%;display:flex;flex-direction:column;padding:80px;box-sizing:border-box;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;">' +
						'<div style="display:flex;align-items:center;gap:14px;"><span style="width:56px;height:56px;display:flex;flex:none;">{{logo}}</span><span style="font:700 29px ' + POPPINS + ';color:#2A2438;">HowToInvest</span></div>' +
						'<span style="font:700 17px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#FF6B5E;background:#FFEDE9;padding:11px 22px;border-radius:999px;">{{badge}}</span>' +
					'</div>' +
					'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
						'<span style="font:700 22px ' + JAKARTA + ';letter-spacing:.04em;text-transform:uppercase;color:#7C5CFC;">{{kicker}}</span>' +
						'<h2 style="margin:10px 0 0;font:800 124px ' + POPPINS + ';line-height:.92;letter-spacing:-.03em;color:#2A2438;">{{term}}</h2>' +
						'<span style="display:block;width:130px;height:9px;background:#FF6B5E;border-radius:5px;margin-top:20px;"></span>' +
						'<div style="margin-top:38px;background:#fff;border:1px solid #F2E4DD;border-left:6px solid #FF6B5E;border-radius:20px;padding:30px 34px;">' +
							'<span style="font:700 17px ' + JAKARTA + ';letter-spacing:.08em;text-transform:uppercase;color:#FF6B5E;">O que é?</span>' +
							'<p style="margin:10px 0 0;font:500 32px ' + JAKARTA + ';color:#3A3450;line-height:1.4;">{{definition}}</p>' +
						'</div>' +
						'<div style="margin-top:22px;background:#FFEDE9;border-radius:20px;padding:30px 34px;">' +
							'<span style="font:700 17px ' + JAKARTA + ';letter-spacing:.08em;text-transform:uppercase;color:#FF6B5E;">Em palavras simples</span>' +
							'<p style="margin:10px 0 0;font:500 30px ' + JAKARTA + ';color:#3A3450;line-height:1.4;">{{simpleWords}}</p>' +
						'</div>' +
						'{{#has:related}}<div style="margin-top:30px;">' +
							'<span style="font:700 16px ' + JAKARTA + ';letter-spacing:.06em;text-transform:uppercase;color:#A89FB5;">Termos relacionados</span>' +
							'<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;">{{chips:related}}</div>' +
						'</div>{{/has:related}}' +
					'</div>' +
					'<div style="border-top:1px solid #F2E4DD;padding-top:24px;">' +
						'<div style="display:flex;align-items:center;justify-content:space-between;gap:20px;">' +
							'<span style="font:600 22px ' + JAKARTA + ';color:#A89FB5;">@{{handle}}</span>' +
						'</div>' +
						'{{#legal}}<p style="margin:16px 0 0;font:400 14.5px ' + JAKARTA + ';color:#B7AEC4;line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- Glossary · Stories 1080×1920 --------------------------------------
	var glossaryStory = {
		id: 'glossary-story',
		category: 'glossary',
		label: { en: 'Glossary · Story', pt: 'Glossário · Story' },
		w: 1080,
		h: 1920,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Glossário' },
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'Termo da semana · Conceito' },
			{ key: 'term', label: { en: 'Term', pt: 'Termo' }, type: 'text', default: 'ETF' },
			{ key: 'definition', label: { en: 'What it is', pt: 'O que é' }, type: 'textarea', default: 'Um fundo que junta muitos investimentos num só e que se compra e vende em bolsa, como se fosse uma ação.' },
			{ key: 'simpleWords', label: { en: 'In simple words', pt: 'Em palavras simples' }, type: 'textarea', default: 'Em vez de comprares 500 empresas uma a uma, um ETF do índice dá-te exposição às 500 de uma só vez.' },
			{ key: 'ctaText', label: { en: 'CTA', pt: 'CTA' }, type: 'text', default: 'Vê mais em howtoinvest.pro' },
			{ key: 'tapHint', label: { en: 'Tap hint', pt: 'Dica de toque' }, type: 'text', default: 'Toca no link ↑' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1920px;overflow:hidden;background:#FFF6F1;font-family:' + JAKARTA + ';color:#2A2438;box-sizing:border-box;">' +
				'<span style="position:absolute;top:380px;right:-60px;font:800 760px ' + POPPINS + ';color:#F8E7E1;line-height:1;z-index:0;">{{initial}}</span>' +
				'<div style="position:relative;z-index:1;height:100%;display:flex;flex-direction:column;padding:130px 80px;box-sizing:border-box;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;">' +
						'<div style="display:flex;align-items:center;gap:16px;"><span style="width:62px;height:62px;display:flex;flex:none;">{{logo}}</span><span style="font:700 32px ' + POPPINS + ';color:#2A2438;">HowToInvest</span></div>' +
						'<span style="font:700 19px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#FF6B5E;background:#FFEDE9;padding:13px 26px;border-radius:999px;">{{badge}}</span>' +
					'</div>' +
					'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
						'<span style="font:700 24px ' + JAKARTA + ';letter-spacing:.04em;text-transform:uppercase;color:#7C5CFC;">{{kicker}}</span>' +
						'<h2 style="margin:14px 0 0;font:800 150px ' + POPPINS + ';line-height:.9;letter-spacing:-.03em;color:#2A2438;">{{term}}</h2>' +
						'<span style="display:block;width:150px;height:10px;background:#FF6B5E;border-radius:5px;margin-top:24px;"></span>' +
						'<div style="margin-top:54px;background:#fff;border:1px solid #F2E4DD;border-left:7px solid #FF6B5E;border-radius:22px;padding:36px 40px;">' +
							'<span style="font:700 19px ' + JAKARTA + ';letter-spacing:.08em;text-transform:uppercase;color:#FF6B5E;">O que é?</span>' +
							'<p style="margin:12px 0 0;font:500 38px ' + JAKARTA + ';color:#3A3450;line-height:1.4;">{{definition}}</p>' +
						'</div>' +
						'<div style="margin-top:26px;background:#FFEDE9;border-radius:22px;padding:36px 40px;">' +
							'<span style="font:700 19px ' + JAKARTA + ';letter-spacing:.08em;text-transform:uppercase;color:#FF6B5E;">Em palavras simples</span>' +
							'<p style="margin:12px 0 0;font:500 36px ' + JAKARTA + ';color:#3A3450;line-height:1.4;">{{simpleWords}}</p>' +
						'</div>' +
					'</div>' +
					'<div>' +
						'<div style="display:flex;align-items:center;justify-content:center;gap:16px;background:#FF6B5E;border-radius:20px;padding:30px;box-shadow:0 10px 28px rgba(255,107,94,.32);">' +
							'<span style="font:700 34px ' + POPPINS + ';color:#fff;letter-spacing:-.01em;">{{ctaText}}</span>' +
							'<span style="font:700 34px ' + POPPINS + ';color:#fff;">→</span>' +
						'</div>' +
						'<div style="margin-top:22px;text-align:center;">' +
							'<span style="font:600 25px ' + JAKARTA + ';color:#A89FB5;">{{tapHint}} · @{{handle}}</span>' +
						'</div>' +
						'{{#legal}}<p style="margin:18px 0 0;font:400 16px ' + JAKARTA + ';color:#B7AEC4;line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- Fun fact · Square (green) 1080×1080 -------------------------------
	var factGreen = {
		id: 'fact-green',
		category: 'fact',
		label: { en: 'Fun fact · Green', pt: 'Facto curioso · Verde' },
		w: 1080,
		h: 1080,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Sabias que?' },
			{ key: 'headline', label: { en: 'Fact', pt: 'Facto' }, type: 'textarea', default: 'A primeira Bolsa de Valores do mundo nasceu em 1602.' },
			{ key: 'body', label: { en: 'Detail', pt: 'Detalhe' }, type: 'textarea', default: 'Em Amesterdão, para financiar viagens comerciais por todo o mundo.' }
		],
		html:
			'<div style="width:1080px;height:1080px;background:radial-gradient(120% 100% at 50% 0%,#147A57,#0B4D37 70%);display:flex;flex-direction:column;align-items:center;padding:72px;color:#fff;text-align:center;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<span style="font:700 20px ' + JAKARTA + ';letter-spacing:.2em;text-transform:uppercase;color:#0B4D37;background:#7FE0B0;padding:13px 30px;border-radius:999px;">{{badge}}</span>' +
				'<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:40px;">' +
					'<h2 style="margin:0;font:800 62px ' + POPPINS + ';line-height:1.1;letter-spacing:-.02em;color:#fff;max-width:16ch;">{{headline}}</h2>' +
					'<div style="width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;"><span style="width:150px;height:150px;display:flex;">{{illoShip}}</span></div>' +
					'<p style="margin:0;font:500 26px ' + JAKARTA + ';color:#BFEFD7;line-height:1.45;max-width:30ch;">{{body}}</p>' +
				'</div>' +
				'<div style="width:100%;display:flex;align-items:center;justify-content:center;gap:16px;">' +
					'<span style="width:50px;height:50px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 26px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
					'<span style="font:500 22px ' + JAKARTA + ';color:#7FE0B0;">· @{{handle}}</span>' +
				'</div>' +
				'{{#legal}}<p style="margin:18px 0 0;font:400 13.5px ' + JAKARTA + ';color:rgba(191,239,215,.7);line-height:1.4;max-width:64ch;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	// --- Fun fact · Square (purple) 1080×1080 ------------------------------
	var factPurple = {
		id: 'fact-purple',
		category: 'fact',
		label: { en: 'Fun fact · Purple', pt: 'Facto curioso · Roxo' },
		w: 1080,
		h: 1080,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Facto curioso' },
			{ key: 'headline', label: { en: 'Fact', pt: 'Facto' }, type: 'textarea', default: 'Juntar 5€ por dia dá mais de 1800€ ao fim de um ano.' },
			{ key: 'body', label: { en: 'Detail', pt: 'Detalhe' }, type: 'textarea', default: 'O hábito constante pesa mais do que o valor de cada contribuição.' }
		],
		html:
			'<div style="width:1080px;height:1080px;background:radial-gradient(120% 100% at 50% 0%,#3A2280,#1C1046 70%);display:flex;flex-direction:column;align-items:center;padding:72px;color:#fff;text-align:center;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<span style="font:700 20px ' + JAKARTA + ';letter-spacing:.2em;text-transform:uppercase;color:#fff;background:#7C5CFC;padding:13px 30px;border-radius:999px;">{{badge}}</span>' +
				'<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:40px;">' +
					'<h2 style="margin:0;font:800 62px ' + POPPINS + ';line-height:1.1;letter-spacing:-.02em;color:#fff;max-width:16ch;">{{headline}}</h2>' +
					'<div style="width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.07);display:flex;align-items:center;justify-content:center;"><span style="width:150px;height:150px;display:flex;">{{illoGold}}</span></div>' +
					'<p style="margin:0;font:500 26px ' + JAKARTA + ';color:#C9BCF7;line-height:1.45;max-width:30ch;">{{body}}</p>' +
				'</div>' +
				'<div style="width:100%;display:flex;align-items:center;justify-content:center;gap:16px;">' +
					'<span style="width:50px;height:50px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 26px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
					'<span style="font:500 22px ' + JAKARTA + ';color:#C9BCF7;">· @{{handle}}</span>' +
				'</div>' +
				'{{#legal}}<p style="margin:18px 0 0;font:400 13.5px ' + JAKARTA + ';color:rgba(201,188,247,.65);line-height:1.4;max-width:64ch;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	// --- Fun fact · Story (green) 1080×1920 --------------------------------
	var factStory = {
		id: 'fact-story',
		category: 'fact',
		label: { en: 'Fun fact · Story', pt: 'Facto curioso · Story' },
		w: 1080,
		h: 1920,
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Sabias que?' },
			{ key: 'headline', label: { en: 'Fact', pt: 'Facto' }, type: 'textarea', default: 'A primeira Bolsa de Valores nasceu em 1602.' },
			{ key: 'body', label: { en: 'Detail', pt: 'Detalhe' }, type: 'textarea', default: 'Em Amesterdão, para financiar viagens comerciais por todo o mundo.' }
		],
		html:
			'<div style="width:1080px;height:1920px;background:radial-gradient(110% 80% at 50% 12%,#147A57,#0B4D37 72%);display:flex;flex-direction:column;align-items:center;padding:130px 80px;color:#fff;text-align:center;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<span style="font:700 24px ' + JAKARTA + ';letter-spacing:.2em;text-transform:uppercase;color:#0B4D37;background:#7FE0B0;padding:16px 36px;border-radius:999px;">{{badge}}</span>' +
				'<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:60px;">' +
					'<div style="width:320px;height:320px;border-radius:50%;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;"><span style="width:210px;height:210px;display:flex;">{{illoShip}}</span></div>' +
					'<h2 style="margin:0;font:800 80px ' + POPPINS + ';line-height:1.08;letter-spacing:-.02em;color:#fff;max-width:14ch;">{{headline}}</h2>' +
					'<p style="margin:0;font:500 34px ' + JAKARTA + ';color:#BFEFD7;line-height:1.45;max-width:26ch;">{{body}}</p>' +
				'</div>' +
				'<div style="display:flex;align-items:center;justify-content:center;gap:18px;">' +
					'<span style="width:56px;height:56px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 30px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
					'<span style="font:500 26px ' + JAKARTA + ';color:#7FE0B0;">· @{{handle}}</span>' +
				'</div>' +
				'{{#legal}}<p style="margin:22px 0 0;font:400 16px ' + JAKARTA + ';color:rgba(191,239,215,.7);line-height:1.4;max-width:66ch;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	// --- Quiz CTA · Square 1080×1080 ---------------------------------------
	var ctaSquare = {
		id: 'cta-square',
		category: 'cta',
		label: { en: 'Quiz CTA · Square', pt: 'CTA Questionário · Quadrado' },
		w: 1080,
		h: 1080,
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Que tipo de investidor és?' },
			{ key: 'body', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'Descobre o teu perfil em 6 perguntas — grátis, sem jargão e sem registo.' },
			{ key: 'step1', label: { en: 'Step 1', pt: 'Passo 1' }, type: 'text', default: '1 · Respondes' },
			{ key: 'step2', label: { en: 'Step 2', pt: 'Passo 2' }, type: 'text', default: '2 · Vês o perfil' },
			{ key: 'step3', label: { en: 'Step 3', pt: 'Passo 3' }, type: 'text', default: '3 · Aprendes' },
			{ key: 'button', label: { en: 'Button', pt: 'Botão' }, type: 'text', default: 'Fazer o questionário →' }
		],
		html:
			'<div style="width:1080px;height:1080px;background:linear-gradient(160deg,#FF7A6B,#F2503F);display:flex;flex-direction:column;padding:72px;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;gap:16px;">' +
					'<span style="width:58px;height:58px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 29px ' + POPPINS + ';color:#fff;letter-spacing:-.01em;">HowToInvest</span>' +
				'</div>' +
				'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
					'<h2 style="margin:0;font:800 78px ' + POPPINS + ';line-height:1.02;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
					'<p style="margin:26px 0 0;font:500 31px ' + JAKARTA + ';color:#FFE2DC;line-height:1.4;max-width:26ch;">{{body}}</p>' +
					'<div style="display:flex;gap:14px;margin-top:40px;flex-wrap:wrap;">' +
						'<span style="font:600 22px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:14px 26px;border-radius:999px;">{{step1}}</span>' +
						'<span style="font:600 22px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:14px 26px;border-radius:999px;">{{step2}}</span>' +
						'<span style="font:600 22px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:14px 26px;border-radius:999px;">{{step3}}</span>' +
					'</div>' +
					'<div style="margin-top:44px;align-self:flex-start;background:#fff;color:#F2503F;font:700 30px ' + POPPINS + ';padding:24px 44px;border-radius:18px;">{{button}}</div>' +
				'</div>' +
				'<div style="border-top:1px solid rgba(255,255,255,.22);padding-top:22px;display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
					'<span style="font:600 21px ' + JAKARTA + ';color:#FFE2DC;">@{{handle}}</span>' +
					'<span style="font:600 19px ' + JAKARTA + ';color:#FFCFC7;">{{domain}}</span>' +
				'</div>' +
				'{{#legal}}<p style="margin:14px 0 0;font:400 14.5px ' + JAKARTA + ';color:#FFCFC7;line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	// --- Quiz CTA · Story 1080×1920 ----------------------------------------
	var ctaStory = {
		id: 'cta-story',
		category: 'cta',
		label: { en: 'Quiz CTA · Story', pt: 'CTA Questionário · Story' },
		w: 1080,
		h: 1920,
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Que tipo de investidor és?' },
			{ key: 'body', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'Descobre o teu perfil em 6 perguntas — grátis e sem registo.' },
			{ key: 'step1', label: { en: 'Step 1', pt: 'Passo 1' }, type: 'text', default: '1 · Respondes a 6 perguntas curtas' },
			{ key: 'step2', label: { en: 'Step 2', pt: 'Passo 2' }, type: 'text', default: '2 · Vês o teu perfil de investidor' },
			{ key: 'step3', label: { en: 'Step 3', pt: 'Passo 3' }, type: 'text', default: '3 · Aprendes o que estudar a seguir' },
			{ key: 'button', label: { en: 'Button', pt: 'Botão' }, type: 'text', default: 'Fazer o questionário →' }
		],
		html:
			'<div style="width:1080px;height:1920px;background:linear-gradient(165deg,#FF7A6B,#F2503F);display:flex;flex-direction:column;padding:130px 80px;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;gap:18px;">' +
					'<span style="width:64px;height:64px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 34px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
				'</div>' +
				'<div style="flex:1;display:flex;flex-direction:column;justify-content:center;">' +
					'<h2 style="margin:0;font:800 96px ' + POPPINS + ';line-height:1.0;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
					'<p style="margin:34px 0 0;font:500 38px ' + JAKARTA + ';color:#FFE2DC;line-height:1.4;max-width:22ch;">{{body}}</p>' +
					'<div style="display:flex;flex-direction:column;gap:20px;margin-top:54px;">' +
						'<span style="font:600 30px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:22px 32px;border-radius:18px;">{{step1}}</span>' +
						'<span style="font:600 30px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:22px 32px;border-radius:18px;">{{step2}}</span>' +
						'<span style="font:600 30px ' + JAKARTA + ';color:#fff;background:rgba(255,255,255,.18);padding:22px 32px;border-radius:18px;">{{step3}}</span>' +
					'</div>' +
					'<div style="margin-top:60px;align-self:flex-start;background:#fff;color:#F2503F;font:700 38px ' + POPPINS + ';padding:30px 54px;border-radius:22px;">{{button}}</div>' +
				'</div>' +
				'<div style="border-top:1px solid rgba(255,255,255,.22);padding-top:28px;display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
					'<span style="font:600 26px ' + JAKARTA + ';color:#FFE2DC;">@{{handle}}</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';color:#FFCFC7;">{{domain}}</span>' +
				'</div>' +
				'{{#legal}}<p style="margin:18px 0 0;font:400 17px ' + JAKARTA + ';color:#FFCFC7;line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	// --- Quiz CTA · X 1600×900 ---------------------------------------------
	var ctaX = {
		id: 'cta-x',
		category: 'cta',
		label: { en: 'Quiz CTA · X', pt: 'CTA Questionário · X' },
		w: 1600,
		h: 900,
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Que tipo de investidor és?' },
			{ key: 'body', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: '6 perguntas, grátis e sem registo. Descobre o teu perfil.' },
			{ key: 'button', label: { en: 'Button', pt: 'Botão' }, type: 'text', default: 'Fazer o questionário →' }
		],
		html:
			'<div style="width:1600px;height:900px;background:linear-gradient(125deg,#FF7A6B,#F2503F);display:flex;flex-direction:column;padding:80px;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;gap:16px;">' +
					'<span style="width:58px;height:58px;display:flex;flex:none;">{{logo}}</span>' +
					'<span style="font:700 30px ' + POPPINS + ';color:#fff;">HowToInvest</span>' +
				'</div>' +
				'<div style="flex:1;display:flex;align-items:center;justify-content:space-between;gap:48px;">' +
					'<div style="flex:1;">' +
						'<h2 style="margin:0;font:800 74px ' + POPPINS + ';line-height:1.0;letter-spacing:-.02em;color:#fff;max-width:14ch;">{{headline}}</h2>' +
						'<p style="margin:24px 0 0;font:500 30px ' + JAKARTA + ';color:#FFE2DC;line-height:1.4;max-width:28ch;">{{body}}</p>' +
					'</div>' +
					'<div style="flex:none;background:#fff;color:#F2503F;font:700 34px ' + POPPINS + ';padding:30px 50px;border-radius:20px;white-space:nowrap;">{{button}}</div>' +
				'</div>' +
				'<div style="border-top:1px solid rgba(255,255,255,.22);padding-top:22px;display:flex;align-items:center;justify-content:space-between;gap:24px;">' +
					'<span style="font:600 22px ' + JAKARTA + ';color:#FFE2DC;">@{{handleTw}} · {{domain}}</span>' +
					'{{#legal}}<p style="margin:0;font:400 14px ' + JAKARTA + ';color:#FFCFC7;line-height:1.4;max-width:58ch;text-align:right;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	// --- og:image · Full photo 1200×630 -----------------------------------
	var ogPhoto = {
		id: 'og-photo',
		category: 'og',
		label: { en: 'og:image · Full photo', pt: 'og:image · Foto cheia' },
		w: 1200,
		h: 630,
		images: { 'og-photo-bg': { h: '100%', radius: 0, placeholder: 'Arrasta a foto do artigo' } },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Notícias' },
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Banco Central mantém as taxas de juro.' },
			{ key: 'dek', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'O que muda — e o que não muda — para quem poupa.' }
		],
		html:
			'<div style="position:relative;width:1200px;height:630px;overflow:hidden;background:#0B0D24;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;inset:0;">{{img:og-photo-bg}}</div>' +
				'<div style="position:absolute;inset:0;background:linear-gradient(100deg,rgba(8,10,30,.92) 0%,rgba(8,10,30,.7) 38%,rgba(8,10,30,.15) 70%,rgba(8,10,30,.45) 100%);"></div>' +
				'<div style="position:absolute;inset:0;display:flex;flex-direction:column;justify-content:space-between;padding:56px 60px;">' +
					'<div style="display:flex;align-items:center;justify-content:space-between;">' +
						'<div style="display:flex;align-items:center;gap:14px;"><span style="width:50px;height:50px;display:flex;flex:none;">{{logo}}</span><span style="font:700 27px ' + POPPINS + ';color:#fff;text-shadow:0 1px 8px rgba(0,0,0,.5);">HowToInvest</span></div>' +
						'<span style="font:700 16px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:10px 20px;border-radius:999px;">{{badge}}</span>' +
					'</div>' +
					'<div style="max-width:680px;">' +
						'<span style="display:block;width:64px;height:5px;background:#FF6B5E;border-radius:3px;margin-bottom:18px;"></span>' +
						'<h2 style="margin:0;font:800 60px ' + POPPINS + ';line-height:1.02;letter-spacing:-.02em;color:#fff;text-shadow:0 2px 14px rgba(0,0,0,.5);">{{headline}}</h2>' +
						'<p style="margin:16px 0 0;font:600 22px ' + JAKARTA + ';color:#C7CEF2;line-height:1.35;text-shadow:0 1px 8px rgba(0,0,0,.5);">{{dek}}</p>' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- og:image · Split 1200×630 -----------------------------------------
	var ogSplit = {
		id: 'og-split',
		category: 'og',
		label: { en: 'og:image · Split', pt: 'og:image · Split' },
		w: 1200,
		h: 630,
		images: { 'og-split-img': { h: '100%', radius: 20, placeholder: 'Arrasta a foto' } },
		fields: [
			{ key: 'badge', label: { en: 'Badge', pt: 'Etiqueta' }, type: 'text', default: 'Notícias' },
			{ key: 'date', label: { en: 'Date', pt: 'Data' }, type: 'text', default: '16 jun 2026' },
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Reabertura de Ormuz pode baixar o Brent em 2026.' }
		],
		html:
			'<div style="display:flex;width:1200px;height:630px;overflow:hidden;background:linear-gradient(155deg,#1C2150,#0F1130);font-family:' + JAKARTA + ';color:#fff;">' +
				'<div style="flex:1;display:flex;flex-direction:column;justify-content:space-between;padding:56px;">' +
					'<div style="display:flex;align-items:center;gap:14px;"><span style="width:50px;height:50px;display:flex;flex:none;">{{logo}}</span><span style="font:700 27px ' + POPPINS + ';color:#fff;">HowToInvest</span></div>' +
					'<div>' +
						'<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">' +
							'<span style="font:700 15px ' + JAKARTA + ';letter-spacing:.16em;text-transform:uppercase;color:#fff;background:#FF6B5E;padding:9px 18px;border-radius:999px;">{{badge}}</span>' +
							'<span style="font:600 18px ' + JAKARTA + ';color:#9BA7E8;">{{date}}</span>' +
						'</div>' +
						'<h2 style="margin:0;font:800 52px ' + POPPINS + ';line-height:1.05;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
					'</div>' +
					'<span style="font:600 19px ' + JAKARTA + ';color:#6E76A8;">{{domain}}</span>' +
				'</div>' +
				'<div style="flex:none;width:480px;padding:32px 32px 32px 0;">{{img:og-split-img}}</div>' +
			'</div>'
	};

	// --- Editorial · Featured news 4:5 -------------------------------------
	var edNews = {
		id: 'ed-news',
		category: 'editorial',
		label: { en: 'Editorial · Featured', pt: 'Editorial · Destaque' },
		w: 1080,
		h: 1350,
		images: { 'ed-news-bg': { h: '100%', radius: 0, placeholder: 'Arrasta a foto principal — retrato, edifício, mercado…' } },
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Decisão de taxas de juro do BCE é hoje.' },
			{ key: 'dek', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'Os mercados esperam que as taxas fiquem inalteradas.' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1350px;overflow:hidden;background:#0B0D24;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;inset:0;">{{img:ed-news-bg}}</div>' +
				'<div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(8,10,30,.5) 0%,rgba(8,10,30,0) 26%,rgba(8,10,30,.08) 46%,rgba(8,10,30,.78) 68%,#080A1E 100%);"></div>' +
				'<div style="position:absolute;inset:0;display:flex;flex-direction:column;justify-content:space-between;padding:64px;">' +
					'<div style="display:flex;align-items:center;gap:16px;"><span style="width:58px;height:58px;display:flex;flex:none;">{{logo}}</span><span style="font:700 30px ' + POPPINS + ';color:#fff;text-shadow:0 1px 8px rgba(0,0,0,.5);">HowToInvest</span></div>' +
					'<div>' +
						'<div style="display:flex;align-items:center;gap:18px;margin-bottom:26px;">' +
							'<span style="flex:1;height:1px;background:rgba(255,255,255,.4);"></span>' +
							'<span style="font:700 19px ' + JAKARTA + ';letter-spacing:.08em;color:#fff;">@{{handle}}</span>' +
							'<span style="flex:1;height:1px;background:rgba(255,255,255,.4);"></span>' +
						'</div>' +
						'<h2 style="margin:0;font:800 70px ' + POPPINS + ';line-height:1.02;letter-spacing:-.01em;text-transform:uppercase;color:#F4C24E;text-shadow:0 2px 16px rgba(0,0,0,.5);">{{headline}}</h2>' +
						'<p style="margin:22px 0 0;font:700 28px ' + JAKARTA + ';color:#fff;line-height:1.3;text-shadow:0 1px 10px rgba(0,0,0,.55);">{{dek}}</p>' +
						'{{#legal}}<p style="margin:22px 0 0;font:400 15px ' + JAKARTA + ';color:rgba(255,255,255,.62);line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- Editorial · Economy 4:5 -------------------------------------------
	var edEcon = {
		id: 'ed-econ',
		category: 'editorial',
		label: { en: 'Editorial · Economy', pt: 'Editorial · Economia' },
		w: 1080,
		h: 1350,
		images: { 'ed-econ-bg': { h: '100%', radius: 0, placeholder: 'Arrasta a foto — mercado, barris, gráfico…' } },
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Reabertura total de Ormuz pode baixar o Brent para 82€/barril em 2026.' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1350px;overflow:hidden;background:#11131F;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;inset:0;">{{img:ed-econ-bg}}</div>' +
				'<div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,12,24,.45) 0%,rgba(10,12,24,0) 30%,rgba(10,12,24,.05) 55%,rgba(10,12,24,.9) 88%);"></div>' +
				'<div style="position:absolute;inset:0;display:flex;flex-direction:column;justify-content:space-between;padding:64px;">' +
					'<div>' +
						'<div style="display:flex;align-items:center;gap:14px;"><span style="width:54px;height:54px;display:flex;flex:none;">{{logo}}</span><span style="font:700 30px ' + POPPINS + ';color:#fff;text-shadow:0 1px 8px rgba(0,0,0,.5);">HowToInvest</span></div>' +
						'<span style="display:block;width:96px;height:5px;background:#FF6B5E;border-radius:3px;margin-top:14px;"></span>' +
					'</div>' +
					'<div>' +
						'<h2 style="margin:0;font:800 64px ' + POPPINS + ';line-height:1.08;letter-spacing:-.01em;text-transform:uppercase;color:#fff;max-width:18ch;text-shadow:0 2px 16px rgba(0,0,0,.55);">{{headline}}</h2>' +
						'{{#legal}}<p style="margin:22px 0 0;font:400 15px ' + JAKARTA + ';color:rgba(255,255,255,.6);line-height:1.45;">{{disclaimer}}</p>{{/legal}}' +
					'</div>' +
				'</div>' +
			'</div>'
	};

	// --- Editorial · Tool promo (square) -----------------------------------
	var edPromo = {
		id: 'ed-promo',
		category: 'editorial',
		label: { en: 'Editorial · Tool promo', pt: 'Editorial · Promo ferramenta' },
		w: 1080,
		h: 1080,
		images: { 'ed-phone': { h: '100%', radius: 34, placeholder: 'Captura do site / app' } },
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Inteligência de mercado, explicada.' },
			{ key: 'body', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'text', default: 'Educação clara para decisões mais informadas.' },
			{ key: 'button', label: { en: 'Button', pt: 'Botão' }, type: 'text', default: 'Descobre o teu perfil' },
			{ key: 'foot', label: { en: 'Footnote', pt: 'Rodapé' }, type: 'text', default: 'Conteúdo educativo. Não é aconselhamento financeiro.' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1080px;overflow:hidden;background:#0E1030;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;inset:0;background:radial-gradient(75% 85% at 100% 2%,rgba(255,107,94,.95) 0%,rgba(255,107,94,.2) 26%,rgba(14,16,48,0) 52%),linear-gradient(135deg,#15183C,#0A0C20);"></div>' +
				'<div style="position:absolute;inset:0;display:flex;flex-direction:column;padding:68px;color:#fff;">' +
					'<div style="display:flex;align-items:center;gap:14px;"><span style="width:52px;height:52px;display:flex;flex:none;">{{logo}}</span><span style="font:700 28px ' + POPPINS + ';color:#fff;">HowToInvest</span></div>' +
					'<div style="text-align:center;margin-top:26px;">' +
						'<h2 style="margin:0;font:800 58px ' + POPPINS + ';line-height:1.04;letter-spacing:-.02em;color:#fff;">{{headline}}</h2>' +
						'<p style="margin:14px 0 0;font:500 26px ' + JAKARTA + ';color:#B6BFEC;">{{body}}</p>' +
					'</div>' +
					'<div style="flex:1;display:flex;align-items:center;justify-content:center;">' +
						'<div style="position:relative;width:320px;height:500px;background:#1B1F3A;border:11px solid #05060F;border-radius:46px;padding:9px;box-shadow:0 34px 70px rgba(0,0,0,.55);">' +
							'{{img:ed-phone}}' +
							'<div style="position:absolute;top:20px;left:50%;transform:translateX(-50%);width:108px;height:26px;background:#05060F;border-radius:14px;"></div>' +
						'</div>' +
					'</div>' +
					'<div style="display:flex;justify-content:center;">' +
						'<div style="background:#FF6B5E;color:#fff;font:700 30px ' + POPPINS + ';padding:23px 46px;border-radius:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 24px rgba(255,107,94,.4);"><span style="width:30px;height:30px;display:flex;flex:none;">{{logo}}</span>{{button}}</div>' +
					'</div>' +
					'<p style="margin:20px 0 0;text-align:center;font:400 15px ' + JAKARTA + ';color:#7E86B6;">{{foot}}</p>' +
				'</div>' +
			'</div>'
	};

	// --- Editorial · Data infographic 4:5 ----------------------------------
	var edInfographic = {
		id: 'ed-infographic',
		category: 'editorial',
		label: { en: 'Editorial · Infographic', pt: 'Editorial · Infográfico' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'title', label: { en: 'Title', pt: 'Título' }, type: 'text', default: 'O poder do tempo' },
			{ key: 'subtitle', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'text', default: 'Juntar 100€/mês durante 30 anos' },
			{ key: 'legend1', label: { en: 'Legend 1', pt: 'Legenda 1' }, type: 'text', default: 'Investido e diversificado' },
			{ key: 'legend2', label: { en: 'Legend 2', pt: 'Legenda 2' }, type: 'text', default: 'Só a poupar' },
			{ key: 'annotMain', label: { en: 'Main annotation', pt: 'Anotação principal' }, type: 'text', default: '≈ 113 000€' },
			{ key: 'annotSecond', label: { en: 'Second annotation', pt: 'Anotação secundária' }, type: 'text', default: '36 000€ poupados' },
			{ key: 'foot', label: { en: 'Footnote', pt: 'Rodapé' }, type: 'textarea', default: 'Exemplo ilustrativo: retorno médio anual hipotético de 7%. Não é previsão nem recomendação — investir envolve risco, incluindo a perda de capital.' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:radial-gradient(130% 80% at 0% 0%,#1A1E3C,#0B0D20 62%);font-family:' + JAKARTA + ';color:#fff;padding:72px;display:flex;flex-direction:column;box-sizing:border-box;">' +
				'<div style="display:flex;align-items:center;justify-content:space-between;">' +
					'<div style="display:flex;align-items:center;gap:14px;"><span style="width:50px;height:50px;display:flex;flex:none;">{{logo}}</span><span style="font:700 27px ' + POPPINS + ';color:#fff;">HowToInvest</span></div>' +
					'<span style="font:600 22px ' + JAKARTA + ';color:#8189B8;">{{domain}}</span>' +
				'</div>' +
				'<div style="margin-top:46px;">' +
					'<h2 style="margin:0;font:800 96px ' + POPPINS + ';line-height:.98;letter-spacing:-.02em;color:#FF6B5E;">{{title}}</h2>' +
					'<p style="margin:14px 0 0;font:700 32px ' + JAKARTA + ';color:#fff;">{{subtitle}}</p>' +
				'</div>' +
				'<div style="display:flex;gap:26px;margin-top:30px;flex-wrap:wrap;">' +
					'<span style="display:flex;align-items:center;gap:10px;font:600 22px ' + JAKARTA + ';color:#E7E0F6;"><span style="width:18px;height:6px;border-radius:3px;background:#FF6B5E;"></span>{{legend1}}</span>' +
					'<span style="display:flex;align-items:center;gap:10px;font:600 22px ' + JAKARTA + ';color:#E7E0F6;"><span style="width:18px;height:6px;border-radius:3px;background:#7C5CFC;"></span>{{legend2}}</span>' +
				'</div>' +
				'<div style="flex:1;margin-top:24px;">' +
					'<svg viewBox="0 0 940 540" width="100%" height="100%" preserveAspectRatio="none" style="overflow:visible;">' +
						'<g stroke="rgba(255,255,255,.1)" stroke-width="1">' +
							'<line x1="90" y1="40" x2="900" y2="40"></line>' +
							'<line x1="90" y1="147.5" x2="900" y2="147.5"></line>' +
							'<line x1="90" y1="255" x2="900" y2="255"></line>' +
							'<line x1="90" y1="362.5" x2="900" y2="362.5"></line>' +
							'<line x1="90" y1="470" x2="900" y2="470"></line>' +
						'</g>' +
						'<g fill="#7E86B6" font-family="Plus Jakarta Sans" font-size="20" text-anchor="end">' +
							'<text x="76" y="47">120k€</text><text x="76" y="154.5">90k€</text><text x="76" y="262">60k€</text><text x="76" y="369.5">30k€</text><text x="76" y="477">0</text>' +
						'</g>' +
						'<path d="M90 470 L225 445.3 L360 410.6 L495 361.9 L630 293.7 L765 198.1 L900 63.8 L900 470 L90 470 Z" fill="rgba(255,107,94,.16)"></path>' +
						'<path d="M90 470 L225 448.5 L360 427 L495 405.5 L630 384 L765 362.5 L900 341" fill="none" stroke="#7C5CFC" stroke-width="4.5" stroke-dasharray="3 9" stroke-linecap="round"></path>' +
						'<path d="M90 470 L225 445.3 L360 410.6 L495 361.9 L630 293.7 L765 198.1 L900 63.8" fill="none" stroke="#FF6B5E" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"></path>' +
						'<circle cx="900" cy="63.8" r="8" fill="#FF6B5E" stroke="#0B0D20" stroke-width="3"></circle>' +
						'<circle cx="900" cy="341" r="7" fill="#7C5CFC" stroke="#0B0D20" stroke-width="3"></circle>' +
						'<text x="884" y="50" text-anchor="end" font-family="Poppins" font-weight="700" font-size="30" fill="#FF6B5E">{{annotMain}}</text>' +
						'<text x="884" y="332" text-anchor="end" font-family="Poppins" font-weight="700" font-size="26" fill="#B7A6F5">{{annotSecond}}</text>' +
						'<g fill="#7E86B6" font-family="Plus Jakarta Sans" font-size="20" text-anchor="middle">' +
							'<text x="90" y="500">0</text><text x="225" y="500">5</text><text x="360" y="500">10</text><text x="495" y="500">15</text><text x="630" y="500">20</text><text x="765" y="500">25</text><text x="900" y="500">30 anos</text>' +
						'</g>' +
					'</svg>' +
				'</div>' +
				'<p style="margin:14px 0 0;font:400 16px ' + JAKARTA + ';color:#8189B8;line-height:1.45;">{{foot}}</p>' +
			'</div>'
	};

	// --- Editorial · Daily recap 4:5 ---------------------------------------
	var edRecap = {
		id: 'ed-recap',
		category: 'editorial',
		label: { en: 'Editorial · Daily recap', pt: 'Editorial · Resumo diário' },
		w: 1080,
		h: 1350,
		images: { 'ed-recap-bg': { h: '100%', radius: 0, placeholder: 'Arrasta a foto principal' } },
		fields: [
			{ key: 'headline', label: { en: 'Headline', pt: 'Título' }, type: 'textarea', default: 'Tudo o que aconteceu no mundo das finanças nas últimas 24 horas' },
			{ key: 'strap', label: { en: 'Strap', pt: 'Assinatura' }, type: 'text', default: 'HOWTOINVEST.PRO' }
		],
		html:
			'<div style="position:relative;width:1080px;height:1350px;overflow:hidden;background:#11131F;font-family:' + JAKARTA + ';">' +
				'<div style="position:absolute;inset:0;">{{img:ed-recap-bg}}</div>' +
				'<div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,12,24,.35) 0%,rgba(10,12,24,0) 32%,rgba(10,12,24,.12) 52%,rgba(10,12,24,.92) 84%);"></div>' +
				'<div style="position:absolute;top:0;left:0;width:0;height:0;border-top:210px solid #FF6B5E;border-right:210px solid transparent;"></div>' +
				'<span style="position:absolute;top:40px;left:40px;width:62px;height:62px;display:flex;filter:drop-shadow(0 2px 6px rgba(0,0,0,.3));">{{logo}}</span>' +
				'<div style="position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;padding:64px;">' +
					'<h2 style="margin:0;font:800 66px ' + POPPINS + ';line-height:1.05;letter-spacing:-.01em;text-transform:uppercase;color:#fff;text-shadow:0 2px 16px rgba(0,0,0,.55);">{{headline}}</h2>' +
					'<div style="display:flex;align-items:center;gap:18px;margin-top:30px;">' +
						'<span style="flex:1;height:1px;background:rgba(255,255,255,.45);"></span>' +
						'<span style="font:700 20px ' + JAKARTA + ';letter-spacing:.18em;color:#fff;">{{strap}}</span>' +
						'<span style="flex:1;height:1px;background:rgba(255,255,255,.45);"></span>' +
					'</div>' +
					'{{#legal}}<p style="margin:20px 0 0;font:400 15px ' + JAKARTA + ';color:rgba(255,255,255,.6);line-height:1.45;text-align:center;">{{disclaimer}}</p>{{/legal}}' +
				'</div>' +
			'</div>'
	};

	/* =====================================================================
	 * Myth carousel (handoff 10 — "10k Myth Carousel"). Five 1080×1350 (4:5)
	 * slides; export each as its own PNG and post them as an Instagram carousel.
	 * Educational framing only — by design it names no financial instruments;
	 * the slide-4 broker names ship as generic, editable placeholders so the
	 * template itself carries no named companies (project invariant).
	 * ===================================================================== */

	// --- Myth · 01 Hook (dark) ---------------------------------------------
	var mythHook = {
		id: 'myth-hook',
		category: 'carousel',
		label: { en: 'Myth · 01 Hook', pt: 'Mito · 01 Gancho' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'tag', label: { en: 'Myth tag', pt: 'Etiqueta mito' }, type: 'text', default: 'MYTH' },
			{ key: 'pre', label: { en: 'Before amount', pt: 'Antes do valor' }, type: 'text', default: 'You need' },
			{ key: 'amount', label: { en: 'Amount (highlight)', pt: 'Valor (destaque)' }, type: 'text', default: '$10,000' },
			{ key: 'post', label: { en: 'After amount', pt: 'Depois do valor' }, type: 'text', default: 'to start investing' },
			{ key: 'swipe', label: { en: 'Swipe label', pt: 'Texto de deslize' }, type: 'text', default: 'Swipe for the truth' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:#141631;position:relative;overflow:hidden;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="position:absolute;right:-260px;top:-260px;width:720px;height:720px;border-radius:50%;background:radial-gradient(circle at 50% 50%,rgba(124,92,252,.30) 0%,rgba(20,22,49,0) 70%);"></div>' +
				'<div style="position:absolute;left:-220px;bottom:-260px;width:680px;height:680px;border-radius:50%;background:radial-gradient(circle at 50% 50%,rgba(255,107,94,.20) 0%,rgba(20,22,49,0) 70%);"></div>' +
				'<div style="position:absolute;top:64px;left:72px;display:flex;align-items:center;gap:14px;">' +
					'<span style="width:46px;height:46px;display:flex;">' + LOGO_DARK + '</span>' +
					'<span style="font:600 26px ' + JAKARTA + ';letter-spacing:-.02em;">HowToInvest</span>' +
				'</div>' +
				'<div style="position:absolute;top:300px;left:72px;">' +
					'<span style="display:inline-flex;align-items:center;gap:14px;background:#FF3B30;color:#fff;font:700 30px ' + POPPINS + ';letter-spacing:.22em;padding:14px 30px;border-radius:999px;box-shadow:0 12px 40px rgba(255,59,48,.4);">' +
						'<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>{{tag}}' +
					'</span>' +
				'</div>' +
				'<div style="position:absolute;top:404px;left:72px;right:72px;">' +
					'<h1 style="font:800 118px ' + POPPINS + ';line-height:.98;letter-spacing:-.035em;margin:0;position:relative;display:inline-block;">' +
						'{{pre}} <span style="color:#FF6B5E;">{{amount}}</span> {{post}}' +
						'<svg viewBox="0 0 940 560" style="position:absolute;left:-20px;top:-20px;width:calc(100% + 40px);height:calc(100% + 40px);pointer-events:none;" fill="none" preserveAspectRatio="none"><path d="M40 70 L900 500" stroke="#FF3B30" stroke-width="26" stroke-linecap="round"/></svg>' +
					'</h1>' +
				'</div>' +
				'<div style="position:absolute;bottom:96px;left:72px;right:72px;display:flex;align-items:center;gap:22px;">' +
					'<div style="flex:1;height:2px;background:rgba(255,255,255,.16);"></div>' +
					'<span style="font:600 30px ' + JAKARTA + ';color:#A9A4C4;">{{swipe}}</span>' +
					'<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>' +
				'</div>' +
			'</div>'
	};

	// --- Myth · 02 Reality (cream, phone) ----------------------------------
	var mythReality = {
		id: 'myth-reality',
		category: 'carousel',
		label: { en: 'Myth · 02 Reality', pt: 'Mito · 02 Realidade' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'tag', label: { en: 'Tag', pt: 'Etiqueta' }, type: 'text', default: 'Reality' },
			{ key: 'pre', label: { en: 'Before amount', pt: 'Antes do valor' }, type: 'text', default: 'You can start with' },
			{ key: 'amount', label: { en: 'Amount (highlight)', pt: 'Valor (destaque)' }, type: 'text', default: '$5' },
			{ key: 'sub', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: 'The price of a coffee. No minimums, no excuses.' },
			{ key: 'balance', label: { en: 'Phone balance', pt: 'Saldo no telemóvel' }, type: 'text', default: '$5.00' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:#FFF6F1;position:relative;overflow:hidden;color:#2A2438;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="position:absolute;right:-200px;top:-200px;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle at 50% 50%,#FFE7DF 0%,rgba(255,246,241,0) 70%);"></div>' +
				'<div style="position:absolute;top:64px;left:72px;display:flex;align-items:center;gap:14px;">' +
					'<span style="width:44px;height:44px;display:flex;">' + LOGO_LIGHT + '</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';letter-spacing:-.02em;">HowToInvest</span>' +
				'</div>' +
				'<div style="position:absolute;top:230px;left:72px;right:520px;">' +
					'<span style="display:inline-flex;align-items:center;gap:10px;background:#E3F7F1;color:#0E9E82;font:700 20px ' + JAKARTA + ';letter-spacing:.14em;text-transform:uppercase;padding:11px 22px;border-radius:999px;">{{tag}}</span>' +
					'<h1 style="font:800 96px ' + POPPINS + ';line-height:1.0;letter-spacing:-.035em;margin:34px 0 0;">{{pre}} <span style="color:#FF6B5E;">{{amount}}</span></h1>' +
					'<p style="font:400 32px ' + JAKARTA + ';line-height:1.5;color:#6E6680;margin:34px 0 0;max-width:20ch;">{{sub}}</p>' +
				'</div>' +
				'<div style="position:absolute;top:300px;right:96px;">' +
					'<div style="width:372px;height:760px;background:#1E2147;border-radius:52px;padding:16px;box-shadow:0 44px 90px -30px rgba(30,33,71,.55);">' +
						'<div style="width:100%;height:100%;background:#F4F1FB;border-radius:38px;overflow:hidden;position:relative;">' +
							'<div style="position:absolute;top:16px;left:50%;transform:translateX(-50%);width:120px;height:28px;background:#1E2147;border-radius:999px;"></div>' +
							'<div style="padding:58px 26px 0;display:flex;align-items:center;justify-content:space-between;">' +
								'<span style="font:600 17px ' + JAKARTA + ';color:#6E6680;">My account</span>' +
								'<span style="width:34px;height:34px;border-radius:50%;background:#EDE7FB;display:flex;align-items:center;justify-content:center;"><span style="width:14px;height:14px;border-radius:50%;background:#7C5CFC;"></span></span>' +
							'</div>' +
							'<div style="margin:22px 20px 0;background:#fff;border-radius:26px;padding:30px 26px;box-shadow:0 12px 30px -14px rgba(30,33,71,.2);">' +
								'<span style="font:600 15px ' + JAKARTA + ';color:#A89FB5;letter-spacing:.03em;">Available balance</span>' +
								'<div style="font:700 62px ' + POPPINS + ';letter-spacing:-.03em;color:#1E2147;margin-top:8px;">{{balance}}</div>' +
								'<div style="display:flex;align-items:center;gap:8px;margin-top:8px;"><span style="display:inline-flex;align-items:center;gap:5px;background:#E3F7F1;color:#0E9E82;font:700 14px ' + JAKARTA + ';padding:5px 12px;border-radius:999px;">▲ ready to invest</span></div>' +
								'<div style="margin-top:22px;width:100%;background:#FF6B5E;color:#fff;font:600 19px ' + POPPINS + ';padding:16px;border-radius:16px;text-align:center;">Invest now</div>' +
							'</div>' +
							'<div style="margin:20px 20px 0;display:flex;flex-direction:column;gap:12px;">' +
								'<div style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:18px;padding:15px 16px;">' +
									'<span style="width:38px;height:38px;border-radius:11px;background:#EDE7FB;display:flex;align-items:center;justify-content:center;color:#7C5CFC;font:700 15px ' + JAKARTA + ';">ETF</span>' +
									'<div style="flex:1;"><div style="font:600 16px ' + JAKARTA + ';color:#2A2438;">Global index</div><div style="font:500 13px ' + JAKARTA + ';color:#A89FB5;">fractions from $1</div></div>' +
									'<span style="font:700 15px ' + JAKARTA + ';color:#0E9E82;">+0,4%</span>' +
								'</div>' +
								'<div style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:18px;padding:15px 16px;">' +
									'<span style="width:38px;height:38px;border-radius:11px;background:#FFEDE9;display:flex;align-items:center;justify-content:center;color:#FF6B5E;font:700 15px ' + JAKARTA + ';">A</span>' +
									'<div style="flex:1;"><div style="font:600 16px ' + JAKARTA + ';color:#2A2438;">Fractional shares</div><div style="font:500 13px ' + JAKARTA + ';color:#A89FB5;">buy $5 of any stock</div></div>' +
									'<span style="font:700 15px ' + JAKARTA + ';color:#0E9E82;">+1,2%</span>' +
								'</div>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div style="position:absolute;bottom:70px;left:72px;"><span style="font:600 22px ' + JAKARTA + ';color:#A89FB5;">02 / 05</span></div>' +
			'</div>'
	};

	// --- Myth · 03 How (cream, fractional) ---------------------------------
	var mythHow = {
		id: 'myth-how',
		category: 'carousel',
		label: { en: 'Myth · 03 How', pt: 'Mito · 03 Como' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'kicker', label: { en: 'Kicker', pt: 'Antetítulo' }, type: 'text', default: 'HOW?' },
			{ key: 'title1', label: { en: 'Title', pt: 'Título' }, type: 'text', default: 'Fractional' },
			{ key: 'title2', label: { en: 'Title (highlight)', pt: 'Título (destaque)' }, type: 'text', default: 'shares' },
			{ key: 'sub', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: "You don't buy the whole share. You buy a slice of it." },
			{ key: 'sharePrice', label: { en: 'Whole share price', pt: 'Preço da ação inteira' }, type: 'text', default: '≈ $210' },
			{ key: 'slice', label: { en: 'Slice amount', pt: 'Valor da fração' }, type: 'text', default: '$5' },
			{ key: 'note', label: { en: 'Note', pt: 'Nota' }, type: 'textarea', default: 'Your $5 slice still counts. It grows at the same rate.' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:#FFF6F1;position:relative;overflow:hidden;color:#2A2438;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="position:absolute;left:-200px;bottom:-200px;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle at 50% 50%,#EFE9FE 0%,rgba(255,246,241,0) 70%);"></div>' +
				'<div style="position:absolute;top:64px;left:72px;display:flex;align-items:center;gap:14px;">' +
					'<span style="width:44px;height:44px;display:flex;">' + LOGO_LIGHT + '</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';letter-spacing:-.02em;">HowToInvest</span>' +
				'</div>' +
				'<div style="position:absolute;top:210px;left:72px;right:72px;">' +
					'<span style="font:700 24px ' + JAKARTA + ';color:#7C5CFC;letter-spacing:.04em;">{{kicker}}</span>' +
					'<h1 style="font:800 100px ' + POPPINS + ';line-height:.98;letter-spacing:-.035em;margin:14px 0 0;">{{title1}} <span style="color:#FF6B5E;">{{title2}}</span></h1>' +
					'<p style="font:400 32px ' + JAKARTA + ';line-height:1.5;color:#6E6680;margin:26px 0 0;max-width:30ch;">{{sub}}</p>' +
				'</div>' +
				'<div style="position:absolute;top:640px;left:72px;right:72px;">' +
					'<div style="display:flex;align-items:center;gap:36px;">' +
						'<div style="text-align:center;">' +
							'<div style="width:250px;height:250px;border-radius:40px;background:#1E2147;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;box-shadow:0 30px 60px -26px rgba(30,33,71,.5);">' +
								'<svg width="86" height="106" viewBox="0 0 24 24" fill="#fff"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>' +
								'<span style="font:700 24px ' + POPPINS + ';color:#fff;">1 share</span>' +
								'<span style="font:600 20px ' + JAKARTA + ';color:#A9A4C4;">{{sharePrice}}</span>' +
							'</div>' +
						'</div>' +
						'<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#C9362C" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:none;"><path d="M5 12h14M13 6l6 6-6 6"/></svg>' +
						'<div style="flex:1;">' +
							'<div style="display:grid;grid-template-columns:repeat(6,1fr);grid-auto-rows:1fr;gap:10px;">' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FF6B5E;display:flex;align-items:center;justify-content:center;box-shadow:0 14px 30px -14px rgba(255,107,94,.55);"><span style="font:700 26px ' + POPPINS + ';color:#fff;">{{slice}}</span></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#EDE7FB;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#EDE7FB;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#EDE7FB;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#EDE7FB;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#EDE7FB;"></div>' +
								'<div style="aspect-ratio:1;border-radius:16px;background:#FFD9D2;"></div>' +
							'</div>' +
							'<p style="font:600 24px ' + JAKARTA + ';color:#6E6680;margin:22px 0 0;">{{note}}</p>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div style="position:absolute;bottom:70px;left:72px;"><span style="font:600 22px ' + JAKARTA + ';color:#A89FB5;">03 / 05</span></div>' +
			'</div>'
	};

	// --- Myth · 04 Proof (dark, checklist) ---------------------------------
	// Broker names ship as generic placeholders (Broker A/B/C) — the template
	// carries no named companies. Edit them if your policy allows.
	var mythProof = {
		id: 'myth-proof',
		category: 'carousel',
		label: { en: 'Myth · 04 Proof', pt: 'Mito · 04 Prova' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'tag', label: { en: 'Tag', pt: 'Etiqueta' }, type: 'text', default: 'Zero minimum · 2026' },
			{ key: 'title1', label: { en: 'Title', pt: 'Título' }, type: 'text', default: 'Zero-minimum' },
			{ key: 'title2', label: { en: 'Title (highlight)', pt: 'Título (destaque)' }, type: 'text', default: 'brokers' },
			{ key: 'b1name', label: { en: 'Broker 1', pt: 'Corretora 1' }, type: 'text', default: 'Broker A' },
			{ key: 'b1note', label: { en: 'Broker 1 note', pt: 'Nota 1' }, type: 'text', default: 'fractional shares from $1' },
			{ key: 'b2name', label: { en: 'Broker 2', pt: 'Corretora 2' }, type: 'text', default: 'Broker B' },
			{ key: 'b2note', label: { en: 'Broker 2 note', pt: 'Nota 2' }, type: 'text', default: 'stock slices from $5' },
			{ key: 'b3name', label: { en: 'Broker 3', pt: 'Corretora 3' }, type: 'text', default: 'Broker C' },
			{ key: 'b3note', label: { en: 'Broker 3 note', pt: 'Nota 3' }, type: 'text', default: 'commission-free ETFs' },
			{ key: 'minLabel', label: { en: 'Minimum label', pt: 'Etiqueta mínimo' }, type: 'text', default: '$0 min.' },
			{ key: 'note', label: { en: 'Footer note', pt: 'Nota de rodapé' }, type: 'textarea', default: 'Illustrative examples of zero-minimum brokers. Always check terms and availability in your country.' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:#141631;position:relative;overflow:hidden;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="position:absolute;right:-240px;bottom:-240px;width:640px;height:640px;border-radius:50%;background:radial-gradient(circle at 50% 50%,rgba(34,195,166,.22) 0%,rgba(20,22,49,0) 70%);"></div>' +
				'<div style="position:absolute;top:64px;left:72px;display:flex;align-items:center;gap:14px;">' +
					'<span style="width:44px;height:44px;display:flex;">' + LOGO_DARK + '</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';letter-spacing:-.02em;">HowToInvest</span>' +
				'</div>' +
				'<div style="position:absolute;top:220px;left:72px;right:72px;">' +
					'<span style="display:inline-flex;align-items:center;gap:10px;background:rgba(34,195,166,.15);color:#3FE0BF;font:700 20px ' + JAKARTA + ';letter-spacing:.12em;text-transform:uppercase;padding:11px 22px;border-radius:999px;">{{tag}}</span>' +
					'<h1 style="font:800 84px ' + POPPINS + ';line-height:1.0;letter-spacing:-.035em;margin:30px 0 0;">{{title1}} <span style="color:#FF6B5E;">{{title2}}</span></h1>' +
				'</div>' +
				'<div style="position:absolute;top:520px;left:72px;right:72px;display:flex;flex-direction:column;gap:26px;">' +
					mythBrokerRow( '{{b1name}}', '{{b1note}}', '{{minLabel}}' ) +
					mythBrokerRow( '{{b2name}}', '{{b2note}}', '{{minLabel}}' ) +
					mythBrokerRow( '{{b3name}}', '{{b3note}}', '{{minLabel}}' ) +
				'</div>' +
				'<p style="position:absolute;bottom:64px;left:72px;right:72px;font:500 20px ' + JAKARTA + ';color:#787498;line-height:1.5;">{{note}} · 04 / 05</p>' +
			'</div>'
	};

	// --- Myth · 05 CTA (coral) ---------------------------------------------
	var mythCta = {
		id: 'myth-cta',
		category: 'carousel',
		label: { en: 'Myth · 05 CTA', pt: 'Mito · 05 CTA' },
		w: 1080,
		h: 1350,
		fields: [
			{ key: 'pre', label: { en: 'Before highlight', pt: 'Antes do destaque' }, type: 'text', default: 'What was your' },
			{ key: 'hi', label: { en: 'Highlight (navy)', pt: 'Destaque (navy)' }, type: 'text', default: 'FIRST' },
			{ key: 'post', label: { en: 'After highlight', pt: 'Depois do destaque' }, type: 'text', default: 'investment?' },
			{ key: 'sub', label: { en: 'Subtitle', pt: 'Subtítulo' }, type: 'textarea', default: '$5? $50? Tell us — comment below 👇' },
			{ key: 'follow', label: { en: 'Follow line', pt: 'Linha de seguir' }, type: 'text', default: 'Follow @HowToInvest for more' }
		],
		html:
			'<div style="width:1080px;height:1350px;background:#FF6B5E;position:relative;overflow:hidden;color:#fff;font-family:' + JAKARTA + ';box-sizing:border-box;">' +
				'<div style="position:absolute;left:-200px;top:-200px;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle at 50% 50%,rgba(255,255,255,.18) 0%,rgba(255,107,94,0) 70%);"></div>' +
				'<div style="position:absolute;right:-220px;bottom:-220px;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle at 50% 50%,rgba(30,33,71,.22) 0%,rgba(255,107,94,0) 70%);"></div>' +
				'<div style="position:absolute;top:64px;left:72px;display:flex;align-items:center;gap:14px;">' +
					'<span style="width:44px;height:44px;display:flex;">' + LOGO_LIGHT + '</span>' +
					'<span style="font:600 24px ' + JAKARTA + ';letter-spacing:-.02em;color:#fff;">HowToInvest</span>' +
				'</div>' +
				'<div style="position:absolute;top:210px;left:72px;right:72px;">' +
					'<h1 style="font:800 104px ' + POPPINS + ';line-height:.98;letter-spacing:-.035em;margin:0;">{{pre}} <span style="color:#1E2147;">{{hi}}</span> {{post}}</h1>' +
					'<p style="font:500 36px ' + JAKARTA + ';line-height:1.45;color:rgba(255,255,255,.92);margin:30px 0 0;max-width:22ch;">{{sub}}</p>' +
				'</div>' +
				'<div style="position:absolute;bottom:210px;left:72px;right:72px;">' +
					'<div style="display:flex;align-items:flex-end;gap:26px;height:340px;">' +
						'<div style="flex:none;display:flex;flex-direction:column;align-items:center;gap:16px;">' +
							'<div style="width:210px;height:210px;border-radius:44px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 30px 60px -26px rgba(30,33,71,.5);">' +
								'<svg width="120" height="120" viewBox="0 0 24 24" fill="#FF6B5E"><path d="M19.5 9.5c-.06 0-.11 0-.17.01A6.98 6.98 0 0 0 13 5H9a7 7 0 0 0-6.9 5.8L1 12v3.5h1.5A3.5 3.5 0 0 0 5 18.9V21h3v-1.5h3V21h3v-2.06a5.02 5.02 0 0 0 2.42-2.44H21v-3.5A2.5 2.5 0 0 0 19.5 9.5zM16 12a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM7 9a3 3 0 0 1 0-.02L11 8v1.4A8.9 8.9 0 0 0 7 9z"/></svg>' +
							'</div>' +
							'<span style="font:700 26px ' + POPPINS + ';color:#1E2147;">$5</span>' +
						'</div>' +
						'<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#1E2147" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-bottom:120px;"><path d="M5 12h14M13 6l6 6-6 6"/></svg>' +
						'<div style="flex:1;display:flex;align-items:flex-end;gap:20px;height:100%;">' +
							'<div style="flex:1;height:34%;background:#FFD1CB;border-radius:16px 16px 0 0;"></div>' +
							'<div style="flex:1;height:52%;background:#FFC0B8;border-radius:16px 16px 0 0;"></div>' +
							'<div style="flex:1;height:70%;background:#1E2147;border-radius:16px 16px 0 0;"></div>' +
							'<div style="flex:1;height:100%;background:#22C3A6;border-radius:16px 16px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:16px;"><span style="font:700 24px ' + POPPINS + ';color:#0E2A24;">📈</span></div>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div style="position:absolute;bottom:78px;left:72px;right:72px;display:flex;align-items:center;gap:18px;">' +
					'<span style="font:600 26px ' + JAKARTA + ';color:rgba(255,255,255,.9);">{{follow}}</span>' +
					'<div style="flex:1;height:2px;background:rgba(255,255,255,.28);"></div>' +
					'<span style="font:600 22px ' + JAKARTA + ';color:rgba(255,255,255,.8);">05 / 05</span>' +
				'</div>' +
				'{{#legal}}<p style="position:absolute;bottom:34px;left:72px;right:72px;font:400 14px ' + JAKARTA + ';color:rgba(255,255,255,.72);line-height:1.4;">{{disclaimer}}</p>{{/legal}}' +
			'</div>'
	};

	return [
		newsSquare, newsStory, newsX,
		glossaryFb, glossaryFeed, glossaryStory,
		factGreen, factPurple, factStory,
		ctaSquare, ctaStory, ctaX,
		ogPhoto, ogSplit,
		edNews, edEcon, edPromo, edInfographic, edRecap,
		mythHook, mythReality, mythHow, mythProof, mythCta
	];
}() );
