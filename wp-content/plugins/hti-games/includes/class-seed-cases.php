<?php
/**
 * The dossier library for The Reveal — seeded DELIBERATELY UNFINISHED.
 *
 * READ THIS BEFORE "FIXING" THE MISSING NUMBERS. They are missing on purpose,
 * and filling them in from anywhere other than a document is the exact failure
 * this file exists to prevent.
 *
 * CLAUDE.md invariant 2 forbids naming companies anywhere in the engine's or
 * the LLM's output. The Reveal has one narrow written exemption: it may name a
 * real company, at a real year, **only** inside `hti_reveal_case`, **only** for
 * a period at least Config::REVEAL_MIN_AGE_YEARS in the past, and **only** with
 * a verified source recorded on the case. The exemption is not a permission to
 * name a company; it is a permission to name a company *next to figures that
 * can be traced to a filing*. Strip the second half and the exemption is gone,
 * and what is left is an unsourced claim about a real business on a site that
 * teaches beginners.
 *
 * This build environment has no external network access. Nothing here can be
 * checked against anything, and model memory is never a publishable source
 * (.claude/skills/financial-analyst/SKILL.md). So every case is seeded as:
 *
 *  - `post_status` = 'draft' — never published, and the pool the game serves is
 *    published cases only, so none of these can reach a player;
 *  - `hti_rev_verified` = '0' — nobody has checked anything;
 *  - `hti_rev_source_url` = '' — DELIBERATELY EMPTY. It is the field an editor
 *    fills with the document they read the figures out of, and pre-filling it
 *    with a plausible URL would be forging the audit trail;
 *  - every numeric field — both five-year returns, and every fundamental's
 *    value and sector average — EMPTY. Not approximated, not "roughly right
 *    for now". A number in the box is a claim, and there is nothing here
 *    entitled to make one;
 *  - every headline slot EMPTY, because a period headline is a quotation;
 *  - `hti_rev_context_*` and `hti_rev_lesson_*` EMPTY, because "what happened
 *    next" is the outcome and the outcome is a claim.
 *
 * WHAT IS FILLED IS EVERYTHING THAT IS NOT A CLAIM, and that turns out to be
 * most of the work:
 *
 *  - the company, the year and the sector — the SUBJECT of the case, not an
 *    assertion about it;
 *  - the PATTERN the dossier is expected to have, as a hypothesis to test
 *    against the filing and to change if the filing disagrees;
 *  - the six fundamental LABELS, chosen for that pattern out of metrics().
 *    A label is a question, and a question carries no answer: a fraud-shaped
 *    dossier asks about cash against reported profit and about obligations
 *    parked off the balance sheet, where a cyclical one asks about the price
 *    a producer got and how the year compares with a whole cycle. Six generic
 *    ratios on every case would have quietly thrown that away;
 *  - a RESEARCH BRIEF, bilingual, naming the kind of document to open, which
 *    line item feeds which of the six labels, where a defensible sector
 *    average comes from, and the two returns. It names document TYPES and
 *    where they live, never a specific URL, filing date or registry id — those
 *    cannot be checked here, and a guessed one would forge the audit trail as
 *    surely as a guessed source URL would;
 *  - the tint rubric, and the ready-written pattern lesson from
 *    Reveal_Lessons, which is general and about how to read a dossier rather
 *    than about anybody in particular.
 *
 * The publish gate in class-case-admin.php will refuse every one of them until
 * an editor supplies a source URL, both returns and a tick. That is the
 * intended workflow and not an oversight: tests/test-seed-cases.php asserts
 * that not one case is publishable, that no figure field is populated, and
 * that no numeric-looking financial value appears anywhere in this file —
 * which is the assertion that goes red if somebody ever "finishes" the seed
 * data from memory.
 *
 * UNVERIFIED PROTOTYPE NOTES — NOT DATA, NOT A SOURCE, NEVER COPIED INTO A
 * POST. Every company/year pairing here was chosen for the SHAPE the dossier
 * is expected to have, from recollection only, and recollection is not a
 * source. No direction, magnitude, outcome or date beyond the year of the
 * dossier itself is written down anywhere in this file — a precise number in a
 * comment is the number somebody eventually pastes into the box, and the whole
 * point is that the figure must come out of the filing the editor is reading.
 * If the filing does not have the shape the pattern predicts, the pattern was
 * wrong; change it, and leave the reading alone.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

// Every research brief is built around one pattern lesson, so the two files
// are never useful apart. Guarded by require_once, so the CLI bundle, the
// tests and this file can each load whichever they reach first.
require_once __DIR__ . '/class-reveal-lessons.php';

/**
 * The case library as pure data, plus the CLI seeder.
 */
class Seed_Cases {

	/**
	 * Appended to every seeded title.
	 *
	 * In the title rather than in a meta field because the title is what an
	 * editor sees in the post list, in the trash, in search and at the top of
	 * the edit screen. A warning nobody has to open a box to read is the only
	 * kind that survives a busy afternoon. It is expected to be removed when
	 * the case is finished.
	 */
	public const DRAFT_MARK = '— unverified seed: needs a source and the figures';

	/**
	 * The number of fundamentals rows a dossier carries; six, as the meta box
	 * and CPT::san_fundamentals both assume.
	 */
	public const FUNDAMENTALS = 6;

	/**
	 * The number of period headlines a dossier carries.
	 */
	public const HEADLINES = 3;

	/**
	 * Every case, ready for the seeder.
	 *
	 * Every key of CPT::case_meta() is present on every case, including the
	 * empty ones, so that "this field was left blank on purpose" is visible in
	 * the data rather than inferred from its absence.
	 *
	 * @return array<int,array{company:string,year:int,pattern:string,title:string,meta:array<string,mixed>}>
	 */
	public static function cases(): array {
		$out = array();

		foreach ( self::definitions() as $def ) {
			$out[] = array(
				'company' => $def['company'],
				'year'    => $def['year'],
				'pattern' => $def['pattern'],
				'title'   => sprintf( '%s %d %s', $def['company'], $def['year'], self::DRAFT_MARK ),
				'meta'    => self::meta( $def ),
			);
		}

		return $out;
	}

	/**
	 * One case's complete meta, structure filled and every figure empty.
	 *
	 * @param array<string,mixed> $def One row of definitions().
	 * @return array<string,mixed> Keyed by CPT::case_meta() key.
	 */
	public static function meta( array $def ): array {
		$sector = self::sector( (string) $def['sector'] );

		return array(
			'hti_rev_company'            => (string) $def['company'],
			'hti_rev_year'               => (int) $def['year'],
			'hti_rev_sector_en'          => $sector['en'],
			'hti_rev_sector_pt'          => $sector['pt'],

			// Which shape this dossier is expected to have. A hypothesis the
			// editor tests against the filing, and the key Reveal_Lessons
			// hangs the ready-written lesson on.
			'hti_rev_pattern'            => (string) $def['pattern'],

			// The editor-facing half of the case: what to open, what to read
			// out of it, and where the sector comparison comes from. Bilingual
			// in one field, because the admin screen renders one.
			'hti_rev_brief'              => self::brief( $def ),

			// A band is still a figure about a real company, so what is seeded
			// is the SHAPE of the answer and not an answer. It reads as an
			// instruction on any screen it could ever reach.
			'hti_rev_revenue_band_en'    => 'To fill: a revenue band, never an exact figure (for example, $1bn–$5bn).',
			'hti_rev_revenue_band_pt'    => 'A preencher: uma banda de receitas, nunca um valor exato (por exemplo, 1–5 mil milhões de dólares).',

			// Labels yes, values no. The editor gets the six questions; the
			// answers come out of the filing.
			'hti_rev_fundamentals'       => (string) wp_json_encode( self::fundamentals( (array) $def['fundamentals'] ) ),
			'hti_rev_headlines'          => (string) wp_json_encode( self::headlines() ),

			// The two figures the whole game is built on. Empty, so the publish
			// gate names them as missing rather than accepting a seeded zero as
			// somebody's answer.
			'hti_rev_return_5y_bp'       => '',
			'hti_rev_index_return_5y_bp' => '',

			// "What happened next" is the outcome, and the outcome is a claim.
			// The lesson field is empty for a different reason: the lesson for
			// this pattern is already written in Reveal_Lessons and quoted in
			// the brief, and an editor pastes it once they have read enough of
			// the filing to know the pattern held.
			'hti_rev_context_en'         => '',
			'hti_rev_context_pt'         => '',
			'hti_rev_lesson_en'          => '',
			'hti_rev_lesson_pt'          => '',

			// Deliberately empty. See the file docblock: this is the audit
			// trail, and a plausible pre-filled URL would be a forged one.
			'hti_rev_source_url'         => '',
			'hti_rev_source_label'       => '',
			'hti_rev_source_accessed'    => '',
			'hti_rev_verified'           => '0',
			'hti_rev_verified_by'        => '',
			'hti_rev_verified_at'        => '',
			'hti_rev_slot'               => 0,
		);
	}

