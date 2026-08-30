<?php
/**
 * What each SHAPE of dossier teaches, in English and Portuguese.
 *
 * Survive the Charts has class-lessons.php. The Reveal had nothing equivalent,
 * which meant the lesson lived per case — so every editor who finished a case
 * also had to write, bilingually and in the house voice, a paragraph about
 * what the case taught. Two dossiers of the same shape then got two different
 * lessons, and the one written at the end of a long afternoon got the worse of
 * them.
 *
 * So the lessons are keyed by PATTERN and never by company. "Profit and cash
 * disagreeing" teaches the same thing whoever the company was, and a lesson
 * about that carries no claim about anybody: it is a sentence about how to
 * read a dossier, not a sentence about a business. That is also what makes
 * this file safe to write in an environment where nothing can be checked —
 * there is nothing here to check, because there is no fact here.
 *
 * WHAT A LESSON IS ALLOWED TO BE ABOUT. How to read the page in front of you,
 * and how much of the account to put behind an incomplete picture. Never what
 * the company went on to do — that is the case's own `hti_rev_context_*`, it
 * belongs to whoever read the filing, and a pattern lesson that drifted into
 * it would be making an unsourced claim about every company filed under the
 * pattern at once.
 *
 * Never, either, a rule that would have paid on this particular case. "The
 * cheap one was the right one" is a sentence about an outcome that has already
 * happened, and a game that hands it over teaches the player to reach for it
 * next time.
 *
 * TWO PER PATTERN, where Survive the Charts needs eight per class. The reason
 * is arithmetic rather than taste: a scenario class comes round every third
 * day, a pattern roughly every fortnight, so two rotate about as far apart
 * there as eight do here.
 *
 * The voice is the site's — calm, second person, conditional, no urgency, no
 * promises, no imperatives. See .claude/skills/brand-voice/SKILL.md. Not
 * `__()`, for the reason given at the top of HTI\Games\Strings: the site runs
 * `pt_PT_ao90` against `pt_PT` files, WordPress does not fall back between
 * them, and a missing Portuguese string renders in English without telling
 * anybody. tests/test-reveal-lessons.php fails if either language is missing.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The Reveal's bilingual lesson library, keyed by dossier pattern. Pure.
 */
class Reveal_Lessons {

	/**
	 * Languages this table is complete in.
	 *
	 * Mirrors Strings::LANGS deliberately rather than importing it, exactly as
	 * Lessons does; tests/test-reveal-lessons.php asserts the lists agree.
	 */
	public const LANGS = array( 'en', 'pt' );

	/**
	 * The pattern drawn on when a case names one this library does not know.
	 *
	 * `dossier_limits` and not one of the others, because its two lessons are
	 * the ones true of every dossier ever built: six figures and three
	 * headlines are not a company, and the size is the part you chose. It is
	 * deliberately the one pattern no seeded case is filed under — a case
	 * whose shape nobody has decided yet is exactly the case that should get
	 * the lesson about not being able to see the whole thing.
	 */
	public const FALLBACK_PATTERN = 'dossier_limits';

