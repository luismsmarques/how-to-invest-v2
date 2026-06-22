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

	return [
		newsSquare, newsStory, newsX,
		glossaryFb, glossaryFeed, glossaryStory,
		factGreen, factPurple, factStory,
		ctaSquare, ctaStory, ctaX,
		ogPhoto, ogSplit,
		edNews, edEcon, edPromo, edInfographic, edRecap
	];
}() );