	/**
	 * Six fundamentals rows: labelled, untinted in effect, and unanswered.
	 *
	 * The tint is 'warn' on every row, which is CPT::san_fundamentals's own
	 * default. A tint is a judgement about a value — green means this number
	 * is good — and there is no value here to judge, so seeding 'good' or
	 * 'bad' would be an unsourced verdict rendered in colour.
	 *
	 * @param array<int,string> $keys Metric keys for this case, in dossier order.
	 * @return array<int,array<string,string>>
	 */
	public static function fundamentals( array $keys ): array {
		$metrics = self::metrics();
		$out     = array();

		foreach ( array_slice( $keys, 0, self::FUNDAMENTALS ) as $key ) {
			$key    = (string) $key;
			$metric = $metrics[ $key ] ?? null;
			if ( null === $metric ) {
				continue;
			}

			$out[] = array(
				'key'           => $key,
				'label_en'      => $metric['en'],
				'label_pt'      => $metric['pt'],
				'value_en'      => '',
				'value_pt'      => '',
				'sector_avg_en' => '',
				'sector_avg_pt' => '',
				'tint'          => 'warn',
			);
		}

		return $out;
	}

	/**
	 * Three empty headline slots.
	 *
	 * Empty, not written. A period headline is a quotation from a real
	 * publication on a real date; inventing three plausible ones would be
	 * fabricating the most quotable part of the dossier, which is also the
	 * part a player is most likely to repeat afterwards.
	 *
	 * @return array<int,array{en:string,pt:string}>
	 */
	public static function headlines(): array {
		$out = array();

		for ( $i = 0; $i < self::HEADLINES; $i++ ) {
			$out[] = array(
				'en' => '',
				'pt' => '',
			);
		}

		return $out;
	}

	/* =====================================================================
	 * The editorial material. None of it is a claim about anybody: a label is
	 * a question, a rubric is a convention, and a brief is an instruction.
	 * ================================================================== */

