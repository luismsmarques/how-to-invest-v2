/**
 * HowToInvest ebook builder.
 *
 * Assembles the print-ready A4 ebook "Como começar a investir" / "How to start
 * investing" from bilingual content + shared page renderers, then writes one
 * HTML file per language. A separate step renders each to PDF with headless
 * Chromium (see build.sh).
 *
 * This first edition covers the front matter + Module 00 + closing pages; the
 * remaining modules (01–06) and back matter (myths, glossary) are added the
 * same way — register more pages in buildPages().
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import QRCode from 'qrcode';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

/* ------------------------------------------------------------------ logos */
function logo( variant ) {
	// Shield + 4 ascending bars. variant: 'navy' (navy disc) | 'white' (white disc).
	const disc = variant === 'white' ? '#fff' : '#1E2147';
	const shield = variant === 'white' ? '#1E2147' : '#fff';
	const bars = '#7C5CFC';
	return `<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none"><circle cx="32" cy="32" r="32" fill="${disc}"/><path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="${shield}"/><g fill="${bars}"><rect x="20.4" y="40" width="3.6" height="6" rx=".8"/><rect x="25.9" y="37.5" width="3.6" height="8.5" rx=".8"/><rect x="31.4" y="35" width="3.6" height="11" rx=".8"/><rect x="36.9" y="32.5" width="3.6" height="13.5" rx=".8"/></g></svg>`;
}
const brandRow = ( size = 15 ) =>
	`<div class="brand"><span class="brand__logo" style="width:${size + 9}px;height:${size + 9}px">${logo( 'navy' )}</span><span class="brand__name" style="font-size:${size}px">HowToInvest</span></div>`;

const foot = ( n, title ) =>
	`<div class="pgfoot"><span>${title}</span><span>${String( n ).padStart( 2, '0' )}</span></div>`;

