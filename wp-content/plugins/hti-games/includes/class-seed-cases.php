<?php
/**
 * The five prototype dossiers for The Reveal — seeded DELIBERATELY UNFINISHED.
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
 *    entitled to make one.
 *
 * What IS filled is the shape: the company, the year, the sector, the six
 * fundamental labels and the three headline slots. That is the difference
 * between handing an editor a form and handing them a blank page — they open
 * one filing and know exactly which six numbers they are looking for.
 *
 * The publish gate in class-case-admin.php will refuse all five until an
 * editor supplies a source URL and ticks verified. That is the intended
 * workflow and not an oversight: tests/test-seed-cases.php asserts that not one
 * of them is publishable, and that test is the guarantee that an unverified
 * claim about a real company cannot reach production by accident.
 *
 * UNVERIFIED PROTOTYPE NOTES — NOT DATA, NOT A SOURCE, NEVER COPIED INTO A POST.
 * The design handoff picked these five for their shape, not their arithmetic.
 * Roughly, and from recollection only: Amazon 2001 fell very hard and then
 * recovered very hard; Enron 2000 went to zero the following year; Nokia 2007
 * fell a long way over the following five; Coca-Cola 2010 did something modest
 * and index-like; Pets.com 1999 was liquidated in 2000. Directions only, with
 * no figures written down anywhere in this file — a precise number in a comment
 * is the number somebody eventually pastes into the box, and the whole point is
 * that the figure must come out of the filing the editor is looking at.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The five prototype cases as pure data, plus the CLI seeder. Pure data.
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
	 * The five prototype cases.
	 *
	 * Every key of CPT::case_meta() is present on every case, including the
	 * empty ones, so that "this field was left blank on purpose" is visible in
	 * the data rather than inferred from its absence.
	 *
	 * @return array<int,array{company:string,year:int,title:string,meta:array<string,mixed>}>
	 */
	public static function cases(): array {
		$out = array();

		foreach ( self::definitions() as $def ) {
			$out[] = array(
				'company' => $def['company'],
				'year'    => $def['year'],
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
		return array(
			'hti_rev_company'            => (string) $def['company'],
			'hti_rev_year'               => (int) $def['year'],
			'hti_rev_sector_en'          => (string) $def['sector_en'],
			'hti_rev_sector_pt'          => (string) $def['sector_pt'],

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

			// "What happened next" and the lesson are prose ABOUT the company.
			// They belong with the verified figures, written by whoever read
			// the source, and not with the skeleton.
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
	 * @param array<int,array{key:string,en:string,pt:string}> $rows Label set for this case.
	 * @return array<int,array<string,string>>
	 */
	public static function fundamentals( array $rows ): array {
		$out = array();

		foreach ( array_slice( $rows, 0, self::FUNDAMENTALS ) as $row ) {
			$out[] = array(
				'key'           => (string) $row['key'],
				'label_en'      => (string) $row['en'],
				'label_pt'      => (string) $row['pt'],
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

	/**
	 * The five prototypes: who, when, what sector, and which six numbers the
	 * editor should go and find.
	 *
	 * The fundamentals differ per case on purpose. "Months of cash remaining"
	 * is the whole story of one of these and meaningless for another, and a
	 * single generic six-row template would have quietly thrown that away.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions(): array {
		return array(
			array(
				'company'      => 'Amazon',
				'year'         => 2001,
				'sector_en'    => 'Online retail',
				'sector_pt'    => 'Retalho online',
				'fundamentals' => array(
					self::row( 'revenue_growth', 'Revenue growth', 'Crescimento das receitas' ),
					self::row( 'gross_margin', 'Gross margin', 'Margem bruta' ),
					self::row( 'operating_margin', 'Operating margin', 'Margem operacional' ),
					self::row( 'free_cash_flow', 'Free cash flow', 'Fluxo de caixa livre' ),
					self::row( 'net_debt', 'Net debt', 'Dívida líquida' ),
					self::row( 'cash', 'Cash and equivalents', 'Caixa e equivalentes' ),
				),
			),
			array(
				'company'      => 'Enron',
				'year'         => 2000,
				'sector_en'    => 'Energy and energy trading',
				'sector_pt'    => 'Energia e negociação de energia',
				'fundamentals' => array(
					self::row( 'revenue_growth', 'Revenue growth', 'Crescimento das receitas' ),
					self::row( 'operating_margin', 'Operating margin', 'Margem operacional' ),
					self::row( 'return_on_equity', 'Return on equity', 'Rendibilidade dos capitais próprios' ),
					self::row( 'debt_to_equity', 'Debt to equity', 'Dívida sobre capitais próprios' ),
					self::row( 'cash_conversion', 'Cash flow against reported profit', 'Fluxo de caixa face ao lucro declarado' ),
					self::row( 'off_balance_sheet', 'Obligations held off the balance sheet', 'Compromissos fora do balanço' ),
				),
			),
			array(
				'company'      => 'Nokia',
				'year'         => 2007,
				'sector_en'    => 'Consumer electronics and mobile handsets',
				'sector_pt'    => 'Eletrónica de consumo e telemóveis',
				'fundamentals' => array(
					self::row( 'revenue_growth', 'Revenue growth', 'Crescimento das receitas' ),
					self::row( 'operating_margin', 'Operating margin', 'Margem operacional' ),
					self::row( 'market_share', 'Share of its market', 'Quota do seu mercado' ),
					self::row( 'rnd_intensity', 'Research and development as a share of revenue', 'Investigação e desenvolvimento em percentagem das receitas' ),
					self::row( 'net_cash', 'Net cash', 'Caixa líquida' ),
					self::row( 'pe_ratio', 'Price against earnings', 'Preço face aos lucros' ),
				),
			),
			array(
				'company'      => 'Coca-Cola',
				'year'         => 2010,
				'sector_en'    => 'Beverages',
				'sector_pt'    => 'Bebidas',
				'fundamentals' => array(
					self::row( 'revenue_growth', 'Revenue growth', 'Crescimento das receitas' ),
					self::row( 'operating_margin', 'Operating margin', 'Margem operacional' ),
					self::row( 'return_on_equity', 'Return on equity', 'Rendibilidade dos capitais próprios' ),
					self::row( 'dividend_payout', 'Share of profit paid out as dividends', 'Percentagem do lucro distribuída em dividendos' ),
					self::row( 'net_debt_ebitda', 'Net debt against operating earnings', 'Dívida líquida face aos resultados operacionais' ),
					self::row( 'pe_ratio', 'Price against earnings', 'Preço face aos lucros' ),
				),
			),
			array(
				'company'      => 'Pets.com',
				'year'         => 1999,
				'sector_en'    => 'Online retail',
				'sector_pt'    => 'Retalho online',
				'fundamentals' => array(
					self::row( 'revenue_growth', 'Revenue growth', 'Crescimento das receitas' ),
					self::row( 'gross_margin', 'Gross margin', 'Margem bruta' ),
					self::row( 'operating_margin', 'Operating margin', 'Margem operacional' ),
					self::row( 'marketing_intensity', 'Marketing spending as a share of revenue', 'Despesa de marketing em percentagem das receitas' ),
					self::row( 'cash_burn', 'Cash consumed over the year', 'Caixa consumida no ano' ),
					self::row( 'cash_runway', 'Months of cash remaining', 'Meses de caixa restantes' ),
				),
			),
		);
	}

	/**
	 * One fundamentals label, in both languages.
	 *
	 * @param string $key Row key.
	 * @param string $en  English label.
	 * @param string $pt  Portuguese label.
	 * @return array{key:string,en:string,pt:string}
	 */
	private static function row( string $key, string $en, string $pt ): array {
		return array(
			'key' => $key,
			'en'  => $en,
			'pt'  => $pt,
		);
	}

	/* =====================================================================
	 * The WordPress half. Everything above this line is pure data.
	 * ================================================================== */

	/**
	 * `wp hti-games seed-cases`
	 *
	 * Inserts the five prototypes as unverified drafts. Idempotent by company
	 * and year: a case already in the database is left exactly as an editor
	 * left it, because re-running a seeder must never overwrite the work the
	 * seeder exists to make possible.
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
				\WP_CLI::log( sprintf( '  %s %d — draft created, unverified.', $case['company'], $case['year'] ) );
			}
		}

		\WP_CLI::success( sprintf( '%d cases created, %d already present.', $created, $skipped ) );
		\WP_CLI::log(
			'None of them can be published yet, and that is the workflow: open each one, read the figures out of a filing, paste the source URL, then tick verified. The publish gate will keep refusing until you do.'
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
				// phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- five exact-match lookups, once, in a CLI command.
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