	/**
	 * Every fundamental a dossier can ask about: the bilingual LABEL, and the
	 * line item it is asking for.
	 *
	 * The label is the question the dossier puts to the player and carries no
	 * answer. `from_en`/`from_pt` are the other half of the editor's job made
	 * explicit — which statement, which note, which subtraction — so that
	 * filling a case is reading a document rather than deciding what to read.
	 *
	 * Where a definition is genuinely contested (return on capital, same-outlet
	 * sales, active users) the entry says so and asks the editor to record the
	 * definition they used. Two cases filled to two different definitions of
	 * the same word is the failure mode a sector comparison dies of.
	 *
	 * NO ENTRY CARRIES A VALUE, A RANGE OR A TYPICAL FIGURE. Not even a
	 * plausible one, and not in a comment.
	 *
	 * @return array<string,array{en:string,pt:string,from_en:string,from_pt:string}>
	 */
	public static function metrics(): array {
		return array(
			'revenue_growth'          => array(
				'en'      => 'Revenue growth',
				'pt'      => 'Crescimento das receitas',
				'from_en' => 'the income statement: revenue for the year against the year before, both as printed in this report.',
				'from_pt' => 'a demonstração de resultados: as receitas do ano face ao ano anterior, ambas tal como impressas neste relatório.',
			),
			'gross_margin'            => array(
				'en'      => 'Gross margin',
				'pt'      => 'Margem bruta',
				'from_en' => 'revenue less the cost of sales, divided by revenue, from the income statement.',
				'from_pt' => 'as receitas menos o custo das vendas, a dividir pelas receitas, na demonstração de resultados.',
			),
			'operating_margin'        => array(
				'en'      => 'Operating margin',
				'pt'      => 'Margem operacional',
				'from_en' => 'operating profit divided by revenue, from the income statement, ahead of any adjusted measure the company prefers.',
				'from_pt' => 'o resultado operacional a dividir pelas receitas, na demonstração de resultados, antes de qualquer medida ajustada que a empresa prefira.',
			),
			'free_cash_flow'          => array(
				'en'      => 'Free cash flow',
				'pt'      => 'Fluxo de caixa livre',
				'from_en' => 'cash generated by operations less capital spending, both lines from the cash flow statement.',
				'from_pt' => 'a caixa gerada pela atividade operacional menos o investimento em ativos fixos, ambas as linhas na demonstração de fluxos de caixa.',
			),
			'net_debt'                => array(
				'en'      => 'Net debt',
				'pt'      => 'Dívida líquida',
				'from_en' => 'borrowings, short and long term, less cash and equivalents, from the balance sheet and the debt note.',
				'from_pt' => 'os financiamentos, de curto e de longo prazo, menos a caixa e equivalentes, no balanço e na nota da dívida.',
			),
			'net_cash'                => array(
				'en'      => 'Net cash',
				'pt'      => 'Caixa líquida',
				'from_en' => 'the same subtraction as net debt, for a company where it comes out the other way round.',
				'from_pt' => 'a mesma subtração da dívida líquida, numa empresa em que dá ao contrário.',
			),
			'cash'                    => array(
				'en'      => 'Cash and equivalents',
				'pt'      => 'Caixa e equivalentes',
				'from_en' => 'the balance sheet line at the year end, plus whatever the note counts as an equivalent.',
				'from_pt' => 'a linha do balanço no fim do ano, mais aquilo que a nota conta como equivalente.',
			),
			'return_on_equity'        => array(
				'en'      => 'Return on equity',
				'pt'      => 'Rendibilidade dos capitais próprios',
				'from_en' => 'profit for the year over average shareholders equity, both from the primary statements.',
				'from_pt' => 'o resultado do ano sobre os capitais próprios médios, ambos nas demonstrações principais.',
			),
			'roic'                    => array(
				'en'      => 'Return on capital employed',
				'pt'      => 'Rendibilidade do capital investido',
				'from_en' => 'operating profit after tax over the capital the business actually uses; record which definition you took, because they differ.',
				'from_pt' => 'o resultado operacional após imposto sobre o capital que o negócio usa de facto; regista que definição usaste, porque variam.',
			),
			'debt_to_equity'          => array(
				'en'      => 'Debt to equity',
				'pt'      => 'Dívida sobre capitais próprios',
				'from_en' => 'total borrowings over shareholders equity, from the balance sheet.',
				'from_pt' => 'o total dos financiamentos sobre os capitais próprios, no balanço.',
			),
			'cash_conversion'         => array(
				'en'      => 'Cash flow against reported profit',
				'pt'      => 'Fluxo de caixa face ao lucro declarado',
				'from_en' => 'cash from operations set against profit for the same year; the cash flow statement reconciles the two at the top.',
				'from_pt' => 'a caixa gerada pela operação face ao lucro do mesmo ano; a demonstração de fluxos de caixa reconcilia os dois logo no topo.',
			),
			'off_balance_sheet'       => array(
				'en'      => 'Obligations held off the balance sheet',
				'pt'      => 'Compromissos fora do balanço',
				'from_en' => 'the commitments and contingencies note, plus any note on unconsolidated entities and on guarantees given.',
				'from_pt' => 'a nota de compromissos e contingências, mais qualquer nota sobre entidades não consolidadas e garantias prestadas.',
			),
			'market_share'            => array(
				'en'      => 'Share of its market',
				'pt'      => 'Quota do seu mercado',
				'from_en' => 'a published industry estimate, cited by name; the company\'s own claim about itself is a starting point and not a source.',
				'from_pt' => 'uma estimativa setorial publicada e citada pelo nome; a afirmação da própria empresa sobre si é um ponto de partida e não uma fonte.',
			),
			'rnd_intensity'           => array(
				'en'      => 'Research and development as a share of revenue',
				'pt'      => 'Investigação e desenvolvimento em percentagem das receitas',
				'from_en' => 'the research and development expense line over revenue, from the income statement.',
				'from_pt' => 'a linha de gastos com investigação e desenvolvimento sobre as receitas, na demonstração de resultados.',
			),
			'pe_ratio'                => array(
				'en'      => 'Price against earnings',
				'pt'      => 'Preço face aos lucros',
				'from_en' => 'the share price at the year end over earnings per share as reported; note which earnings figure you used.',
				'from_pt' => 'a cotação no fim do ano sobre o resultado por ação declarado; regista que resultado por ação usaste.',
			),
			'dividend_payout'         => array(
				'en'      => 'Share of profit paid out as dividends',
				'pt'      => 'Percentagem do lucro distribuída em dividendos',
				'from_en' => 'dividends declared for the year over profit for the year, from the statement of changes in equity and the income statement.',
				'from_pt' => 'os dividendos declarados no ano sobre o resultado do ano, na demonstração de alterações no capital próprio e na de resultados.',
			),
			'net_debt_ebitda'         => array(
				'en'      => 'Net debt against operating earnings',
				'pt'      => 'Dívida líquida face aos resultados operacionais',
				'from_en' => 'net debt over operating profit before depreciation and amortisation; the depreciation line is in the cash flow statement.',
				'from_pt' => 'a dívida líquida sobre o resultado operacional antes de amortizações e depreciações; a linha das amortizações está na demonstração de fluxos de caixa.',
			),
			'marketing_intensity'     => array(
				'en'      => 'Marketing spending as a share of revenue',
				'pt'      => 'Despesa de marketing em percentagem das receitas',
				'from_en' => 'the marketing or selling expense line over revenue; if it sits inside a larger line, say so in the source label.',
				'from_pt' => 'a linha de gastos de marketing ou comerciais sobre as receitas; se estiver dentro de uma linha maior, diz isso no rótulo da fonte.',
			),
			'cash_burn'               => array(
				'en'      => 'Cash consumed over the year',
				'pt'      => 'Caixa consumida no ano',
				'from_en' => 'the movement in cash across the year, from the foot of the cash flow statement, before money raised.',
				'from_pt' => 'a variação de caixa ao longo do ano, no fim da demonstração de fluxos de caixa, antes do dinheiro angariado.',
			),
			'cash_runway'             => array(
				'en'      => 'Months of cash remaining',
				'pt'      => 'Meses de caixa restantes',
				'from_en' => 'cash at the year end over the monthly rate at which it was being consumed; show the arithmetic in the source label.',
				'from_pt' => 'a caixa no fim do ano sobre o ritmo mensal a que estava a ser consumida; mostra a conta no rótulo da fonte.',
			),
			'deferred_revenue'        => array(
				'en'      => 'Money collected and not yet earned',
				'pt'      => 'Dinheiro cobrado e ainda não reconhecido',
				'from_en' => 'the deferred revenue line on the balance sheet, and its movement in the revenue note.',
				'from_pt' => 'a linha de rendimentos diferidos no balanço, e o seu movimento na nota de rédito.',
			),
			'stock_comp'              => array(
				'en'      => 'Share-based pay as a share of revenue',
				'pt'      => 'Pagamentos em ações em percentagem das receitas',
				'from_en' => 'the share-based payment charge, from the cash flow statement or its own note, over revenue.',
				'from_pt' => 'o gasto com pagamentos em ações, na demonstração de fluxos de caixa ou na nota própria, sobre as receitas.',
			),
			'cac_payback'             => array(
				'en'      => 'Months of gross profit needed to repay the cost of winning a customer',
				'pt'      => 'Meses de margem bruta para recuperar o custo de ganhar um cliente',
				'from_en' => 'marketing spending over customers added, set against the gross profit one customer brings in a month.',
				'from_pt' => 'a despesa de marketing sobre os clientes ganhos, face à margem bruta que um cliente traz num mês.',
			),
			'repeat_rate'             => array(
				'en'      => 'Share of revenue from returning customers',
				'pt'      => 'Percentagem das receitas de clientes que voltam',
				'from_en' => 'the operating review or the risk section, where a company that has this number tends to disclose it.',
				'from_pt' => 'a análise operacional ou a secção de riscos, onde uma empresa que tem este número costuma divulgá-lo.',
			),
			'store_count'             => array(
				'en'      => 'Outlets at the end of the year',
				'pt'      => 'Lojas no fim do ano',
				'from_en' => 'the operating review, counted at the year end and split between owned and franchised if the report splits them.',
				'from_pt' => 'a análise operacional, contadas no fim do ano e separadas entre próprias e franquiadas se o relatório as separar.',
			),
			'same_store_sales'        => array(
				'en'      => 'Sales from outlets open more than a year',
				'pt'      => 'Vendas de lojas abertas há mais de um ano',
				'from_en' => 'the operating review; record the exact definition the company uses, because it moves between companies.',
				'from_pt' => 'a análise operacional; regista a definição exata que a empresa usa, porque varia de empresa para empresa.',
			),
			'franchise_share'         => array(
				'en'      => 'Share of outlets run by franchisees',
				'pt'      => 'Percentagem de lojas exploradas por franquiados',
				'from_en' => 'the outlet count in the operating review, split between company-run and franchised.',
				'from_pt' => 'a contagem de lojas na análise operacional, dividida entre exploração própria e franquia.',
			),
			'legacy_revenue'          => array(
				'en'      => 'Share of revenue from the older product line',
				'pt'      => 'Percentagem das receitas da linha de produto mais antiga',
				'from_en' => 'the segment note, which is where a report has to say how much came from where.',
				'from_pt' => 'a nota de segmentos, que é onde o relatório tem de dizer quanto veio de onde.',
			),
			'platform_fragmentation'  => array(
				'en'      => 'Software platforms the company supported',
				'pt'      => 'Plataformas de software que a empresa suportava',
				'from_en' => 'the business description and the research and development section; count them, and note which were being wound down.',
				'from_pt' => 'a descrição do negócio e a secção de investigação e desenvolvimento; conta-as, e regista quais estavam a ser descontinuadas.',
			),
			'related_party'           => array(
				'en'      => 'Transactions with related parties',
				'pt'      => 'Transações com partes relacionadas',
				'from_en' => 'the related party note, which every set of audited accounts has to carry.',
				'from_pt' => 'a nota de partes relacionadas, que todas as contas auditadas têm de incluir.',
			),
			'cash_confirmation'       => array(
				'en'      => 'Cash on the balance sheet, and who confirmed it',
				'pt'      => 'Caixa no balanço, e quem a confirmou',
				'from_en' => 'the balance sheet for the amount, and the audit report for whether it was independently confirmed.',
				'from_pt' => 'o balanço para o montante, e o relatório de auditoria para saber se foi confirmada de forma independente.',
			),
			'receivables_days'        => array(
				'en'      => 'Days a sale waits to be collected',
				'pt'      => 'Dias que uma venda espera para ser cobrada',
				'from_en' => 'trade receivables over revenue, turned into days; the receivables note carries the ageing.',
				'from_pt' => 'as contas a receber de clientes sobre as receitas, convertidas em dias; a nota de contas a receber traz a antiguidade.',
			),
			'inventory_days'          => array(
				'en'      => 'Days of stock held',
				'pt'      => 'Dias de existências em armazém',
				'from_en' => 'inventories over the cost of sales, turned into days, from the balance sheet and the income statement.',
				'from_pt' => 'as existências sobre o custo das vendas, convertidas em dias, no balanço e na demonstração de resultados.',
			),
			'audit_opinion'           => array(
				'en'      => 'The auditor, and the opinion given for the year',
				'pt'      => 'O auditor, e a opinião dada no ano',
				'from_en' => 'the audit report at the front of the accounts: who signed it, what opinion, and any matter they emphasised.',
				'from_pt' => 'o relatório de auditoria no início das contas: quem assinou, que opinião, e qualquer matéria que tenha realçado.',
			),
			'capex_intensity'         => array(
				'en'      => 'Capital spending as a share of revenue',
				'pt'      => 'Investimento em ativos fixos em percentagem das receitas',
				'from_en' => 'purchases of property, plant and equipment from the cash flow statement, over revenue.',
				'from_pt' => 'as aquisições de ativos fixos tangíveis na demonstração de fluxos de caixa, sobre as receitas.',
			),
			'accounting_policy'       => array(
				'en'      => 'Changes of accounting policy or estimate in the year',
				'pt'      => 'Alterações de política ou estimativa contabilística no ano',
				'from_en' => 'the accounting policies note and the note after it, where a change and its effect have to be described.',
				'from_pt' => 'a nota de políticas contabilísticas e a nota seguinte, onde uma alteração e o seu efeito têm de ser descritos.',
			),
			'working_capital'         => array(
				'en'      => 'Movement in working capital over the year',
				'pt'      => 'Variação do fundo de maneio no ano',
				'from_en' => 'the working capital lines in the cash flow statement, between profit and cash from operations.',
				'from_pt' => 'as linhas de fundo de maneio na demonstração de fluxos de caixa, entre o lucro e a caixa da operação.',
			),
			'commodity_exposure'      => array(
				'en'      => 'Share of revenue from a single commodity',
				'pt'      => 'Percentagem das receitas de uma única matéria-prima',
				'from_en' => 'the segment note and the operating review, which set out volumes and prices by product.',
				'from_pt' => 'a nota de segmentos e a análise operacional, que apresentam volumes e preços por produto.',
			),
			'mid_cycle_earnings'      => array(
				'en'      => 'Earnings for the year against the average of a full cycle',
				'pt'      => 'Lucros do ano face à média de um ciclo completo',
				'from_en' => 'the year from the income statement, the average from the long-run summary a report of this kind usually carries at the back.',
				'from_pt' => 'o ano na demonstração de resultados, a média no resumo de longo prazo que um relatório destes costuma trazer no fim.',
			),
			'price_realisation'       => array(
				'en'      => 'Average price achieved against the year before',
				'pt'      => 'Preço médio conseguido face ao ano anterior',
				'from_en' => 'the operating review, where a producer states the volumes sold and the price it got for them.',
				'from_pt' => 'a análise operacional, onde um produtor indica os volumes vendidos e o preço que conseguiu por eles.',
			),
			'capacity_utilisation'    => array(
				'en'      => 'Share of capacity in use',
				'pt'      => 'Percentagem da capacidade em utilização',
				'from_en' => 'the operating review: output over the capacity stated in the same section.',
				'from_pt' => 'a análise operacional: a produção sobre a capacidade indicada na mesma secção.',
			),
			'interest_cover'          => array(
				'en'      => 'Operating profit against the interest bill',
				'pt'      => 'Resultado operacional face aos encargos com juros',
				'from_en' => 'operating profit over net finance costs, from the income statement and the finance cost note.',
				'from_pt' => 'o resultado operacional sobre os custos financeiros líquidos, na demonstração de resultados e na nota de custos financeiros.',
			),
			'organic_growth'          => array(
				'en'      => 'Revenue growth excluding acquisitions',
				'pt'      => 'Crescimento das receitas excluindo aquisições',
				'from_en' => 'the segment note and the business combinations note, which state what the acquired businesses contributed.',
				'from_pt' => 'a nota de segmentos e a nota de concentrações de atividades, que indicam o que os negócios adquiridos contribuíram.',
			),
			'acquisition_spend'       => array(
				'en'      => 'Cash spent on acquisitions in the year',
				'pt'      => 'Caixa gasta em aquisições no ano',
				'from_en' => 'the investing section of the cash flow statement, net of the cash acquired with the businesses.',
				'from_pt' => 'a secção de investimento na demonstração de fluxos de caixa, líquida da caixa adquirida com os negócios.',
			),
			'goodwill_share'          => array(
				'en'      => 'Goodwill as a share of total assets',
				'pt'      => 'Goodwill em percentagem do ativo total',
				'from_en' => 'the goodwill line and the total assets line on the balance sheet, plus the impairment note.',
				'from_pt' => 'a linha de goodwill e a linha de ativo total no balanço, mais a nota de imparidade.',
			),
			'provisions'              => array(
				'en'      => 'Provisions taken against contracts',
				'pt'      => 'Provisões constituídas sobre contratos',
				'from_en' => 'the provisions note: what was set aside during the year, and what was released.',
				'from_pt' => 'a nota de provisões: o que foi constituído durante o ano, e o que foi revertido.',
			),
			'loan_growth'             => array(
				'en'      => 'Growth in lending over the year',
				'pt'      => 'Crescimento do crédito concedido no ano',
				'from_en' => 'the balance sheet: loans and advances at the year end against the year before.',
				'from_pt' => 'o balanço: o crédito concedido no fim do ano face ao ano anterior.',
			),
			'funding_mix'             => array(
				'en'      => 'Share of funding raised in wholesale markets',
				'pt'      => 'Percentagem do financiamento obtido nos mercados grossistas',
				'from_en' => 'the funding or liquidity note, which separates customer balances from money borrowed in the markets.',
				'from_pt' => 'a nota de financiamento ou de liquidez, que separa os saldos de clientes do dinheiro tomado nos mercados.',
			),
			'debt_maturity'           => array(
				'en'      => 'Share of borrowings falling due within the year',
				'pt'      => 'Percentagem do financiamento a vencer no prazo de um ano',
				'from_en' => 'the maturity table in the financial instruments note.',
				'from_pt' => 'o quadro de maturidades na nota de instrumentos financeiros.',
			),
			'equity_to_assets'        => array(
				'en'      => 'Equity as a share of total assets',
				'pt'      => 'Capitais próprios em percentagem do ativo total',
				'from_en' => 'the two balance sheet totals; for a regulated lender, record the regulatory capital ratio beside it.',
				'from_pt' => 'os dois totais do balanço; num credor regulado, regista ao lado o rácio de capital regulamentar.',
			),
			'fixed_rate_assets'       => array(
				'en'      => 'Share of assets in long-dated fixed-rate securities',
				'pt'      => 'Percentagem do ativo em títulos de taxa fixa de longo prazo',
				'from_en' => 'the securities note, which sets out what is held, at what maturity and at what rate.',
				'from_pt' => 'a nota de títulos, que apresenta o que é detido, com que maturidade e a que taxa.',
			),
			'unrealised_marks'        => array(
				'en'      => 'Unrealised gains and losses on the securities held',
				'pt'      => 'Ganhos e perdas não realizados nos títulos detidos',
				'from_en' => 'the securities note, comparing amortised cost with fair value at the year end.',
				'from_pt' => 'a nota de títulos, comparando o custo amortizado com o justo valor no fim do ano.',
			),
			'depositor_concentration' => array(
				'en'      => 'Share of funding from one industry or a few large holders',
				'pt'      => 'Percentagem do financiamento vinda de um setor ou de poucos grandes detentores',
				'from_en' => 'the funding note and the risk section, which describe whom the money belongs to.',
				'from_pt' => 'a nota de financiamento e a secção de riscos, que descrevem a quem pertence o dinheiro.',
			),
			'regulated_revenue'       => array(
				'en'      => 'Share of revenue dependent on one public programme or licence',
				'pt'      => 'Percentagem das receitas dependente de um único programa ou licença pública',
				'from_en' => 'the business description and the risk section; the programme or the licence is usually named there.',
				'from_pt' => 'a descrição do negócio e a secção de riscos; o programa ou a licença costuma estar lá nomeado.',
			),
			'customer_concentration'  => array(
				'en'      => 'Share of revenue from the largest customer',
				'pt'      => 'Percentagem das receitas do maior cliente',
				'from_en' => 'the segment or concentration note, where a customer above a disclosure threshold has to be named or counted.',
				'from_pt' => 'a nota de segmentos ou de concentração, onde um cliente acima do limiar de divulgação tem de ser identificado ou contado.',
			),
			'product_concentration'   => array(
				'en'      => 'Share of revenue from the largest product line',
				'pt'      => 'Percentagem das receitas da maior linha de produto',
				'from_en' => 'the segment note, or the disaggregation of revenue the report gives.',
				'from_pt' => 'a nota de segmentos, ou a desagregação das receitas que o relatório apresenta.',
			),
			'share_count'             => array(
				'en'      => 'Shares in issue against the year before',
				'pt'      => 'Ações em circulação face ao ano anterior',
				'from_en' => 'the share capital note, on a diluted basis, at both year ends.',
				'from_pt' => 'a nota de capital social, em base diluída, nos dois fins de ano.',
			),
			'equity_raised'           => array(
				'en'      => 'Cash raised by issuing shares in the year',
				'pt'      => 'Caixa obtida com a emissão de ações no ano',
				'from_en' => 'the financing section of the cash flow statement.',
				'from_pt' => 'a secção de financiamento na demonstração de fluxos de caixa.',
			),
			'buyback'                 => array(
				'en'      => 'Cash spent buying back shares',
				'pt'      => 'Caixa gasta na recompra de ações',
				'from_en' => 'the financing section of the cash flow statement, and the share capital note.',
				'from_pt' => 'a secção de financiamento na demonstração de fluxos de caixa, e a nota de capital social.',
			),
			'vendor_financing'        => array(
				'en'      => 'Sales financed by credit the company gave its own customers',
				'pt'      => 'Vendas financiadas por crédito concedido pela própria empresa aos clientes',
				'from_en' => 'the receivables and commitments notes, plus any customer finance arrangement described in the risk section.',
				'from_pt' => 'as notas de contas a receber e de compromissos, mais qualquer acordo de financiamento a clientes descrito na secção de riscos.',
			),
			'unbilled_work'           => array(
				'en'      => 'Work done and not yet billed',
				'pt'      => 'Trabalho executado e ainda não faturado',
				'from_en' => 'the contract assets line, or amounts recoverable on contracts, and its note.',
				'from_pt' => 'a linha de ativos contratuais, ou de valores a recuperar de contratos, e a respetiva nota.',
			),
			'pension_deficit'         => array(
				'en'      => 'Pension deficit against the market value of the company',
				'pt'      => 'Défice do fundo de pensões face ao valor de mercado da empresa',
				'from_en' => 'the retirement benefits note for the deficit, and the share price times the share count for the comparison.',
				'from_pt' => 'a nota de benefícios de reforma para o défice, e a cotação vezes o número de ações para a comparação.',
			),
			'dividend_cover'          => array(
				'en'      => 'Dividends against free cash flow',
				'pt'      => 'Dividendos face ao fluxo de caixa livre',
				'from_en' => 'dividends paid in the financing section, set against cash from operations less capital spending.',
				'from_pt' => 'os dividendos pagos na secção de financiamento, face à caixa da operação menos o investimento em ativos fixos.',
			),
			'active_users'            => array(
				'en'      => 'People using the product each day at the year end',
				'pt'      => 'Pessoas a usar o produto por dia no fim do ano',
				'from_en' => 'the operating metrics the report defines; record the definition, because it is the company\'s own.',
				'from_pt' => 'as métricas operacionais que o relatório define; regista a definição, porque é da própria empresa.',
			),
		);
	}