	/**
	 * Every pattern: id => bilingual name, plus the question the dossier is
	 * really putting to the player.
	 *
	 * The `asks` line is not decoration. It is what a case's six fundamentals
	 * are chosen to answer, and Seed_Cases quotes it at the top of every
	 * research brief so the editor filling the six boxes knows what the six
	 * boxes are for.
	 *
	 * A pattern is a HYPOTHESIS about a dossier, never a verdict on a company.
	 * Filing a case under one says "go and see whether the filing has this
	 * shape", and the honest outcome of that reading is sometimes "it does
	 * not, so the pattern was wrong".
	 *
	 * @return array<string,array{en:string,pt:string,asks_en:string,asks_pt:string}>
	 */
	public static function patterns(): array {
		return array(
			'hidden_compounder'       => array(
				'en'      => 'The loss-making business that was compounding',
				'pt'      => 'O negócio deficitário que estava a compor',
				'asks_en' => 'Is this company losing money, or paying cash now for something the accounts will not call an asset until later?',
				'asks_pt' => 'Esta empresa está a perder dinheiro, ou a pagar agora em caixa por algo que as contas só mais tarde chamarão ativo?',
			),
			'fraud'                   => array(
				'en'      => 'The numbers that were not real',
				'pt'      => 'Os números que não eram reais',
				'asks_en' => 'Does the cash agree with the profit, and can anybody outside the company confirm what the balance sheet says is there?',
				'asks_pt' => 'A caixa concorda com o lucro, e alguém de fora da empresa consegue confirmar o que o balanço diz estar lá?',
			),
			'tech_shift'              => array(
				'en'      => 'Excellent numbers, and a technology about to move',
				'pt'      => 'Números excelentes, e uma tecnologia prestes a mudar',
				'asks_en' => 'Do these figures describe a position that is being defended, or one that is about to be walked around?',
				'asks_pt' => 'Estes números descrevem uma posição que está a ser defendida, ou uma que está prestes a ser contornada?',
			),
			'great_company_bad_price' => array(
				'en'      => 'A fine business at an unforgiving price',
				'pt'      => 'Um bom negócio a um preço implacável',
				'asks_en' => 'How much of the good news in this dossier has already been paid for?',
				'asks_pt' => 'Quanto das boas notícias deste dossiê já foi pago?',
			),
			'unit_economics'          => array(
				'en'      => 'Every sale lost money',
				'pt'      => 'Cada venda perdia dinheiro',
				'asks_en' => 'Does a single sale, on its own, leave anything behind once making it and winning it are both paid for?',
				'asks_pt' => 'Uma venda isolada deixa alguma coisa depois de pagos o custo de a produzir e o custo de a conquistar?',
			),
			'cyclical_peak'           => array(
				'en'      => 'A cyclical business at the top of its cycle',
				'pt'      => 'Um negócio cíclico no topo do ciclo',
				'asks_en' => 'Are these earnings what the business makes, or what the cycle handed it this year?',
				'asks_pt' => 'Estes lucros são o que o negócio gera, ou o que o ciclo lhe entregou neste ano?',
			),
			'rollup'                  => array(
				'en'      => 'Growth that was bought, not built',
				'pt'      => 'Crescimento comprado, não construído',
				'asks_en' => 'How much of this growth would still be here if the company had bought nothing?',
				'asks_pt' => 'Quanto deste crescimento continuaria cá se a empresa não tivesse comprado nada?',
			),
			'leverage_rates'          => array(
				'en'      => 'A balance sheet built for one interest rate',
				'pt'      => 'Um balanço feito para uma única taxa de juro',
				'asks_en' => 'What does this balance sheet assume about the cost and the availability of money, and when is that assumption tested?',
				'asks_pt' => 'O que é que este balanço assume sobre o custo e a disponibilidade do dinheiro, e quando é que esse pressuposto é testado?',
			),
			'regulatory_moat'         => array(
				'en'      => 'A moat written in law',
				'pt'      => 'Um fosso escrito na lei',
				'asks_en' => 'Where does the protection behind these margins come from, and who is able to withdraw it?',
				'asks_pt' => 'De onde vem a proteção que sustenta estas margens, e quem a pode retirar?',
			),
			'cash_vs_earnings'        => array(
				'en'      => 'Profit and cash disagreeing',
				'pt'      => 'O lucro e a caixa em desacordo',
				'asks_en' => 'If the profit is real, where is it, and why has it not turned up in the bank?',
				'asks_pt' => 'Se o lucro é real, onde está, e porque é que ainda não apareceu no banco?',
			),
			'boring_compounder'       => array(
				'en'      => 'The dull business nobody wanted',
				'pt'      => 'O negócio aborrecido que ninguém queria',
				'asks_en' => 'Is there anything wrong with this business, or is it only uninteresting to talk about?',
				'asks_pt' => 'Há alguma coisa de errado com este negócio, ou é apenas pouco interessante de conversa?',
			),
			'turnaround_worked'       => array(
				'en'      => 'The turnaround that took',
				'pt'      => 'A recuperação que pegou',
				'asks_en' => 'Is the recovery visible in the operating figures yet, or only in the plan?',
				'asks_pt' => 'A recuperação já se vê nos números operacionais, ou só no plano?',
			),
			'turnaround_failed'       => array(
				'en'      => 'The turnaround that did not',
				'pt'      => 'A recuperação que não pegou',
				'asks_en' => 'Is the business getting better, or getting smaller more tidily?',
				'asks_pt' => 'O negócio está a melhorar, ou apenas a encolher de forma mais arrumada?',
			),
			'accounting_change'       => array(
				'en'      => 'The accounting changed the year the numbers improved',
				'pt'      => 'A contabilidade mudou no ano em que os números melhoraram',
				'asks_en' => 'Did the business change this year, or did the way it is described change?',
				'asks_pt' => 'O que mudou este ano foi o negócio, ou a forma como ele é descrito?',
			),
			'concentration'           => array(
				'en'      => 'One product, one customer, one market',
				'pt'      => 'Um produto, um cliente, um mercado',
				'asks_en' => 'How many separate decisions, made by how many separate people, does this year of revenue rest on?',
				'asks_pt' => 'Em quantas decisões separadas, tomadas por quantas pessoas diferentes, assenta este ano de receitas?',
			),
			'dilution'                => array(
				'en'      => 'Growth paid for with new shares',
				'pt'      => 'Crescimento pago com ações novas',
				'asks_en' => 'The company is bigger — but is each share a claim on more than it was a year ago?',
				'asks_pt' => 'A empresa é maior — mas cada ação dá direito a mais do que dava há um ano?',
			),
			'dossier_limits'          => array(
				'en'      => 'What six numbers cannot tell you',
				'pt'      => 'O que seis números não te podem dizer',
				'asks_en' => 'What has been left off this page, and how much of the account would you put behind what is on it?',
				'asks_pt' => 'O que ficou de fora desta página, e quanto da conta porias atrás do que lá está?',
			),
		);
	}

