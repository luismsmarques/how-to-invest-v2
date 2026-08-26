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

// Shared chapter-page header + title row.
const chapHead = ( c ) =>
	`<div style="display:flex;align-items:center;gap:8px;margin-bottom:7mm"><span class="kick kick--purple">${c.modlabel}</span><span style="flex:1"></span><span style="font-weight:600;font-size:10px;color:#fff;background:#FF6B5E;padding:3px 10px;border-radius:999px">${c.time}</span></div>`;
const chapTitle = ( c ) =>
	`<div style="display:flex;align-items:baseline;gap:12px"><span class="pop" style="font-weight:800;font-size:22px;color:#FFD3CC">${c.num}</span><h3 class="h3" style="max-width:440px">${c.h}</h3></div>`;
const KEY = ( t ) => ( t.lang === 'pt' ? 'Ideia-chave' : 'Key idea' );

// Module divider page. accent 'coral' (even modules) | 'purple' (odd).
function moduleDivider( m ) {
	const purple = m.accent === 'purple';
	const bg = purple ? '#F0EBFE' : '#FFEFEA';
	const fg = purple ? '#7C5CFC' : '#FF6B5E';
	const line = purple ? '#E4DAF6' : '#F1D9CE';
	const inK = purple ? '#6A4BE0' : '#C9362C';
	return `<section class="pg"><div style="position:absolute;left:0;bottom:0;width:100%;height:34%;background:${bg}"></div>
	  <div style="position:absolute;right:-40px;bottom:26%;opacity:.5"><svg viewBox="0 0 220 110" width="300" height="150"><g fill="${purple ? '#E1D6FA' : '#F7DCD3'}"><rect x="0" y="60" width="28" height="50" rx="7"/><rect x="46" y="42" width="28" height="68" rx="7"/><rect x="92" y="24" width="28" height="86" rx="7"/><rect x="138" y="10" width="28" height="100" rx="7"/><rect x="184" y="0" width="24" height="110" rx="7"/></g></svg></div>
	  <div style="position:relative;height:100%;padding:24mm 22mm 22mm;display:flex;flex-direction:column">
	    <div style="display:flex;align-items:center;gap:8px"><span style="font-weight:600;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:${fg}">${m.label}</span><span style="flex:1;height:1px;background:#ECD9CF"></span><span style="font-weight:600;font-size:11px;color:#A89FB5">${m.read}</span></div>
	    <div style="flex:1;display:flex;flex-direction:column;justify-content:center">
	      <div class="pop" style="font-weight:800;font-size:150px;line-height:.85;letter-spacing:-.04em;color:${fg};opacity:.16">${m.num}</div>
	      <h2 class="h2" style="font-size:46px;margin:6px 0 0;line-height:1.02">${m.title}</h2>
	      <p class="lead" style="font-size:17px;color:#6E6680;margin:18px 0 0;max-width:420px">${m.desc}</p>
	    </div>
	    <div><div style="font-weight:600;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:${inK};margin-bottom:12px">${m.inThis}</div>
	      <div style="display:flex;flex-direction:column;gap:2px">${m.chapters.map( c => `<div style="display:flex;align-items:center;gap:14px;padding:11px 0;border-top:1px solid ${line}"><span class="pop" style="font-weight:700;font-size:14px;color:${fg};width:24px">${c[ 0 ]}</span><span style="flex:1;font-weight:600;font-size:15px;color:#2A2438">${c[ 1 ]}</span><span style="font-weight:600;font-size:12px;color:#A89FB5">${c[ 2 ]}</span></div>` ).join( '' )}</div></div>
	  </div></section>`;
}