	/**
	 * The sector taxonomy, key => bilingual name.
	 *
	 * A table rather than a string typed onto each case, so that two dossiers
	 * in the same sector are comparable — and so that the Portuguese of a
	 * sector is written once instead of drifting a word at a time across a
	 * library. The sector is also the only part of the dossier a player sees
	 * before deciding, so it has to read the same way every time.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	public static function sectors(): array {
		return array(
			'online_retail'          => array(
				'en' => 'Online retail',
				'pt' => 'Retalho online',
			),
			'energy_trading'         => array(
				'en' => 'Energy and energy trading',
				'pt' => 'Energia e negociação de energia',
			),
			'consumer_electronics'   => array(
				'en' => 'Consumer electronics and mobile handsets',
				'pt' => 'Eletrónica de consumo e telemóveis',
			),
			'beverages'              => array(
				'en' => 'Beverages',
				'pt' => 'Bebidas',
			),
			'networking'             => array(
				'en' => 'Networking equipment',
				'pt' => 'Equipamento de redes',
			),
			'enterprise_software'    => array(
				'en' => 'Enterprise software',
				'pt' => 'Software empresarial',
			),
			'meal_kits'              => array(
				'en' => 'Meal-kit delivery',
				'pt' => 'Entrega de kits de refeição',
			),
			'video_rental'           => array(
				'en' => 'Video rental',
				'pt' => 'Aluguer de vídeo',
			),
			'dairy_food'             => array(
				'en' => 'Dairy and food processing',
				'pt' => 'Lacticínios e transformação alimentar',
			),
			'it_services'            => array(
				'en' => 'Information technology services',
				'pt' => 'Serviços de tecnologias de informação',
			),
			'telecoms'               => array(
				'en' => 'Long-distance telecommunications',
				'pt' => 'Telecomunicações de longa distância',
			),
			'grocery'                => array(
				'en' => 'Grocery retail',
				'pt' => 'Retalho alimentar',
			),
			'copper_gold_mining'     => array(
				'en' => 'Copper and gold mining',
				'pt' => 'Mineração de cobre e ouro',
			),
			'coal_mining'            => array(
				'en' => 'Coal mining',
				'pt' => 'Mineração de carvão',
			),
			'specialty_pharma'       => array(
				'en' => 'Specialty pharmaceuticals',
				'pt' => 'Farmacêutica de especialidade',
			),
			'diversified_industrial' => array(
				'en' => 'Diversified industrial',
				'pt' => 'Industrial diversificado',
			),
			'mortgage_lending'       => array(
				'en' => 'Mortgage lending',
				'pt' => 'Crédito hipotecário',
			),
			'regional_banking'       => array(
				'en' => 'Regional banking',
				'pt' => 'Banca regional',
			),
			'student_lending'        => array(
				'en' => 'Student lending',
				'pt' => 'Crédito ao estudante',
			),
			'credit_ratings'         => array(
				'en' => 'Credit ratings',
				'pt' => 'Notação de risco de crédito',
			),
			'telecom_equipment'      => array(
				'en' => 'Telecommunications equipment',
				'pt' => 'Equipamento de telecomunicações',
			),
			'construction_services'  => array(
				'en' => 'Construction and facilities management',
				'pt' => 'Construção e gestão de instalações',
			),
			'salvage_auctions'       => array(
				'en' => 'Vehicle salvage auctions',
				'pt' => 'Leilões de veículos sinistrados',
			),
			'uniform_services'       => array(
				'en' => 'Uniform rental and workplace supplies',
				'pt' => 'Aluguer de fardamento e consumíveis de trabalho',
			),
			'personal_computers'     => array(
				'en' => 'Personal computers',
				'pt' => 'Computadores pessoais',
			),
			'restaurant_franchising' => array(
				'en' => 'Restaurant franchising',
				'pt' => 'Franquia de restauração',
			),
			'imaging'                => array(
				'en' => 'Photographic products and imaging',
				'pt' => 'Produtos fotográficos e imagem',
			),
			'department_stores'      => array(
				'en' => 'Department stores',
				'pt' => 'Grandes armazéns',
			),
			'action_cameras'         => array(
				'en' => 'Consumer cameras and accessories',
				'pt' => 'Câmaras de consumo e acessórios',
			),
			'semiconductors'         => array(
				'en' => 'Semiconductors',
				'pt' => 'Semicondutores',
			),
			'fuel_cells'             => array(
				'en' => 'Fuel-cell power systems',
				'pt' => 'Sistemas de energia por células de combustível',
			),
			'social_apps'            => array(
				'en' => 'Social media and camera applications',
				'pt' => 'Redes sociais e aplicações de câmara',
			),
			'mobile_devices'         => array(
				'en' => 'Mobile devices and enterprise messaging',
				'pt' => 'Dispositivos móveis e mensagens empresariais',
			),
		);
	}

	/**
	 * One sector, bilingual, falling back to an empty pair.
	 *
	 * @param string $key Sector key.
	 * @return array{en:string,pt:string}
	 */
	public static function sector( string $key ): array {
		return self::sectors()[ $key ] ?? array(
			'en' => '',
			'pt' => '',
		);
	}

