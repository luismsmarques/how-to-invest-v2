<?php
/**
 * The dossier library for The Reveal — thirty-four complete, ILLUSTRATIVE cases.
 *
 * READ THIS BEFORE TAKING A FIGURE OUT OF HERE AND USING IT FOR ANYTHING.
 * Every number in this file is a RECONSTRUCTION. The companies are real, the
 * years are real, and the direction of what happened next is real; the ratios,
 * the sector averages, the headlines and the two five-year returns were
 * written to make a pattern legible to a beginner, not copied out of a filing.
 * No line of this was checked against a document, because the environment it
 * was written in had no way to open one — and model memory is never a
 * publishable source (.claude/skills/financial-analyst/SKILL.md).
 *
 * That is a deliberate product decision, taken by the owner with the
 * constraint in front of him: a case library that cannot be played is not a
 * case library. What makes it honest is not the figures, which nobody has
 * verified — it is that every case SAYS SO. Each one is stamped
 * `hti_rev_provenance = 'illustrative'`, and the reveal screen prints
 * Strings::get( 'rev_illustrative' ) where a verified case would print its
 * source: the company, the period and the direction are real, the figures and
 * the headlines are reconstructed to show the pattern. A reader is never left
 * to assume an accuracy nobody checked.
 *
 * CLAUDE.md invariant 2 forbids naming companies anywhere in the engine's or
 * the LLM's output. The Reveal has one narrow written exemption: it may name a
 * real company, at a real year, **only** inside `hti_reveal_case`, **only**
 * for a period at least Config::REVEAL_MIN_AGE_YEARS in the past, and **only**
 * where the case has met the conditions for what it claims to be. There are
 * two such claims and Case_Admin::missing() enforces both:
 *
 *  - a VERIFIED case claims its figures came out of a document, so it cannot
 *    be published without that document's address and a tick from whoever read
 *    it. Nothing in this file makes that claim;
 *  - an ILLUSTRATIVE case claims no document, so what it has to carry instead
 *    is a whole dossier: six fundamentals with a key, both languages and a
 *    tint, three headlines, the revenue band and the aftermath, in both
 *    languages. A hole in it is not untidiness, it is the hole the player is
 *    looking at while being told the figures show a pattern.
 *
 * SO WHAT THE FIGURES OWE, IN ORDER:
 *
 *  1. THE DIRECTION OF HISTORY IS NOT NEGOTIABLE. A reconstructed ratio is a
 *     reconstruction; an inverted outcome is an error. Enron went to nothing,
 *     Kodak and Blockbuster did not recover, Amazon compounded enormously, and
 *     1997 was the start of something extraordinary at Apple.
 *  2. THE FIGURES MAKE THE PATTERN LEGIBLE. A fraud case shows profit and cash
 *     disagreeing by a margin a beginner can see. A technology-shift case
 *     shows six good-looking rows, because the lesson is that the accounts
 *     described a business about to be walked around.
 *  3. THEY ARE INTERNALLY COHERENT. The margin, the debt ratio and the
 *     multiple on one dossier describe the same company, and a sector average
 *     is plausible for that sector in that decade.
 *  4. THE TINT FOLLOWS tint_rubric(), not intuition, and agrees with the value
 *     beside it.
 *  5. THE HEADLINES ARE RECONSTRUCTED PERIOD CONTEXT. They are written as the
 *     kind of thing being said at the time — never inside quotation marks,
 *     never attributed to a named publication, never presented as a citation.
 *  6. THE INDEX RETURN IS PLAUSIBLE FOR THAT FIVE-YEAR WINDOW. Several cases
 *     teach "a good company that still lost to the index", and that lesson
 *     only exists if the index number is sane for the period.
 *
 * WHAT IS DELIBERATELY STILL EMPTY:
 *
 *  - `hti_rev_source_url`, `hti_rev_source_label`, `hti_rev_source_accessed` —
 *    a plausible pre-filled URL would forge exactly the audit trail the
 *    verified path exists to keep;
 *  - `hti_rev_verified`, `hti_rev_verified_by`, `hti_rev_verified_at` — nobody
 *    has checked anything, and the tick is a statement by a person.
 *
 * Every case also carries a RESEARCH BRIEF, and its job has changed: it is now
 * the instructions for PROMOTING a case from illustrative to verified. It
 * names document TYPES and where they live, which line item feeds which of the
 * six labels, and where a defensible sector average comes from — never a URL,
 * a filing reference or a date, because those cannot be checked from here.
 *
 * tests/test-seed-cases.php is where all of the above is held to: that every
 * case is complete and internally consistent, that every one is marked
 * illustrative with no source and no tick, that a case claiming verified still
 * cannot publish without both, and that no brief guesses at an address.
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
	 * the edit screen. A label nobody has to open a box to read is the only
	 * kind that survives a busy afternoon — and what it has to say is not
	 * "unfinished" but WHICH KIND OF CASE THIS IS, because a finished case
	 * with reconstructed figures looks exactly like a finished case with
	 * checked ones from the post list. It is removed when somebody promotes
	 * the case to verified, and not before.
	 */
	public const TITLE_MARK = '— illustrative reconstruction';

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
				'title'   => sprintf( '%s %d %s', $def['company'], $def['year'], self::TITLE_MARK ),
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
		$lesson = Reveal_Lessons::for_pattern( (string) $def['pattern'], (int) $def['variant'] );

		return array(
			'hti_rev_company'            => (string) $def['company'],
			'hti_rev_year'               => (int) $def['year'],
			'hti_rev_sector_en'          => $sector['en'],
			'hti_rev_sector_pt'          => $sector['pt'],

			// Which shape this dossier has, and the key Reveal_Lessons hangs
			// the ready-written lesson on.
			'hti_rev_pattern'            => (string) $def['pattern'],

			// What the figures below ARE. Every seeded case says the same
			// thing, and it is the thing that keeps the library honest: the
			// reveal screen prints a sentence saying the figures were
			// reconstructed rather than read out of a filing.
			'hti_rev_provenance'         => 'illustrative',

			// The editor-facing half of the case: what to open, what to read
			// out of it, and what it would take to promote this case to
			// verified. Bilingual in one field, because the admin screen
			// renders one.
			'hti_rev_brief'              => self::brief( $def ),

			// A band and never an exact figure — the dossier is anonymous
			// before the decision or it is not a dossier, and a revenue to the
			// nearest million is one search away from the answer.
			'hti_rev_revenue_band_en'    => (string) $def['band'][0],
			'hti_rev_revenue_band_pt'    => (string) $def['band'][1],

			'hti_rev_fundamentals'       => (string) wp_json_encode( self::fundamentals( (array) $def['fundamentals'] ) ),
			'hti_rev_headlines'          => (string) wp_json_encode( self::headlines( (array) $def['headlines'] ) ),

			// The two figures the whole game is built on, in signed basis
			// points: -10000 is a total loss, and that is the more instructive
			// half of the archive.
			'hti_rev_return_5y_bp'       => (int) $def['returns'][0],
			'hti_rev_index_return_5y_bp' => (int) $def['returns'][1],

			// What happened next, per case; and the lesson, which is the
			// ready-written one for this PATTERN rather than a paragraph about
			// this company. Two cases of the same shape take different
			// variants, so a player who meets the shape twice is not read the
			// same sentence twice.
			'hti_rev_context_en'         => (string) $def['aftermath'][0],
			'hti_rev_context_pt'         => (string) $def['aftermath'][1],
			'hti_rev_lesson_en'          => $lesson['en'],
			'hti_rev_lesson_pt'          => $lesson['pt'],

			// Deliberately empty, all six. Nothing here was read out of a
			// document, so there is no document to cite and nobody to record
			// as having checked it — and a plausible pre-filled URL would be a
			// forged audit trail rather than a missing one.
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
	 * Six fundamentals rows: the question, the answer, and the tint.
	 *
	 * The LABEL comes from metrics(), so two dossiers asking the same question
	 * ask it in the same words and in the same Portuguese. The VALUE and the
	 * sector average come from the case, because they are the case.
	 *
	 * A row whose key is not in metrics() is dropped rather than rendered
	 * unlabelled: REST::fundamentals() would drop it on the way to the player
	 * anyway, and a row that exists in the editor and not in the game is the
	 * failure worth failing loudly here.
	 *
	 * @param array<int,array<string,string>> $rows Rows from fig(), in dossier order.
	 * @return array<int,array<string,string>>
	 */
	public static function fundamentals( array $rows ): array {
		$metrics = self::metrics();
		$out     = array();

		foreach ( array_slice( $rows, 0, self::FUNDAMENTALS ) as $row ) {
			$key    = (string) ( $row['key'] ?? '' );
			$metric = $metrics[ $key ] ?? null;
			if ( null === $metric ) {
				continue;
			}

			$out[] = array(
				'key'           => $key,
				'label_en'      => $metric['en'],
				'label_pt'      => $metric['pt'],
				'value_en'      => (string) $row['value_en'],
				'value_pt'      => (string) $row['value_pt'],
				'sector_avg_en' => (string) $row['sector_avg_en'],
				'sector_avg_pt' => (string) $row['sector_avg_pt'],
				'tint'          => (string) $row['tint'],
			);
		}

		return $out;
	}

	/**
	 * One fundamentals row, with the Portuguese derived where it can be.
	 *
	 * A figure like "18%" or "1.4x" is the same sentence in both languages
	 * apart from the decimal separator, so the Portuguese is derived rather
	 * than typed twice — two copies of the same number are two numbers that
	 * can disagree. Anything carrying a word or a currency symbol takes an
	 * explicit Portuguese, because "$1.1bn" is not Portuguese and a reader of
	 * one language should never be shown the other one's units.
	 *
	 * @param string $key           Key into metrics().
	 * @param string $tint          'good', 'warn' or 'bad', per tint_rubric().
	 * @param string $value_en      The figure, in English.
	 * @param string $avg_en        The sector average, in English.
	 * @param string $value_pt      The figure in Portuguese; derived when empty.
	 * @param string $avg_pt        The sector average in Portuguese; derived when empty.
	 * @return array<string,string>
	 */
	public static function fig( string $key, string $tint, string $value_en, string $avg_en, string $value_pt = '', string $avg_pt = '' ): array {
		return array(
			'key'           => $key,
			'tint'          => $tint,
			'value_en'      => $value_en,
			'value_pt'      => '' !== $value_pt ? $value_pt : self::pt_figure( $value_en ),
			'sector_avg_en' => $avg_en,
			'sector_avg_pt' => '' !== $avg_pt ? $avg_pt : self::pt_figure( $avg_en ),
		);
	}

	/**
	 * A language-neutral figure in European Portuguese: the decimal point
	 * becomes a comma, and nothing else changes.
	 *
	 * Deliberately narrow. It only rewrites a point that sits between two
	 * digits, so "1.4x" becomes "1,4x" while a full stop at the end of a
	 * sentence, or the point in a thousands separator, is left alone.
	 *
	 * @param string $value A figure with no words in it.
	 */
	public static function pt_figure( string $value ): string {
		return (string) preg_replace( '/(\d)\.(\d)/', '$1,$2', $value );
	}

	/**
	 * Three headlines, each in both languages.
	 *
	 * Reconstructed period context and NOT quotations: they are written as the
	 * kind of thing being said at the time, never inside quotation marks and
	 * never attributed to a publication. That is what the reveal screen's
	 * illustrative line tells the player, in as many words, and it is why the
	 * dossier can carry the mood of a year without anybody having read a
	 * newspaper from it.
	 *
	 * @param array<int,array<int,string>> $rows Pairs of [ en, pt ].
	 * @return array<int,array{en:string,pt:string}>
	 */
	public static function headlines( array $rows ): array {
		$out = array();

		foreach ( array_slice( $rows, 0, self::HEADLINES ) as $row ) {
			$out[] = array(
				'en' => (string) $row[0],
				'pt' => (string) $row[1],
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
	 * Its job is PROMOTION. The case ships complete and playable with
	 * reconstructed figures; the brief is what somebody follows to replace
	 * every one of them with a figure read out of a document, and only then
	 * tick verified. Until that happens the figures stay what they are and the
	 * reveal screen keeps saying so.
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
		foreach ( array_slice( (array) $def['metrics'], 0, self::FUNDAMENTALS ) as $key ) {
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
				'ESTADO DESTE CASO: ilustrativo. Os valores, as médias do setor e as manchetes já preenchidos foram reconstruídos para mostrar o padrão — a empresa, o período e a direção do que aconteceu são reais, os números não saíram de nenhum documento, e o ecrã da revelação diz isso ao jogador. Para promover o caso a verificado: abre o documento indicado abaixo, substitui TODOS os valores pelo que lá estiver, cola o endereço, e só então marca verificado. A partir daí o caso passa a ser julgado como verificado e não pode ser publicado sem a fonte e sem a marca.',
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
					'MANCHETES: as três que já lá estão são contexto reconstruído da época — escritas como o género de coisa que se dizia na altura, nunca entre aspas, nunca atribuídas a uma publicação, nunca apresentadas como citação. Se promoveres o caso a verificado, substitui-as por manchetes que tenhas mesmo lido, datadas dentro de ' . $year . ', cada uma com a sua referência.',
					'',
					'TONS: ' . $rubric['good']['pt'] . ' ' . $rubric['warn']['pt'] . ' ' . $rubric['bad']['pt'],
					'',
					'LIÇÃO — já escrita para este padrão, e segura para colar tal como está:',
					'  ' . $lesson['pt'],
					'',
					'NO FIM: cola o endereço do documento que abriste mesmo, dá-lhe um rótulo, data o dia em que o leste, muda este caso para verificado, e só então marca verificado. A marca é uma afirmação sobre o ano e sobre os dois retornos; alterar qualquer um deles apaga-a de novo.',
				)
			);

			return implode( "\n", $lines );
		}

		$lines = array(
			'RESEARCH BRIEF (EN) — nothing here is a number; it is what to open and what to read inside it.',
			'',
			'STATE OF THIS CASE: illustrative. The values, sector averages and headlines already filled in were reconstructed to show the pattern — the company, the period and the direction of what happened are real, the numbers came out of no document, and the reveal screen tells the player so. To promote it to verified: open the document named below, replace EVERY value with what it says, paste the address, and only then tick verified. From that point the case is judged as a verified one and cannot be published without the source and the tick.',
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
				'HEADLINES: the three already there are reconstructed period context — written as the kind of thing being said at the time, never inside quotation marks, never attributed to a publication, never presented as a citation. If you promote the case to verified, replace them with headlines you have actually read, dated inside ' . $year . ', each with its own reference.',
				'',
				'TINTS: ' . $rubric['good']['en'] . ' ' . $rubric['warn']['en'] . ' ' . $rubric['bad']['en'],
				'',
				'LESSON — already written for this pattern, and safe to paste as it stands:',
				'  ' . $lesson['en'],
				'',
				'LAST: paste the address of the document you actually opened, give it a label, date the day you read it, switch this case to verified, and only then tick verified. The tick is a statement about the year and the two returns; changing any of them clears it again.',
			)
		);

		return implode( "\n", $lines );
	}

	/* =====================================================================
	 * The library itself.
	 * ================================================================== */

	/**
	 * Every case: who, when, what sector, which shape, and the whole dossier.
	 *
	 * The fundamentals differ per case on purpose. "Months of cash remaining"
	 * is the whole story of one of these and meaningless for another, and a
	 * single generic six-row template would have quietly thrown that away.
	 *
	 * The library spans the patterns the game exists to teach, at least two
	 * cases each, so that a player meets the same SHAPE of dossier twice with
	 * two different companies inside it — which is the only way a pattern
	 * becomes a thing you can recognise rather than a story you remember. The
	 * two cases of a shape take different lesson variants for the same reason.
	 *
	 * `dossier_limits` is deliberately absent: it is the library's fallback
	 * pattern, the one a case gets when nobody has decided its shape yet, and
	 * a seeded case that had not been thought about would defeat the point.
	 *
	 * Every figure below is an illustrative reconstruction. See the file
	 * docblock for what that means and for the six rules it obeys.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions(): array {
		return array(
			self::def(
				array(
					'company'      => 'Amazon',
					'year'         => 2001,
					'sector'       => 'online_retail',
					'pattern'      => 'hidden_compounder',
					'variant'      => 0,
					'band'         => array( '$2bn–$5bn', '2 a 5 mil milhões de dólares' ),
					'returns'      => array( 26000, 3500 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '13%', '4%' ),
						self::fig( 'gross_margin', 'warn', '26%', '24%' ),
						self::fig( 'operating_margin', 'warn', '-5%', '-14%' ),
						self::fig( 'free_cash_flow', 'warn', '-$170m', '-$310m', '-170 milhões de dólares', '-310 milhões de dólares' ),
						self::fig( 'net_debt', 'bad', '$1.1bn', '$0.2bn', '1,1 mil milhões de dólares', '0,2 mil milhões de dólares' ),
						self::fig( 'cash', 'good', '$1.0bn', '$0.3bn', '1,0 mil milhões de dólares', '0,3 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Online retailer posts another year of losses as rivals close their sites', 'Retalhista online regista mais um ano de prejuízos enquanto rivais fecham os sites' ),
						array( 'Investors ask how many years a shop can go on selling below cost', 'Investidores perguntam quantos anos pode uma loja continuar a vender abaixo do custo' ),
						array( 'Spending on warehouses questioned as the online boom cools', 'Investimento em armazéns posto em causa com o arrefecimento do boom online' ),
					),
					'aftermath'    => array( 'The losses narrowed every year while revenue kept growing, and the cash the accounts recorded as a loss turned out to be the price of warehouses, delivery and a buying habit. Over the following five years the shares multiplied several times over, well ahead of an index that gained about a third.', 'Os prejuízos encolheram todos os anos enquanto as receitas continuaram a crescer, e a caixa que as contas registavam como prejuízo era o preço de armazéns, entregas e um hábito de compra. Nos cinco anos seguintes as ações multiplicaram-se várias vezes, muito à frente de um índice que ganhou cerca de um terço.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Enron',
					'year'         => 2000,
					'sector'       => 'energy_trading',
					'pattern'      => 'fraud',
					'variant'      => 0,
					'band'         => array( 'Over $50bn', 'Mais de 50 mil milhões de dólares' ),
					'returns'      => array( -10000, 300 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '151%', '12%' ),
						self::fig( 'operating_margin', 'bad', '2%', '9%' ),
						self::fig( 'return_on_equity', 'warn', '11%', '10%' ),
						self::fig( 'debt_to_equity', 'warn', '1.0x', '0.8x' ),
						self::fig( 'cash_conversion', 'bad', '0.2x', '0.9x' ),
						self::fig( 'off_balance_sheet', 'bad', '$3.4bn in unconsolidated partnerships', 'none disclosed', '3,4 mil milhões de dólares em parcerias não consolidadas', 'nenhum divulgado' ),
					),
					'headlines'    => array(
						array( 'Energy trader named one of the most admired companies of the year', 'Negociadora de energia eleita uma das empresas mais admiradas do ano' ),
						array( 'Revenue doubles again as the trading desks expand into broadband', 'Receitas voltam a duplicar com as mesas de negociação a entrar na banda larga' ),
						array( 'A few analysts say nobody outside the company can explain how it makes money', 'Alguns analistas dizem que ninguém de fora da empresa consegue explicar de onde vem o lucro' ),
					),
					'aftermath'    => array( 'The distance between reported profit and cash was the whole story rather than a detail of it. Within a year the partnerships were brought back on to the balance sheet, the profits were restated away and the company filed for bankruptcy; the shares ended worthless while the index finished the five years roughly where it started.', 'A distância entre o lucro declarado e a caixa era toda a história e não um pormenor dela. Em menos de um ano as parcerias voltaram ao balanço, os lucros foram reexpressos e a empresa entrou em falência; as ações acabaram sem valor e o índice terminou os cinco anos praticamente onde tinha começado.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Nokia',
					'year'         => 2007,
					'sector'       => 'consumer_electronics',
					'pattern'      => 'tech_shift',
					'variant'      => 0,
					'band'         => array( '€50bn–€75bn', '50 a 75 mil milhões de euros' ),
					'returns'      => array( -8500, -1200 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '24%', '11%' ),
						self::fig( 'operating_margin', 'good', '15%', '7%' ),
						self::fig( 'market_share', 'good', '38%', '13%' ),
						self::fig( 'rnd_intensity', 'good', '11%', '8%' ),
						self::fig( 'net_cash', 'good', '€8.2bn', '€0.9bn', '8,2 mil milhões de euros', '0,9 mil milhões de euros' ),
						self::fig( 'pe_ratio', 'good', '15x', '19x' ),
					),
					'headlines'    => array(
						array( 'Handset maker ships more phones than its next three rivals combined', 'Fabricante de telemóveis vende mais aparelhos do que os três rivais seguintes juntos' ),
						array( 'A computer company enters the phone market with a touchscreen', 'Uma empresa de computadores entra no mercado dos telemóveis com um ecrã tátil' ),
						array( 'Analysts call the share price undemanding for a business this dominant', 'Analistas consideram a cotação pouco exigente para um negócio tão dominante' ),
					),
					'aftermath'    => array( 'Every figure on the page was excellent and not one of them described what was about to happen. Within five years the choice people were making was between software platforms rather than handsets, share of the market collapsed, and the shares lost most of their value while the European index also ended lower.', 'Todos os números da página eram excelentes e nenhum deles descrevia o que estava prestes a acontecer. Em cinco anos a escolha das pessoas passou a ser entre plataformas de software e não entre aparelhos, a quota de mercado desabou, e as ações perderam quase todo o valor enquanto o índice europeu também terminou mais baixo.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Coca-Cola',
					'year'         => 2010,
					'sector'       => 'beverages',
					'pattern'      => 'great_company_bad_price',
					'variant'      => 0,
					'band'         => array( '$25bn–$50bn', '25 a 50 mil milhões de dólares' ),
					'returns'      => array( 4500, 8000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '13%', '6%' ),
						self::fig( 'operating_margin', 'good', '24%', '15%' ),
						self::fig( 'return_on_equity', 'good', '38%', '18%' ),
						self::fig( 'dividend_payout', 'warn', '50%', '45%' ),
						self::fig( 'net_debt_ebitda', 'good', '1.3x', '1.6x' ),
						self::fig( 'pe_ratio', 'bad', '19x', '16x' ),
					),
					'headlines'    => array(
						array( 'Drinks group completes the purchase of its largest bottler and takes back distribution', 'Grupo de bebidas conclui a compra do maior engarrafador e retoma a distribuição' ),
						array( 'Defensive shares in demand while the recovery stays uneven', 'Ações defensivas procuradas enquanto a recuperação continua desigual' ),
						array( 'One of the most recognised brands in the world raises its dividend again', 'Uma das marcas mais reconhecidas do mundo volta a aumentar o dividendo' ),
					),
					'aftermath'    => array( 'Nothing went wrong with the business. It kept its margins, kept raising the dividend and kept selling more every year. The shares still returned a little over half what the index did over the next five years, because most of the good news in the dossier had already been paid for.', 'Nada correu mal ao negócio. Manteve as margens, continuou a aumentar o dividendo e continuou a vender mais todos os anos. Ainda assim, as ações renderam pouco mais de metade do que o índice nos cinco anos seguintes, porque quase todas as boas notícias do dossiê já tinham sido pagas.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Pets.com',
					'year'         => 1999,
					'sector'       => 'online_retail',
					'pattern'      => 'unit_economics',
					'variant'      => 0,
					'band'         => array( 'Under $50m', 'Menos de 50 milhões de dólares' ),
					'returns'      => array( -10000, -1100 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '480%', '35%' ),
						self::fig( 'gross_margin', 'bad', '-19%', '22%' ),
						self::fig( 'operating_margin', 'bad', '-700%', '-40%' ),
						self::fig( 'marketing_intensity', 'bad', '420%', '35%' ),
						self::fig( 'cash_burn', 'bad', '-$62m', '-$18m', '-62 milhões de dólares', '-18 milhões de dólares' ),
						self::fig( 'cash_runway', 'bad', '9 months', '22 months', '9 meses', '22 meses' ),
					),
					'headlines'    => array(
						array( 'Online pet shop buys the year\'s most expensive television advertising slot', 'Loja online de animais compra o espaço publicitário televisivo mais caro do ano' ),
						array( 'Delivery costs on heavy goods come under scrutiny as orders climb', 'Custos de entrega de produtos pesados sob escrutínio com a subida das encomendas' ),
						array( 'Investors ask when an internet retailer will make money on a single order', 'Investidores perguntam quando é que um retalhista online ganhará dinheiro numa única encomenda' ),
					),
					'aftermath'    => array( 'Every sale lost money before anybody had been paid to run the company, so more sales made the hole deeper. The shop was wound down within a year of listing and shareholders were left with nothing, while the index also ended the five years lower.', 'Cada venda perdia dinheiro antes de alguém ter sido pago para gerir a empresa, por isso mais vendas tornavam o buraco maior. A loja foi encerrada menos de um ano depois da entrada em bolsa e os acionistas ficaram sem nada, enquanto o índice também terminou os cinco anos mais baixo.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Cisco Systems',
					'year'         => 2000,
					'sector'       => 'networking',
					'pattern'      => 'great_company_bad_price',
					'variant'      => 1,
					'band'         => array( '$15bn–$25bn', '15 a 25 mil milhões de dólares' ),
					'returns'      => array( -5500, 300 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '55%', '22%' ),
						self::fig( 'gross_margin', 'good', '64%', '48%' ),
						self::fig( 'operating_margin', 'good', '21%', '11%' ),
						self::fig( 'roic', 'good', '28%', '12%' ),
						self::fig( 'pe_ratio', 'bad', '127x', '38x' ),
						self::fig( 'net_cash', 'good', '$14.4bn', '$1.1bn', '14,4 mil milhões de dólares', '1,1 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Networking group is briefly the most valuable company in the world', 'Grupo de equipamento de redes é por breves dias a empresa mais valiosa do mundo' ),
						array( 'Orders for internet equipment described as effectively limitless', 'Encomendas de equipamento para a internet descritas como praticamente ilimitadas' ),
						array( 'Analysts argue an ordinary multiple cannot apply to a business growing this fast', 'Analistas defendem que um múltiplo normal não se aplica a um negócio que cresce tão depressa' ),
					),
					'aftermath'    => array( 'The business survived, kept its margins and is still one of the largest of its kind. The price did not survive: the multiple came down for years, and five years later the shares were worth about half what they cost while the index ended roughly flat.', 'O negócio sobreviveu, manteve as margens e continua a ser um dos maiores do seu setor. O preço não sobreviveu: o múltiplo foi descendo durante anos e, cinco anos depois, as ações valiam cerca de metade do que tinham custado, com o índice a terminar praticamente na mesma.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Salesforce',
					'year'         => 2008,
					'sector'       => 'enterprise_software',
					'pattern'      => 'hidden_compounder',
					'variant'      => 1,
					'band'         => array( '$500m–$1bn', '500 milhões a 1 mil milhões de dólares' ),
					'returns'      => array( 21000, 12800 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '51%', '12%' ),
						self::fig( 'gross_margin', 'good', '78%', '72%' ),
						self::fig( 'operating_margin', 'bad', '2%', '17%' ),
						self::fig( 'deferred_revenue', 'good', '$381m, up 58% on the year before', 'up 9%', '381 milhões de dólares, mais 58% do que no ano anterior', 'mais 9%' ),
						self::fig( 'free_cash_flow', 'good', '$120m', '$61m', '120 milhões de dólares', '61 milhões de dólares' ),
						self::fig( 'stock_comp', 'bad', '9% of revenue', '3% of revenue', '9% das receitas', '3% das receitas' ),
					),
					'headlines'    => array(
						array( 'Subscription software sold to finance directors who used to buy licences', 'Software por subscrição vendido a diretores financeiros que antes compravam licenças' ),
						array( 'Analysts note the company spends most of its gross profit winning customers', 'Analistas notam que a empresa gasta quase toda a margem bruta a ganhar clientes' ),
						array( 'Corporate buyers ask whether business data belongs on somebody else\'s servers', 'Compradores empresariais perguntam se os dados do negócio devem estar em servidores de outros' ),
					),
					'aftermath'    => array( 'The operating margin stayed thin for years and the cash did not: subscriptions were collected up front and spent on winning customers who then renewed. Over the next five years revenue more than tripled and the shares comfortably beat an index that itself more than doubled.', 'A margem operacional manteve-se fina durante anos e a caixa não: as subscrições eram cobradas adiantadas e gastas a ganhar clientes que depois renovavam. Nos cinco anos seguintes as receitas mais do que triplicaram e as ações bateram com folga um índice que também mais do que duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Blue Apron',
					'year'         => 2017,
					'sector'       => 'meal_kits',
					'pattern'      => 'unit_economics',
					'variant'      => 1,
					'band'         => array( '$500m–$1bn', '500 milhões a 1 mil milhões de dólares' ),
					'returns'      => array( -9500, 5700 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'warn', '11%', '9%' ),
						self::fig( 'gross_margin', 'warn', '32%', '34%' ),
						self::fig( 'marketing_intensity', 'bad', '22%', '9%' ),
						self::fig( 'cac_payback', 'bad', '14 months', '5 months', '14 meses', '5 meses' ),
						self::fig( 'repeat_rate', 'bad', '31%', '62%' ),
						self::fig( 'cash_burn', 'bad', '-$153m', '-$25m', '-153 milhões de dólares', '-25 milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Meal-kit company lists weeks after a grocery deal reshapes the sector', 'Empresa de kits de refeição entra em bolsa semanas depois de um negócio no retalho alimentar reconfigurar o setor' ),
						array( 'Marketing spending questioned as customers cancel after the discounted boxes end', 'Despesa de marketing posta em causa com clientes a cancelar quando acabam as caixas com desconto' ),
						array( 'Supermarkets put their own kits in the chilled aisle', 'Supermercados colocam os seus próprios kits no corredor dos refrigerados' ),
					),
					'aftermath'    => array( 'Winning a customer cost more than that customer brought back before leaving, so more marketing bought more churn. Revenue fell for years, the cash kept going out, and the shares lost almost all their value while the index rose by more than half.', 'Ganhar um cliente custava mais do que esse cliente devolvia antes de sair, por isso mais marketing comprava mais abandono. As receitas caíram durante anos, a caixa continuou a sair, e as ações perderam quase todo o valor enquanto o índice subiu mais de metade.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Blockbuster',
					'year'         => 2004,
					'sector'       => 'video_rental',
					'pattern'      => 'tech_shift',
					'variant'      => 1,
					'band'         => array( '$5bn–$10bn', '5 a 10 mil milhões de dólares' ),
					'returns'      => array( -9600, 200 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '4%', '0%' ),
						self::fig( 'operating_margin', 'good', '7%', '5%' ),
						self::fig( 'store_count', 'good', '9,100 outlets', '1,400 outlets', '9100 lojas', '1400 lojas' ),
						self::fig( 'same_store_sales', 'warn', '-1%', '1%' ),
						self::fig( 'net_debt', 'warn', '$1.0bn', '$0.4bn', '1,0 mil milhões de dólares', '0,4 mil milhões de dólares' ),
						self::fig( 'legacy_revenue', 'bad', '88% from renting in a shop', '74%', '88% do aluguer em loja', '74%' ),
					),
					'headlines'    => array(
						array( 'Rental chain drops late fees to keep customers coming through the door', 'Cadeia de aluguer acaba com as multas de atraso para manter clientes na loja' ),
						array( 'A postal subscription service passes three million members', 'Um serviço de subscrição por correio ultrapassa os três milhões de assinantes' ),
						array( 'Landlords report strong demand for large units on the high street', 'Senhorios registam forte procura por lojas de grande área no centro das cidades' ),
					),
					'aftermath'    => array( 'The shops were an asset in the accounts and an obligation in the market: rent and staff had to be paid whether or not anybody still drove out for a film. Revenue fell every year, the debt did not, and the company filed for bankruptcy within six years.', 'As lojas eram um ativo nas contas e um compromisso no mercado: a renda e o pessoal tinham de ser pagos, houvesse ou não quem ainda saísse de casa para alugar um filme. As receitas caíram todos os anos, a dívida não, e a empresa entrou em falência menos de seis anos depois.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Research In Motion',
					'year'         => 2010,
					'sector'       => 'mobile_devices',
					'pattern'      => 'tech_shift',
					'variant'      => 0,
					'band'         => array( '$10bn–$25bn', '10 a 25 mil milhões de dólares' ),
					'returns'      => array( -8300, 8000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '35%', '12%' ),
						self::fig( 'operating_margin', 'good', '23%', '9%' ),
						self::fig( 'market_share', 'good', '20%', '8%' ),
						self::fig( 'platform_fragmentation', 'warn', '3 platforms supported, one being wound down', '1 platform', '3 plataformas suportadas, uma em descontinuação', '1 plataforma' ),
						self::fig( 'rnd_intensity', 'bad', '6%', '9%' ),
						self::fig( 'legacy_revenue', 'warn', '80% from handsets with a keyboard', '55%', '80% de aparelhos com teclado', '55%' ),
					),
					'headlines'    => array(
						array( 'Corporate email on a handset becomes standard issue for the office', 'O email da empresa no telemóvel torna-se equipamento padrão do escritório' ),
						array( 'Two touchscreen platforms open their software stores to outside developers', 'Duas plataformas de ecrã tátil abrem as lojas de aplicações a programadores externos' ),
						array( 'Executives say the keyboard is what business users actually want', 'Responsáveis dizem que o teclado é o que os utilizadores empresariais realmente querem' ),
					),
					'aftermath'    => array( 'The figures described a company defending a position rather than one being walked around. Within five years the applications mattered more than the keyboard or the security, share of the market fell to almost nothing, and the shares lost more than four fifths of their value while the index nearly doubled.', 'Os números descreviam uma empresa a defender uma posição e não uma que estava a ser contornada. Em cinco anos as aplicações passaram a pesar mais do que o teclado ou a segurança, a quota de mercado caiu para quase nada, e as ações perderam mais de quatro quintos do valor enquanto o índice quase duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Parmalat',
					'year'         => 2002,
					'sector'       => 'dairy_food',
					'pattern'      => 'fraud',
					'variant'      => 1,
					'band'         => array( '€5bn–€10bn', '5 a 10 mil milhões de euros' ),
					'returns'      => array( -9500, 7000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '9%', '3%' ),
						self::fig( 'operating_margin', 'warn', '8%', '6%' ),
						self::fig( 'cash', 'warn', '€3.9bn reported', '€0.3bn', '3,9 mil milhões de euros declarados', '0,3 mil milhões de euros' ),
						self::fig( 'debt_to_equity', 'bad', '1.9x', '0.7x' ),
						self::fig( 'cash_conversion', 'bad', '0.3x', '0.9x' ),
						self::fig( 'related_party', 'bad', '12 transactions with entities controlled by the founding family', '1 transaction', '12 transações com entidades controladas pela família fundadora', '1 transação' ),
					),
					'headlines'    => array(
						array( 'Family-controlled dairy group expands across three continents', 'Grupo de lacticínios controlado por uma família expande-se por três continentes' ),
						array( 'Bondholders ask why a company holding this much cash keeps borrowing', 'Obrigacionistas perguntam porque é que uma empresa com tanta caixa continua a endividar-se' ),
						array( 'Auditors change as the group restructures its offshore subsidiaries', 'Os auditores mudam enquanto o grupo reestrutura as filiais offshore' ),
					),
					'aftermath'    => array( 'A company holding that much cash does not need to keep borrowing at that rate, and the contradiction was the finding. The cash was not there; the accounts were restated, the founder was convicted, and shareholders lost almost everything while the European index rose strongly.', 'Uma empresa com tanta caixa não precisa de continuar a endividar-se àquele ritmo, e a contradição era a conclusão. A caixa não existia; as contas foram reexpressas, o fundador foi condenado, e os acionistas perderam quase tudo enquanto o índice europeu subiu com força.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Satyam Computer Services',
					'year'         => 2008,
					'sector'       => 'it_services',
					'pattern'      => 'fraud',
					'variant'      => 0,
					'band'         => array( '$1bn–$5bn', '1 a 5 mil milhões de dólares' ),
					'returns'      => array( -7500, 11000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '46%', '26%' ),
						self::fig( 'operating_margin', 'good', '27%', '22%' ),
						self::fig( 'cash_confirmation', 'bad', '$1.1bn, confirmed only by the company\'s own statements', 'confirmed directly by the banks', '1,1 mil milhões de dólares, confirmados apenas pelos mapas da própria empresa', 'confirmados diretamente pelos bancos' ),
						self::fig( 'receivables_days', 'bad', '118 days', '64 days', '118 dias', '64 dias' ),
						self::fig( 'audit_opinion', 'warn', 'unqualified, no matter emphasised', 'unqualified', 'sem reservas, sem matérias realçadas', 'sem reservas' ),
						self::fig( 'return_on_equity', 'good', '31%', '24%' ),
					),
					'headlines'    => array(
						array( 'Outsourcing group wins a global award for corporate governance', 'Grupo de outsourcing ganha um prémio global de governo societário' ),
						array( 'The board approves buying two construction firms owned by the founder\'s family', 'A administração aprova a compra de duas construtoras detidas pela família do fundador' ),
						array( 'Institutional shareholders object and the purchase is withdrawn within a day', 'Acionistas institucionais opõem-se e a compra é retirada num dia' ),
					),
					'aftermath'    => array( 'The margins looked real and the cash was not: the balance was never confirmed by the banks that were supposed to be holding it. The founder admitted the accounts had been inflated for years, the shares fell by three quarters in a day, and what was left of the company was sold to a rival.', 'As margens pareciam reais e a caixa não era: o saldo nunca foi confirmado pelos bancos que supostamente o guardavam. O fundador admitiu que as contas estavam inflacionadas havia anos, as ações caíram três quartos num dia, e o que restava da empresa foi vendido a um rival.' ),
				)
			),
			self::def(
				array(
					'company'      => 'WorldCom',
					'year'         => 2001,
					'sector'       => 'telecoms',
					'pattern'      => 'accounting_change',
					'variant'      => 0,
					'band'         => array( 'Over $25bn', 'Mais de 25 mil milhões de dólares' ),
					'returns'      => array( -10000, 3500 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'warn', '-9%', '-6%' ),
						self::fig( 'operating_margin', 'good', '13%', '8%' ),
						self::fig( 'capex_intensity', 'bad', '22%', '14%' ),
						self::fig( 'accounting_policy', 'bad', 'line costs reclassified from expense to capital spending', 'no change in the year', 'custos de linha reclassificados de gasto para investimento', 'sem alterações no ano' ),
						self::fig( 'free_cash_flow', 'bad', '-$4.1bn', '-$0.6bn', '-4,1 mil milhões de dólares', '-0,6 mil milhões de dólares' ),
						self::fig( 'net_debt', 'bad', '$30bn', '$9bn', '30 mil milhões de dólares', '9 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Long-distance carrier holds its margins while rivals warn on profits', 'Operadora de longa distância mantém as margens enquanto rivais avisam para quebras de lucros' ),
						array( 'Capital spending on networks continues after the fibre boom ends', 'O investimento em redes continua depois de terminado o boom da fibra' ),
						array( 'Debt markets close to carriers as network capacity goes unused', 'Os mercados de dívida fecham-se às operadoras com capacidade de rede por usar' ),
					),
					'aftermath'    => array( 'The margin held because an ordinary running cost had been moved on to the balance sheet, where it turned into depreciation spread over years. The restatement was the largest of its kind at the time, the company filed for bankruptcy and shareholders were left with nothing.', 'A margem aguentou-se porque um custo corrente comum tinha sido levado para o balanço, onde se transformou em amortizações repartidas por vários anos. A reexpressão foi a maior do género até então, a empresa entrou em falência e os acionistas ficaram sem nada.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Tesco',
					'year'         => 2013,
					'sector'       => 'grocery',
					'pattern'      => 'accounting_change',
					'variant'      => 1,
					'band'         => array( '£50bn–£75bn', '50 a 75 mil milhões de libras' ),
					'returns'      => array( -4000, 2200 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'warn', '2%', '3%' ),
						self::fig( 'operating_margin', 'good', '5.2%', '4.1%' ),
						self::fig( 'accounting_policy', 'bad', 'supplier income recognised earlier in the year', 'no change in the year', 'rendimentos de fornecedores reconhecidos mais cedo no ano', 'sem alterações no ano' ),
						self::fig( 'same_store_sales', 'warn', '-1%', '0%' ),
						self::fig( 'working_capital', 'bad', '£0.6bn released from working capital', 'no material movement', '0,6 mil milhões de libras libertados do fundo de maneio', 'sem movimento material' ),
						self::fig( 'net_debt', 'warn', '£6.6bn', '£2.1bn', '6,6 mil milhões de libras', '2,1 mil milhões de libras' ),
					),
					'headlines'    => array(
						array( 'Grocer withdraws from an overseas market after years of investment', 'Retalhista alimentar retira-se de um mercado externo após anos de investimento' ),
						array( 'Discount chains take share in almost every week of the year', 'Cadeias de desconto ganham quota em quase todas as semanas do ano' ),
						array( 'Suppliers describe tougher terms for shelf space and promotions', 'Fornecedores descrevem condições mais duras por espaço em prateleira e promoções' ),
					),
					'aftermath'    => array( 'Two years later the group announced that profit had been overstated by recognising supplier payments too early, the market regulator and the fraud office opened investigations, and the shares lost about two fifths of their value while the domestic index rose.', 'Dois anos depois o grupo anunciou que o lucro tinha sido sobreavaliado por reconhecer cedo demais pagamentos de fornecedores, o regulador de mercado e a autoridade de investigação abriram inquéritos, e as ações perderam cerca de dois quintos do valor enquanto o índice nacional subiu.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Freeport-McMoRan',
					'year'         => 2007,
					'sector'       => 'copper_gold_mining',
					'pattern'      => 'cyclical_peak',
					'variant'      => 0,
					'band'         => array( '$15bn–$25bn', '15 a 25 mil milhões de dólares' ),
					'returns'      => array( -2500, 900 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '170%', '38%' ),
						self::fig( 'operating_margin', 'good', '38%', '24%' ),
						self::fig( 'commodity_exposure', 'bad', '78% of revenue from copper', '45%', '78% das receitas do cobre', '45%' ),
						self::fig( 'mid_cycle_earnings', 'bad', '2.6x the ten-year average', '1.1x', '2,6x a média de dez anos', '1,1x' ),
						self::fig( 'net_debt_ebitda', 'warn', '1.4x', '0.9x' ),
						self::fig( 'pe_ratio', 'warn', '11x', '15x' ),
					),
					'headlines'    => array(
						array( 'Copper sets another record as building demand outruns supply', 'O cobre bate novo recorde com a procura da construção a superar a oferta' ),
						array( 'Miner completes a debt-funded purchase of a larger rival', 'Mineira conclui a compra, financiada com dívida, de um rival maior' ),
						array( 'Producers say the cycle has further to run this time', 'Produtores dizem que desta vez o ciclo ainda tem muito para andar' ),
					),
					'aftermath'    => array( 'The earnings on the page were the earnings the cycle handed over that year, and the low multiple was the same price with a flattering denominator. Copper fell by about two thirds within eighteen months, the debt taken on at the top stayed, and five years later the shares were worth a quarter less while the index was slightly up.', 'Os lucros da página eram os lucros que o ciclo entregou naquele ano, e o múltiplo baixo era o mesmo preço com um denominador lisonjeiro. O cobre caiu cerca de dois terços em ano e meio, a dívida assumida no topo ficou, e cinco anos depois as ações valiam menos um quarto enquanto o índice subia ligeiramente.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Peabody Energy',
					'year'         => 2011,
					'sector'       => 'coal_mining',
					'pattern'      => 'cyclical_peak',
					'variant'      => 1,
					'band'         => array( '$5bn–$10bn', '5 a 10 mil milhões de dólares' ),
					'returns'      => array( -9900, 9800 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '18%', '9%' ),
						self::fig( 'operating_margin', 'good', '17%', '12%' ),
						self::fig( 'price_realisation', 'good', '24% above the year before', '9% above', '24% acima do ano anterior', '9% acima' ),
						self::fig( 'capacity_utilisation', 'good', '96%', '88%' ),
						self::fig( 'net_debt_ebitda', 'bad', '2.8x', '1.5x' ),
						self::fig( 'interest_cover', 'warn', '5.1x', '8.0x' ),
					),
					'headlines'    => array(
						array( 'Seaborne coal reaches a record price after flooding closes export mines', 'O carvão marítimo atinge um preço recorde depois de cheias fecharem minas de exportação' ),
						array( 'Producer outbids a rival for an Australian coking coal group', 'Produtora supera a oferta de um rival por um grupo australiano de carvão de coque' ),
						array( 'Utilities sign long-term supply contracts expecting tight markets', 'Elétricas assinam contratos de fornecimento de longo prazo à espera de mercados apertados' ),
					),
					'aftermath'    => array( 'Price, volume and utilisation were all at the top of the cycle; the debt taken on to buy at that point was not. Coal prices fell for four straight years, the interest bill did not, and the company filed for bankruptcy protection with the old shares almost worthless while the index nearly doubled.', 'O preço, o volume e a utilização estavam todos no topo do ciclo; a dívida assumida para comprar nesse momento não estava. Os preços do carvão caíram quatro anos seguidos, os juros não, e a empresa pediu proteção contra credores com as ações antigas quase sem valor enquanto o índice quase duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Valeant Pharmaceuticals',
					'year'         => 2015,
					'sector'       => 'specialty_pharma',
					'pattern'      => 'rollup',
					'variant'      => 0,
					'band'         => array( '$10bn–$15bn', '10 a 15 mil milhões de dólares' ),
					'returns'      => array( -8000, 10300 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '65%', '8%' ),
						self::fig( 'organic_growth', 'warn', '9%', '6%' ),
						self::fig( 'acquisition_spend', 'bad', '$14.4bn in the year', '$0.6bn', '14,4 mil milhões de dólares no ano', '0,6 mil milhões de dólares' ),
						self::fig( 'goodwill_share', 'bad', '68% of total assets', '31%', '68% do ativo total', '31%' ),
						self::fig( 'net_debt_ebitda', 'bad', '5.6x', '1.9x' ),
						self::fig( 'cash_conversion', 'bad', '0.5x', '1.0x' ),
					),
					'headlines'    => array(
						array( 'Drug group buys a gastrointestinal specialist in its largest deal yet', 'Grupo farmacêutico compra uma especialista em gastroenterologia no seu maior negócio até à data' ),
						array( 'Price rises on acquired medicines draw questions from lawmakers', 'Aumentos de preços em medicamentos adquiridos levantam questões de legisladores' ),
						array( 'Short sellers ask how much of the growth would exist without the deals', 'Vendedores a descoberto perguntam quanto deste crescimento existiria sem os negócios' ),
					),
					'aftermath'    => array( 'Growth that is bought has to keep being bought, and the debt raised to buy it does not go away when the buying stops. Questions about a distribution arrangement and about the price rises ended the model within months; the shares lost about four fifths of their value while the index doubled.', 'O crescimento que se compra tem de continuar a ser comprado, e a dívida contraída para o comprar não desaparece quando as compras param. As perguntas sobre um acordo de distribuição e sobre os aumentos de preços acabaram com o modelo em poucos meses; as ações perderam cerca de quatro quintos do valor enquanto o índice duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Tyco International',
					'year'         => 1999,
					'sector'       => 'diversified_industrial',
					'pattern'      => 'rollup',
					'variant'      => 1,
					'band'         => array( 'Over $20bn', 'Mais de 20 mil milhões de dólares' ),
					'returns'      => array( -1200, -1100 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '83%', '7%' ),
						self::fig( 'organic_growth', 'warn', '7%', '6%' ),
						self::fig( 'acquisition_spend', 'bad', '$5.3bn in the year', '$0.3bn', '5,3 mil milhões de dólares no ano', '0,3 mil milhões de dólares' ),
						self::fig( 'goodwill_share', 'bad', '44% of total assets', '18%', '44% do ativo total', '18%' ),
						self::fig( 'operating_margin', 'good', '16%', '11%' ),
						self::fig( 'provisions', 'bad', '$1.2bn taken against acquired contracts', '$0.1bn', '1,2 mil milhões de dólares constituídos sobre contratos adquiridos', '0,1 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Conglomerate completes its fortieth acquisition of the year', 'Conglomerado conclui a quadragésima aquisição do ano' ),
						array( 'A research firm questions how the merger charges are accounted for', 'Uma casa de estudos questiona a forma como os encargos de fusão são contabilizados' ),
						array( 'Management says the model works because integration takes weeks, not years', 'A gestão diz que o modelo funciona porque a integração leva semanas e não anos' ),
					),
					'aftermath'    => array( 'Charges taken at the moment of purchase flattered the years that followed, and the questions arrived before the accounting did. Investigations and prosecutions followed, two executives were convicted, and five years later the shares were worth slightly less than at the start against an index that had also fallen.', 'Os encargos assumidos no momento da compra embelezavam os anos seguintes, e as perguntas chegaram antes da contabilidade. Seguiram-se investigações e processos, dois administradores foram condenados, e cinco anos depois as ações valiam um pouco menos do que no início, num índice que também tinha caído.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Northern Rock',
					'year'         => 2006,
					'sector'       => 'mortgage_lending',
					'pattern'      => 'leverage_rates',
					'variant'      => 0,
					'band'         => array( '£1bn–£2bn of income', '1 a 2 mil milhões de libras de proveitos' ),
					'returns'      => array( -10000, 600 ),
					'fundamentals' => array(
						self::fig( 'loan_growth', 'good', '23%', '9%' ),
						self::fig( 'funding_mix', 'bad', '71% raised in wholesale markets', '34%', '71% obtido nos mercados grossistas', '34%' ),
						self::fig( 'debt_maturity', 'bad', '48% falling due within a year', '22%', '48% a vencer no prazo de um ano', '22%' ),
						self::fig( 'equity_to_assets', 'bad', '2.2%', '4.5%' ),
						self::fig( 'return_on_equity', 'good', '24%', '15%' ),
						self::fig( 'dividend_payout', 'warn', '48%', '40%' ),
					),
					'headlines'    => array(
						array( 'Mortgage lender writes one in every five new home loans', 'Credor hipotecário concede um em cada cinco novos créditos à habitação' ),
						array( 'Securitisation markets described as deep and permanent', 'Mercados de titularização descritos como profundos e permanentes' ),
						array( 'Regulator approves the bank\'s new capital model and a larger dividend', 'Regulador aprova o novo modelo de capital do banco e um dividendo maior' ),
					),
					'aftermath'    => array( 'The model needed the wholesale market to stay open every single quarter, and one summer it did not. Savers queued outside the branches within a year, the bank was taken into public ownership, and shareholders received nothing while the index ended the five years roughly flat.', 'O modelo precisava de que o mercado grossista estivesse aberto em todos os trimestres, e num verão não esteve. Menos de um ano depois havia filas de aforradores à porta dos balcões, o banco foi nacionalizado, e os acionistas não receberam nada enquanto o índice terminou os cinco anos praticamente na mesma.' ),
				)
			),
			self::def(
				array(
					'company'      => 'SVB Financial Group',
					'year'         => 2021,
					'sector'       => 'regional_banking',
					'pattern'      => 'leverage_rates',
					'variant'      => 1,
					'band'         => array( '$5bn–$10bn of income', '5 a 10 mil milhões de dólares de proveitos' ),
					'returns'      => array( -9900, 5000 ),
					'fundamentals' => array(
						self::fig( 'loan_growth', 'good', '31%', '8%' ),
						self::fig( 'fixed_rate_assets', 'bad', '57% of assets', '19%', '57% do ativo', '19%' ),
						self::fig( 'unrealised_marks', 'bad', '-$1.3bn against amortised cost', '-$0.1bn', '-1,3 mil milhões de dólares face ao custo amortizado', '-0,1 mil milhões de dólares' ),
						self::fig( 'depositor_concentration', 'bad', '88% of deposits above the insured limit', '42%', '88% dos depósitos acima do limite garantido', '42%' ),
						self::fig( 'equity_to_assets', 'warn', '8.1%', '9.4%' ),
						self::fig( 'return_on_equity', 'good', '17%', '11%' ),
					),
					'headlines'    => array(
						array( 'Deposits from technology start-ups double in two years', 'Depósitos de start-ups tecnológicas duplicam em dois anos' ),
						array( 'The bank invests the inflow in long-dated government-backed securities', 'O banco investe a entrada em títulos de longo prazo com garantia pública' ),
						array( 'Central bankers describe the rise in prices as likely to be temporary', 'Bancos centrais descrevem a subida dos preços como provavelmente temporária' ),
					),
					'aftermath'    => array( 'The balance sheet worked at one interest rate. When rates rose the securities lost value, and the depositors — most of them above the insured limit and most of them in one industry — moved at the same time. Regulators closed the bank within fifteen months and almost nothing was left for the holding company\'s shareholders.', 'O balanço funcionava a uma taxa de juro. Quando as taxas subiram, os títulos perderam valor e os depositantes — quase todos acima do limite garantido e quase todos do mesmo setor — moveram-se ao mesmo tempo. Os reguladores fecharam o banco em quinze meses e quase nada sobrou para os acionistas da holding.' ),
				)
			),
			self::def(
				array(
					'company'      => 'SLM Corporation',
					'year'         => 2006,
					'sector'       => 'student_lending',
					'pattern'      => 'regulatory_moat',
					'variant'      => 0,
					'band'         => array( '$5bn–$10bn of income', '5 a 10 mil milhões de dólares de proveitos' ),
					'returns'      => array( -7000, -100 ),
					'fundamentals' => array(
						self::fig( 'regulated_revenue', 'warn', '76% from federally guaranteed lending', '12%', '76% de crédito com garantia federal', '12%' ),
						self::fig( 'loan_growth', 'good', '17%', '7%' ),
						self::fig( 'return_on_equity', 'good', '38%', '16%' ),
						self::fig( 'funding_mix', 'bad', '83% raised in wholesale markets', '38%', '83% obtido nos mercados grossistas', '38%' ),
						self::fig( 'equity_to_assets', 'bad', '3.1%', '7.4%' ),
						self::fig( 'dividend_payout', 'warn', '42%', '35%' ),
					),
					'headlines'    => array(
						array( 'Guaranteed student lending grows for a tenth consecutive year', 'O crédito ao estudante com garantia pública cresce pelo décimo ano consecutivo' ),
						array( 'Legislators debate cutting the subsidy paid to private lenders', 'Legisladores debatem cortar o subsídio pago aos credores privados' ),
						array( 'A buyout group agrees to take the company private', 'Um grupo de capital de investimento acorda retirar a empresa de bolsa' ),
					),
					'aftermath'    => array( 'The margin came from a subsidy, and a subsidy can be withdrawn by the people who wrote it. The buyout collapsed, the guarantee programme was ended and new lending moved directly to the government; the shares lost about seven tenths of their value while the index ended the five years flat.', 'A margem vinha de um subsídio, e um subsídio pode ser retirado por quem o escreveu. A compra caiu, o programa de garantia acabou e o novo crédito passou a ser concedido diretamente pelo Estado; as ações perderam cerca de sete décimos do valor enquanto o índice terminou os cinco anos na mesma.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Moody\'s',
					'year'         => 2006,
					'sector'       => 'credit_ratings',
					'pattern'      => 'regulatory_moat',
					'variant'      => 1,
					'band'         => array( '$1bn–$5bn', '1 a 5 mil milhões de dólares' ),
					'returns'      => array( -5000, -100 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '18%', '9%' ),
						self::fig( 'operating_margin', 'good', '55%', '19%' ),
						self::fig( 'regulated_revenue', 'warn', '94% from ratings that regulation requires somebody to hold', '8%', '94% de notações que a regulação obriga alguém a ter', '8%' ),
						self::fig( 'roic', 'good', '148%', '14%' ),
						self::fig( 'customer_concentration', 'good', '6% of revenue from the largest issuer', '9%', '6% das receitas do maior emitente', '9%' ),
						self::fig( 'buyback', 'warn', '$1.6bn spent buying back shares', '$0.2bn', '1,6 mil milhões de dólares gastos na recompra de ações', '0,2 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Demand for ratings climbs with the volume of structured finance', 'A procura por notações sobe com o volume da finança estruturada' ),
						array( 'Rules require many institutional buyers to hold a rating from a recognised agency', 'As regras obrigam muitos compradores institucionais a exigir notação de uma agência reconhecida' ),
						array( 'Issuers choose and pay the agency that rates their securities', 'Os emitentes escolhem e pagam a agência que classifica os seus títulos' ),
					),
					'aftermath'    => array( 'The moat held — very few businesses are allowed to do this at all — but the volume behind those margins was structured finance, and that market stopped. Earnings roughly halved, the legal and political cost arrived, and five years later the shares were worth half what they cost while the index was flat.', 'O fosso aguentou-se — muito poucos negócios podem sequer fazer isto — mas o volume que sustentava aquelas margens era finança estruturada, e esse mercado parou. Os lucros caíram para cerca de metade, chegou o custo legal e político, e cinco anos depois as ações valiam metade do que tinham custado com o índice na mesma.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Lucent Technologies',
					'year'         => 1999,
					'sector'       => 'telecom_equipment',
					'pattern'      => 'cash_vs_earnings',
					'variant'      => 0,
					'band'         => array( 'Over $25bn', 'Mais de 25 mil milhões de dólares' ),
					'returns'      => array( -9500, -1100 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '20%', '12%' ),
						self::fig( 'operating_margin', 'good', '13%', '9%' ),
						self::fig( 'receivables_days', 'bad', '104 days', '62 days', '104 dias', '62 dias' ),
						self::fig( 'vendor_financing', 'bad', '$1.6bn of sales financed by the company itself', '$0.1bn', '1,6 mil milhões de dólares de vendas financiadas pela própria empresa', '0,1 mil milhões de dólares' ),
						self::fig( 'cash_conversion', 'bad', '0.4x', '0.9x' ),
						self::fig( 'inventory_days', 'bad', '77 days', '51 days', '77 dias', '51 dias' ),
					),
					'headlines'    => array(
						array( 'Equipment maker beats its earnings target for a twelfth consecutive quarter', 'Fabricante de equipamento supera a meta de resultados pelo décimo segundo trimestre consecutivo' ),
						array( 'New operators build networks with money lent to them by their suppliers', 'Novos operadores constroem redes com dinheiro emprestado pelos próprios fornecedores' ),
						array( 'Order books described as full into the next century', 'Carteiras de encomendas descritas como cheias até ao século seguinte' ),
					),
					'aftermath'    => array( 'The profit was recognised and the cash was not there, because the customers had been lent the money they were buying with. When the new operators could not raise any more, the orders and the amounts owed went together; the shares lost about nineteen twentieths of their value over the five years that followed.', 'O lucro era reconhecido e a caixa não estava lá, porque aos clientes tinha sido emprestado o dinheiro com que compravam. Quando os novos operadores deixaram de conseguir financiar-se, as encomendas e os valores a receber foram juntos; as ações perderam cerca de dezanove vigésimos do valor nos cinco anos seguintes.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Carillion',
					'year'         => 2016,
					'sector'       => 'construction_services',
					'pattern'      => 'cash_vs_earnings',
					'variant'      => 1,
					'band'         => array( '£4bn–£6bn', '4 a 6 mil milhões de libras' ),
					'returns'      => array( -10000, 3000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '14%', '4%' ),
						self::fig( 'operating_margin', 'good', '4.6%', '3.1%' ),
						self::fig( 'unbilled_work', 'bad', '£1.6bn of work done and not yet billed', '£0.3bn', '1,6 mil milhões de libras de trabalho executado e ainda não faturado', '0,3 mil milhões de libras' ),
						self::fig( 'cash_conversion', 'bad', '0.3x', '0.9x' ),
						self::fig( 'pension_deficit', 'bad', '£0.8bn against a market value of £1.0bn', '£0.1bn', '0,8 mil milhões de libras face a um valor de mercado de 1,0 mil milhões', '0,1 mil milhões de libras' ),
						self::fig( 'dividend_cover', 'bad', '1.4x free cash flow', '0.5x', '1,4x o fluxo de caixa livre', '0,5x' ),
					),
					'headlines'    => array(
						array( 'Support services group wins a record volume of public contracts', 'Grupo de serviços de apoio ganha um volume recorde de contratos públicos' ),
						array( 'Bidding described as aggressive as competitors leave fixed-price work', 'Os concursos são descritos como agressivos com concorrentes a sair do trabalho a preço fixo' ),
						array( 'The board raises the dividend for a sixteenth consecutive year', 'A administração aumenta o dividendo pelo décimo sexto ano consecutivo' ),
					),
					'aftermath'    => array( 'Profit was recognised as work was done and the cash arrived, when it arrived, much later. Three contract write-downs in a single year emptied what was left, the banks stopped lending, and the company went into compulsory liquidation within eighteen months with shareholders receiving nothing.', 'O lucro era reconhecido à medida que o trabalho era feito e a caixa chegava, quando chegava, muito mais tarde. Três imparidades de contratos num único ano esvaziaram o que restava, os bancos pararam de emprestar, e a empresa entrou em liquidação em ano e meio sem nada para os acionistas.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Copart',
					'year'         => 2010,
					'sector'       => 'salvage_auctions',
					'pattern'      => 'boring_compounder',
					'variant'      => 0,
					'band'         => array( '$500m–$1bn', '500 milhões a 1 mil milhões de dólares' ),
					'returns'      => array( 11000, 8000 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '9%', '4%' ),
						self::fig( 'operating_margin', 'good', '33%', '12%' ),
						self::fig( 'roic', 'good', '24%', '10%' ),
						self::fig( 'free_cash_flow', 'good', '$180m', '$41m', '180 milhões de dólares', '41 milhões de dólares' ),
						self::fig( 'net_debt', 'good', 'none, and $200m of net cash', '$0.3bn of net debt', 'nenhuma, e 200 milhões de dólares de caixa líquida', '0,3 mil milhões de dólares de dívida líquida' ),
						self::fig( 'buyback', 'good', '$220m spent buying back shares', '$30m', '220 milhões de dólares gastos na recompra de ações', '30 milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Salvage yards consolidate quietly as insurers move their auctions online', 'Os parques de sinistrados consolidam-se discretamente com as seguradoras a levar os leilões para a internet' ),
						array( 'Analysts describe the business as unglamorous and hard to copy', 'Analistas descrevem o negócio como pouco atrativo e difícil de copiar' ),
						array( 'Few funds attend the company\'s investor day', 'Poucos fundos comparecem ao dia do investidor da empresa' ),
					),
					'aftermath'    => array( 'There was nothing interesting to say about it and nothing wrong with it. The same margins, the same buybacks and the same land compounded quietly; over the next five years the shares roughly doubled, ahead of an index that itself did well.', 'Não havia nada de interessante para dizer sobre ele e nada de errado com ele. As mesmas margens, as mesmas recompras e os mesmos terrenos foram compondo em silêncio; nos cinco anos seguintes as ações praticamente duplicaram, à frente de um índice que também se portou bem.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Cintas',
					'year'         => 2006,
					'sector'       => 'uniform_services',
					'pattern'      => 'boring_compounder',
					'variant'      => 1,
					'band'         => array( '$3bn–$5bn', '3 a 5 mil milhões de dólares' ),
					'returns'      => array( -300, -100 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '9%', '5%' ),
						self::fig( 'operating_margin', 'good', '15%', '8%' ),
						self::fig( 'roic', 'good', '13%', '9%' ),
						self::fig( 'free_cash_flow', 'good', '$260m', '$70m', '260 milhões de dólares', '70 milhões de dólares' ),
						self::fig( 'dividend_cover', 'good', '0.2x free cash flow', '0.6x', '0,2x o fluxo de caixa livre', '0,6x' ),
						self::fig( 'customer_concentration', 'good', 'no customer above 1% of revenue', '9%', 'nenhum cliente acima de 1% das receitas', '9%' ),
					),
					'headlines'    => array(
						array( 'Uniform rental group raises its dividend for a thirty-seventh year', 'Grupo de aluguer de fardamento aumenta o dividendo pelo trigésimo sétimo ano' ),
						array( 'Route density is described as the reason new entrants struggle', 'A densidade das rotas é apontada como a razão pela qual novos concorrentes têm dificuldade' ),
						array( 'Industrial services shares out of favour as investors look to housing and credit', 'Ações de serviços industriais em desfavor com os investidores voltados para o imobiliário e o crédito' ),
					),
					'aftermath'    => array( 'The business did what it had always done, through a recession that cut the number of people wearing its uniforms. The shares ended the five years roughly where they started, about level with an index that had been through the same crisis — and then went on compounding, which is the part a five-year window cannot show.', 'O negócio fez o que sempre tinha feito, atravessando uma recessão que reduziu o número de pessoas a usar as suas fardas. As ações terminaram os cinco anos praticamente onde tinham começado, a par de um índice que passou pela mesma crise — e depois continuaram a compor, que é a parte que uma janela de cinco anos não consegue mostrar.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Apple',
					'year'         => 1997,
					'sector'       => 'personal_computers',
					'pattern'      => 'turnaround_worked',
					'variant'      => 0,
					'band'         => array( '$5bn–$10bn', '5 a 10 mil milhões de dólares' ),
					'returns'      => array( 12000, -300 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'bad', '-28%', '8%' ),
						self::fig( 'gross_margin', 'bad', '19%', '22%' ),
						self::fig( 'operating_margin', 'bad', '-15%', '5%' ),
						self::fig( 'cash_runway', 'bad', '11 months', 'over 36 months', '11 meses', 'mais de 36 meses' ),
						self::fig( 'market_share', 'bad', '3%', '9%' ),
						self::fig( 'rnd_intensity', 'good', '6%', '4%' ),
					),
					'headlines'    => array(
						array( 'Computer maker cuts its product line from fifteen machines to four', 'Fabricante de computadores corta a linha de produtos de quinze máquinas para quatro' ),
						array( 'A rival invests in the company and the patent dispute is settled', 'Um rival investe na empresa e a disputa de patentes é resolvida' ),
						array( 'Commentators ask how many months of cash are left', 'Comentadores perguntam quantos meses de caixa restam' ),
					),
					'aftermath'    => array( 'Almost every figure on the page was bad, and the two things that had changed were not on it: what the company had decided to stop making, and who had come back to run it. The losses ended within a year, and over the following five years the shares roughly doubled against an index that fell.', 'Quase todos os números da página eram maus, e as duas coisas que tinham mudado não estavam lá: o que a empresa tinha decidido deixar de fazer, e quem tinha voltado para a dirigir. Os prejuízos acabaram em menos de um ano e, nos cinco anos seguintes, as ações praticamente duplicaram num índice que caiu.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Domino\'s Pizza',
					'year'         => 2009,
					'sector'       => 'restaurant_franchising',
					'pattern'      => 'turnaround_worked',
					'variant'      => 1,
					'band'         => array( '$1bn–$2bn', '1 a 2 mil milhões de dólares' ),
					'returns'      => array( 82000, 10500 ),
					'fundamentals' => array(
						self::fig( 'same_store_sales', 'good', '1%', '-2%' ),
						self::fig( 'store_count', 'good', '8,900 outlets', '3,100 outlets', '8900 lojas', '3100 lojas' ),
						self::fig( 'franchise_share', 'good', '90% run by franchisees', '58%', '90% exploradas por franquiados', '58%' ),
						self::fig( 'operating_margin', 'good', '13%', '9%' ),
						self::fig( 'net_debt_ebitda', 'bad', '5.4x', '2.1x' ),
						self::fig( 'free_cash_flow', 'good', '$85m', '$34m', '85 milhões de dólares', '34 milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Pizza chain says in its own advertising that the recipe was not good enough', 'Cadeia de pizzas diz na própria publicidade que a receita não era suficientemente boa' ),
						array( 'Debt taken on in an earlier recapitalisation still weighs on the balance sheet', 'A dívida assumida numa recapitalização anterior continua a pesar no balanço' ),
						array( 'Delivery orders move to the internet faster than the industry expected', 'As encomendas para entrega passam para a internet mais depressa do que o setor esperava' ),
					),
					'aftermath'    => array( 'The recipe change showed up in sales at the same outlets within two quarters, and because almost every shop was run by a franchisee the recovery reached profit almost immediately. Over the following five years the shares rose many times over, far ahead of an index that itself doubled.', 'A mudança da receita apareceu nas vendas das mesmas lojas ao fim de dois trimestres e, como quase todas as lojas eram de franquiados, a recuperação chegou ao lucro quase de imediato. Nos cinco anos seguintes as ações subiram várias vezes, muito à frente de um índice que também duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Eastman Kodak',
					'year'         => 2005,
					'sector'       => 'imaging',
					'pattern'      => 'turnaround_failed',
					'variant'      => 0,
					'band'         => array( '$10bn–$15bn', '10 a 15 mil milhões de dólares' ),
					'returns'      => array( -7700, 1200 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'bad', '-1%', '6%' ),
						self::fig( 'legacy_revenue', 'bad', '58% from film and photographic paper', '22%', '58% de película e papel fotográfico', '22%' ),
						self::fig( 'gross_margin', 'bad', '23%', '34%' ),
						self::fig( 'free_cash_flow', 'bad', '-$0.6bn', '$0.4bn', '-0,6 mil milhões de dólares', '0,4 mil milhões de dólares' ),
						self::fig( 'pension_deficit', 'warn', '$1.4bn against a market value of $6.7bn', '$0.2bn', '1,4 mil milhões de dólares face a um valor de mercado de 6,7 mil milhões', '0,2 mil milhões de dólares' ),
						self::fig( 'net_debt', 'bad', '$2.4bn', '$0.5bn', '2,4 mil milhões de dólares', '0,5 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Photography group closes more film plants and moves into digital printing', 'Grupo de fotografia fecha mais fábricas de película e entra na impressão digital' ),
						array( 'Camera phones outsell compact cameras for the first time', 'Os telemóveis com câmara vendem mais do que as câmaras compactas pela primeira vez' ),
						array( 'Management says the patent portfolio alone is worth billions', 'A gestão diz que só a carteira de patentes vale milhares de milhões' ),
					),
					'aftermath'    => array( 'The film business shrank faster than the digital one grew, which is a different thing from a recovery. Cash kept going out while the pension and the debt stayed, and the company filed for bankruptcy protection six years later; the shares lost more than three quarters of their value while the index was slightly up.', 'O negócio da película encolheu mais depressa do que o digital cresceu, o que é diferente de uma recuperação. A caixa continuou a sair enquanto o fundo de pensões e a dívida ficaram, e a empresa pediu proteção contra credores seis anos depois; as ações perderam mais de três quartos do valor enquanto o índice subia ligeiramente.' ),
				)
			),
			self::def(
				array(
					'company'      => 'J. C. Penney',
					'year'         => 2012,
					'sector'       => 'department_stores',
					'pattern'      => 'turnaround_failed',
					'variant'      => 1,
					'band'         => array( '$10bn–$15bn', '10 a 15 mil milhões de dólares' ),
					'returns'      => array( -8400, 10800 ),
					'fundamentals' => array(
						self::fig( 'same_store_sales', 'bad', '-25%', '2%' ),
						self::fig( 'gross_margin', 'bad', '31%', '38%' ),
						self::fig( 'operating_margin', 'bad', '-9%', '6%' ),
						self::fig( 'cash_burn', 'bad', '-$0.9bn', '$0.2bn', '-0,9 mil milhões de dólares', '0,2 mil milhões de dólares' ),
						self::fig( 'net_debt', 'warn', '$2.0bn', '$0.9bn', '2,0 mil milhões de dólares', '0,9 mil milhões de dólares' ),
						self::fig( 'store_count', 'warn', '1,100 outlets', '800 outlets', '1100 lojas', '800 lojas' ),
					),
					'headlines'    => array(
						array( 'Department store drops coupons and sales in favour of one everyday price', 'Grandes armazéns eliminam cupões e saldos a favor de um preço único diário' ),
						array( 'Long-standing customers say they miss the discounts', 'Clientes antigos dizem que sentem falta dos descontos' ),
						array( 'The chain borrows against its property to pay for the refit', 'A cadeia hipoteca os imóveis para pagar a remodelação' ),
					),
					'aftermath'    => array( 'The plan removed the reason a quarter of the customers came, and the refitted shops it was meant to fill stayed empty. The chief executive left within a year, the discounts came back, and five years later the shares had lost more than four fifths of their value while the index doubled.', 'O plano eliminou a razão pela qual um quarto dos clientes ia à loja, e as lojas remodeladas que era suposto encher ficaram vazias. O presidente executivo saiu em menos de um ano, os descontos voltaram, e cinco anos depois as ações tinham perdido mais de quatro quintos do valor enquanto o índice duplicou.' ),
				)
			),
			self::def(
				array(
					'company'      => 'GoPro',
					'year'         => 2014,
					'sector'       => 'action_cameras',
					'pattern'      => 'concentration',
					'variant'      => 0,
					'band'         => array( '$1bn–$2bn', '1 a 2 mil milhões de dólares' ),
					'returns'      => array( -9100, 7400 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '41%', '12%' ),
						self::fig( 'gross_margin', 'good', '45%', '34%' ),
						self::fig( 'product_concentration', 'bad', '94% of revenue from one camera line', '48%', '94% das receitas de uma única linha de câmaras', '48%' ),
						self::fig( 'rnd_intensity', 'warn', '10%', '8%' ),
						self::fig( 'inventory_days', 'warn', '68 days', '45 days', '68 dias', '45 dias' ),
						self::fig( 'marketing_intensity', 'warn', '13%', '11%' ),
					),
					'headlines'    => array(
						array( 'Camera maker lists on the stock market after a year of record sales', 'Fabricante de câmaras entra em bolsa após um ano de vendas recorde' ),
						array( 'Phone cameras improve again with wider lenses and better stabilisation', 'As câmaras dos telemóveis melhoram outra vez com lentes mais largas e melhor estabilização' ),
						array( 'The company describes itself as a media business as well as a hardware one', 'A empresa descreve-se como um negócio de media além de um negócio de equipamento' ),
					),
					'aftermath'    => array( 'One product line, sold to one kind of buyer, in a category the phone in that buyer\'s pocket was moving into. Growth stopped the following year, stock had to be written down, and the shares lost about nine tenths of their value while the index rose by three quarters.', 'Uma única linha de produto, vendida a um único tipo de comprador, numa categoria para onde o telemóvel do bolso desse comprador estava a entrar. O crescimento parou no ano seguinte, houve existências a abater, e as ações perderam cerca de nove décimos do valor enquanto o índice subiu três quartos.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Skyworks Solutions',
					'year'         => 2015,
					'sector'       => 'semiconductors',
					'pattern'      => 'concentration',
					'variant'      => 1,
					'band'         => array( '$3bn–$5bn', '3 a 5 mil milhões de dólares' ),
					'returns'      => array( 10000, 10300 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '42%', '9%' ),
						self::fig( 'gross_margin', 'warn', '47%', '44%' ),
						self::fig( 'operating_margin', 'good', '34%', '18%' ),
						self::fig( 'customer_concentration', 'bad', '44% of revenue from the largest customer', '14%', '44% das receitas do maior cliente', '14%' ),
						self::fig( 'capex_intensity', 'warn', '11%', '9%' ),
						self::fig( 'free_cash_flow', 'good', '$780m', '$210m', '780 milhões de dólares', '210 milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Chip supplier wins more content in the year\'s best-selling handset', 'Fornecedor de chips ganha mais conteúdo no telemóvel mais vendido do ano' ),
						array( 'Handset volumes are expected to keep growing in every major market', 'Espera-se que os volumes de telemóveis continuem a crescer em todos os grandes mercados' ),
						array( 'Suppliers say a single customer can change a component decision in one cycle', 'Fornecedores dizem que um único cliente pode mudar uma decisão de componente num só ciclo' ),
					),
					'aftermath'    => array( 'The margins were real and so was the dependence: almost half of revenue rested on one buyer\'s design decisions. The next five years brought a lost socket, a recovered one and a great deal of volatility, and the shares finished roughly level with the index.', 'As margens eram reais e a dependência também: quase metade das receitas assentava nas decisões de desenho de um único comprador. Os cinco anos seguintes trouxeram um lugar perdido, outro recuperado e muita volatilidade, e as ações terminaram praticamente a par do índice.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Plug Power',
					'year'         => 2014,
					'sector'       => 'fuel_cells',
					'pattern'      => 'dilution',
					'variant'      => 0,
					'band'         => array( 'Under $100m', 'Menos de 100 milhões de dólares' ),
					'returns'      => array( 1000, 7400 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '185%', '14%' ),
						self::fig( 'gross_margin', 'bad', '-9%', '24%' ),
						self::fig( 'cash_burn', 'bad', '-$52m', '-$8m', '-52 milhões de dólares', '-8 milhões de dólares' ),
						self::fig( 'equity_raised', 'bad', '$150m raised by issuing shares', '$12m', '150 milhões de dólares obtidos com a emissão de ações', '12 milhões de dólares' ),
						self::fig( 'share_count', 'bad', '34% more than the year before', '3% more', 'mais 34% do que no ano anterior', 'mais 3%' ),
						self::fig( 'cash_runway', 'warn', '18 months', 'over 36 months', '18 meses', 'mais de 36 meses' ),
					),
					'headlines'    => array(
						array( 'Fuel-cell maker signs its first large warehouse customer', 'Fabricante de células de combustível assina o primeiro grande cliente de armazenagem' ),
						array( 'Hydrogen described as the fuel of the next decade, for the fourth decade running', 'O hidrogénio é descrito como o combustível da próxima década, pela quarta década seguida' ),
						array( 'The company raises money again to pay for production', 'A empresa volta a angariar dinheiro para pagar a produção' ),
					),
					'aftermath'    => array( 'The company grew and each share came to represent less of it, because every year of production was paid for with new shares. Five years later revenue had more than doubled and the shares were worth about what they had been, while the index rose by three quarters.', 'A empresa cresceu e cada ação passou a representar menos dela, porque cada ano de produção foi pago com ações novas. Cinco anos depois as receitas tinham mais do que duplicado e as ações valiam sensivelmente o mesmo, enquanto o índice subia três quartos.' ),
				)
			),
			self::def(
				array(
					'company'      => 'Snap',
					'year'         => 2017,
					'sector'       => 'social_apps',
					'pattern'      => 'dilution',
					'variant'      => 1,
					'band'         => array( '$500m–$1bn', '500 milhões a 1 mil milhões de dólares' ),
					'returns'      => array( -3900, 5700 ),
					'fundamentals' => array(
						self::fig( 'revenue_growth', 'good', '104%', '28%' ),
						self::fig( 'gross_margin', 'bad', '-27%', '62%' ),
						self::fig( 'active_users', 'good', '187m people a day', '43m', '187 milhões de pessoas por dia', '43 milhões' ),
						self::fig( 'stock_comp', 'bad', '32% of revenue', '6% of revenue', '32% das receitas', '6% das receitas' ),
						self::fig( 'share_count', 'bad', '21% more than the year before', '2% more', 'mais 21% do que no ano anterior', 'mais 2%' ),
						self::fig( 'cash_burn', 'bad', '-$0.8bn', '-$0.1bn', '-0,8 mil milhões de dólares', '-0,1 mil milhões de dólares' ),
					),
					'headlines'    => array(
						array( 'Camera application lists with two classes of shares that carry no vote', 'Aplicação de câmara entra em bolsa com duas classes de ações sem direito de voto' ),
						array( 'A larger rival copies the disappearing-photo format across three of its apps', 'Um rival maior copia o formato das fotografias que desaparecem em três das suas aplicações' ),
						array( 'Daily user growth slows in the quarter after the listing', 'O crescimento diário de utilizadores abranda no trimestre a seguir à entrada em bolsa' ),
					),
					'aftermath'    => array( 'The audience kept growing and so did the number of shares: the pay bill was settled in stock, and each share came to represent less of the company. Revenue quadrupled over the next five years and the shares still ended lower than they started, against an index that rose by more than half.', 'A audiência continuou a crescer e o número de ações também: a despesa com pessoal era paga em ações, e cada ação passou a representar menos da empresa. As receitas quadruplicaram nos cinco anos seguintes e as ações ainda assim terminaram mais baixas do que começaram, num índice que subiu mais de metade.' ),
				)
			),
		);
	}

	/**
	 * One case definition, normalised.
	 *
	 * `metrics` is derived from the rows rather than typed a second time, so
	 * the research brief and the dossier can never end up describing different
	 * six questions — the brief maps a label to a line item, and a map of the
	 * wrong six is worse than no map.
	 *
	 * @param array<string,mixed> $def A case, as written in definitions().
	 * @return array<string,mixed>
	 */
	private static function def( array $def ): array {
		$def['metrics'] = array_map(
			static fn( array $row ): string => (string) $row['key'],
			array_slice( (array) $def['fundamentals'], 0, self::FUNDAMENTALS )
		);

		return $def;
	}

	/* =====================================================================
	 * The WordPress half. Everything above this line is pure data.
	 * ================================================================== */

	/**
	 * Install the shipped library. The one implementation behind both doors.
	 *
	 * There are two ways in — `wp hti-games seed-cases` for anyone with a
	 * shell, and the button on the settings screen for everyone else — and
	 * they must not be able to disagree about what "install the cases" means.
	 * The CLI passes a logger and gets its per-case narration; the button
	 * passes nothing and gets the tally. Neither owns the behaviour.
	 *
	 * Not sliced, unlike Installer. Thirty-four cases at twenty-four meta rows
	 * each is about two thousand queries — two thirds of one of that class's
	 * slices, which its own reasoning calls a fair ask of a single request. And
	 * the property that makes slicing unnecessary here is the same one that
	 * makes it safe: this is idempotent by company and year, so a run that dies
	 * on a slow host is resumed by pressing the button again, which skips
	 * whatever the first attempt managed to store.
	 *
	 * @param callable|null $log Optional per-case reporter, called with a line.
	 * @return array{created:int,skipped:int,failed:int,total:int}
	 */
	public static function install( ?callable $log = null ): array {
		$report = array(
			'created' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'total'   => 0,
		);

		foreach ( self::cases() as $case ) {
			++$report['total'];
			$company = (string) $case['company'];
			$year    = (int) $case['year'];

			if ( self::exists( $company, $year ) ) {
				++$report['skipped'];
				if ( $log ) {
					$log( sprintf( '%s %d — already present, left alone.', $company, $year ) );
				}
				continue;
			}

			if ( self::create( $case ) ) {
				++$report['created'];
				if ( $log ) {
					$log( sprintf( '%s %d — published, illustrative (%s).', $company, $year, $case['pattern'] ) );
				}
				continue;
			}

			++$report['failed'];
			if ( $log ) {
				$log( sprintf( '%s %d — could not be created.', $company, $year ) );
			}
		}

		return $report;
	}

	/**
	 * `wp hti-games seed-cases`
	 *
	 * Inserts the library as published, illustrative cases — complete dossiers
	 * whose figures are reconstructions, each one saying so on the screen the
	 * player reads. Published rather than drafted because a case library that
	 * cannot be played is not a case library, and because the publish gate is
	 * what decides whether a case may be served: these meet its illustrative
	 * conditions, so leaving them in draft would be withholding them from the
	 * game for a reason the gate does not have.
	 *
	 * Idempotent by company and year: a case already in the database is left
	 * exactly as an editor left it, because re-running a seeder must never
	 * overwrite the work the seeder exists to make possible — including a case
	 * somebody has already promoted to verified.
	 *
	 * @param array<int,string>    $args  Positional arguments (unused).
	 * @param array<string,string> $assoc Associative arguments (unused).
	 */
	public static function cli_seed( array $args, array $assoc ): void {
		unset( $args, $assoc );

		$report = self::install(
			static function ( string $line ): void {
				\WP_CLI::log( '  ' . $line );
			}
		);

		\WP_CLI::success( sprintf( '%d cases created, %d already present.', $report['created'], $report['skipped'] ) );
		\WP_CLI::log(
			'They are playable, and every one of them tells the player what its figures are: the company, the period and the direction of what happened are real, and the numbers and headlines were reconstructed to show the pattern. To turn one into a sourced case, open it, follow the research brief in the dossier box, replace every figure with what the document says, paste the source URL, switch it to verified and tick the box. From that moment the gate holds it to the verified standard.'
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
	 * Insert one case, then publish it.
	 *
	 * Two steps, and the order is forced rather than clumsy. Case_Admin::gate()
	 * runs on `wp_insert_post_data`, which fires BEFORE any meta exists, so a
	 * one-step insert with post_status 'publish' would be judged against an
	 * empty dossier and pushed straight back to draft. Writing the meta first
	 * and publishing second means the gate reads the case it is actually being
	 * asked about — and if it still refuses, the case stays a draft, which is
	 * the correct outcome and not something to work around.
	 *
	 * @param array{company:string,year:int,title:string,meta:array<string,mixed>} $case One row of cases().
	 * @return bool
	 */
	private static function create( array $case ): bool {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Config::CPT_CASE,
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
			// so this is the only place the values need to be assembled — and
			// it is what turns 'illustrative' into a stored provenance the
			// gate and the pool query can both read.
			update_post_meta( (int) $post_id, $key, $value );
		}

		wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_status' => 'publish',
			)
		);

		return true;
	}
}