	/**
	 * Every lesson, pattern => list of { id, en, pt }.
	 *
	 * @return array<string,array<int,array{id:string,en:string,pt:string}>>
	 */
	public static function all(): array {
		return array(
			'hidden_compounder'       => array(
				array(
					'id' => 'rev_lesson_hidden_compounder_01',
					'en' => 'A business can consume cash for years because it is buying something the accounts will not call an asset — a customer base, a network, a habit. Whether it was doing that, or simply losing money, is the question the dossier puts to you, and the profit line on its own does not answer it.',
					'pt' => 'Um negócio pode consumir caixa durante anos porque está a comprar algo que as contas não chamam ativo — uma base de clientes, uma rede, um hábito. Se era isso que estava a fazer, ou se estava apenas a perder dinheiro, é a pergunta que o dossiê te põe, e a linha do lucro sozinha não a responde.',
				),
				array(
					'id' => 'rev_lesson_hidden_compounder_02',
					'en' => 'Losses that shrink while revenue grows read very differently from losses that grow with it. In the single year you are shown, both are a red number; only one of them has a direction.',
					'pt' => 'Prejuízos que encolhem enquanto as receitas crescem leem-se de forma muito diferente de prejuízos que crescem com elas. No único ano que te mostram, ambos são um número vermelho; só um deles tem uma direção.',
				),
			),
			'fraud'                   => array(
				array(
					'id' => 'rev_lesson_fraud_01',
					'en' => 'Reported profit is an opinion about timing; cash is a fact about a bank account. When the two have been apart for years and the explanation keeps changing, the gap is the story rather than a detail of it.',
					'pt' => 'O lucro declarado é uma opinião sobre calendário; a caixa é um facto sobre uma conta bancária. Quando os dois andam separados há anos e a explicação muda de cada vez, a diferença é a história e não um pormenor dela.',
				),
				array(
					'id' => 'rev_lesson_fraud_02',
					'en' => 'Complexity nobody inside the company can explain simply is itself a finding. A structure that parks obligations outside the balance sheet is not automatically dishonest, and it is by construction somewhere a reader cannot see.',
					'pt' => 'Uma complexidade que ninguém dentro da empresa consegue explicar de forma simples é, por si só, uma conclusão. Uma estrutura que estaciona compromissos fora do balanço não é automaticamente desonesta, e está, por construção, num sítio onde quem lê não consegue ver.',
				),
			),
			'tech_shift'              => array(
				array(
					'id' => 'rev_lesson_tech_shift_01',
					'en' => 'The best year of a business and the first year of its decline can be the same year. Margins and market share describe what has already been sold; they say nothing about what the next buyer will want.',
					'pt' => 'O melhor ano de um negócio e o primeiro ano do seu declínio podem ser o mesmo ano. As margens e a quota de mercado descrevem o que já foi vendido; não dizem nada sobre o que o próximo comprador vai querer.',
				),
				array(
					'id' => 'rev_lesson_tech_shift_02',
					'en' => 'A dossier of excellent figures is a photograph of the past. When the real question is whether the product survives a change in how people do the thing it does, the accounts are not where the answer lives.',
					'pt' => 'Um dossiê de números excelentes é uma fotografia do passado. Quando a pergunta verdadeira é se o produto sobrevive a uma mudança na forma como as pessoas fazem aquilo, a resposta não está nas contas.',
				),
			),
			'great_company_bad_price' => array(
				array(
					'id' => 'rev_lesson_great_company_bad_price_01',
					'en' => 'A wonderful business and a wonderful investment are not the same object. The dossier withholds the price on purpose, because the price is where most of the difference between the two ends up.',
					'pt' => 'Um negócio excelente e um investimento excelente não são a mesma coisa. O dossiê esconde o preço de propósito, porque é no preço que fica quase toda a diferença entre os dois.',
				),
				array(
					'id' => 'rev_lesson_great_company_bad_price_02',
					'en' => 'When a company is priced for everything going right, the years that follow can go right and still disappoint. Quality answers whether the business lasts; the multiple answers what was paid for that.',
					'pt' => 'Quando uma empresa está avaliada para que tudo corra bem, os anos seguintes podem correr bem e ainda assim desiludir. A qualidade responde se o negócio dura; o múltiplo responde ao que se pagou por isso.',
				),
			),
			'unit_economics'          => array(
				array(
					'id' => 'rev_lesson_unit_economics_01',
					'en' => 'If a sale costs more to make and to deliver than it brings in, more sales make the hole deeper. Growth is only good news once the arithmetic of one single sale works.',
					'pt' => 'Se uma venda custa mais a produzir e a entregar do que aquilo que traz, mais vendas tornam o buraco maior. O crescimento só é boa notícia quando a aritmética de uma única venda fecha.',
				),
				array(
					'id' => 'rev_lesson_unit_economics_02',
					'en' => 'Gross margin is where this shows up first: it is what is left before anybody has been paid to run the company. A thin one leaves nothing for the rest of the business to come out of.',
					'pt' => 'A margem bruta é onde isto aparece primeiro: é o que sobra antes de alguém ter sido pago para gerir a empresa. Uma margem fina não deixa nada de onde possa sair o resto do negócio.',
				),
			),
			'cyclical_peak'           => array(
				array(
					'id' => 'rev_lesson_cyclical_peak_01',
					'en' => 'In a cyclical business the best-looking year is often the most expensive one to buy into, because the earnings on the page are the earnings the cycle happened to hand over that year.',
					'pt' => 'Num negócio cíclico, o ano com melhor aspeto é muitas vezes o mais caro para entrar, porque os lucros que estão na página são os lucros que o ciclo calhou entregar nesse ano.',
				),
				array(
					'id' => 'rev_lesson_cyclical_peak_02',
					'en' => 'A low multiple on peak earnings is not cheap; it is the same price with a flattering denominator. Setting the year against the average of a whole cycle changes what the number means.',
					'pt' => 'Um múltiplo baixo sobre lucros de pico não é barato; é o mesmo preço com um denominador lisonjeiro. Pôr o ano ao lado da média de um ciclo inteiro muda o que o número quer dizer.',
				),
			),
			'rollup'                  => array(
				array(
					'id' => 'rev_lesson_rollup_01',
					'en' => 'Revenue that arrived by acquisition and revenue the business grew itself are printed on the same line. Separating the two is the first thing worth doing to a company whose main activity is buying other companies.',
					'pt' => 'As receitas que chegaram por aquisição e as receitas que o negócio fez crescer sozinho aparecem na mesma linha. Separar as duas é a primeira coisa a fazer a uma empresa cuja atividade principal é comprar outras empresas.',
				),
				array(
					'id' => 'rev_lesson_rollup_02',
					'en' => 'A roll-up has to keep buying in order to keep growing, and each purchase is paid for with cash, with debt or with new shares. Goodwill piling up on the balance sheet is the record of what was paid above the value of what was bought.',
					'pt' => 'Um consolidador tem de continuar a comprar para continuar a crescer, e cada compra é paga com caixa, com dívida ou com ações novas. O goodwill a acumular no balanço é o registo do que se pagou acima do valor daquilo que se comprou.',
				),
			),
			'leverage_rates'          => array(
				array(
					'id' => 'rev_lesson_leverage_rates_01',
					'en' => 'Borrowing is cheap until it is refinanced. A balance sheet that works at one interest rate and not at another has an assumption inside it that the income statement never shows.',
					'pt' => 'O financiamento é barato até ser refinanciado. Um balanço que funciona a uma taxa de juro e não a outra tem lá dentro um pressuposto que a demonstração de resultados nunca mostra.',
				),
				array(
					'id' => 'rev_lesson_leverage_rates_02',
					'en' => 'Two questions decide how much a move in rates matters: when the borrowing falls due, and how many times the operating profit covers the interest. Both sit in the notes rather than in the headline figures.',
					'pt' => 'Duas perguntas decidem o quanto uma mudança de taxas importa: quando é que o financiamento vence, e quantas vezes o resultado operacional cobre os juros. As duas estão nas notas e não nos números de capa.',
				),
			),
			'regulatory_moat'         => array(
				array(
					'id' => 'rev_lesson_regulatory_moat_01',
					'en' => 'A protection written into a rule can be undone by the same pen that wrote it. A business whose advantage comes from a licence or a public programme has a competitor that meets in a parliament.',
					'pt' => 'Uma proteção escrita numa regra pode ser desfeita pela mesma caneta que a escreveu. Um negócio cuja vantagem vem de uma licença ou de um programa público tem um concorrente que se reúne num parlamento.',
				),
				array(
					'id' => 'rev_lesson_regulatory_moat_02',
					'en' => 'Moats built by customers behave differently from moats built by legislators. Both produce comfortable margins; only one of them is renegotiated on somebody else timetable.',
					'pt' => 'Fossos construídos por clientes comportam-se de forma diferente de fossos construídos por legisladores. Ambos dão margens confortáveis; só um deles é renegociado no calendário de outra pessoa.',
				),
			),
			'cash_vs_earnings'        => array(
				array(
					'id' => 'rev_lesson_cash_vs_earnings_01',
					'en' => 'Profit is a judgement about when a sale counts. Cash is what arrived. When the two drift apart year after year, the interesting question is which judgement keeps being made, and by whom.',
					'pt' => 'O lucro é um juízo sobre quando é que uma venda conta. A caixa é o que chegou. Quando os dois se afastam ano após ano, a pergunta interessante é que juízo continua a ser feito, e por quem.',
				),
				array(
					'id' => 'rev_lesson_cash_vs_earnings_02',
					'en' => 'Money owed by customers growing faster than sales, or work billed later and later, are ordinary things that also happen to be how profit stays ahead of cash. It is worth knowing which of the two you are looking at.',
					'pt' => 'Dinheiro em dívida de clientes a crescer mais depressa do que as vendas, ou trabalho faturado cada vez mais tarde, são coisas banais que também são a forma como o lucro se mantém à frente da caixa. Vale a pena saber qual das duas estás a ver.',
				),
			),
			'boring_compounder'       => array(
				array(
					'id' => 'rev_lesson_boring_compounder_01',
					'en' => 'A dossier with nothing dramatic in it is easy to pass over, and the absence of drama is sometimes the finding. Steady margins and a business that repeats itself do not photograph well.',
					'pt' => 'Um dossiê sem nada de dramático é fácil de saltar, e a ausência de drama é, às vezes, a própria conclusão. Margens estáveis e um negócio que se repete não ficam bem na fotografia.',
				),
				array(
					'id' => 'rev_lesson_boring_compounder_02',
					'en' => 'Returns on capital that hold for years, in an industry nobody discusses, describe a business that has been left alone. Dullness is not a defect of the numbers; quite often it is the whole of the case.',
					'pt' => 'Rendibilidades do capital que se mantêm durante anos, num setor de que ninguém fala, descrevem um negócio que foi deixado em paz. O tédio não é um defeito dos números; muitas vezes é o caso inteiro.',
				),
			),
			'turnaround_worked'       => array(
				array(
					'id' => 'rev_lesson_turnaround_worked_01',
					'en' => 'A recovery shows up in the operating figures before it shows up in the story: the same sales costing less, or the same outlets selling more. Those move first, and they are hard to arrange.',
					'pt' => 'Uma recuperação aparece nos números operacionais antes de aparecer na narrativa: as mesmas vendas a custar menos, ou as mesmas lojas a vender mais. Esses mexem primeiro, e são difíceis de encenar.',
				),
				array(
					'id' => 'rev_lesson_turnaround_worked_02',
					'en' => 'The dossier of a company in trouble and the dossier of a company coming out of trouble look alike for a year or two. What separates them is usually whether cash is arriving, not whether the plan is convincing.',
					'pt' => 'O dossiê de uma empresa em apuros e o de uma empresa a sair de apuros parecem-se durante um ano ou dois. O que os separa é normalmente se a caixa está a entrar, e não se o plano é convincente.',
				),
			),
			'turnaround_failed'       => array(
				array(
					'id' => 'rev_lesson_turnaround_failed_01',
					'en' => 'Cutting costs can hold a profit line still while the business underneath goes on shrinking. A margin defended by spending less is not the same thing as a margin defended by customers.',
					'pt' => 'Cortar custos pode segurar a linha do lucro enquanto o negócio por baixo continua a encolher. Uma margem defendida a gastar menos não é a mesma coisa que uma margem defendida por clientes.',
				),
				array(
					'id' => 'rev_lesson_turnaround_failed_02',
					'en' => 'Most turnarounds do not turn. That is no reason to dismiss one out of hand, and it is a reason to ask what this dossier would look like if the plan simply did not work, and how much of the account would be left.',
					'pt' => 'A maioria das recuperações não recupera. Isso não é razão para descartar nenhuma à partida, e é razão para perguntar como seria este dossiê se o plano simplesmente não resultasse, e quanto da conta sobraria.',
				),
			),
			'accounting_change'       => array(
				array(
					'id' => 'rev_lesson_accounting_change_01',
					'en' => 'When the year the numbers improved is also the year a policy, an estimate or an auditor changed, the improvement and the change are worth reading side by side before either one is trusted.',
					'pt' => 'Quando o ano em que os números melhoraram é também o ano em que mudou uma política, uma estimativa ou um auditor, vale a pena ler a melhoria e a mudança lado a lado antes de confiar em qualquer uma delas.',
				),
				array(
					'id' => 'rev_lesson_accounting_change_02',
					'en' => 'An accounting policy is a choice about how to describe the same events. Changing one is perfectly legitimate, and it also resets the comparison — which is why the note explaining it tends to be the most useful page in the report.',
					'pt' => 'Uma política contabilística é uma escolha sobre como descrever os mesmos acontecimentos. Mudar uma é perfeitamente legítimo, e também reinicia a comparação — e é por isso que a nota que a explica costuma ser a página mais útil do relatório.',
				),
			),
			'concentration'           => array(
				array(
					'id' => 'rev_lesson_concentration_01',
					'en' => 'A business with one product, one customer or one market can be excellent and still have a single point of failure. Concentration does not show up in a margin; it shows up in the risk section, in a sentence.',
					'pt' => 'Um negócio com um produto, um cliente ou um mercado pode ser excelente e ainda assim ter um único ponto de rutura. A concentração não aparece numa margem; aparece na secção de riscos, numa frase.',
				),
				array(
					'id' => 'rev_lesson_concentration_02',
					'en' => 'The question is not how good this year of sales was, but how many separate decisions it depended on. When the answer is one, the size of the position matters more than the quality of the business.',
					'pt' => 'A pergunta não é o quão bom foi este ano de vendas, mas de quantas decisões separadas dependeu. Quando a resposta é uma, o tamanho da posição importa mais do que a qualidade do negócio.',
				),
			),
			'dilution'                => array(
				array(
					'id' => 'rev_lesson_dilution_01',
					'en' => 'A company can grow while your slice of it shrinks. Revenue per share and profit per share answer a different question from revenue and profit, and it is the question an owner is asking.',
					'pt' => 'Uma empresa pode crescer enquanto a tua fatia dela encolhe. As receitas por ação e o lucro por ação respondem a uma pergunta diferente das receitas e do lucro, e é a pergunta que um dono faz.',
				),
				array(
					'id' => 'rev_lesson_dilution_02',
					'en' => 'New shares are a way of paying for things — for acquisitions, for staff, for another year of losses. The share count over time is the record of how much of the company was spent doing it.',
					'pt' => 'Ações novas são uma forma de pagar coisas — aquisições, pessoal, mais um ano de prejuízos. O número de ações ao longo do tempo é o registo de quanto da empresa foi gasto a fazê-lo.',
				),
			),
			'dossier_limits'          => array(
				array(
					'id' => 'rev_lesson_dossier_limits_01',
					'en' => 'Six figures and three headlines are not a company. They are the part of a company that fits on a page, chosen by somebody, and the decision in front of you is how much of the account to put behind an incomplete picture.',
					'pt' => 'Seis números e três manchetes não são uma empresa. São a parte de uma empresa que cabe numa página, escolhida por alguém, e a decisão à tua frente é quanto da conta pôr atrás de um retrato incompleto.',
				),
				array(
					'id' => 'rev_lesson_dossier_limits_02',
					'en' => 'Every dossier leaves something out, and what it leaves out is not random. The size you commit is how you answer a question you cannot fully see — which is most of them.',
					'pt' => 'Todos os dossiês deixam alguma coisa de fora, e o que fica de fora não é ao acaso. O tamanho que comprometes é a forma de responder a uma pergunta que não consegues ver por inteiro — que são quase todas.',
				),
			),
		);
	}