	/**
	 * When a figure is green, amber or red — the one rubric, written down.
	 *
	 * A tint is the editor's judgement about a NUMBER against its sector
	 * average, and never a view on the company. Left to taste it drifts: the
	 * same margin ends up green on Monday and amber on Thursday, and the
	 * dossier stops meaning anything consistent across a library.
	 *
	 * Two axes, and both are needed. Where the figure stands against the
	 * sector, and which way it has been moving. A figure ahead of its sector
	 * and falling is not the same dossier row as one behind and rising, and a
	 * rubric with only the first axis cannot tell them apart.
	 *
	 * Amber is the default and the honest answer whenever the two axes
	 * disagree or the sector average is soft. CPT::san_fundamentals falls back
	 * to it for the same reason.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	public static function tint_rubric(): array {
		return array(
			'good' => array(
				'en' => 'Green: clearly ahead of the sector average, and moving the favourable way over the years the report shows. Both halves, or it is not green.',
				'pt' => 'Verde: claramente acima da média do setor, e a mover-se no sentido favorável ao longo dos anos que o relatório mostra. As duas metades, ou não é verde.',
			),
			'warn' => array(
				'en' => 'Amber: close to the sector average, or ahead on one axis and behind on the other, or drawn from a sector average you would not want to defend. Amber is the default and there is no shame in it.',
				'pt' => 'Âmbar: perto da média do setor, ou à frente num eixo e atrás no outro, ou tirado de uma média setorial que não te apetecia defender. Âmbar é o valor por omissão e não há vergonha nisso.',
			),
			'bad'  => array(
				'en' => 'Red: clearly behind the sector average, and moving the wrong way. A single bad year inside a steady record is amber, not red.',
				'pt' => 'Vermelho: claramente abaixo da média do setor, e a mover-se no sentido errado. Um único ano mau dentro de um percurso estável é âmbar, não vermelho.',
			),
		);
	}

	/**
	 * The sourcing rule attached to a pattern.
	 *
	 * Most patterns are read out of the company's own accounts. Two are not:
	 * a pattern that amounts to an allegation cannot be confirmed by the
	 * accounts of the party it is about, and a case filed under one is not
	 * finished until a court or a regulator has published something that says
	 * so. That is not caution for its own sake — it is the difference between
	 * a dossier and a defamation.
	 *
	 * @param string $pattern Pattern id.
	 * @return array{en:string,pt:string}
	 */
	public static function pattern_note( string $pattern ): array {
		$adjudicated = array( 'fraud', 'accounting_change' );

		if ( in_array( $pattern, $adjudicated, true ) ) {
			return array(
				'en' => 'This pattern is an allegation, so the company\'s own report cannot confirm it. It stands only on a court judgment or a published decision of the market regulator, cited as the source alongside the accounts. If you cannot find one, change the pattern — do not soften the wording.',
				'pt' => 'Este padrão é uma acusação, por isso o relatório da própria empresa não o pode confirmar. Só se sustenta com uma decisão judicial ou uma decisão publicada do regulador de mercado, citada como fonte ao lado das contas. Se não encontrares nenhuma, muda o padrão — não suavizes a redação.',
			);
		}

		return array(
			'en' => 'The pattern is a hypothesis about the shape of the filing, never a verdict on the company. Read the document first; if it does not have this shape, change the pattern rather than the reading.',
			'pt' => 'O padrão é uma hipótese sobre a forma do relatório, nunca um veredicto sobre a empresa. Lê primeiro o documento; se ele não tiver esta forma, muda o padrão e não a leitura.',
		);
	}

