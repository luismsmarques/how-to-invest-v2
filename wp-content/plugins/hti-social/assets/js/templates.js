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

	return [ newsSquare, newsStory, newsX, glossaryFb, glossaryFeed, glossaryStory ];
}() );