/* ------------------------------------------------------------------ content */
const C = {
	pt: {
		lang: 'pt',
		running: 'HowToInvest · Como começar a investir',
		ebookTag: 'Ebook · 2026',
		// Cover
		coverPill: 'Guia educativo gratuito',
		coverTitle: 'Como<br>começar a<br><span style="color:#FF6B5E">investir</span>',
		coverSub: 'As bases reunidas num só sítio — pensado para quem está mesmo a começar.',
		coverTag: 'Sem produtos.<br>Sem promessas.',
		// Colophon
		colKick: 'Sobre este guia',
		colH: 'Tudo o que precisas para dar o primeiro passo — sem ruído.',
		colP1: 'Este ebook reúne, num só sítio, as ideias-base de quem está mesmo a começar a investir. Está organizado em módulos curtos, do estado de espírito certo até montares o teu primeiro plano. Lê pela ordem ou salta para o que te faz falta — está tudo aberto.',
		colP2: 'Não vendemos nada e não fazemos promessas de retorno. Os exemplos são sempre por <em>classe de ativos</em>, nunca produtos concretos.',
		colStats: [ [ '7', 'módulos guiados' ], [ '~75', 'minutos de leitura' ], [ '0', 'produtos vendidos' ] ],
		colWarnL: 'Aviso importante',
		colWarn: 'Conteúdo meramente educativo sobre literacia financeira. Nada aqui constitui aconselhamento financeiro, de investimento, fiscal ou jurídico, nem uma recomendação de compra ou venda. Investir envolve risco, incluindo a possibilidade de perda de capital. Rendibilidades passadas não garantem rendibilidades futuras. Os exemplos são ilustrativos e apenas por classe de ativos. Antes de decidir, considera a tua situação e, se necessário, procura aconselhamento profissional independente.',
		colMeta: '© 2026 HowToInvest · Edição 1 · PT-PT · howtoinvest.pro',
		// TOC
		tocH: 'Índice',
		// How to use
		howKick: 'Antes de começares',
		howH: 'Como usar este guia',
		howP: 'Lê pela ordem se estás mesmo a começar — cada módulo assenta no anterior. Se já sabes o básico, salta para o que te interessa. Não há prazos: avança ao teu ritmo.',
		howCards: [
			[ 'key', 'Ideia-chave', 'A frase que deves levar de cada capítulo. Se só leres isto, já ganhaste algo.' ],
			[ 'ex', 'Exemplo', 'Um caso ilustrativo, sempre por classe de ativos — nunca um produto concreto.' ],
			[ 'caution', 'Cuidado', 'Um erro comum ou uma armadilha a evitar antes que te custe dinheiro.' ],
			[ 'term', 'Termo', 'Palavra sublinhada que encontras explicada no glossário, no fim do guia.' ],
		],
		howPathKick: 'O percurso',
		howPathH: 'Sete módulos, uma ideia de cada vez',
		howPathTime: '~75 min no total',
		// Why
		whyKick: 'Introdução',
		whyH: 'Poupar protege.<br>Investir faz crescer.',
		whyP1: 'Guardar dinheiro é o primeiro passo e é essencial — é a tua rede de segurança. Mas dinheiro parado perde valor todos os anos, devorado pela inflação. Investir é pôr parte desse dinheiro a trabalhar, para que o tempo jogue a teu favor em vez de contra ti.',
		whyP2: 'Não precisas de ser especialista, de ter muito dinheiro, nem de adivinhar o mercado. Precisas de entender meia dúzia de ideias e de ser consistente. É exatamente isso que este guia te dá.',
		whyCardAKick: 'O efeito do tempo',
		whyCardAH: 'O juro composto trabalha enquanto dormes',
		whyCardACap: 'Quanto mais cedo começas, menos esforço o resto exige.',
		whyCardBKick: 'A nossa promessa',
		whyCardBH: 'Sem produtos. Sem promessas.',
		whyChecks: [ 'Não vendemos nada nem recebemos comissões.', 'Nunca prometemos retornos nem dizemos o que comprar.', 'Só explicamos as bases, em português claro.' ],
		whyKey: 'Investir bem é, sobretudo, começar cedo, manter-se simples e não interromper o tempo.',
		// Module 0
		m0: {
			label: 'Módulo 00', read: '9 min de leitura', num: '00',
			title: 'Mentalidade<br>&amp; dinheiro',
			desc: 'Antes dos gráficos e dos nomes complicados, o que conta é a relação certa com o risco, o tempo e os teus objetivos.',
			inThis: 'Neste módulo',
			chapters: [ [ '1', 'Porque é que poupar não chega', '4 min' ], [ '2', 'Risco e tempo: os teus dois aliados', '5 min' ] ],
		},
		m0c1: {
			modlabel: 'Módulo 00 · Mentalidade &amp; dinheiro', time: '4 min', num: '01',
			h: 'Porque é que poupar não chega',
			p1: 'Poupar é o alicerce de tudo. Sem uma reserva, qualquer imprevisto vira dívida. Mas há um problema silencioso: o dinheiro parado numa conta perde poder de compra ano após ano. A isso chama-se <span class="term">inflação</span> — e é por isso que poupar, sozinho, não chega.',
			chartH: '1.000 € parados, durante 10 anos', chartSub: 'inflação média de ~2,5%/ano',
			barNow: 'Hoje', barNowV: '1.000 €', barLater: 'Daqui a 10 anos', barLaterV: '~781 €',
			chartNote: 'Mesmo sem gastares um cêntimo, o que esse dinheiro compra encolhe. Valores ilustrativos.',
			p2: 'A solução não é deixar de poupar — é dividir o dinheiro por funções. Uma parte fica líquida, para emergências e objetivos próximos. Outra parte, a que não vais precisar tão cedo, pode ser investida para crescer acima da inflação.',
			key: 'Poupar guarda o teu dinheiro; investir impede que a inflação o vá comendo.',
		},
		m0c2: {
			modlabel: 'Módulo 00 · Mentalidade &amp; dinheiro', time: '5 min', num: '02',
			h: 'Risco e tempo: os teus dois aliados',
			p1: 'Risco assusta, mas não é o inimigo — é o preço de qualquer retorno acima da inflação. O segredo é combiná-lo com o teu <span class="term">horizonte temporal</span>: quanto mais longe está o objetivo, mais oscilações podes aguentar pelo caminho.',
			chartH: 'Quanto tempo tens até precisar do dinheiro?',
			rows: [ [ '&lt; 3 anos', 22, 'Mais estável', '#0E9C84', '#22C3A6' ], [ '3–10 anos', 58, 'Equilíbrio', '#7C5CFC', 'linear-gradient(90deg,#22C3A6,#7C5CFC)' ], [ '&gt; 10 anos', 90, 'Mais risco ok', '#FF6B5E', 'linear-gradient(90deg,#7C5CFC,#FF6B5E)' ] ],
			chartNote: 'Com mais tempo, há margem para recuperar de quedas — por isso o horizonte muda o que faz sentido.',
			exL: 'Exemplo', ex: 'Dinheiro para um carro daqui a 2 anos pede estabilidade. Dinheiro para a reforma, daqui a 30, pode suportar bem mais oscilação.',
			cauL: 'Cuidado', cau: 'Risco a mais para um objetivo próximo pode obrigar-te a vender no pior momento. Ajusta sempre ao prazo.',
		},
		m0r: {
			kick: 'Módulo 00 · Em resumo', h: 'O que levas deste módulo',
			points: [
				'Poupar é a base, mas dinheiro parado perde valor com a inflação. Investir serve para o teu dinheiro crescer acima dela.',
				'Risco não é o inimigo — é o preço do retorno. O que o torna sensato (ou não) é o teu horizonte temporal.',
				'Divide o dinheiro por funções: reserva líquida para o curto prazo, dinheiro investido para o longo prazo.',
			],
			termsL: 'Termos deste módulo', terms: [ 'Inflação', 'Horizonte temporal', 'Liquidez', 'Reserva de emergência' ],
			qKick: 'Uma pergunta para ti', q: 'Que parte do teu dinheiro não vais mesmo precisar nos próximos cinco anos?',
		},
		// Next steps
		nsKick: 'Para terminar', nsH: 'Os teus primeiros passos',
		nsP: 'Saber é metade; a outra metade é fazer. Eis uma sequência simples para passares da leitura à ação — sem pressas.',
		nsSteps: [
			'Garante a tua <strong>reserva de emergência</strong> antes de investir.',
			'Escreve <strong>objetivo, prazo e valor mensal</strong> na tua folha-plano.',
			'Define a tua <strong>alocação-alvo</strong> de acordo com o horizonte.',
			'<strong>Automatiza</strong> o reforço periódico e mantém custos baixos.',
			'<strong>Mantém o rumo</strong> e relê o teu plano nos dias de queda.',
		],
		nsQrKick: 'Continua na app', nsQrH: 'Descobre o teu perfil e segue o percurso guiado',
		nsQrP: 'Aponta a câmara ao código para abrir a HowToInvest. Questionário de perfil, módulos e glossário interativo — gratuito e sem produtos à venda.',
		// Back cover
		bcH: 'Começar é o passo mais difícil.',
		bcP: 'Já o deste ao ler até aqui. Agora é manter a simplicidade, a regularidade e a paciência. O tempo trata do resto.',
		bcTag: 'Sem produtos. Sem promessas.',
		bcDisc: 'Conteúdo meramente educativo. Não constitui aconselhamento financeiro, de investimento, fiscal ou jurídico, nem recomendação de compra ou venda. Investir envolve risco, incluindo perda de capital. Rendibilidades passadas não garantem rendibilidades futuras. Exemplos ilustrativos e apenas por classe de ativos. © 2026 HowToInvest · Edição 1 · PT-PT.',
	},
};