	/**
	 * The bilingual research brief for one case.
	 *
	 * Both languages in one field, because the admin panel renders one field
	 * and a Portuguese editor should not have to read the English half to find
	 * out which note to open.
	 *
	 * Everything in it is either an instruction, a label, or the year of the
	 * dossier. It names document TYPES — an annual report for a financial
	 * year, an audit report, a maturity table, a regulator's published
	 * decision — and never a URL, a filing reference or a date, because those
	 * cannot be checked from here and a guessed one would forge exactly the
	 * audit trail the empty source URL is protecting.
	 *
	 * @param array<string,mixed> $def One row of definitions().
	 */
	public static function brief( array $def ): string {
		return self::brief_lang( $def, 'en' ) . "\n\n" . self::brief_lang( $def, 'pt' );
	}

	/**
	 * One language of the research brief.
	 *
	 * @param array<string,mixed> $def  One row of definitions().
	 * @param string              $lang 'en' or 'pt'.
	 */
	private static function brief_lang( array $def, string $lang ): string {
		$lang    = 'pt' === $lang ? 'pt' : 'en';
		$pattern = (string) $def['pattern'];
		$year    = (int) $def['year'];
		$company = (string) $def['company'];
		$taxon   = Reveal_Lessons::pattern( $pattern );
		$lesson  = Reveal_Lessons::for_pattern( $pattern, 0 );
		$note    = self::pattern_note( $pattern );
		$rubric  = self::tint_rubric();
		$metrics = self::metrics();

		$rows = array();
		foreach ( array_slice( (array) $def['fundamentals'], 0, self::FUNDAMENTALS ) as $key ) {
			$metric = $metrics[ (string) $key ] ?? null;
			if ( null === $metric ) {
				continue;
			}
			$rows[] = '  - ' . $metric[ $lang ] . ' — ' . $metric[ 'from_' . $lang ];
		}

		if ( 'pt' === $lang ) {
			$lines = array(
				'GUIÃO DE PESQUISA (PT) — nada aqui é um número; é o que abrir e o que ler lá dentro.',
				'',
				'PADRÃO A TESTAR: ' . $taxon['pt'] . '.',
				'O que o dossiê pergunta: ' . $taxon['asks_pt'],
				$note['pt'],
				'',
				'ABRIR: o relatório e contas de ' . $company . ' referente ao exercício de ' . $year . ', tal como publicado pela empresa e depositado junto do regulador de mercado da sua bolsa principal, incluindo as contas auditadas e as notas. Tira todos os números desse documento. Se algum não estiver lá, encontra o documento que o tem e cita também esse.',
				'',
				'AS SEIS LINHAS — o item que cada rótulo está a pedir:',
			);
			$lines = array_merge( $lines, $rows );
			$lines = array_merge(
				$lines,
				array(
					'',
					'MÉDIA DO SETOR: tira-a de um agregado publicado a que possas ligar — uma ficha setorial de um fornecedor de índices ou de um regulador, as estatísticas anuais de uma associação do setor, ou a mediana do grupo de comparáveis que a própria empresa nomeia neste mesmo relatório. Escreve no rótulo da fonte qual deles usaste. Uma média de memória não é uma média.',
					'',
					'OS DOIS RETORNOS: o retorno total das ações nos cinco exercícios seguintes a ' . $year . ', e a mesma janela para o índice alargado com que o ecrã da revelação compara, com dividendos reinvestidos nos dois. Ambos em pontos base, com sinal. Tira-os de um histórico de cotações ou de uma ficha de índice a que possas ligar, e usa exatamente as mesmas datas de início e de fim nos dois.',
					'',
					'MANCHETES: três, citadas de publicações datadas dentro de ' . $year . ', cada uma com a sua referência. Uma manchete escrita por ti não é uma manchete.',
					'',
					'TONS: ' . $rubric['good']['pt'] . ' ' . $rubric['warn']['pt'] . ' ' . $rubric['bad']['pt'],
					'',
					'LIÇÃO — já escrita para este padrão, e segura para colar tal como está:',
					'  ' . $lesson['pt'],
					'',
					'NO FIM: cola o endereço do documento que abriste mesmo, dá-lhe um rótulo, data o dia em que o leste, e só então marca verificado. A marca é uma afirmação sobre o ano e sobre os dois retornos; alterar qualquer um deles apaga-a.',
				)
			);

			return implode( "\n", $lines );
		}

		$lines = array(
			'RESEARCH BRIEF (EN) — nothing here is a number; it is what to open and what to read inside it.',
			'',
			'PATTERN TO TEST: ' . $taxon['en'] . '.',
			'What the dossier asks: ' . $taxon['asks_en'],
			$note['en'],
			'',
			'OPEN: the annual report and accounts of ' . $company . ' for the ' . $year . ' financial year, as published by the company and as filed with the market regulator of its primary listing, including the audited accounts and the notes. Take every figure out of that document. If one is not in it, find the document that has it and cite that one too.',
			'',
			'THE SIX ROWS — the line item each label is asking for:',
		);
		$lines = array_merge( $lines, $rows );
		$lines = array_merge(
			$lines,
			array(
				'',
				'SECTOR AVERAGE: take it from a published aggregate you can link to — an index provider\'s or a regulator\'s sector factsheet, an industry body\'s annual statistics, or the median of the peer group the company itself names in this same report. Write down in the source label which one you used. A remembered average is not an average.',
				'',
				'THE TWO RETURNS: total return of the shares over the five financial years following ' . $year . ', and the same window for the broad index the reveal screen compares against, dividends reinvested in both. Both in basis points, signed. Take them from a price history or an index factsheet you can link to, and use identical start and end dates for the two.',
				'',
				'HEADLINES: three, quoted from publications dated inside ' . $year . ', each with its own reference. A headline you write yourself is not a headline.',
				'',
				'TINTS: ' . $rubric['good']['en'] . ' ' . $rubric['warn']['en'] . ' ' . $rubric['bad']['en'],
				'',
				'LESSON — already written for this pattern, and safe to paste as it stands:',
				'  ' . $lesson['en'],
				'',
				'LAST: paste the address of the document you actually opened, give it a label, date the day you read it, and only then tick verified. The tick is a statement about the year and the two returns; changing any of them clears it.',
			)
		);

		return implode( "\n", $lines );
	}