// Module summary page (numbered takeaways + term pills + a reflection card).
// Accent 'coral' (default) or 'purple' tints the number chips; r.iconPath sets
// the reflection-card icon (falls back to a per-accent default).
function summaryPage( t, r, num ) {
	const purple = r.accent === 'purple';
	const cBg = purple ? '#EFE9FE' : '#FFEDE9';
	const cFg = purple ? '#7C5CFC' : '#FF6B5E';
	const def = purple
		? '<path d="M12 7v5l3 2"/>'
		: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>';
	const inner = r.iconPath || def;
	const circle = purple ? '<circle cx="12" cy="12" r="9"/>' : '';
	const icon = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#B7A4FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${r.iconPath ? inner : ( circle + inner )}</svg>`;
	return `<section class="pg"><div class="pad">
	  <div style="margin-bottom:7mm"><span class="kick">${r.kick}</span></div>
	  <h3 class="h3" style="font-size:28px;max-width:440px">${r.h}</h3>
	  <div style="display:flex;flex-direction:column;gap:12px;margin:22px 0 0">${r.points.map( ( p, i ) => `<div class="card" style="display:flex;gap:14px;align-items:flex-start;padding:16px 18px"><span class="pop" style="flex:none;width:26px;height:26px;border-radius:50%;background:${cBg};color:${cFg};font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center">${i + 1}</span><p style="margin:0;font-size:14.5px;line-height:1.55;color:#3A3450">${p}</p></div>` ).join( '' )}</div>
	  <div style="margin-top:22px"><div class="kick kick--purple" style="letter-spacing:.1em;margin-bottom:10px">${r.termsL}</div><div style="display:flex;flex-wrap:wrap;gap:8px">${r.terms.map( w => `<span style="background:#fff;border:1px solid #E7DBF6;color:#6A4BE0;font-weight:600;font-size:12px;padding:6px 13px;border-radius:999px">${w}</span>` ).join( '' )}</div></div>
	  <div class="push" style="background:#1E2147;border-radius:16px;padding:20px 24px;color:#fff;display:flex;align-items:center;gap:18px">
	    <span style="flex:none;width:42px;height:42px;border-radius:12px;background:rgba(124,92,252,.25);display:flex;align-items:center;justify-content:center">${icon}</span>
	    <div><div class="kick" style="color:#B7A4FF;letter-spacing:.08em;margin-bottom:4px">${r.qKick}</div><p style="margin:0;font-size:15px;line-height:1.45;color:#fff">${r.q}</p></div>
	  </div>${foot( num, t.running )}</div></section>`;
}

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
		m1: {
			label: 'Módulo 01', read: '13 min de leitura', num: '01', accent: 'purple',
			title: 'Fundamentos', desc: 'As ideias-base que sustentam tudo o resto — juro composto, inflação e a relação entre liquidez e tempo.',
			inThis: 'Neste módulo',
			chapters: [ [ '1', 'Juro composto, explicado com calma', '5 min' ], [ '2', 'Inflação: o imposto invisível', '4 min' ], [ '3', 'Liquidez e horizonte temporal', '4 min' ] ],
		},
		m1c1: {
			modlabel: 'Módulo 01 · Fundamentos', time: '5 min', num: '01', h: 'Juro composto, explicado com calma',
			p1: 'É a ideia mais poderosa de todas — e a mais simples. <span class="term">Juro composto</span> é ganhares rendimento não só sobre o que investiste, mas também sobre o rendimento que já foi acumulando. Os ganhos passam a gerar os seus próprios ganhos.',
			chartH: '100 € por mês, durante 30 anos', chartSub: 'retorno ilustrativo de ~6%/ano',
			legA: 'Só o que depositaste · ~36 000 €', legB: 'Com juro composto · ~100 000 €',
			p2: 'Repara na curva: no início mal se distingue de uma linha reta. É lá para o fim que dispara. Por isso o ingrediente mais valioso não é teres muito dinheiro — é dares <strong>tempo</strong> ao processo.',
			key: 'O tempo é o motor do juro composto. Começar cedo vale mais do que começar com muito.',
		},
		m1c2: {
			modlabel: 'Módulo 01 · Fundamentos', time: '4 min', num: '02', h: 'Inflação: o imposto invisível',
			p1: 'Ninguém te cobra inflação diretamente, mas ela tira-te poder de compra todos os anos. Se os preços sobem 3% e o teu dinheiro está parado, ficaste 3% mais pobre sem teres gasto nada. É o imposto que não vem no recibo.',
			chartH: 'O que 100 € compram ao longo do tempo (inflação ~3%/ano)',
			bars: [ [ '100 €', 100, 'Hoje', true ], [ '~86 €', 74, '5 anos', false ], [ '~74 €', 60, '10 anos', false ], [ '~55 €', 42, '20 anos', false ] ],
			exL: 'Exemplo', ex: 'Um café a 1 € hoje custará perto de 1,34 € daqui a 10 anos com 3% de inflação. O preço subiu; o teu dinheiro parado não.',
			cauL: 'Cuidado', cau: '"Não perdi dinheiro, está tudo na conta" é uma ilusão. Em poder de compra, dinheiro parado perde quase sempre.',
		},
		m1c3: {
			modlabel: 'Módulo 01 · Fundamentos', time: '4 min', num: '03', h: 'Liquidez e horizonte temporal',
			p1: '<span class="term">Liquidez</span> é a rapidez com que transformas algo em dinheiro sem perder valor. Dinheiro na conta é muito líquido; um imóvel é pouco líquido. Cruza isto com o teu horizonte e percebes onde colocar cada euro.',
			chartH: 'Da mais líquida à menos líquida',
			spectrum: [ [ 'Conta / depósito', '#22C3A6', 'Imediato', '#0E9C84' ], [ 'Obrigações', '#5BB8C9', 'Dias', '#A89FB5' ], [ 'Ações', '#7C5CFC', 'Dias', '#A89FB5' ], [ 'Imóvel', '#FF6B5E', 'Meses', '#C9362C' ] ],
			chartNote: 'Quanto menos líquido, mais tempo (e paciência) o ativo costuma exigir.',
			p2: 'Regra prática: o dinheiro de que podes precisar a qualquer momento fica líquido; o que tem anos pela frente pode ir para ativos menos líquidos, que tendem a compensar essa espera.',
			key: 'Combina liquidez e horizonte: dinheiro próximo, líquido e estável; dinheiro distante, a render.',
		},
		m1r: {
			kick: 'Módulo 01 · Em resumo', h: 'O que levas deste módulo', accent: 'purple',
			points: [
				'O juro composto faz os ganhos gerarem ganhos. A curva dispara no fim, por isso o tempo é o ingrediente mais valioso.',
				'A inflação corrói dinheiro parado todos os anos. Investir é a forma de tentar crescer acima dela.',
				'Liquidez e horizonte andam a par: dinheiro de curto prazo fica acessível; dinheiro de longo prazo pode render mais.',
			],
			termsL: 'Termos deste módulo', terms: [ 'Juro composto', 'Inflação', 'Poder de compra', 'Liquidez' ],
			qKick: 'Uma pergunta para ti', q: 'Se começasses hoje com pouco, mas todos os meses, onde estarias daqui a 20 anos?',
		},
		m2: {
			label: 'Módulo 02', read: '16 min de leitura', num: '02', accent: 'coral',
			title: 'Classes de ativos', desc: 'As grandes famílias onde podes pôr o teu dinheiro — ações, obrigações, liquidez e os alternativos.',
			inThis: 'Neste módulo',
			chapters: [ [ '1', 'Ações globais: ser dono de empresas', '6 min' ], [ '2', 'Obrigações: emprestar com regras', '5 min' ], [ '3', 'Liquidez, imobiliário e alternativos', '5 min' ] ],
		},
		m2c1: { modlabel: 'Módulo 02 · Classes de ativos', time: '6 min', num: '01', h: 'Ações globais: ser dono de empresas',
			p1: 'Uma <span class="term">ação</span> é uma fatia de uma empresa. Se ela cresce e dá lucro, tu — como sócio — beneficias. Em vez de apostar numa única empresa, podes ser dono de milhares ao mesmo tempo através de um <span class="term">índice</span> global.',
			chartH: 'Uma ação vs. um índice global', oneT: '1 empresa', oneD: 'Tudo depende dela', manyT: 'Milhares de empresas', manyD: 'O risco fica diluído',
			p2: 'Historicamente, as ações são a classe que mais cresce a longo prazo — mas também a que mais oscila pelo caminho. É o prémio que se paga por suportar a volatilidade.',
			key: 'Ser dono de muitas empresas ao mesmo tempo dilui o risco de qualquer uma correr mal.' },
		m2c2: { modlabel: 'Módulo 02 · Classes de ativos', time: '5 min', num: '02', h: 'Obrigações: emprestar com regras',
			p1: 'Comprar uma <span class="term">obrigação</span> é emprestar dinheiro a um Estado ou empresa. Em troca, recebes juros periódicos (o <span class="term">cupão</span>) e, no fim, o capital de volta. Mais previsível do que ações — e por isso costuma oscilar menos.',
			chartH: 'Como funciona, ao longo do tempo', steps: [ 'Emprestas', 'Cupão', 'Cupão', 'Cupão', 'Capital de volta' ],
			exL: 'Exemplo', ex: 'Emprestas a um Estado por 5 anos. Recebes um cupão anual e, no fim do prazo, o valor que emprestaste regressa.',
			cauL: 'Cuidado', cau: '"Previsível" não é "sem risco": quem te paga pode falhar e o valor mexe com as taxas de juro.' },
		m2c3: { modlabel: 'Módulo 02 · Classes de ativos', time: '5 min', num: '03', h: 'Liquidez, imobiliário e alternativos',
			p1: 'Além de ações e obrigações, há outras famílias com papéis específicos. Não são obrigatórias para começar, mas vale conhecer o que fazem — e o que pedem em troca.',
			cards: [
				[ 'green', 'Liquidez / cash', 'Depósitos e fundos do mercado monetário. Estável e acessível; rende pouco. É a tua almofada.' ],
				[ 'coral', 'Imobiliário', 'Renda e potencial valorização. Pouco líquido e exige muito capital; também via fundos cotados.' ],
				[ 'purple', 'Ouro e matérias-primas', 'Costumam mover-se de forma diferente do resto. Não pagam rendimento; servem de contrapeso.' ],
				[ 'gold', 'Cripto e outros', 'Muito voláteis e especulativos. Se entrarem, só com uma fatia pequena e dinheiro que aguentas perder.' ],
			],
			key: 'Para começar, ações e obrigações chegam. Os alternativos são tempero, não o prato principal.' },
		m2r: { kick: 'Módulo 02 · Em resumo', h: 'O que levas deste módulo', accent: 'coral', iconPath: '<path d="M4 19V5M4 19h16M8 15l3.5-4 3 2.5L20 8"/>',
			points: [ 'Ações são fatias de empresas: mais retorno a longo prazo, mais oscilação. Um índice global dilui o risco de uma só.', 'Obrigações são empréstimos com juros: mais previsíveis e estáveis, mas não isentas de risco.', 'Liquidez, imobiliário e alternativos têm papéis específicos — úteis em pequenas doses, não no centro.' ],
			termsL: 'Termos deste módulo', terms: [ 'Ação', 'Índice', 'Obrigação', 'Cupão', 'Volatilidade' ],
			qKick: 'Uma pergunta para ti', q: 'Entre crescer mais (e oscilar) ou dormir descansado, onde te sentes confortável?' },
		m3: { label: 'Módulo 03', read: '11 min de leitura', num: '03', accent: 'purple',
			title: 'Diversificação<br>&amp; carteiras', desc: 'Como peças simples — combinadas com cabeça — formam um todo mais robusto do que qualquer aposta isolada.',
			inThis: 'Neste módulo', chapters: [ [ '1', 'Não pôr os ovos no mesmo cesto', '5 min' ], [ '2', 'Como nasce uma carteira equilibrada', '6 min' ] ] },
		m3c1: { modlabel: 'Módulo 03 · Diversificação', time: '5 min', num: '01', h: 'Não pôr os ovos no mesmo cesto',
			p1: '<span class="term">Diversificar</span> é repartir o dinheiro por coisas que não sobem e descem todas ao mesmo tempo. Quando uma falha, outra segura. Não elimina o risco — mas suaviza os solavancos.',
			chartH: 'Tudo num só vs. repartido', concK: 'Concentrado', concD: 'Sobe muito, cai muito. Tudo depende de uma aposta.', divK: 'Diversificado', divD: 'Caminho mais suave. As quedas de uns compensam-se com outros.',
			p2: 'Diversifica-se em camadas: entre classes (ações, obrigações), dentro de cada classe (muitas empresas, vários países) e ao longo do tempo. A boa notícia: um único fundo de índice global já traz muita desta diversificação de fábrica.',
			key: 'Diversificar não maximiza o ganho de um ano — torna o percurso de muitos anos mais suportável.' },
		m3c2: { modlabel: 'Módulo 03 · Diversificação', time: '6 min', num: '02', h: 'Como nasce uma carteira equilibrada',
			p1: 'Uma <span class="term">carteira</span> é só a combinação de classes que escolhes. A grande decisão — a <span class="term">alocação</span> — é quanto pões em cada uma. Mais ações puxam pelo crescimento; mais obrigações e liquidez acalmam as oscilações.',
			chartH: 'Três perfis ilustrativos',
			donuts: [ [ 'Cautelosa', 'Horizonte curto', 40, 75 ], [ 'Equilibrada', 'Horizonte médio', 20, 45 ], [ 'Arrojada', 'Horizonte longo', 8, 22 ] ],
			legend: [ [ '#FF6B5E', 'Ações' ], [ '#7C5CFC', 'Obrigações' ], [ '#22C3A6', 'Liquidez' ] ],
			exL: 'Exemplo', ex: 'Uma regra de partida muito citada: a percentagem em obrigações perto da tua idade. Ponto de partida, não lei.',
			cauL: 'Cuidado', cau: 'Não há carteira "certa" universal. A melhor é a que consegues manter nos anos maus.' },
		m3r: { kick: 'Módulo 03 · Em resumo', h: 'O que levas deste módulo', accent: 'purple', iconPath: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 1.5"/>',
			points: [ 'Diversificar é repartir por coisas que não caem todas ao mesmo tempo. Suaviza o percurso sem eliminar o risco.', 'A alocação — quanto pões em cada classe — é a decisão que mais define o comportamento da carteira.', 'A melhor carteira é a que aguentas manter nos anos maus — não a que parece perfeita no papel.' ],
			termsL: 'Termos deste módulo', terms: [ 'Diversificação', 'Carteira', 'Alocação', 'Rebalanceamento' ],
			qKick: 'Uma pergunta para ti', q: 'Numa queda de 20%, conseguirias não mexer na tua carteira durante um ano inteiro?' },
		m4: { label: 'Módulo 04', read: '9 min de leitura', num: '04', accent: 'coral',
			title: 'Na prática', desc: 'Os hábitos discretos que fazem um plano resultar ao longo dos anos. Menos emoção, mais sistema.',
			inThis: 'Neste módulo', chapters: [ [ '1', 'Investir com regularidade', '4 min' ], [ '2', 'Custos e impostos, sem dores de cabeça', '5 min' ] ] },
		m4c1: { modlabel: 'Módulo 04 · Na prática', time: '4 min', num: '01', h: 'Investir com regularidade',
			p1: 'Investir sempre o mesmo valor, em intervalos fixos, chama-se <span class="term">reforço periódico</span>. Compras mais quando está barato e menos quando está caro — sem teres de adivinhar o momento certo. O hábito faz o trabalho pesado.',
			chartH: 'O mesmo valor, mês após mês', chartNote: 'Cada ponto é uma compra. O mercado sobe e desce; tu continuas, sempre igual.',
			p2: 'A maior vantagem é comportamental: automatizas a decisão e tiras a emoção do caminho. Configuras uma vez e deixas correr. Consistência ganha quase sempre a tentar adivinhar o "melhor dia".',
			key: 'Tempo no mercado vence quase sempre tentar acertar no momento do mercado.' },
		m4c2: { modlabel: 'Módulo 04 · Na prática', time: '5 min', num: '02', h: 'Custos e impostos, sem dores de cabeça',
			p1: 'Os custos são dos poucos fatores que controlas a 100%. Uma <span class="term">comissão</span> anual que parece pequena, ano após ano, come uma fatia enorme do resultado final. Vale a pena percebê-los antes de começar.',
			chartH: 'Quanto pesa 1% de comissão ao ano', chartSub: '30 anos · ilustrativo', legA: 'Custo baixo', legB: 'Custo alto · menos uns bons milhares no fim',
			exL: 'Exemplo', ex: 'Compara sempre a comissão anual (TER) de um fundo. A diferença entre 0,2% e 1,5% é gigante ao fim de décadas.',
			cauL: 'Cuidado', cau: 'Há também impostos sobre ganhos e custos de transação. As regras dependem do país — informa-te na tua jurisdição.',
			p2: 'Regra simples: paga o menos possível em comissões, evita comprar e vender a toda a hora, e percebe como os ganhos são tributados onde vives.' },
		m4r: { kick: 'Módulo 04 · Em resumo', h: 'O que levas deste módulo', accent: 'coral', iconPath: '<path d="M12 2v4M12 18v4M2 12h4M18 12h4"/><circle cx="12" cy="12" r="4"/>',
			points: [ 'Investir o mesmo valor com regularidade tira a emoção e a adivinhação da equação. O hábito faz o trabalho.', 'Os custos são dos poucos fatores que controlas. Comissões baixas, ao fim de anos, valem muito dinheiro.', 'Informa-te sobre impostos onde vives e evita transacionar em excesso — cada movimento tem custo.' ],
			termsL: 'Termos deste módulo', terms: [ 'Reforço periódico', 'Comissão (TER)', 'Automatização' ],
			qKick: 'Uma pergunta para ti', q: 'Que valor mensal podias automatizar hoje, sem dar pela falta dele?' },
		m5: { label: 'Módulo 05', read: '10 min de leitura', num: '05', accent: 'purple',
			title: 'Comportamento', desc: 'Manter a cabeça fria quando o mercado treme — porque o maior risco, muitas vezes, és tu próprio.',
			inThis: 'Neste módulo', chapters: [ [ '1', 'Como não entrar em pânico numa queda', '5 min' ], [ '2', 'Os erros mais comuns (e como evitá-los)', '5 min' ] ] },
		m5c1: { modlabel: 'Módulo 05 · Comportamento', time: '5 min', num: '01', h: 'Como não entrar em pânico numa queda',
			p1: 'As quedas fazem parte. Acontecem, recuperam, voltam a acontecer. O erro caro não é a queda — é vender no fundo, transformando uma perda temporária numa perda definitiva.',
			chartH: 'Quem aguenta vs. quem vende no fundo', legA: 'Manteve-se e recuperou', legB: 'Vendeu no fundo · cristalizou a perda', dropLabel: 'queda',
			p2: 'A defesa monta-se <em>antes</em>: tem reserva de emergência (para não precisares de vender), escolhe uma alocação que aguentes e combina contigo não tomar decisões grandes no meio do susto.',
			key: 'Uma queda só vira perda real quando vendes. Quem não precisa de vender, espera.' },
		m5c2: { modlabel: 'Módulo 05 · Comportamento', time: '5 min', num: '02', h: 'Os erros mais comuns (e como evitá-los)',
			p1: 'Quase ninguém falha por falta de informação — falha por repetir os mesmos deslizes. Conhece-os de antemão e já estás meio caminho andado.',
			mistakes: [
				[ 'Tentar acertar no momento certo', 'Ninguém o faz de forma consistente. Antídoto: reforços periódicos e automáticos.' ],
				[ 'Perseguir o que subiu mais', 'Comprar no topo da moda costuma sair caro. Antídoto: plano definido e diversificação.' ],
				[ 'Mexer na carteira a toda a hora', 'Mais custos, mais erros, mais stress. Antídoto: rever poucas vezes por ano, com regras.' ],
				[ 'Investir sem reserva de emergência', 'Obriga a vender no pior momento. Antídoto: monta a almofada antes de investir.' ],
			] },
		m5r: { kick: 'Módulo 05 · Em resumo', h: 'O que levas deste módulo', accent: 'purple', iconPath: '<path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2zM9 21h6"/>',
			points: [ 'Quedas são normais e recuperam. O erro caro é vender no fundo e transformar uma perda temporária em definitiva.', 'A defesa monta-se antes da tempestade: reserva de emergência, alocação suportável e regras decididas com calma.', 'Os erros comuns são previsíveis — timing, modas, excesso de mexidas. Conhecê-los é metade da solução.' ],
			termsL: 'Termos deste módulo', terms: [ 'Volatilidade', 'Correção', 'Viés comportamental' ],
			qKick: 'Uma pergunta para ti', q: 'Qual será a tua regra escrita para os dias em que o mercado cai a pique?' },
		m6: { label: 'Módulo 06', read: '9 min de leitura', num: '06', accent: 'coral',
			title: 'O teu plano', desc: 'Tudo o que viste até aqui condensado num plano simples, escrito por ti e que cabe numa página.',
			inThis: 'Neste módulo', chapters: [ [ '1', 'Define os teus objetivos', '4 min' ], [ '2', 'Junta tudo numa página', '5 min' ] ] },
		m6c1: { modlabel: 'Módulo 06 · O teu plano', time: '4 min', num: '01', h: 'Define os teus objetivos',
			p1: 'Investir sem objetivo é remar sem rumo. Antes do "onde", responde ao "para quê" e ao "quando". É o objetivo e o prazo que determinam quanto risco faz sentido — não o contrário.',
			chartH: 'Um bom objetivo tem quatro peças',
			pieces: [ [ 'coral', 'Para quê', 'Reforma, casa, margem de manobra…' ], [ 'purple', 'Quando', 'O horizonte: 3, 10, 30 anos?' ], [ 'green', 'Quanto', 'O valor-alvo, mesmo que aproximado.' ], [ 'gold', 'Quanto risco', 'O que aguentas sem perder o sono.' ] ],
			p2: 'Podes ter vários objetivos ao mesmo tempo, cada um com o seu prazo e a sua alocação. O de curto prazo, mais estável; o de longo prazo, com mais espaço para crescer.',
			key: 'Primeiro o objetivo e o prazo. O risco e os ativos são consequência, não o ponto de partida.' },
		m6plan: { modlabel: 'Módulo 06 · O teu plano', time: '5 min', num: '02', h: 'Junta tudo numa página',
			p: 'Preenche estes campos e tens o teu plano de investimento numa só folha. Simples de manter, fácil de reler num dia de pânico.',
			cardH: 'O meu plano de investimento',
			fields: [ 'O meu objetivo', 'Horizonte (anos)', 'Reserva de emergência (meses)', 'Valor a investir por mês' ],
			allocL: 'A minha alocação-alvo', alloc: [ [ '#FBEFE9', '#C9362C', '#FF6B5E', 'Ações' ], [ '#EFEAFB', '#6A4BE0', '#7C5CFC', 'Obrigações' ], [ '#E6F7F2', '#0E9C84', '#22C3A6', 'Liquidez' ] ],
			ruleL: 'A minha regra para os dias de queda' },
		m6r: { kick: 'Módulo 06 · Em resumo', h: 'O que levas deste módulo', accent: 'coral', iconPath: '<path d="M5 13l4 4L19 7"/>',
			points: [ 'O objetivo e o prazo vêm primeiro. São eles que decidem o risco e os ativos — nunca o contrário.', 'Um plano que cabe numa página é mais fácil de manter — e de reler quando as emoções apertam.', 'Define a tua regra para os dias maus enquanto estás calmo. O teu "eu" futuro vai agradecer.' ],
			termsL: 'Termos deste módulo', terms: [ 'Objetivo financeiro', 'Alocação-alvo', 'Plano de investimento' ],
			qKick: 'Uma pergunta para ti', q: 'Já preencheste a tua folha-plano? Se não, esse é o teu próximo passo.' },
		mitos: { kick: 'Extra', h: 'Mitos comuns sobre investir', p: 'Muito do que trava as pessoas é simplesmente falso. Aqui ficam cinco ideias feitas — e o que realmente se passa.',
			mythL: 'Mito', truthL: 'Na verdade',
			rows: [
				[ '"É só para ricos"', 'Hoje começa-se com poucos euros por mês. O que importa é a regularidade e o tempo, não o montante inicial.' ],
				[ '"Tenho de saber escolher ações"', 'Um fundo de índice global dá-te milhares de empresas de uma vez. Não precisas de adivinhar vencedores.' ],
				[ '"É como ir ao casino"', 'Apostar é tudo-ou-nada de curto prazo. Investir diversificado a longo prazo é o oposto: paciência e probabilidades a teu favor.' ],
				[ '"Tenho de acompanhar todos os dias"', 'Olhar todos os dias só alimenta a ansiedade. Um bom plano funciona melhor quando o deixas em paz.' ],
				[ '"Tenho de esperar pelo momento certo"', 'O "momento certo" só se conhece depois. Começar cedo e com regularidade bate, quase sempre, esperar pelo dia perfeito.' ],
			] },
		gloss: { h: 'Glossário', sub: 'As palavras-chave, em claro', cont: 'continuação', noteL: 'Nota',
			note: 'Encontras estas e outras palavras explicadas com exemplos no glossário interativo da app HowToInvest — sempre em português claro, sem jargão.',
			p1: [ [ 'Ação', 'Uma fatia de propriedade de uma empresa. Se ela cresce e dá lucro, beneficias como sócio.' ], [ 'Alocação', 'A repartição do dinheiro pelas classes de ativos. A decisão que mais define o comportamento da carteira.' ], [ 'Carteira', 'O conjunto de todos os teus investimentos, vistos como um todo.' ], [ 'Cupão', 'O juro periódico que uma obrigação paga a quem a detém.' ], [ 'Diversificação', 'Repartir o dinheiro por ativos que não sobem e descem todos ao mesmo tempo, para suavizar o percurso.' ], [ 'Dividendo', 'Parte do lucro que uma empresa distribui aos acionistas.' ], [ 'ETF / Fundo de índice', 'Um cabaz que segue um índice inteiro. Compras um e ficas exposto a centenas ou milhares de empresas.' ], [ 'Horizonte temporal', 'O tempo que falta até precisares do dinheiro. Define quanto risco é razoável correr.' ] ],
			p2: [ [ 'Inflação', 'A subida geral dos preços ao longo do tempo. Faz o dinheiro parado perder poder de compra.' ], [ 'Índice', 'Um cabaz que representa um mercado (ex.: as maiores empresas do mundo). Serve de referência e de base aos fundos de índice.' ], [ 'Juro composto', 'Ganhar rendimento também sobre os rendimentos anteriores. O efeito dispara com o tempo.' ], [ 'Liquidez', 'A rapidez com que transformas um ativo em dinheiro sem perder valor. Cash é muito líquido; imóvel, pouco.' ], [ 'Obrigação', 'Um empréstimo a um Estado ou empresa. Pagam-te juros e devolvem o capital no fim do prazo.' ], [ 'Poder de compra', 'O que o teu dinheiro realmente compra. É isto, e não o número na conta, que a inflação corrói.' ], [ 'Rebalanceamento', 'Ajustar a carteira de volta à alocação-alvo quando uma classe cresceu demais face às outras.' ], [ 'Reforço periódico', 'Investir o mesmo valor em intervalos fixos, independentemente do preço. Tira a emoção da decisão.' ] ],
			p3: [ [ 'Rendibilidade', 'O ganho (ou perda) de um investimento, normalmente em percentagem por ano.' ], [ 'Reserva de emergência', 'Dinheiro líquido para imprevistos (tipicamente alguns meses de despesas). Evita ter de vender investimentos no pior momento.' ], [ 'Risco', 'A incerteza do resultado — incluindo a possibilidade de perder dinheiro. É o preço de qualquer retorno acima da inflação.' ], [ 'TER / Comissão', 'O custo anual de um fundo, em percentagem. Pequeno à vista, enorme ao fim de décadas. Compara sempre.' ], [ 'Volatilidade', 'O sobe-e-desce do valor de um ativo. Mais volatilidade não é falência — é caminho acidentado.' ] ] },
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
	m1: {
		label: 'Module 01', read: '13 min read', num: '01', accent: 'purple',
		title: 'Fundamentals', desc: 'The core ideas that hold up everything else — compound interest, inflation, and the link between liquidity and time.',
		inThis: 'In this module',
		chapters: [ [ '1', 'Compound interest, explained calmly', '5 min' ], [ '2', 'Inflation: the invisible tax', '4 min' ], [ '3', 'Liquidity and time horizon', '4 min' ] ],
	},
	m1c1: {
		modlabel: 'Module 01 · Fundamentals', time: '5 min', num: '01', h: 'Compound interest, explained calmly',
		p1: 'It is the most powerful idea of all — and the simplest. <span class="term">Compound interest</span> is earning a return not only on what you invested, but also on the return that has already built up. Gains start generating their own gains.',
		chartH: '€100 a month, for 30 years', chartSub: 'illustrative ~6%/yr return',
		legA: 'Only what you paid in · ~€36,000', legB: 'With compound interest · ~€100,000',
		p2: 'Notice the curve: at the start it barely differs from a straight line. It only takes off near the end. That is why the most valuable ingredient is not having a lot of money — it is giving <strong>time</strong> to the process.',
		key: 'Time is the engine of compound interest. Starting early is worth more than starting big.',
	},
	m1c2: {
		modlabel: 'Module 01 · Fundamentals', time: '4 min', num: '02', h: 'Inflation: the invisible tax',
		p1: 'Nobody charges you inflation directly, but it takes buying power from you every year. If prices rise 3% and your money sits idle, you became 3% poorer without spending a thing. It is the tax that never shows on a receipt.',
		chartH: 'What €100 buys over time (inflation ~3%/yr)',
		bars: [ [ '€100', 100, 'Today', true ], [ '~€86', 74, '5 years', false ], [ '~€74', 60, '10 years', false ], [ '~€55', 42, '20 years', false ] ],
		exL: 'Example', ex: 'A €1 coffee today will cost close to €1.34 in 10 years at 3% inflation. The price went up; your idle money did not.',
		cauL: 'Caution', cau: '"I didn\'t lose money, it\'s all in the account" is an illusion. In buying power, idle money almost always loses.',
	},
	m1c3: {
		modlabel: 'Module 01 · Fundamentals', time: '4 min', num: '03', h: 'Liquidity and time horizon',
		p1: '<span class="term">Liquidity</span> is how quickly you can turn something into cash without losing value. Money in an account is very liquid; property is not. Cross this with your horizon and you see where to place each euro.',
		chartH: 'From most to least liquid',
		spectrum: [ [ 'Account / deposit', '#22C3A6', 'Instant', '#0E9C84' ], [ 'Bonds', '#5BB8C9', 'Days', '#A89FB5' ], [ 'Equities', '#7C5CFC', 'Days', '#A89FB5' ], [ 'Property', '#FF6B5E', 'Months', '#C9362C' ] ],
		chartNote: 'The less liquid it is, the more time (and patience) the asset tends to ask for.',
		p2: 'Rule of thumb: money you might need at any moment stays liquid; money with years ahead can go to less-liquid assets, which tend to reward that wait.',
		key: 'Pair liquidity and horizon: near money liquid and stable; distant money put to work.',
	},
	m1r: {
		kick: 'Module 01 · In summary', h: 'What you take from this module', accent: 'purple',
		points: [
			'Compound interest makes gains generate gains. The curve takes off at the end, so time is the most valuable ingredient.',
			'Inflation erodes idle money every year. Investing is how you try to grow above it.',
			'Liquidity and horizon go together: short-term money stays accessible; long-term money can earn more.',
		],
		termsL: 'Terms in this module', terms: [ 'Compound interest', 'Inflation', 'Buying power', 'Liquidity' ],
		qKick: 'A question for you', q: 'If you started today with little, but every month, where would you be in 20 years?',
	},
	m2: { label: 'Module 02', read: '16 min read', num: '02', accent: 'coral',
		title: 'Asset classes', desc: 'The big families where you can put your money — equities, bonds, cash and the alternatives.',
		inThis: 'In this module', chapters: [ [ '1', 'Global equities: owning companies', '6 min' ], [ '2', 'Bonds: lending with rules', '5 min' ], [ '3', 'Cash, real estate and alternatives', '5 min' ] ] },
	m2c1: { modlabel: 'Module 02 · Asset classes', time: '6 min', num: '01', h: 'Global equities: owning companies',
		p1: 'A <span class="term">share</span> is a slice of a company. If it grows and makes a profit, you — as a part-owner — benefit. Instead of betting on a single company, you can own thousands at once through a global <span class="term">index</span>.',
		chartH: 'One share vs. a global index', oneT: '1 company', oneD: 'Everything depends on it', manyT: 'Thousands of companies', manyD: 'The risk is diluted',
		p2: 'Historically, equities are the class that grows most over the long run — but also the one that swings most along the way. It is the premium you pay for enduring volatility.',
		key: 'Owning many companies at once dilutes the risk of any single one going wrong.' },
	m2c2: { modlabel: 'Module 02 · Asset classes', time: '5 min', num: '02', h: 'Bonds: lending with rules',
		p1: 'Buying a <span class="term">bond</span> is lending money to a government or company. In return you receive periodic interest (the <span class="term">coupon</span>) and, at the end, your capital back. More predictable than equities — and so it usually swings less.',
		chartH: 'How it works, over time', steps: [ 'You lend', 'Coupon', 'Coupon', 'Coupon', 'Capital back' ],
		exL: 'Example', ex: 'You lend to a government for 5 years. You receive an annual coupon and, at maturity, the amount you lent returns.',
		cauL: 'Caution', cau: '"Predictable" is not "risk-free": who pays you can default, and the value moves with interest rates.' },
	m2c3: { modlabel: 'Module 02 · Asset classes', time: '5 min', num: '03', h: 'Cash, real estate and alternatives',
		p1: 'Beyond equities and bonds, there are other families with specific roles. They are not required to start, but it is worth knowing what they do — and what they ask in return.',
		cards: [
			[ 'green', 'Cash / liquidity', 'Deposits and money-market funds. Stable and accessible; earns little. It is your cushion.' ],
			[ 'coral', 'Real estate', 'Rent and potential appreciation. Illiquid and capital-heavy; also via listed funds.' ],
			[ 'purple', 'Gold and commodities', 'They tend to move differently from the rest. They pay no income; they act as a counterweight.' ],
			[ 'gold', 'Crypto and others', 'Very volatile and speculative. If they come in, only with a small slice and money you can afford to lose.' ],
		],
		key: 'To start, equities and bonds are enough. Alternatives are seasoning, not the main dish.' },
	m2r: { kick: 'Module 02 · In summary', h: 'What you take from this module', accent: 'coral', iconPath: '<path d="M4 19V5M4 19h16M8 15l3.5-4 3 2.5L20 8"/>',
		points: [ 'Equities are slices of companies: more long-term return, more swing. A global index dilutes the risk of any single one.', 'Bonds are loans with interest: more predictable and stable, but not free of risk.', 'Cash, real estate and alternatives have specific roles — useful in small doses, not at the centre.' ],
		termsL: 'Terms in this module', terms: [ 'Share', 'Index', 'Bond', 'Coupon', 'Volatility' ],
		qKick: 'A question for you', q: 'Between growing more (and swinging) or sleeping soundly, where do you feel comfortable?' },
	m3: { label: 'Module 03', read: '11 min read', num: '03', accent: 'purple',
		title: 'Diversification<br>&amp; portfolios', desc: 'How simple pieces — combined thoughtfully — form a whole more robust than any single bet.',
		inThis: 'In this module', chapters: [ [ '1', 'Don\'t put all eggs in one basket', '5 min' ], [ '2', 'How a balanced portfolio is born', '6 min' ] ] },
	m3c1: { modlabel: 'Module 03 · Diversification', time: '5 min', num: '01', h: 'Don\'t put all eggs in one basket',
		p1: 'To <span class="term">diversify</span> is to spread money across things that don\'t rise and fall all at the same time. When one fails, another holds. It doesn\'t remove risk — but it softens the jolts.',
		chartH: 'All in one vs. spread out', concK: 'Concentrated', concD: 'Rises a lot, falls a lot. Everything rides on one bet.', divK: 'Diversified', divD: 'A smoother path. Some falls offset with others.',
		p2: 'You diversify in layers: across classes (equities, bonds), within each class (many companies, several countries) and over time. The good news: a single global index fund already brings much of this diversification out of the box.',
		key: 'Diversifying doesn\'t maximise one year\'s gain — it makes the journey of many years more bearable.' },
	m3c2: { modlabel: 'Module 03 · Diversification', time: '6 min', num: '02', h: 'How a balanced portfolio is born',
		p1: 'A <span class="term">portfolio</span> is just the mix of classes you choose. The big decision — the <span class="term">allocation</span> — is how much you put in each. More equities pull for growth; more bonds and cash calm the swings.',
		chartH: 'Three illustrative profiles',
		donuts: [ [ 'Cautious', 'Short horizon', 40, 75 ], [ 'Balanced', 'Medium horizon', 20, 45 ], [ 'Bold', 'Long horizon', 8, 22 ] ],
		legend: [ [ '#FF6B5E', 'Equities' ], [ '#7C5CFC', 'Bonds' ], [ '#22C3A6', 'Cash' ] ],
		exL: 'Example', ex: 'A widely cited starting rule: the percentage in bonds near your age. A starting point, not a law.',
		cauL: 'Caution', cau: 'There is no universal "right" portfolio. The best one is the one you can keep through the bad years.' },
	m3r: { kick: 'Module 03 · In summary', h: 'What you take from this module', accent: 'purple', iconPath: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 1.5"/>',
		points: [ 'Diversifying is spreading across things that don\'t all fall at once. It smooths the journey without removing risk.', 'The allocation — how much you put in each class — is the decision that most defines the portfolio\'s behaviour.', 'The best portfolio is the one you can keep through the bad years — not the one that looks perfect on paper.' ],
		termsL: 'Terms in this module', terms: [ 'Diversification', 'Portfolio', 'Allocation', 'Rebalancing' ],
		qKick: 'A question for you', q: 'In a 20% drop, could you avoid touching your portfolio for a whole year?' },
	m4: { label: 'Module 04', read: '9 min read', num: '04', accent: 'coral',
		title: 'In practice', desc: 'The quiet habits that make a plan work over the years. Less emotion, more system.',
		inThis: 'In this module', chapters: [ [ '1', 'Investing regularly', '4 min' ], [ '2', 'Costs and taxes, without headaches', '5 min' ] ] },
	m4c1: { modlabel: 'Module 04 · In practice', time: '4 min', num: '01', h: 'Investing regularly',
		p1: 'Investing the same amount, at fixed intervals, is called <span class="term">regular investing</span>. You buy more when it\'s cheap and less when it\'s dear — without having to guess the right moment. The habit does the heavy lifting.',
		chartH: 'The same amount, month after month', chartNote: 'Each dot is a purchase. The market goes up and down; you keep going, always the same.',
		p2: 'The biggest advantage is behavioural: you automate the decision and take emotion out of the path. Set it once and let it run. Consistency almost always beats trying to guess the "best day".',
		key: 'Time in the market almost always beats trying to time the market.' },
	m4c2: { modlabel: 'Module 04 · In practice', time: '5 min', num: '02', h: 'Costs and taxes, without headaches',
		p1: 'Costs are one of the few factors you control 100%. An annual <span class="term">fee</span> that looks small, year after year, eats a huge slice of the final result. It is worth understanding them before you start.',
		chartH: 'How much a 1% annual fee weighs', chartSub: '30 years · illustrative', legA: 'Low cost', legB: 'High cost · a good few thousand less at the end',
		exL: 'Example', ex: 'Always compare a fund\'s annual fee (TER). The gap between 0.2% and 1.5% is enormous after decades.',
		cauL: 'Caution', cau: 'There are also taxes on gains and transaction costs. The rules depend on the country — check your own jurisdiction.',
		p2: 'Simple rule: pay as little as possible in fees, avoid buying and selling all the time, and understand how gains are taxed where you live.' },
	m4r: { kick: 'Module 04 · In summary', h: 'What you take from this module', accent: 'coral', iconPath: '<path d="M12 2v4M12 18v4M2 12h4M18 12h4"/><circle cx="12" cy="12" r="4"/>',
		points: [ 'Investing the same amount regularly takes emotion and guessing out of the equation. The habit does the work.', 'Costs are one of the few factors you control. Low fees, after years, are worth a lot of money.', 'Learn about taxes where you live and avoid over-trading — every move has a cost.' ],
		termsL: 'Terms in this module', terms: [ 'Regular investing', 'Fee (TER)', 'Automation' ],
		qKick: 'A question for you', q: 'What monthly amount could you automate today, without missing it?' },
	m5: { label: 'Module 05', read: '10 min read', num: '05', accent: 'purple',
		title: 'Behaviour', desc: 'Keeping a cool head when markets shake — because the biggest risk, often, is you.',
		inThis: 'In this module', chapters: [ [ '1', 'How not to panic in a downturn', '5 min' ], [ '2', 'The most common mistakes (and how to avoid them)', '5 min' ] ] },
	m5c1: { modlabel: 'Module 05 · Behaviour', time: '5 min', num: '01', h: 'How not to panic in a downturn',
		p1: 'Drops are part of it. They happen, they recover, they happen again. The costly mistake is not the drop — it is selling at the bottom, turning a temporary loss into a permanent one.',
		chartH: 'Who holds vs. who sells at the bottom', legA: 'Held on and recovered', legB: 'Sold at the bottom · locked in the loss', dropLabel: 'drop',
		p2: 'The defence is built <em>before</em>: keep an emergency fund (so you don\'t need to sell), choose an allocation you can bear, and agree with yourself not to make big decisions mid-fright.',
		key: 'A drop only becomes a real loss when you sell. Those who don\'t need to sell, wait.' },
	m5c2: { modlabel: 'Module 05 · Behaviour', time: '5 min', num: '02', h: 'The most common mistakes (and how to avoid them)',
		p1: 'Almost nobody fails for lack of information — they fail by repeating the same slips. Know them in advance and you\'re halfway there.',
		mistakes: [
			[ 'Trying to time the market', 'Nobody does it consistently. Antidote: regular, automatic contributions.' ],
			[ 'Chasing what rose most', 'Buying at the top of a fad usually ends up costing. Antidote: a defined plan and diversification.' ],
			[ 'Tinkering with the portfolio constantly', 'More costs, more mistakes, more stress. Antidote: review a few times a year, with rules.' ],
			[ 'Investing without an emergency fund', 'Forces you to sell at the worst moment. Antidote: build the cushion before investing.' ],
		] },
	m5r: { kick: 'Module 05 · In summary', h: 'What you take from this module', accent: 'purple', iconPath: '<path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2zM9 21h6"/>',
		points: [ 'Drops are normal and recover. The costly mistake is selling at the bottom and turning a temporary loss into a permanent one.', 'The defence is built before the storm: emergency fund, a bearable allocation, and rules decided calmly.', 'Common mistakes are predictable — timing, fads, over-tinkering. Knowing them is half the solution.' ],
		termsL: 'Terms in this module', terms: [ 'Volatility', 'Correction', 'Behavioural bias' ],
		qKick: 'A question for you', q: 'What will your written rule be for the days the market plunges?' },
	m6: { label: 'Module 06', read: '9 min read', num: '06', accent: 'coral',
		title: 'Your plan', desc: 'Everything you\'ve seen so far condensed into a simple plan, written by you, that fits on one page.',
		inThis: 'In this module', chapters: [ [ '1', 'Define your goals', '4 min' ], [ '2', 'Put it all on one page', '5 min' ] ] },
	m6c1: { modlabel: 'Module 06 · Your plan', time: '4 min', num: '01', h: 'Define your goals',
		p1: 'Investing without a goal is rowing without direction. Before the "where", answer the "what for" and the "when". It is the goal and the timeframe that set how much risk makes sense — not the other way around.',
		chartH: 'A good goal has four pieces',
		pieces: [ [ 'coral', 'What for', 'Retirement, a home, breathing room…' ], [ 'purple', 'When', 'The horizon: 3, 10, 30 years?' ], [ 'green', 'How much', 'The target amount, even if rough.' ], [ 'gold', 'How much risk', 'What you can bear without losing sleep.' ] ],
		p2: 'You can have several goals at once, each with its own timeframe and allocation. The short-term one, more stable; the long-term one, with more room to grow.',
		key: 'Goal and timeframe first. Risk and assets are a consequence, not the starting point.' },
	m6plan: { modlabel: 'Module 06 · Your plan', time: '5 min', num: '02', h: 'Put it all on one page',
		p: 'Fill in these fields and you have your investment plan on a single sheet. Simple to keep, easy to re-read on a panic day.',
		cardH: 'My investment plan',
		fields: [ 'My goal', 'Horizon (years)', 'Emergency fund (months)', 'Amount to invest per month' ],
		allocL: 'My target allocation', alloc: [ [ '#FBEFE9', '#C9362C', '#FF6B5E', 'Equities' ], [ '#EFEAFB', '#6A4BE0', '#7C5CFC', 'Bonds' ], [ '#E6F7F2', '#0E9C84', '#22C3A6', 'Cash' ] ],
		ruleL: 'My rule for down days' },
	m6r: { kick: 'Module 06 · In summary', h: 'What you take from this module', accent: 'coral', iconPath: '<path d="M5 13l4 4L19 7"/>',
		points: [ 'The goal and timeframe come first. They decide the risk and the assets — never the other way around.', 'A plan that fits on one page is easier to keep — and to re-read when emotions run high.', 'Define your rule for the bad days while you\'re calm. Your future self will thank you.' ],
		termsL: 'Terms in this module', terms: [ 'Financial goal', 'Target allocation', 'Investment plan' ],
		qKick: 'A question for you', q: 'Have you filled in your plan sheet yet? If not, that\'s your next step.' },
	mitos: { kick: 'Extra', h: 'Common myths about investing', p: 'Much of what holds people back is simply false. Here are five clichés — and what actually happens.',
		mythL: 'Myth', truthL: 'Actually',
		rows: [
			[ '"It\'s only for the rich"', 'Today you start with a few euros a month. What matters is regularity and time, not the initial amount.' ],
			[ '"I need to know how to pick stocks"', 'A global index fund gives you thousands of companies at once. You don\'t need to guess winners.' ],
			[ '"It\'s like going to a casino"', 'Betting is short-term all-or-nothing. Diversified long-term investing is the opposite: patience and the odds on your side.' ],
			[ '"I have to watch it every day"', 'Looking every day only feeds anxiety. A good plan works best when you leave it alone.' ],
			[ '"I have to wait for the right moment"', 'The "right moment" is only known afterwards. Starting early and regularly beats, almost always, waiting for the perfect day.' ],
		] },
	gloss: { h: 'Glossary', sub: 'The key words, in plain language', cont: 'continued', noteL: 'Note',
		note: 'You\'ll find these and other words explained with examples in the interactive glossary in the HowToInvest app — always in plain language, no jargon.',
		p1: [ [ 'Allocation', 'How money is split across asset classes. The decision that most defines the portfolio\'s behaviour.' ], [ 'Bond', 'A loan to a government or company. They pay you interest and return the capital at maturity.' ], [ 'Compound interest', 'Earning a return on prior returns too. The effect takes off with time.' ], [ 'Coupon', 'The periodic interest a bond pays to whoever holds it.' ], [ 'Diversification', 'Spreading money across assets that don\'t rise and fall all at once, to smooth the journey.' ], [ 'Dividend', 'Part of the profit a company distributes to shareholders.' ], [ 'ETF / Index fund', 'A basket that tracks a whole index. Buy one and you\'re exposed to hundreds or thousands of companies.' ], [ 'Inflation', 'The general rise in prices over time. It makes idle money lose buying power.' ] ],
		p2: [ [ 'Index', 'A basket representing a market (e.g. the largest companies in the world). A reference and the base for index funds.' ], [ 'Liquidity', 'How quickly you can turn an asset into cash without losing value. Cash is very liquid; property, not.' ], [ 'Buying power', 'What your money actually buys. This — not the number in the account — is what inflation erodes.' ], [ 'Portfolio', 'The set of all your investments, seen as a whole.' ], [ 'Rebalancing', 'Adjusting the portfolio back to the target allocation when one class has grown too much vs. the others.' ], [ 'Regular investing', 'Investing the same amount at fixed intervals, regardless of price. Takes emotion out of the decision.' ], [ 'Return', 'The gain (or loss) of an investment, usually as a percentage per year.' ], [ 'Risk', 'The uncertainty of the outcome — including the chance of losing money. It is the price of any return above inflation.' ] ],
		p3: [ [ 'Emergency fund', 'Liquid money for the unexpected (typically a few months of expenses). Avoids having to sell investments at the worst moment.' ], [ 'Share', 'A slice of ownership in a company. If it grows and profits, you benefit as a part-owner.' ], [ 'TER / Fee', 'A fund\'s annual cost, as a percentage. Small at a glance, huge after decades. Always compare.' ], [ 'Time horizon', 'The time until you need the money. It sets how much risk is reasonable to take.' ], [ 'Volatility', 'The ups and downs of an asset\'s value. More volatility isn\'t bankruptcy — it\'s a bumpy road.' ] ] },
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
	add( () => moduleDivider( m ), { num: m.num, title: t.lang === 'pt' ? 'Mentalidade & dinheiro' : 'Mindset & money', sub: m.desc.split( '.' )[ 0 ] } );

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
	add( ( num ) => summaryPage( t, t.m0r, num ) );

	// ===== Module 01 · Fundamentos =====
	const m1 = t.m1;
	add( () => moduleDivider( m1 ), { num: m1.num, title: m1.title, sub: m1.desc.split( '—' )[ 0 ].trim() } );

	// M1 · chapter 1 — compound interest (navy line chart)
	const a = t.m1c1;
	add( ( num ) => `<section class="pg"><div class="pad">
	  ${chapHead( a )}${chapTitle( a )}
	  <p class="body" style="margin:14px 0 0;max-width:485px">${a.p1}</p>
	  <div style="background:#1E2147;border-radius:16px;padding:22px 24px;margin:20px 0 0;color:#fff">
	    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:8px"><div class="pop" style="font-weight:700;font-size:15px">${a.chartH}</div><span style="font-weight:500;font-size:12px;color:#9A9EC4">${a.chartSub}</span></div>
	    <svg viewBox="0 0 480 150" width="100%" height="150"><defs><linearGradient id="gjc" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FF6B5E"/><stop offset="1" stop-color="#7C5CFC"/></linearGradient></defs><line x1="6" y1="132" x2="474" y2="132" stroke="#3A3E66" stroke-width="1.5"/><path d="M6 120 L474 60" fill="none" stroke="#6B6F96" stroke-width="2" stroke-dasharray="5 5"/><path d="M6 120 C 160 116, 280 96, 360 70 C 420 50, 452 26, 474 10" fill="none" stroke="url(#gjc)" stroke-width="3.5" stroke-linecap="round"/><path d="M6 120 C 160 116, 280 96, 360 70 C 420 50, 452 26, 474 10 L474 132 L6 132 Z" fill="url(#gjc)" opacity=".14"/><circle cx="474" cy="10" r="4.5" fill="#FF8377"/><circle cx="474" cy="60" r="4" fill="#6B6F96"/></svg>
	    <div style="display:flex;gap:20px;margin-top:10px;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#6B6F96"></span><span style="font-weight:500;font-size:12px;color:#9A9EC4">${a.legA}</span></div><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#FF8377"></span><span style="font-weight:500;font-size:12px;color:#fff">${a.legB}</span></div></div>
	  </div>
	  <p class="body" style="margin:18px 0 0;max-width:485px">${a.p2}</p>
	  <div class="key push" style="padding:15px 20px"><div class="key__l">${t.lang === 'pt' ? 'Ideia-chave' : 'Key idea'}</div><p class="key__p">${a.key}</p></div>
	  ${foot( num, t.running )}</div></section>` );

	// M1 · chapter 2 — inflation decay bars (purple)
	const b = t.m1c2;
	add( ( num ) => `<section class="pg"><div class="pad">
	  ${chapHead( b )}${chapTitle( b )}
	  <p class="body" style="margin:14px 0 0;max-width:485px">${b.p1}</p>
	  <div class="card" style="margin:20px 0 0">
	    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:18px">${b.chartH}</div>
	    <div style="display:flex;align-items:flex-end;gap:16px;height:130px">${b.bars.map( ( bar ) => `<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%"><span class="pop" style="font-weight:700;font-size:13px;color:${bar[ 3 ] ? '#2A2438' : '#8A7FA0'};margin-bottom:7px">${bar[ 0 ]}</span><div style="width:100%;height:${bar[ 1 ]}%;background:${bar[ 3 ] ? 'linear-gradient(180deg,#7C5CFC,#9B7BF7)' : '#E2DAF1'};border-radius:8px 8px 0 0"></div><span style="font-weight:600;font-size:11px;color:#6E6680;margin-top:8px">${bar[ 2 ]}</span></div>` ).join( '' )}</div>
	  </div>
	  <div style="display:flex;gap:12px;margin-top:18px">
	    <div class="mini mini--ex" style="flex:1"><div class="mini__l">${b.exL}</div><p class="mini__p">${b.ex}</p></div>
	    <div class="mini mini--caution" style="flex:1"><div class="mini__l">${b.cauL}</div><p class="mini__p">${b.cau}</p></div>
	  </div>${foot( num, t.running )}</div></section>` );

	// M1 · chapter 3 — liquidity spectrum
	const cc = t.m1c3;
	add( ( num ) => `<section class="pg"><div class="pad">
	  ${chapHead( cc )}${chapTitle( cc )}
	  <p class="body" style="margin:14px 0 0;max-width:485px">${cc.p1}</p>
	  <div class="card" style="margin:20px 0 0;padding:22px">
	    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:18px">${cc.chartH}</div>
	    <div style="display:flex;align-items:center;gap:0">${cc.spectrum.map( ( sp, i ) => `<div style="flex:1;text-align:center"><div style="height:34px;background:${sp[ 1 ]};${i === 0 ? 'border-radius:9px 0 0 9px;' : i === cc.spectrum.length - 1 ? 'border-radius:0 9px 9px 0;' : ''}display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px">${sp[ 0 ]}</div><div style="font-weight:600;font-size:11px;color:${sp[ 3 ]};margin-top:8px">${sp[ 2 ]}</div></div>` ).join( '' )}</div>
	    <div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:16px;text-align:center">${cc.chartNote}</div>
	  </div>
	  <p class="body" style="margin:18px 0 0;max-width:485px">${cc.p2}</p>
	  <div class="key push" style="padding:15px 20px"><div class="key__l">${t.lang === 'pt' ? 'Ideia-chave' : 'Key idea'}</div><p class="key__p">${cc.key}</p></div>
	  ${foot( num, t.running )}</div></section>` );

	// M1 · summary (purple accent)
	add( ( num ) => summaryPage( t, t.m1r, num ) );

	// Tone → icon-tile colours for small cards.
	const TONE = { green: [ '#E2F7F2', '#0E9C84' ], coral: [ '#FFEDE9', '#FF6B5E' ], purple: [ '#EFE9FE', '#7C5CFC' ], gold: [ '#FCEFD9', '#A9740C' ] };

	// ===== Module 02 · Classes de ativos =====
	add( () => moduleDivider( t.m2 ), { num: t.m2.num, title: t.m2.title, sub: t.m2.desc.split( '—' )[ 0 ].trim() } );
	{
		const c = t.m2c1;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:16px">${c.chartH}</div>
		    <div style="display:flex;gap:16px">
		      <div style="flex:1;background:#FBEFE9;border-radius:12px;padding:16px;text-align:center"><div style="display:flex;justify-content:center;margin-bottom:10px"><span style="width:38px;height:38px;border-radius:9px;background:#FF6B5E"></span></div><div style="font-weight:600;font-size:13px;color:#2A2438">${c.oneT}</div><div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:3px">${c.oneD}</div></div>
		      <div style="flex:1;background:#EFEAFB;border-radius:12px;padding:16px;text-align:center"><div style="display:grid;grid-template-columns:repeat(8,1fr);gap:3px;margin-bottom:10px">${Array.from( { length: 16 } ).map( ( _, i ) => `<span style="aspect-ratio:1;border-radius:2px;background:${[ '#7C5CFC', '#9B7BF7', '#B7A4FF' ][ i % 3 ]}"></span>` ).join( '' )}</div><div style="font-weight:600;font-size:13px;color:#2A2438">${c.manyT}</div><div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:3px">${c.manyD}</div></div>
		    </div></div>
		  <p class="body" style="margin:18px 0 0;max-width:485px">${c.p2}</p>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m2c2;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:20px">${c.chartH}</div>
		    <div style="position:relative;padding:0 4px"><div style="position:absolute;left:10px;right:10px;top:13px;height:2px;background:#F2E4DD"></div>
		      <div style="position:relative;display:flex;justify-content:space-between;align-items:flex-start">${c.steps.map( ( s, i ) => {
			const first = i === 0, last = i === c.steps.length - 1;
			const dot = first ? '<div style="width:26px;height:26px;border-radius:50%;background:#FF6B5E;margin:0 auto;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></div>'
				: last ? '<div style="width:26px;height:26px;border-radius:50%;background:#7C5CFC;margin:0 auto;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>'
				: '<div style="width:26px;height:26px;border-radius:50%;background:#E2F7F2;border:2px solid #22C3A6;margin:0 auto"></div>';
			const col = first ? '#2A2438' : last ? '#6A4BE0' : '#0E9C84';
			return `<div style="text-align:center;width:${first || last ? 18 : 14}%">${dot}<div style="font-weight:600;font-size:11px;color:${col};margin-top:8px">${s}</div></div>`;
		} ).join( '' )}</div></div></div>
		  <div style="display:flex;gap:12px;margin-top:18px"><div class="mini mini--ex" style="flex:1"><div class="mini__l">${c.exL}</div><p class="mini__p">${c.ex}</p></div><div class="mini mini--caution" style="flex:1"><div class="mini__l">${c.cauL}</div><p class="mini__p">${c.cau}</p></div></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m2c3;
		const icons = { green: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/>', coral: '<path d="M3 21V10l9-7 9 7v11M9 21v-6h6v6"/>', purple: '<circle cx="12" cy="12" r="8"/><path d="M9.5 9.5h3.5a1.8 1.8 0 0 1 0 3.6H9.5M11 7v10"/>', gold: '<path d="M12 3 2 20h20L12 3zM12 9v5M12 17.5v.5"/>' };
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0 0">${c.cards.map( cd => `<div class="card" style="padding:18px"><span style="width:34px;height:34px;border-radius:10px;background:${TONE[ cd[ 0 ] ][ 0 ]};color:${TONE[ cd[ 0 ] ][ 1 ]};display:flex;align-items:center;justify-content:center;margin-bottom:12px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${icons[ cd[ 0 ] ]}</svg></span><div class="pop" style="font-weight:700;font-size:15px">${cd[ 1 ]}</div><p style="font-size:12.5px;line-height:1.5;color:#6E6680;margin:6px 0 0">${cd[ 2 ]}</p></div>` ).join( '' )}</div>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	add( ( num ) => summaryPage( t, t.m2r, num ) );

	// ===== Module 03 · Diversificação =====
	add( () => moduleDivider( t.m3 ), { num: t.m3.num, title: ( t.m3.title || '' ).replace( /<br>/g, ' ' ).replace( '&amp;', '&' ), sub: t.m3.desc.split( '—' )[ 0 ].trim() } );
	{
		const c = t.m3c1;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:18px">${c.chartH}</div>
		    <div style="display:flex;gap:16px">
		      <div style="flex:1;background:#FBEFE9;border-radius:12px;padding:18px;text-align:center"><div style="font-weight:600;font-size:11px;color:#C9362C;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">${c.concK}</div><svg viewBox="0 0 120 60" width="100%" height="56"><path d="M6 20 L40 18 L66 40 L90 22 L114 52" fill="none" stroke="#FF6B5E" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg><div style="font-weight:500;font-size:12px;color:#6E6680;margin-top:10px">${c.concD}</div></div>
		      <div style="flex:1;background:#EFEAFB;border-radius:12px;padding:18px;text-align:center"><div style="font-weight:600;font-size:11px;color:#6A4BE0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">${c.divK}</div><svg viewBox="0 0 120 60" width="100%" height="56"><path d="M6 38 L34 34 L62 30 L90 26 L114 20" fill="none" stroke="#7C5CFC" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg><div style="font-weight:500;font-size:12px;color:#6E6680;margin-top:10px">${c.divD}</div></div>
		    </div></div>
		  <p class="body" style="margin:18px 0 0;max-width:485px">${c.p2}</p>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m3c2;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:20px">${c.chartH}</div>
		    <div style="display:flex;gap:14px">${c.donuts.map( d => `<div style="flex:1;text-align:center"><div style="width:96px;height:96px;border-radius:50%;margin:0 auto;background:conic-gradient(#22C3A6 0 ${d[ 2 ]}%,#7C5CFC ${d[ 2 ]}% ${d[ 3 ]}%,#FF6B5E ${d[ 3 ]}% 100%);display:flex;align-items:center;justify-content:center"><div style="width:54px;height:54px;border-radius:50%;background:#fff"></div></div><div class="pop" style="font-weight:700;font-size:14px;margin-top:12px">${d[ 0 ]}</div><div style="font-weight:500;font-size:11px;color:#A89FB5;margin-top:2px">${d[ 1 ]}</div></div>` ).join( '' )}</div>
		    <div style="display:flex;justify-content:center;gap:18px;margin-top:18px">${c.legend.map( l => `<div style="display:flex;align-items:center;gap:7px"><span style="width:11px;height:11px;border-radius:3px;background:${l[ 0 ]}"></span><span style="font-weight:500;font-size:12px;color:#6E6680">${l[ 1 ]}</span></div>` ).join( '' )}</div></div>
		  <div style="display:flex;gap:12px;margin-top:16px"><div class="mini mini--ex" style="flex:1"><div class="mini__l">${c.exL}</div><p class="mini__p">${c.ex}</p></div><div class="mini mini--caution" style="flex:1"><div class="mini__l">${c.cauL}</div><p class="mini__p">${c.cau}</p></div></div>${foot( num, t.running )}</div></section>` );
	}
	add( ( num ) => summaryPage( t, t.m3r, num ) );

	// ===== Module 04 · Na prática =====
	add( () => moduleDivider( t.m4 ), { num: t.m4.num, title: t.m4.title, sub: t.m4.desc.split( '.' )[ 0 ] } );
	{
		const c = t.m4c1;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:8px">${c.chartH}</div>
		    <svg viewBox="0 0 480 150" width="100%" height="150"><path d="M10 100 C 70 60, 110 130, 160 90 C 210 50, 250 120, 300 70 C 350 30, 400 90, 470 40" fill="none" stroke="#E3D7D0" stroke-width="2.5"/><g fill="#FF6B5E"><circle cx="10" cy="100" r="5"/><circle cx="86" cy="93" r="5"/><circle cx="160" cy="90" r="5"/><circle cx="235" cy="86" r="5"/><circle cx="300" cy="70" r="5"/><circle cx="385" cy="62" r="5"/><circle cx="470" cy="40" r="5"/></g></svg>
		    <div style="font-weight:500;font-size:12px;color:#A89FB5;margin-top:8px;text-align:center">${c.chartNote}</div></div>
		  <p class="body" style="margin:18px 0 0;max-width:485px">${c.p2}</p>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m4c2;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div style="background:#1E2147;border-radius:16px;padding:22px 24px;margin:20px 0 0;color:#fff">
		    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:8px"><div class="pop" style="font-weight:700;font-size:15px">${c.chartH}</div><span style="font-weight:500;font-size:12px;color:#9A9EC4">${c.chartSub}</span></div>
		    <svg viewBox="0 0 480 140" width="100%" height="140"><defs><linearGradient id="gfee" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FF6B5E"/><stop offset="1" stop-color="#7C5CFC"/></linearGradient></defs><line x1="6" y1="122" x2="474" y2="122" stroke="#3A3E66" stroke-width="1.5"/><path d="M6 112 C 160 104, 300 78, 380 50 C 430 32, 455 16, 474 6" fill="none" stroke="url(#gfee)" stroke-width="3.5" stroke-linecap="round"/><path d="M6 113 C 160 108, 300 92, 380 72 C 430 58, 455 46, 474 38" fill="none" stroke="#6B6F96" stroke-width="2.5" stroke-dasharray="5 5"/></svg>
		    <div style="display:flex;gap:20px;margin-top:8px;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#FF8377"></span><span style="font-weight:500;font-size:12px;color:#fff">${c.legA}</span></div><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#6B6F96"></span><span style="font-weight:500;font-size:12px;color:#9A9EC4">${c.legB}</span></div></div></div>
		  <div style="display:flex;gap:12px;margin-top:16px"><div class="mini mini--ex" style="flex:1"><div class="mini__l">${c.exL}</div><p class="mini__p">${c.ex}</p></div><div class="mini mini--caution" style="flex:1"><div class="mini__l">${c.cauL}</div><p class="mini__p">${c.cau}</p></div></div>
		  <p style="font-size:14px;line-height:1.6;color:#6E6680;margin:16px 0 0;max-width:485px">${c.p2}</p>${foot( num, t.running )}</div></section>` );
	}
	add( ( num ) => summaryPage( t, t.m4r, num ) );

	// ===== Module 05 · Comportamento =====
	add( () => moduleDivider( t.m5 ), { num: t.m5.num, title: t.m5.title, sub: t.m5.desc.split( '—' )[ 0 ].trim() } );
	{
		const c = t.m5c1;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:8px">${c.chartH}</div>
		    <svg viewBox="0 0 480 150" width="100%" height="150"><line x1="6" y1="138" x2="474" y2="138" stroke="#EFE0D9" stroke-width="1.5"/><path d="M10 70 C 80 64, 120 120, 170 120 C 230 120, 300 60, 380 36 C 420 24, 450 16, 470 12" fill="none" stroke="#22C3A6" stroke-width="3.5" stroke-linecap="round"/><path d="M170 120 C 200 124, 220 126, 240 128" fill="none" stroke="#FF6B5E" stroke-width="3.5" stroke-linecap="round"/><circle cx="170" cy="120" r="6" fill="#FF6B5E"/><circle cx="470" cy="12" r="6" fill="#22C3A6"/><text x="120" y="142" font-family="Jakarta" font-size="11" fill="#A89FB5">${c.dropLabel}</text></svg>
		    <div style="display:flex;gap:20px;margin-top:6px;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#22C3A6"></span><span style="font-weight:500;font-size:12px;color:#6E6680">${c.legA}</span></div><div style="display:flex;align-items:center;gap:7px"><span style="width:18px;height:3px;border-radius:2px;background:#FF6B5E"></span><span style="font-weight:500;font-size:12px;color:#6E6680">${c.legB}</span></div></div></div>
		  <p class="body" style="margin:18px 0 0;max-width:485px">${c.p2}</p>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m5c2;
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div style="display:flex;flex-direction:column;gap:10px;margin:20px 0 0">${c.mistakes.map( mk => `<div class="card" style="display:flex;gap:13px;align-items:flex-start;border-radius:13px;padding:14px 16px"><span style="flex:none;width:26px;height:26px;border-radius:8px;background:#FCEFD9;color:#A9740C;display:flex;align-items:center;justify-content:center"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></span><div><div style="font-weight:600;font-size:14px;color:#2A2438">${mk[ 0 ]}</div><div style="font-size:12.5px;line-height:1.45;color:#6E6680;margin-top:2px">${mk[ 1 ]}</div></div></div>` ).join( '' )}</div>${foot( num, t.running )}</div></section>` );
	}
	add( ( num ) => summaryPage( t, t.m5r, num ) );

	// ===== Module 06 · O teu plano =====
	add( () => moduleDivider( t.m6 ), { num: t.m6.num, title: t.m6.title, sub: t.m6.desc.split( '.' )[ 0 ] } );
	{
		const c = t.m6c1;
		const ic = { coral: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="0.5" fill="currentColor"/>', purple: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', green: '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', gold: '<path d="M3 3v18h18M7 14l3-3 3 2 4-5"/>' };
		add( ( num ) => `<section class="pg"><div class="pad">${chapHead( c )}${chapTitle( c )}
		  <p class="body" style="margin:14px 0 0;max-width:485px">${c.p1}</p>
		  <div class="card" style="margin:20px 0 0;padding:22px">
		    <div class="pop" style="font-weight:700;font-size:15px;margin-bottom:16px">${c.chartH}</div>
		    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">${c.pieces.map( p => `<div style="display:flex;gap:11px;align-items:flex-start"><span style="flex:none;width:30px;height:30px;border-radius:9px;background:${TONE[ p[ 0 ] ][ 0 ]};color:${TONE[ p[ 0 ] ][ 1 ]};display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ic[ p[ 0 ] ]}</svg></span><div><div style="font-weight:600;font-size:13.5px">${p[ 1 ]}</div><div style="font-size:12px;color:#6E6680;line-height:1.4">${p[ 2 ]}</div></div></div>` ).join( '' )}</div></div>
		  <p class="body" style="margin:18px 0 0;max-width:485px">${c.p2}</p>
		  <div class="key push" style="padding:15px 20px"><div class="key__l">${KEY( t )}</div><p class="key__p">${c.key}</p></div>${foot( num, t.running )}</div></section>` );
	}
	{
		const c = t.m6plan;
		add( ( num ) => `<section class="pg" style="background:#1E2147;color:#fff"><div class="pad">
		  <div style="display:flex;align-items:center;gap:8px;margin-bottom:7mm"><span style="font-weight:600;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#B7A4FF">${c.modlabel}</span><span style="flex:1"></span><span style="font-weight:600;font-size:10px;color:#fff;background:#FF6B5E;padding:3px 10px;border-radius:999px">${c.time}</span></div>
		  <div style="display:flex;align-items:baseline;gap:12px"><span class="pop" style="font-weight:800;font-size:22px;color:#42466E">${c.num}</span><h3 class="h3" style="color:#fff">${c.h}</h3></div>
		  <p style="font-size:14.5px;line-height:1.6;color:#B9BCD8;margin:12px 0 0;max-width:480px">${c.p}</p>
		  <div style="background:#fff;border-radius:18px;padding:24px 26px;margin-top:20px;flex:1;display:flex;flex-direction:column;gap:16px;color:#2A2438">
		    <div style="display:flex;align-items:center;gap:10px;border-bottom:1px solid #F2E4DD;padding-bottom:14px"><span style="width:26px;height:26px;display:flex;flex:none">${logo( 'navy' )}</span><span class="pop" style="font-weight:700;font-size:16px">${c.cardH}</span></div>
		    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 22px">${c.fields.map( f => `<div><div style="font-weight:600;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#A89FB5;margin-bottom:9px">${f}</div><div style="height:1px;background:#E3D7D0"></div></div>` ).join( '' )}</div>
		    <div><div style="font-weight:600;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#A89FB5;margin-bottom:12px">${c.allocL}</div>
		      <div style="display:flex;gap:10px">${c.alloc.map( a => `<div style="flex:1;background:${a[ 0 ]};border-radius:11px;padding:13px 14px;text-align:center"><span style="width:11px;height:11px;border-radius:3px;background:${a[ 2 ]};display:inline-block"></span><div style="font-weight:600;font-size:12px;color:#6E6680;margin:6px 0 8px">${a[ 3 ]}</div><div class="pop" style="font-weight:700;font-size:18px;color:${a[ 1 ]}">___ %</div></div>` ).join( '' )}</div></div>
		    <div style="background:#FFF6F1;border:1px dashed #E3D2C9;border-radius:12px;padding:14px 16px"><div style="font-weight:600;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#C9362C;margin-bottom:8px">${c.ruleL}</div><div style="height:1px;background:#E3D7D0;margin-bottom:11px"></div><div style="height:1px;background:#E3D7D0"></div></div>
		  </div>
		  <div class="pgfoot" style="color:#5B5F84"><span>${t.running}</span><span>${String( num ).padStart( 2, '0' )}</span></div></div></section>` );
	}
	add( ( num ) => summaryPage( t, t.m6r, num ) );

	// ===== Mitos / Myths =====
	{
		const c = t.mitos;
		add( ( num ) => `<section class="pg"><div class="pad">
		  <div style="margin-bottom:7mm"><span class="kick">${c.kick}</span></div>
		  <h2 class="h2" style="font-size:32px;max-width:440px">${c.h}</h2>
		  <p class="lead" style="font-size:15px;color:#6E6680;margin:14px 0 0;max-width:470px">${c.p}</p>
		  <div style="display:flex;flex-direction:column;gap:12px;margin:22px 0 0">${c.rows.map( rw => `<div class="card" style="padding:16px 18px;display:flex;gap:16px;align-items:flex-start"><div style="flex:none;width:74px"><span style="font-weight:600;font-size:9px;letter-spacing:.06em;text-transform:uppercase;color:#C9362C">${c.mythL}</span><div class="pop" style="font-weight:700;font-size:13px;color:#2A2438;margin-top:4px;line-height:1.2">${rw[ 0 ]}</div></div><div style="flex:1;border-left:1px solid #F2E4DD;padding-left:16px"><span style="font-weight:600;font-size:9px;letter-spacing:.06em;text-transform:uppercase;color:#0E9C84">${c.truthL}</span><p style="font-size:13px;line-height:1.5;color:#5A5270;margin:4px 0 0">${rw[ 1 ]}</p></div></div>` ).join( '' )}</div>${foot( num, t.running )}</div></section>` );
	}

	// ===== Glossário (3 pages: coral, purple, green) =====
	const glossPage = ( items, color, first, last ) => ( num ) => `<section class="pg"><div class="pad">
	  <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:7mm"><h2 class="h2" style="font-size:34px;color:${first ? '#2A2438' : '#E3CFC6'}">${t.gloss.h}</h2><span class="kick" style="letter-spacing:.1em;font-size:11px">${first ? t.gloss.sub : t.gloss.cont}</span></div>
	  <div style="columns:2;column-gap:28px">${items.map( g => `<div style="break-inside:avoid;margin-bottom:16px"><div class="pop" style="font-weight:700;font-size:15px;color:${color}">${g[ 0 ]}</div><p style="font-size:12.5px;line-height:1.5;color:#5A5270;margin:4px 0 0">${g[ 1 ]}</p></div>` ).join( '' )}</div>
	  ${last ? `<div class="card" style="margin-top:14px;padding:20px 22px"><div class="kick kick--purple" style="letter-spacing:.08em;margin-bottom:7px">${t.gloss.noteL}</div><p style="font-size:13px;line-height:1.6;color:#5A5270;margin:0">${t.gloss.note}</p></div>` : ''}
	  ${foot( num, t.running )}</div></section>`;
	add( glossPage( t.gloss.p1, '#FF6B5E', true, false ), { num: null, title: t.gloss.h } );
	add( glossPage( t.gloss.p2, '#7C5CFC', false, false ) );
	add( glossPage( t.gloss.p3, '#0E9C84', false, true ) );

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