// English edition — same structure, faithful translation, invariants preserved.
C.en = {
	lang: 'en',
	running: 'HowToInvest · How to start investing',
	ebookTag: 'Ebook · 2026',
	coverPill: 'Free educational guide',
	coverTitle: 'How to<br>start<br><span style="color:#FF6B5E">investing</span>',
	coverSub: 'The essentials gathered in one place — made for people who are truly just starting.',
	coverTag: 'No products.<br>No promises.',
	colKick: 'About this guide',
	colH: 'Everything you need to take the first step — without the noise.',
	colP1: 'This ebook brings together, in one place, the core ideas for anyone truly starting to invest. It is organised into short modules, from the right mindset to building your first plan. Read in order or jump to what you need — it is all open.',
	colP2: 'We sell nothing and make no promises of return. Examples are always by <em>asset class</em>, never specific products.',
	colStats: [ [ '7', 'guided modules' ], [ '~75', 'minutes of reading' ], [ '0', 'products sold' ] ],
	colWarnL: 'Important notice',
	colWarn: 'Purely educational content about financial literacy. Nothing here is financial, investment, tax or legal advice, nor a recommendation to buy or sell. Investing involves risk, including the possible loss of capital. Past performance does not guarantee future results. Examples are illustrative and by asset class only. Before deciding, consider your own situation and, if needed, seek independent professional advice.',
	colMeta: '© 2026 HowToInvest · Edition 1 · EN · howtoinvest.pro',
	tocH: 'Contents',
	howKick: 'Before you start',
	howH: 'How to use this guide',
	howP: 'Read in order if you are truly starting — each module builds on the last. If you already know the basics, jump to what interests you. There are no deadlines: go at your own pace.',
	howCards: [
		[ 'key', 'Key idea', 'The sentence to take from each chapter. If you only read this, you have already gained something.' ],
		[ 'ex', 'Example', 'An illustrative case, always by asset class — never a specific product.' ],
		[ 'caution', 'Caution', 'A common mistake or a trap to avoid before it costs you money.' ],
		[ 'term', 'Term', 'An underlined word you will find explained in the glossary, at the end of the guide.' ],
	],
	howPathKick: 'The path',
	howPathH: 'Seven modules, one idea at a time',
	howPathTime: '~75 min total',
	whyKick: 'Introduction',
	whyH: 'Saving protects.<br>Investing grows.',
	whyP1: 'Setting money aside is the first step, and it is essential — it is your safety net. But idle money loses value every year, eaten away by inflation. Investing means putting part of that money to work, so time plays for you instead of against you.',
	whyP2: 'You do not need to be an expert, to have a lot of money, or to predict the market. You need to understand a handful of ideas and be consistent. That is exactly what this guide gives you.',
	whyCardAKick: 'The power of time',
	whyCardAH: 'Compound interest works while you sleep',
	whyCardACap: 'The earlier you start, the less effort the rest demands.',
	whyCardBKick: 'Our promise',
	whyCardBH: 'No products. No promises.',
	whyChecks: [ 'We sell nothing and take no commissions.', 'We never promise returns or tell you what to buy.', 'We only explain the basics, in plain language.' ],
	whyKey: 'Investing well is, above all, starting early, keeping it simple, and not interrupting time.',
	m0: {
		label: 'Module 00', read: '9 min read', num: '00',
		title: 'Mindset<br>&amp; money',
		desc: 'Before the charts and the complicated names, what counts is the right relationship with risk, time and your goals.',
		inThis: 'In this module',
		chapters: [ [ '1', "Why saving isn't enough", '4 min' ], [ '2', 'Risk and time: your two allies', '5 min' ] ],
	},
	m0c1: {
		modlabel: 'Module 00 · Mindset &amp; money', time: '4 min', num: '01',
		h: "Why saving isn't enough",
		p1: 'Saving is the foundation of everything. Without a reserve, any surprise turns into debt. But there is a silent problem: money sitting in an account loses buying power year after year. That is called <span class="term">inflation</span> — and it is why saving, on its own, is not enough.',
		chartH: '€1,000 sitting idle, over 10 years', chartSub: '~2.5%/yr average inflation',
		barNow: 'Today', barNowV: '€1,000', barLater: 'In 10 years', barLaterV: '~€781',
		chartNote: 'Even without spending a cent, what that money buys shrinks. Illustrative figures.',
		p2: 'The answer is not to stop saving — it is to split money by purpose. One part stays liquid, for emergencies and near-term goals. Another part, the money you will not need soon, can be invested to grow above inflation.',
		key: 'Saving keeps your money; investing stops inflation from eating it.',
	},
	m0c2: {
		modlabel: 'Module 00 · Mindset &amp; money', time: '5 min', num: '02',
		h: 'Risk and time: your two allies',
		p1: 'Risk is scary, but it is not the enemy — it is the price of any return above inflation. The trick is to pair it with your <span class="term">time horizon</span>: the further away the goal, the more ups and downs you can ride out along the way.',
		chartH: 'How long until you need the money?',
		rows: [ [ '&lt; 3 years', 22, 'More stable', '#0E9C84', '#22C3A6' ], [ '3–10 years', 58, 'Balance', '#7C5CFC', 'linear-gradient(90deg,#22C3A6,#7C5CFC)' ], [ '&gt; 10 years', 90, 'More risk ok', '#FF6B5E', 'linear-gradient(90deg,#7C5CFC,#FF6B5E)' ] ],
		chartNote: 'With more time, there is room to recover from drops — which is why the horizon changes what makes sense.',
		exL: 'Example', ex: 'Money for a car in 2 years asks for stability. Money for retirement, 30 years out, can take far more swing.',
		cauL: 'Caution', cau: 'Too much risk for a near-term goal can force you to sell at the worst moment. Always match it to the timeframe.',
	},
	m0r: {
		kick: 'Module 00 · In summary', h: 'What you take from this module',
		points: [
			'Saving is the base, but idle money loses value to inflation. Investing is how your money grows above it.',
			'Risk is not the enemy — it is the price of return. What makes it sensible (or not) is your time horizon.',
			'Split money by purpose: a liquid reserve for the short term, invested money for the long term.',
		],
		termsL: 'Terms in this module', terms: [ 'Inflation', 'Time horizon', 'Liquidity', 'Emergency fund' ],
		qKick: 'A question for you', q: "Which part of your money won't you truly need in the next five years?",
	},
	nsKick: 'To finish', nsH: 'Your first steps',
	nsP: 'Knowing is half of it; the other half is doing. Here is a simple sequence to move from reading to action — no rush.',
	nsSteps: [
		'Secure your <strong>emergency fund</strong> before investing.',
		'Write down <strong>goal, timeframe and monthly amount</strong> on your plan sheet.',
		'Set your <strong>target allocation</strong> according to your horizon.',
		'<strong>Automate</strong> regular contributions and keep costs low.',
		'<strong>Stay the course</strong> and re-read your plan on down days.',
	],
	nsQrKick: 'Continue in the app', nsQrH: 'Discover your profile and follow the guided path',
	nsQrP: 'Point your camera at the code to open HowToInvest. A profile quiz, modules and an interactive glossary — free and with no products for sale.',
	bcH: 'Starting is the hardest step.',
	bcP: 'You took it by reading this far. Now it is about keeping it simple, regular and patient. Time handles the rest.',
	bcTag: 'No products. No promises.',
	bcDisc: 'Purely educational content. Not financial, investment, tax or legal advice, nor a recommendation to buy or sell. Investing involves risk, including loss of capital. Past performance does not guarantee future results. Illustrative examples, by asset class only. © 2026 HowToInvest · Edition 1 · EN.',
};