	/* =====================================================================
	 * The library itself.
	 * ================================================================== */

	/**
	 * Every case: who, when, what sector, which shape, and which six figures
	 * the editor should go and find.
	 *
	 * The fundamentals differ per case on purpose. "Months of cash remaining"
	 * is the whole story of one of these and meaningless for another, and a
	 * single generic six-row template would have quietly thrown that away.
	 *
	 * The library spans the patterns the game exists to teach, at least two
	 * cases each, so that a player meets the same SHAPE of dossier twice with
	 * two different companies inside it — which is the only way a pattern
	 * becomes a thing you can recognise rather than a story you remember.
	 *
	 * `dossier_limits` is deliberately absent: it is the library's fallback
	 * pattern, the one a case gets when nobody has decided its shape yet, and
	 * a seeded case that had not been thought about would defeat the point.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions(): array {
		return array(
			self::def( 'Amazon', 2001, 'online_retail', 'hidden_compounder', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'free_cash_flow', 'net_debt', 'cash' ) ),
			self::def( 'Enron', 2000, 'energy_trading', 'fraud', array( 'revenue_growth', 'operating_margin', 'return_on_equity', 'debt_to_equity', 'cash_conversion', 'off_balance_sheet' ) ),
			self::def( 'Nokia', 2007, 'consumer_electronics', 'tech_shift', array( 'revenue_growth', 'operating_margin', 'market_share', 'rnd_intensity', 'net_cash', 'pe_ratio' ) ),
			self::def( 'Coca-Cola', 2010, 'beverages', 'great_company_bad_price', array( 'revenue_growth', 'operating_margin', 'return_on_equity', 'dividend_payout', 'net_debt_ebitda', 'pe_ratio' ) ),
			self::def( 'Pets.com', 1999, 'online_retail', 'unit_economics', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'marketing_intensity', 'cash_burn', 'cash_runway' ) ),

			self::def( 'Cisco Systems', 2000, 'networking', 'great_company_bad_price', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'roic', 'pe_ratio', 'net_cash' ) ),
			self::def( 'Salesforce', 2008, 'enterprise_software', 'hidden_compounder', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'deferred_revenue', 'free_cash_flow', 'stock_comp' ) ),
			self::def( 'Blue Apron', 2017, 'meal_kits', 'unit_economics', array( 'revenue_growth', 'gross_margin', 'marketing_intensity', 'cac_payback', 'repeat_rate', 'cash_burn' ) ),
			self::def( 'Blockbuster', 2004, 'video_rental', 'tech_shift', array( 'revenue_growth', 'operating_margin', 'store_count', 'same_store_sales', 'net_debt', 'legacy_revenue' ) ),
			self::def( 'Research In Motion', 2010, 'mobile_devices', 'tech_shift', array( 'revenue_growth', 'operating_margin', 'market_share', 'platform_fragmentation', 'rnd_intensity', 'legacy_revenue' ) ),

			self::def( 'Parmalat', 2002, 'dairy_food', 'fraud', array( 'revenue_growth', 'operating_margin', 'cash', 'debt_to_equity', 'cash_conversion', 'related_party' ) ),
			self::def( 'Satyam Computer Services', 2008, 'it_services', 'fraud', array( 'revenue_growth', 'operating_margin', 'cash_confirmation', 'receivables_days', 'audit_opinion', 'return_on_equity' ) ),

			self::def( 'WorldCom', 2001, 'telecoms', 'accounting_change', array( 'revenue_growth', 'operating_margin', 'capex_intensity', 'accounting_policy', 'free_cash_flow', 'net_debt' ) ),
			self::def( 'Tesco', 2013, 'grocery', 'accounting_change', array( 'revenue_growth', 'operating_margin', 'accounting_policy', 'same_store_sales', 'working_capital', 'net_debt' ) ),

			self::def( 'Freeport-McMoRan', 2007, 'copper_gold_mining', 'cyclical_peak', array( 'revenue_growth', 'operating_margin', 'commodity_exposure', 'mid_cycle_earnings', 'net_debt_ebitda', 'pe_ratio' ) ),
			self::def( 'Peabody Energy', 2011, 'coal_mining', 'cyclical_peak', array( 'revenue_growth', 'operating_margin', 'price_realisation', 'capacity_utilisation', 'net_debt_ebitda', 'interest_cover' ) ),

			self::def( 'Valeant Pharmaceuticals', 2015, 'specialty_pharma', 'rollup', array( 'revenue_growth', 'organic_growth', 'acquisition_spend', 'goodwill_share', 'net_debt_ebitda', 'cash_conversion' ) ),
			self::def( 'Tyco International', 1999, 'diversified_industrial', 'rollup', array( 'revenue_growth', 'organic_growth', 'acquisition_spend', 'goodwill_share', 'operating_margin', 'provisions' ) ),

			self::def( 'Northern Rock', 2006, 'mortgage_lending', 'leverage_rates', array( 'loan_growth', 'funding_mix', 'debt_maturity', 'equity_to_assets', 'return_on_equity', 'dividend_payout' ) ),
			self::def( 'SVB Financial Group', 2021, 'regional_banking', 'leverage_rates', array( 'loan_growth', 'fixed_rate_assets', 'unrealised_marks', 'depositor_concentration', 'equity_to_assets', 'return_on_equity' ) ),

			self::def( 'SLM Corporation', 2006, 'student_lending', 'regulatory_moat', array( 'regulated_revenue', 'loan_growth', 'return_on_equity', 'funding_mix', 'equity_to_assets', 'dividend_payout' ) ),
			self::def( 'Moody\'s', 2006, 'credit_ratings', 'regulatory_moat', array( 'revenue_growth', 'operating_margin', 'regulated_revenue', 'roic', 'customer_concentration', 'buyback' ) ),

			self::def( 'Lucent Technologies', 1999, 'telecom_equipment', 'cash_vs_earnings', array( 'revenue_growth', 'operating_margin', 'receivables_days', 'vendor_financing', 'cash_conversion', 'inventory_days' ) ),
			self::def( 'Carillion', 2016, 'construction_services', 'cash_vs_earnings', array( 'revenue_growth', 'operating_margin', 'unbilled_work', 'cash_conversion', 'pension_deficit', 'dividend_cover' ) ),

			self::def( 'Copart', 2010, 'salvage_auctions', 'boring_compounder', array( 'revenue_growth', 'operating_margin', 'roic', 'free_cash_flow', 'net_debt', 'buyback' ) ),
			self::def( 'Cintas', 2006, 'uniform_services', 'boring_compounder', array( 'revenue_growth', 'operating_margin', 'roic', 'free_cash_flow', 'dividend_cover', 'customer_concentration' ) ),

			self::def( 'Apple', 1997, 'personal_computers', 'turnaround_worked', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'cash_runway', 'market_share', 'rnd_intensity' ) ),
			self::def( 'Domino\'s Pizza', 2009, 'restaurant_franchising', 'turnaround_worked', array( 'same_store_sales', 'store_count', 'franchise_share', 'operating_margin', 'net_debt_ebitda', 'free_cash_flow' ) ),

			self::def( 'Eastman Kodak', 2005, 'imaging', 'turnaround_failed', array( 'revenue_growth', 'legacy_revenue', 'gross_margin', 'free_cash_flow', 'pension_deficit', 'net_debt' ) ),
			self::def( 'J. C. Penney', 2012, 'department_stores', 'turnaround_failed', array( 'same_store_sales', 'gross_margin', 'operating_margin', 'cash_burn', 'net_debt', 'store_count' ) ),

			self::def( 'GoPro', 2014, 'action_cameras', 'concentration', array( 'revenue_growth', 'gross_margin', 'product_concentration', 'rnd_intensity', 'inventory_days', 'marketing_intensity' ) ),
			self::def( 'Skyworks Solutions', 2015, 'semiconductors', 'concentration', array( 'revenue_growth', 'gross_margin', 'operating_margin', 'customer_concentration', 'capex_intensity', 'free_cash_flow' ) ),

			self::def( 'Plug Power', 2014, 'fuel_cells', 'dilution', array( 'revenue_growth', 'gross_margin', 'cash_burn', 'equity_raised', 'share_count', 'cash_runway' ) ),
			self::def( 'Snap', 2017, 'social_apps', 'dilution', array( 'revenue_growth', 'gross_margin', 'active_users', 'stock_comp', 'share_count', 'cash_burn' ) ),
		);
	}

	/**
	 * One case definition.
	 *
	 * @param string            $company      Company name as it stood in that year.
	 * @param int               $year         The year the dossier describes.
	 * @param string            $sector       Key into sectors().
	 * @param string            $pattern      Key into Reveal_Lessons::patterns().
	 * @param array<int,string> $fundamentals Six keys into metrics(), in dossier order.
	 * @return array<string,mixed>
	 */
	private static function def( string $company, int $year, string $sector, string $pattern, array $fundamentals ): array {
		return array(
			'company'      => $company,
			'year'         => $year,
			'sector'       => $sector,
			'pattern'      => $pattern,
			'fundamentals' => $fundamentals,
		);
	}