	/**
	 * Whether this library knows a pattern by that id.
	 *
	 * @param string $pattern Candidate pattern id.
	 */
	public static function is_pattern( string $pattern ): bool {
		return isset( self::patterns()[ $pattern ] );
	}

	/**
	 * One pattern's taxonomy row, falling back to FALLBACK_PATTERN.
	 *
	 * @param string $pattern Pattern id.
	 * @return array{en:string,pt:string,asks_en:string,asks_pt:string}
	 */
	public static function pattern( string $pattern ): array {
		$all = self::patterns();
		return $all[ $pattern ] ?? $all[ self::FALLBACK_PATTERN ];
	}

	/**
	 * One lesson for a pattern, chosen deterministically.
	 *
	 * The index wraps, and a negative index wraps the same way a positive one
	 * does — the same arithmetic as Lessons::for_class(), and for the same
	 * reason: PHP's % keeps the sign of the dividend, so -1 % 2 is -1 and
	 * would index off the front of the list.
	 *
	 * @param string $pattern Pattern id; anything unknown falls back.
	 * @param int    $index   Rotation position.
	 * @return array{id:string,en:string,pt:string}
	 */
	public static function for_pattern( string $pattern, int $index ): array {
		$all  = self::all();
		$list = $all[ $pattern ] ?? $all[ self::FALLBACK_PATTERN ];
		$n    = count( $list );
		$at   = ( ( $index % $n ) + $n ) % $n;

		return $list[ $at ];
	}

	/**
	 * Every lesson id in the library.
	 *
	 * @return array<int,string>
	 */
	public static function ids(): array {
		$out = array();

		foreach ( self::all() as $list ) {
			foreach ( $list as $lesson ) {
				$out[] = $lesson['id'];
			}
		}

		return $out;
	}
}