/* ------------------------------------------------------------------ pages */
function buildPages( t, qrSvg ) {
	const pages = [];      // { render(n) }
	const toc = [];        // { num, title, sub, page }
	let n = 0;
	const add = ( render, tocEntry ) => { n++; const num = n; if ( tocEntry ) toc.push( { ...tocEntry, page: num } ); pages.push( { render: () => render( num ) } ); };

	// 1 · Cover (direction A — editorial cream)
	add( () => `<section class="pg"><div style="position:absolute;inset:14mm;border:1px solid #ECD9CF;border-radius:3px"></div>
	<div style="position:relative;height:100%;padding:26mm 24mm;display:flex;flex-direction:column">
	  <div style="display:flex;align-items:center;gap:10px">
	    <span style="width:34px;height:34px;display:flex">${logo( 'navy' )}</span>
	    <span class="pop" style="font-weight:600;font-size:19px;letter-spacing:-.02em">HowToInvest</span>
	    <span style="flex:1"></span><span class="kick" style="letter-spacing:.16em">${t.ebookTag}</span>
	  </div>
	  <div style="flex:1;display:flex;flex-direction:column;justify-content:center;margin-top:-6mm">
	    <span class="eyebrow-pill" style="margin-bottom:30px"><span class="dot"></span>${t.coverPill}</span>
	    <h1 class="h1" style="font-size:66px;line-height:1.0">${t.coverTitle}</h1>
	    <p class="lead" style="font-size:20px;color:#6E6680;margin:30px 0 0;max-width:380px">${t.coverSub}</p>
	  </div>
	  <div><div style="height:1px;background:#ECD9CF;margin-bottom:24px"></div>
	    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:18px">
	      <div class="pop" style="font-weight:600;font-size:18px;line-height:1.35">${t.coverTag}</div>
	      <svg viewBox="0 0 150 84" width="150" height="84"><defs><linearGradient id="gcov" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FF6B5E"/><stop offset="1" stop-color="#7C5CFC"/></linearGradient></defs><g fill="url(#gcov)"><rect x="2" y="50" width="20" height="34" rx="5"/><rect x="34" y="36" width="20" height="48" rx="5"/><rect x="66" y="22" width="20" height="62" rx="5"/><rect x="98" y="10" width="20" height="74" rx="5"/><rect x="130" y="0" width="16" height="84" rx="5" opacity=".34"/></g></svg>
	    </div></div></div></section>` );

	// 2 · Colophon
	add( () => `<section class="pg"><div class="pad-wide">
	  <div style="margin-bottom:16mm">${brandRow( 15 )}</div>
	  <div class="grow">
	    <span class="kick kick--purple" style="letter-spacing:.12em">${t.colKick}</span>
	    <h2 class="h2" style="font-size:30px;margin:12px 0 0;max-width:430px">${t.colH}</h2>
	    <p class="lead" style="font-size:15px;color:#6E6680;margin:18px 0 0;max-width:460px">${t.colP1}</p>
	    <p class="lead" style="font-size:15px;color:#6E6680;margin:14px 0 0;max-width:460px">${t.colP2}</p>
	    <div style="display:flex;gap:10px;margin:26px 0 0">${t.colStats.map( s => `<div class="card" style="flex:1;padding:16px 18px"><div class="pop" style="font-weight:700;font-size:24px;color:#FF6B5E">${s[ 0 ]}</div><div style="font-weight:500;font-size:13px;color:#6E6680;margin-top:2px">${s[ 1 ]}</div></div>` ).join( '' )}</div>
	  </div>
	  <div style="background:#FBEFE9;border:1px solid #F2DDD3;border-radius:14px;padding:18px 20px">
	    <div class="key__l">${t.colWarnL}</div>
	    <p style="font-size:12.5px;line-height:1.6;color:#7A6F84;margin:0">${t.colWarn}</p>
	    <div style="margin-top:14px;font-weight:500;font-size:12px;color:#A89FB5">${t.colMeta}</div>
	  </div></div></section>` );

	// 3 · Índice (generated from `toc`, filled by render time)
	add( () => `<section class="pg"><div class="pad-wide">
	  <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:9mm">
	    <h2 class="h2" style="font-size:38px">${t.tocH}</h2>
	    <span class="kick" style="letter-spacing:.14em;font-size:11px">${t.lang === 'pt' ? 'Como começar a investir' : 'How to start investing'}</span>
	  </div>
	  <div style="display:flex;flex-direction:column">${toc.map( e => `<div style="display:flex;align-items:baseline;gap:14px;padding:${e.num ? 13 : 11}px 0;border-bottom:1px solid #EEDFD8">
	      <span class="${e.num ? 'pop' : ''}" style="${e.num ? 'font-weight:700;font-size:15px;color:#FF6B5E' : 'font-weight:600;font-size:13px;color:#A89FB5'};width:34px">${e.num || '—'}</span>
	      <div style="flex:1">${e.num ? `<div class="pop" style="font-weight:700;font-size:16px;letter-spacing:-.01em">${e.title}</div>${e.sub ? `<div style="font-weight:500;font-size:12.5px;color:#A89FB5;margin-top:1px">${e.sub}</div>` : ''}` : `<span style="font-weight:600;font-size:15px;color:#6E6680">${e.title}</span>`}</div>
	      <span class="pop" style="font-weight:600;font-size:14px;color:#A89FB5">${String( e.page ).padStart( 2, '0' )}</span></div>` ).join( '' )}</div></div></section>` );

	// 4 · How to use
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8mm"><span style="width:18px;height:18px;display:flex">${logo( 'navy' )}</span><span class="kick">${t.howKick}</span></div>
	  <h2 class="h2" style="font-size:32px;max-width:440px">${t.howH}</h2>
	  <p class="lead" style="font-size:15.5px;color:#6E6680;margin:14px 0 0;max-width:470px">${t.howP}</p>
	  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:24px 0 0">${t.howCards.map( ( c ) => {
		const map = { key: [ '#FFEDE9', '#FF6B5E', '#C9362C' ], ex: [ '#EFE9FE', '#7C5CFC', '#6A4BE0' ], caution: [ '#FCEFD9', '#C98A0E', '#A9740C' ], term: [ '#E2F7F2', '#0E9C84', '#0E9C84' ] };
		const ic = { key: '<path d="M9 18h6M10 21h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/>', ex: '<path d="M4 19V5M4 19h16M8 15l3.5-4 3 2.5L20 8"/>', caution: '<path d="M12 3 2 20h20L12 3zM12 9v5M12 17.5v.5"/>', term: '<path d="M4 5a2 2 0 0 1 2-2h9v18H6a2 2 0 0 0-2 2V5zM15 3l5 2v16l-5-2"/>' };
		const [ bg, icc, txt ] = map[ c[ 0 ] ];
		return `<div class="card" style="padding:16px 18px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:7px"><span style="width:30px;height:30px;border-radius:9px;background:${bg};color:${icc};display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ic[ c[ 0 ] ]}</svg></span><span style="font-weight:600;font-size:13px;color:${txt};text-transform:uppercase;letter-spacing:.05em">${c[ 1 ]}</span></div><p style="font-size:12.5px;line-height:1.55;color:#6E6680;margin:0">${c[ 2 ]}</p></div>`;
	} ).join( '' )}</div>
	  <div class="card" style="border-radius:16px;padding:20px 22px;margin-top:16px">
	    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:16px"><div><span class="kick kick--purple" style="letter-spacing:.1em">${t.howPathKick}</span><div class="pop" style="font-weight:700;font-size:17px;margin-top:3px">${t.howPathH}</div></div><span style="font-weight:600;font-size:12px;color:#A89FB5">${t.howPathTime}</span></div>
	    <div style="display:flex;align-items:flex-end;gap:6px;height:88px">${[ 30, 44, 60, 72, 80, 90, 100 ].map( ( h, i ) => `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:7px"><div style="width:100%;height:${h}%;background:linear-gradient(180deg,${i < 2 ? '#FF6B5E,#FF8377' : i < 4 ? '#9B7BF7,#7C5CFC' : '#7C5CFC,#6A4BE0'});border-radius:6px 6px 3px 3px"></div><span class="pop" style="font-weight:700;font-size:11px;color:#A89FB5">0${i}</span></div>` ).join( '' )}</div>
	  </div>${foot( num, t.running )}</div></section>`, { num: null, title: t.howH } );

	// 5 · Why invest
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8mm"><span style="width:18px;height:18px;display:flex">${logo( 'navy' )}</span><span class="kick">${t.whyKick}</span></div>
	  <h2 class="h2" style="font-size:34px;max-width:470px;line-height:1.08">${t.whyH}</h2>
	  <p class="lead" style="font-size:16px;color:#5A5270;margin:18px 0 0;max-width:480px">${t.whyP1}</p>
	  <p class="lead" style="font-size:16px;color:#5A5270;margin:14px 0 0;max-width:480px">${t.whyP2}</p>
	  <div style="display:flex;gap:14px;margin:26px 0 0">
	    <div style="flex:1;background:#1E2147;border-radius:18px;padding:22px 22px 18px;color:#fff">
	      <div class="kick" style="color:#B7A4FF;letter-spacing:.1em">${t.whyCardAKick}</div>
	      <div class="pop" style="font-weight:700;font-size:18px;margin:6px 0 14px;line-height:1.2">${t.whyCardAH}</div>
	      <svg viewBox="0 0 260 120" width="100%" height="120"><defs><linearGradient id="gwhy" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FF6B5E"/><stop offset="1" stop-color="#7C5CFC"/></linearGradient></defs><line x1="6" y1="110" x2="254" y2="110" stroke="#3A3E66" stroke-width="1.5"/><path d="M6 108 C 90 104, 150 86, 200 50 C 224 32, 240 16, 254 8" fill="none" stroke="url(#gwhy)" stroke-width="3.5" stroke-linecap="round"/><path d="M6 108 C 90 104, 150 86, 200 50 C 224 32, 240 16, 254 8 L254 110 L6 110 Z" fill="url(#gwhy)" opacity=".14"/><circle cx="254" cy="8" r="4.5" fill="#FF8377"/></svg>
	      <div style="font-weight:500;font-size:12px;color:#9A9EC4;margin-top:6px">${t.whyCardACap}</div>
	    </div>
	    <div class="card" style="flex:1;border-radius:18px;padding:22px">
	      <div class="kick kick--coral" style="letter-spacing:.1em">${t.whyCardBKick}</div>
	      <div class="pop" style="font-weight:700;font-size:18px;margin:6px 0 14px;line-height:1.2;color:#2A2438">${t.whyCardBH}</div>
	      <div style="display:flex;flex-direction:column;gap:11px">${t.whyChecks.map( c => `<div style="display:flex;gap:9px;align-items:flex-start"><span style="flex:none;width:18px;height:18px;border-radius:50%;background:#E2F7F2;display:flex;align-items:center;justify-content:center;margin-top:1px"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#0E9C84" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span><span style="font-size:13px;color:#5A5270;line-height:1.4">${c}</span></div>` ).join( '' )}</div>
	    </div></div>
	  <div class="key" style="margin-top:18px;padding:16px 20px"><div class="key__l">${t.lang === 'pt' ? 'Ideia-chave' : 'Key idea'}</div><p class="key__p" style="font-size:16px">${t.whyKey}</p></div>
	  ${foot( num, t.running )}</div></section>`, { num: null, title: t.whyH } );

	// 6 · Module 00 divider
	const m = t.m0;
	add( () => `<section class="pg"><div style="position:absolute;left:0;bottom:0;width:100%;height:34%;background:#FFEFEA"></div>
	  <div style="position:relative;height:100%;padding:24mm 22mm 22mm;display:flex;flex-direction:column">
	    <div style="display:flex;align-items:center;gap:8px"><span class="kick kick--coral" style="letter-spacing:.16em">${m.label}</span><span style="flex:1;height:1px;background:#ECD9CF"></span><span style="font-weight:600;font-size:11px;color:#A89FB5">${m.read}</span></div>
	    <div style="flex:1;display:flex;flex-direction:column;justify-content:center">
	      <div class="pop" style="font-weight:800;font-size:150px;line-height:.85;letter-spacing:-.04em;color:#FF6B5E;opacity:.16">${m.num}</div>
	      <h2 class="h2" style="font-size:46px;margin:6px 0 0;line-height:1.02">${m.title}</h2>
	      <p class="lead" style="font-size:17px;color:#6E6680;margin:18px 0 0;max-width:420px">${m.desc}</p>
	    </div>
	    <div><div class="key__l" style="letter-spacing:.1em;margin-bottom:12px">${m.inThis}</div>
	      <div style="display:flex;flex-direction:column;gap:2px">${m.chapters.map( c => `<div style="display:flex;align-items:center;gap:14px;padding:11px 0;border-top:1px solid #F1D9CE"><span class="pop" style="font-weight:700;font-size:14px;color:#FF6B5E;width:24px">${c[ 0 ]}</span><span style="flex:1;font-weight:600;font-size:15px;color:#2A2438">${c[ 1 ]}</span><span style="font-weight:600;font-size:12px;color:#A89FB5">${c[ 2 ]}</span></div>` ).join( '' )}</div></div>
	  </div></section>`, { num: m.num, title: t.lang === 'pt' ? 'Mentalidade & dinheiro' : 'Mindset & money', sub: m.desc.split( '.' )[ 0 ] } );

	// 7 · M0 · chapter 1
	const c1 = t.m0c1;
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="display:flex;align-items:center;gap:8px;margin-bottom:7mm"><span class="kick kick--purple">${c1.modlabel}</span><span style="flex:1"></span><span style="font-weight:600;font-size:10px;color:#fff;background:#FF6B5E;padding:3px 10px;border-radius:999px">${c1.time}</span></div>
	  <div style="display:flex;align-items:baseline;gap:12px"><span class="pop" style="font-weight:800;font-size:22px;color:#FFD3CC">${c1.num}</span><h3 class="h3" style="max-width:440px">${c1.h}</h3></div>
	  <p class="body" style="margin:14px 0 0;max-width:485px">${c1.p1}</p>
	  <div class="card" style="margin:20px 0 0">
	    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:18px"><div class="pop" style="font-weight:700;font-size:15px">${c1.chartH}</div><span style="font-weight:500;font-size:12px;color:#A89FB5">${c1.chartSub}</span></div>
	    <div style="display:flex;align-items:flex-end;gap:30px;height:130px;padding:0 6px">
	      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%"><span class="pop" style="font-weight:700;font-size:15px;color:#2A2438;margin-bottom:8px">${c1.barNowV}</span><div style="width:100%;max-width:120px;height:100%;background:linear-gradient(180deg,#FF6B5E,#FF8377);border-radius:10px 10px 0 0"></div><span style="font-weight:600;font-size:12px;color:#6E6680;margin-top:9px">${c1.barNow}</span></div>
	      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%"><span class="pop" style="font-weight:700;font-size:15px;color:#A89FB5;margin-bottom:8px">${c1.barLaterV}</span><div style="width:100%;max-width:120px;height:78%;background:#E7DAD3;border-radius:10px 10px 0 0"></div><span style="font-weight:600;font-size:12px;color:#6E6680;margin-top:9px">${c1.barLater}</span></div>
	    </div>
	    <div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:14px;text-align:center">${c1.chartNote}</div>
	  </div>
	  <p class="body" style="margin:18px 0 0;max-width:485px">${c1.p2}</p>
	  <div class="key push" style="padding:15px 20px"><div class="key__l">${t.lang === 'pt' ? 'Ideia-chave' : 'Key idea'}</div><p class="key__p">${c1.key}</p></div>
	  ${foot( num, t.running )}</div></section>` );

	// 8 · M0 · chapter 2
	const c2 = t.m0c2;
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="display:flex;align-items:center;gap:8px;margin-bottom:7mm"><span class="kick kick--purple">${c2.modlabel}</span><span style="flex:1"></span><span style="font-weight:600;font-size:10px;color:#fff;background:#FF6B5E;padding:3px 10px;border-radius:999px">${c2.time}</span></div>
	  <div style="display:flex;align-items:baseline;gap:12px"><span class="pop" style="font-weight:800;font-size:22px;color:#FFD3CC">${c2.num}</span><h3 class="h3" style="max-width:440px">${c2.h}</h3></div>
	  <p class="body" style="margin:14px 0 0;max-width:485px">${c2.p1}</p>
	  <div class="card" style="margin:20px 0 0">
	    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:16px">${c2.chartH}</div>
	    <div style="display:flex;flex-direction:column;gap:11px">${c2.rows.map( r => `<div style="display:flex;align-items:center;gap:14px"><span style="width:84px;font-weight:600;font-size:12px;color:#6E6680;flex:none">${r[ 0 ]}</span><div style="flex:1;height:14px;border-radius:999px;background:#F2E4DD;overflow:hidden"><div style="width:${r[ 1 ]}%;height:100%;background:${r[ 4 ]};border-radius:999px"></div></div><span style="width:96px;text-align:right;font-weight:600;font-size:12px;color:${r[ 3 ]};flex:none">${r[ 2 ]}</span></div>` ).join( '' )}</div>
	    <div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:14px">${c2.chartNote}</div>
	  </div>
	  <div style="display:flex;gap:12px;margin-top:18px">
	    <div class="mini mini--ex" style="flex:1"><div class="mini__l">${c2.exL}</div><p class="mini__p">${c2.ex}</p></div>
	    <div class="mini mini--caution" style="flex:1"><div class="mini__l">${c2.cauL}</div><p class="mini__p">${c2.cau}</p></div>
	  </div>${foot( num, t.running )}</div></section>` );

	// 9 · M0 · summary
	const r = t.m0r;
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="margin-bottom:7mm"><span class="kick">${r.kick}</span></div>
	  <h3 class="h3" style="font-size:28px;max-width:440px">${r.h}</h3>
	  <div style="display:flex;flex-direction:column;gap:12px;margin:22px 0 0">${r.points.map( ( p, i ) => `<div class="card" style="display:flex;gap:14px;align-items:flex-start;padding:16px 18px"><span class="pop" style="flex:none;width:26px;height:26px;border-radius:50%;background:#FFEDE9;color:#FF6B5E;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center">${i + 1}</span><p style="margin:0;font-size:14.5px;line-height:1.55;color:#3A3450">${p}</p></div>` ).join( '' )}</div>
	  <div style="margin-top:22px"><div class="kick kick--purple" style="letter-spacing:.1em;margin-bottom:10px">${r.termsL}</div><div style="display:flex;flex-wrap:wrap;gap:8px">${r.terms.map( w => `<span style="background:#fff;border:1px solid #E7DBF6;color:#6A4BE0;font-weight:600;font-size:12px;padding:6px 13px;border-radius:999px">${w}</span>` ).join( '' )}</div></div>
	  <div class="push" style="background:#1E2147;border-radius:16px;padding:20px 24px;color:#fff;display:flex;align-items:center;gap:18px">
	    <span style="flex:none;width:42px;height:42px;border-radius:12px;background:rgba(124,92,252,.25);display:flex;align-items:center;justify-content:center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#B7A4FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg></span>
	    <div><div class="kick" style="color:#B7A4FF;letter-spacing:.08em;margin-bottom:4px">${r.qKick}</div><p style="margin:0;font-size:15px;line-height:1.45;color:#fff">${r.q}</p></div>
	  </div>${foot( num, t.running )}</div></section>` );

	// 10 · Next steps + QR
	add( ( num ) => `<section class="pg"><div class="pad">
	  <div style="margin-bottom:7mm"><span class="kick">${t.nsKick}</span></div>
	  <h2 class="h2" style="font-size:32px;max-width:440px">${t.nsH}</h2>
	  <p class="lead" style="font-size:15px;color:#6E6680;margin:14px 0 0;max-width:470px">${t.nsP}</p>
	  <div style="display:flex;flex-direction:column;gap:10px;margin:22px 0 0">${t.nsSteps.map( ( s, i ) => `<div class="card" style="display:flex;gap:14px;align-items:center;border-radius:13px;padding:15px 18px"><span class="pop" style="flex:none;width:30px;height:30px;border-radius:50%;background:#FFEDE9;color:#FF6B5E;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center">${i + 1}</span><span style="font-weight:500;font-size:14.5px;color:#3A3450">${s}</span></div>` ).join( '' )}</div>
	  <div class="push" style="background:#1E2147;border-radius:18px;padding:24px 26px;display:flex;align-items:center;gap:24px;color:#fff">
	    <div style="flex:none;width:108px;height:108px;background:#FFF6F1;border-radius:14px;padding:8px"><div style="width:100%;height:100%">${qrSvg}</div></div>
	    <div style="flex:1"><div class="kick" style="color:#B7A4FF;letter-spacing:.1em;margin-bottom:7px">${t.nsQrKick}</div><div class="pop" style="font-weight:700;font-size:21px;line-height:1.15;letter-spacing:-.01em">${t.nsQrH}</div><p style="font-size:13.5px;line-height:1.55;color:#9A9EC4;margin:9px 0 0">${t.nsQrP}</p></div>
	  </div>${foot( num, t.running )}</div></section>`, { num: null, title: t.nsH } );

	// 11 · Back cover
	add( () => `<section class="pg" style="background:#1E2147;color:#fff">
	  <div style="position:absolute;left:0;bottom:0;width:100%;height:230px"><svg viewBox="0 0 595 230" width="100%" height="100%" preserveAspectRatio="none"><defs><linearGradient id="gback" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FF6B5E"/><stop offset="1" stop-color="#7C5CFC"/></linearGradient></defs><g fill="url(#gback)" opacity=".9"><rect x="20" y="160" width="50" height="70" rx="11"/><rect x="100" y="130" width="50" height="100" rx="11"/><rect x="180" y="95" width="50" height="135" rx="11"/><rect x="260" y="65" width="50" height="165" rx="11"/><rect x="340" y="42" width="50" height="188" rx="11"/><rect x="420" y="22" width="50" height="208" rx="11"/><rect x="500" y="6" width="50" height="224" rx="11"/></g></svg></div>
	  <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(30,33,71,0) 50%,rgba(30,33,71,.6) 78%,rgba(30,33,71,.9) 100%)"></div>
	  <div style="position:relative;height:100%;padding:26mm 24mm;display:flex;flex-direction:column">
	    <div style="display:flex;align-items:center;gap:10px"><span style="width:32px;height:32px;display:flex">${logo( 'white' )}</span><span class="pop" style="font-weight:600;font-size:18px;letter-spacing:-.02em">HowToInvest</span></div>
	    <div style="flex:1;display:flex;flex-direction:column;justify-content:center">
	      <h2 class="h2" style="font-size:40px;line-height:1.05;max-width:380px">${t.bcH}</h2>
	      <p style="font-size:17px;line-height:1.55;color:#B9BCD8;margin:18px 0 0;max-width:380px">${t.bcP}</p>
	      <div class="pop" style="font-weight:600;font-size:18px;color:#fff;margin-top:26px">${t.bcTag}</div>
	    </div>
	    <div>
	      <div style="display:flex;align-items:center;gap:9px;margin-bottom:18px"><span style="flex:1"></span><span style="font-weight:600;font-size:13px;color:#B9BCD8">howtoinvest.pro</span></div>
	      <div style="height:1px;background:rgba(255,255,255,.14);margin-bottom:16px"></div>
	      <p style="font-size:10.5px;line-height:1.55;color:#7E82A8;margin:0">${t.bcDisc}</p>
	    </div>
	  </div></section>` );

	return { pages, toc };
}

/* ------------------------------------------------------------------ assemble */
async function buildLang( lang ) {
	const t = C[ lang ];
	const url = lang === 'pt' ? 'https://howtoinvest.pro/pt/' : 'https://howtoinvest.pro/';
	const qrSvg = await QRCode.toString( url, { type: 'svg', margin: 0, errorCorrectionLevel: 'M', color: { dark: '#1E2147', light: '#FFF6F100' } } );
	const { pages } = buildPages( t, qrSvg );
	const body = pages.map( p => p.render() ).join( '\n' );
	const htmlDoc = `<!doctype html><html lang="${lang}"><head><meta charset="utf-8"><title>HowToInvest — ${lang === 'pt' ? 'Como começar a investir' : 'How to start investing'}</title><link rel="stylesheet" href="ebook.css"></head><body>${body}</body></html>`;
	const out = path.join( __dirname, `ebook.${lang}.html` );
	fs.writeFileSync( out, htmlDoc );
	console.log( `wrote ${out} (${pages.length} pages)` );
}

await buildLang( 'pt' );
await buildLang( 'en' );