	/* =====================================================================
	 * The WordPress half. Everything above this line is pure data.
	 * ================================================================== */

	/**
	 * `wp hti-games seed-cases`
	 *
	 * Inserts the library as unverified drafts. Idempotent by company and
	 * year: a case already in the database is left exactly as an editor left
	 * it, because re-running a seeder must never overwrite the work the seeder
	 * exists to make possible.
	 *
	 * @param array<int,string>    $args  Positional arguments (unused).
	 * @param array<string,string> $assoc Associative arguments (unused).
	 */
	public static function cli_seed( array $args, array $assoc ): void {
		unset( $args, $assoc );

		$created = 0;
		$skipped = 0;

		foreach ( self::cases() as $case ) {
			if ( self::exists( (string) $case['company'], (int) $case['year'] ) ) {
				++$skipped;
				\WP_CLI::log( sprintf( '  %s %d — already present, left alone.', $case['company'], $case['year'] ) );
				continue;
			}

			if ( self::create( $case ) ) {
				++$created;
				\WP_CLI::log( sprintf( '  %s %d — draft created, unverified (%s).', $case['company'], $case['year'], $case['pattern'] ) );
			}
		}

		\WP_CLI::success( sprintf( '%d cases created, %d already present.', $created, $skipped ) );
		\WP_CLI::log(
			'None of them can be published yet, and that is the workflow: open each one, read the research brief in the dossier box, read the figures out of the document it names, paste the source URL, then tick verified. The publish gate will keep refusing until you do.'
		);
	}

	/**
	 * Whether a case for this company and year already exists, in any status.
	 *
	 * @param string $company Company name.
	 * @param int    $year    Year the dossier describes.
	 * @return bool
	 */
	private static function exists( string $company, int $year ): bool {
		$found = get_posts(
			array(
				'post_type'        => Config::CPT_CASE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- exact-match lookups, once each, in a CLI command.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => 'hti_rev_company',
						'value'   => $company,
						'compare' => '=',
					),
					array(
						'key'     => 'hti_rev_year',
						'value'   => (string) $year,
						'compare' => '=',
					),
				),
			)
		);

		return array() !== $found;
	}

	/**
	 * Insert one case as an unverified draft.
	 *
	 * @param array{company:string,year:int,title:string,meta:array<string,mixed>} $case One row of cases().
	 * @return bool
	 */
	private static function create( array $case ): bool {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Config::CPT_CASE,
				// Draft, and the publish gate would send it back here anyway.
				'post_status' => 'draft',
				'post_title'  => $case['title'],
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return false;
		}

		foreach ( $case['meta'] as $key => $value ) {
			// update_post_meta runs the registered sanitize_callback from CPT,
			// which is why the empty numeric fields land as 0 rather than as
			// an empty string. That is fine and changes nothing: the case is
			// still unsourced and still unverified, so it is still unpublishable
			// — and an editor who sees a 0 in the box has to replace it with a
			// figure from the filing before the tick means anything.
			update_post_meta( (int) $post_id, $key, $value );
		}

		return true;
	}
}
