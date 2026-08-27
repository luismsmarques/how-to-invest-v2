<?php
/**
 * Seeder for the broker editorial section (broker-affiliate skill).
 *
 * Creates the `broker_use_case` terms and the ten curated broker review
 * skeletons (EN + linked PT translation via Polylang), with the structured
 * data from the affiliate reference study (verified 2026-06-26) stored as
 * `hti_broker_*` meta on the default-language post. Idempotent like the main
 * Seeder: entries are matched by slug and skipped when they exist, so
 * editorial rewrites in wp-admin are never overwritten.
 *
 * Deliberately conservative with data: commission values and anything marked
 * "not confirmed" in the study is NOT seeded — only qualitative, verifiable
 * facts (regulator, products, CFD yes/no). Affiliate URLs start empty and
 * deals start inactive; both are flipped in the metabox once signed.
 *
 * Run from Tools → Seed brokers, or `wp hti seed-brokers`.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds the broker CPT + use-case taxonomy. Safe to run repeatedly.
 */
class Broker_Seeder {

	/**
	 * Meta key flagging a seeded entry (for traceability).
	 */
	private const SEED_FLAG = '_hti_seeded';

	/**
	 * Date the seeded facts were last re-verified against primary sources
	 * (financial-analyst protocol). Bump whenever the data below is re-checked.
	 */
	private const VERIFIED = '2026-08-27';

	/**
	 * Register the admin tools page and its form handler.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_run_broker_seeder', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Seed everything. Returns a report: created/skipped counts.
	 *
	 * @return array{brokers_created:int,translations_created:int,skipped:int}
	 */
	public static function seed(): array {
		$report = array(
			'brokers_created'      => 0,
			'pages_created'        => 0,
			'translations_created' => 0,
			'skipped'              => 0,
		);

		self::ensure_use_cases();

		foreach ( self::brokers() as $entry ) {
			$id = self::insert_broker( $entry );
			if ( $id > 0 ) {
				++$report['brokers_created'];
			} else {
				++$report['skipped'];
			}
		}

		foreach ( self::pages() as $entry ) {
			$id = self::insert_page( $entry );
			if ( $id > 0 ) {
				++$report['pages_created'];
			} else {
				++$report['skipped'];
			}
		}

		foreach ( self::guides() as $entry ) {
			$id = self::insert_page( $entry );
			if ( $id > 0 ) {
				++$report['pages_created'];
			} else {
				++$report['skipped'];
			}
		}

		self::link_guides();

		$report['translations_created'] = self::seed_translations();

		return $report;
	}

	/**
	 * Point each EN broker record at its EN guide page (review ↔ guide link).
	 * Idempotent; renderers resolve the PT twin through Polylang.
	 */
	private static function link_guides(): void {
		foreach ( self::brokers() as $entry ) {
			$broker = get_page_by_path( $entry['slug'], OBJECT, 'broker' );
			$guide  = get_page_by_path( 'how-to-open-an-account-with-' . $entry['slug'], OBJECT, 'page' );
			if ( $broker instanceof \WP_Post && $guide instanceof \WP_Post ) {
				update_post_meta( (int) $broker->ID, Broker_Admin::PREFIX . 'guide_page', (string) $guide->ID );
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Use-case taxonomy (the comparison categories).
	 * ---------------------------------------------------------------------- */

	/**
	 * Use-case slugs → bilingual names. Mirrors the comparison category pages.
	 *
	 * @return array<string,array{en:string,pt:string}>
	 */
	public static function use_cases(): array {
		return array(
			'beginners'        => array( 'en' => 'For beginners', 'pt' => 'Para iniciantes' ),
			'etfs'             => array( 'en' => 'ETFs', 'pt' => 'ETFs' ),
			'stocks'           => array( 'en' => 'Stocks', 'pt' => 'Ações' ),
			'interest-on-cash' => array( 'en' => 'Interest on cash', 'pt' => 'Juros sobre o saldo' ),
			'crypto'           => array( 'en' => 'Crypto', 'pt' => 'Cripto' ),
		);
	}

	/**
	 * Create the EN use-case terms (idempotent) with the PT name in term meta.
	 */
	private static function ensure_use_cases(): void {
		if ( ! taxonomy_exists( 'broker_use_case' ) ) {
			return;
		}
		foreach ( self::use_cases() as $slug => $names ) {
			$existing = term_exists( $slug, 'broker_use_case' );
			if ( ! $existing ) {
				$existing = wp_insert_term( $names['en'], 'broker_use_case', array( 'slug' => $slug ) );
			}
			if ( is_wp_error( $existing ) ) {
				continue;
			}
			$tid = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			update_term_meta( $tid, 'hti_name_pt', $names['pt'] );

			// Terms suffer the same Polylang auto-language trap as posts: force
			// the EN term to the default language so its PT twin can link.
			if ( function_exists( 'pll_set_term_language' ) && '' !== self::default_lang()
				&& self::default_lang() !== (string) pll_get_term_language( $tid ) ) {
				pll_set_term_language( $tid, self::default_lang() );
			}
		}
	}

	/**
	 * Mirror the use-case terms into linked PT terms (slug + '-{pt}').
	 *
	 * @param string $en English language slug.
	 * @param string $pt Portuguese language slug.
	 */
	private static function translate_use_cases( string $en, string $pt ): void {
		if ( ! taxonomy_exists( 'broker_use_case' )
			|| ! function_exists( 'pll_set_term_language' )
			|| ! function_exists( 'pll_get_term' )
			|| ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		foreach ( self::use_cases() as $slug => $names ) {
			$en_term = get_term_by( 'slug', $slug, 'broker_use_case' );
			if ( ! $en_term instanceof \WP_Term ) {
				continue;
			}
			// Forced, not only-when-missing (same trap as the post loops).
			if ( $en !== (string) pll_get_term_language( $en_term->term_id ) ) {
				pll_set_term_language( (int) $en_term->term_id, $en );
			}
			if ( pll_get_term( (int) $en_term->term_id, $pt ) ) {
				continue;
			}
			$res = wp_insert_term( $names['pt'], 'broker_use_case', array( 'slug' => $slug . '-' . $pt ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$pt_term_id = (int) $res['term_id'];
			pll_set_term_language( $pt_term_id, $pt );
			pll_save_term_translations( array( $en => (int) $en_term->term_id, $pt => $pt_term_id ) );
		}
	}

	/* -------------------------------------------------------------------------
	 * Broker records.
	 * ---------------------------------------------------------------------- */

	/**
	 * Curated Portuguese slug for a broker review ("análise" is the PT search
	 * intent for a review page).
	 *
	 * @param string $en_slug English (brand) slug.
	 */
	public static function pt_slug( string $en_slug ): string {
		return $en_slug . '-analise';
	}

	/**
	 * The ten launch brokers — study data (verified 2026-06-26) plus a short,
	 * factual EN+PT review skeleton the editorial pass expands in wp-admin.
	 *
	 * `meta` keys map to `hti_broker_*` (see Broker_Admin). No affiliate URLs
	 * and no deals active at seed time. Excluded by the study: Freedom24 and
	 * Webull (affiliate program / platform unavailable for PT residents).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function brokers(): array {
		return array(
			array(
				'slug'       => 'xtb',
				'title'      => 'XTB',
				'menu_order' => 10,
				'use_cases'  => array( 'beginners', 'etfs', 'stocks', 'interest-on-cash' ),
				'excerpt'    => 'A large European broker with a local Portuguese branch registered with the CMVM, commission-free stock and ETF investing within limits, and interest on uninvested cash.',
				'seo'        => array(
					'title' => 'XTB review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at XTB for investors in Portugal: regulation (CMVM-registered branch), products, costs and who tends to consider it. Not financial advice.',
				),
				'content'    => self::review_xtb( false ),
				'pt'         => array(
					'title'   => 'XTB — análise',
					'excerpt' => 'Uma grande corretora europeia com sucursal em Portugal registada na CMVM, investimento em ações e ETFs sem comissões dentro de limites, e juros sobre o saldo não investido.',
					'seo'     => array(
						'title' => 'XTB opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à XTB para quem investe em Portugal: regulação (sucursal registada na CMVM), produtos, custos e quem costuma considerá-la. Não é aconselhamento financeiro.',
					),
					'content' => self::review_xtb( true ),
				),
				'meta'       => array(
					'regulator'          => 'CMVM nº 341 (sucursal PT) · KNF',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,interest,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'Commission-free stocks/ETFs up to a monthly volume limit',
					'fees_note_pt'       => 'Ações/ETFs sem comissões até um limite de volume mensal',
					'interest_rate_note' => 'Pays interest on uninvested cash (rate varies)',
					'interest_rate_note_pt' => 'Paga juros sobre o saldo não investido (taxa variável)',
					'rating'             => '4.5',
					'official_url'       => 'https://www.xtb.com/pt',
					'affiliate_network'  => 'own',
					'profile_fit'        => '1,2,3,4,5',
				),
			),
			array(
				'slug'       => 'trading-212',
				'title'      => 'Trading 212',
				'menu_order' => 20,
				'use_cases'  => array( 'beginners', 'etfs', 'stocks', 'interest-on-cash' ),
				'excerpt'    => 'A commission-free app for stocks and ETFs with automated "pies", fractional shares and interest on cash, operating in the EU under Trading 212 EU GmbH.',
				'seo'        => array(
					'title' => 'Trading 212 review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at Trading 212 for investors in Portugal: EU regulation, commission-free investing, pies and interest on cash. Not financial advice.',
				),
				'content'    => self::review_trading212( false ),
				'pt'         => array(
					'title'   => 'Trading 212 — análise',
					'excerpt' => 'Uma app sem comissões para ações e ETFs com "pies" automáticos, frações de ações e juros sobre o saldo, a operar na UE através da Trading 212 EU GmbH.',
					'seo'     => array(
						'title' => 'Trading 212 opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à Trading 212 para quem investe em Portugal: regulação na UE, investimento sem comissões, pies e juros sobre o saldo. Não é aconselhamento financeiro.',
					),
					'content' => self::review_trading212( true ),
				),
				'meta'       => array(
					'regulator'          => 'BaFin (Trading 212 EU GmbH) · CySEC',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,interest,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '1 €',
					'fees_note'          => 'Commission-free stocks/ETFs; 0.15% FX fee outside the account currency',
					'fees_note_pt'       => 'Ações/ETFs sem comissões; 0,15% de taxa cambial fora da moeda da conta',
					'interest_rate_note' => 'Pays interest on uninvested cash (rate varies)',
					'interest_rate_note_pt' => 'Paga juros sobre o saldo não investido (taxa variável)',
					'rating'             => '4.5',
					'official_url'       => 'https://www.trading212.com',
					'affiliate_network'  => 'everflow',
					'profile_fit'        => '1,2,3,4,5',
				),
			),
			array(
				'slug'       => 'trade-republic',
				'title'      => 'Trade Republic',
				'menu_order' => 30,
				'use_cases'  => array( 'beginners', 'etfs', 'stocks', 'interest-on-cash', 'crypto' ),
				'excerpt'    => 'A German bank-broker with free automated ETF savings plans, fractional investing, interest on cash and crypto, supervised by BaFin.',
				'seo'        => array(
					'title' => 'Trade Republic review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at Trade Republic for investors in Portugal: German banking licence, ETF savings plans, interest on cash and crypto. Not financial advice.',
				),
				'content'    => self::review_trade_republic( false ),
				'pt'         => array(
					'title'   => 'Trade Republic — análise',
					'excerpt' => 'Um banco-corretora alemão com planos de poupança em ETFs automáticos e gratuitos, frações, juros sobre o saldo e cripto, supervisionado pelo BaFin.',
					'seo'     => array(
						'title' => 'Trade Republic opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à Trade Republic para quem investe em Portugal: licença bancária alemã, planos de ETFs, juros sobre o saldo e cripto. Não é aconselhamento financeiro.',
					),
					'content' => self::review_trade_republic( true ),
				),
				'meta'       => array(
					'regulator'          => 'BaFin (Trade Republic Bank GmbH, banco alemão)',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,crypto,interest,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt,crypto',
					'min_deposit'        => '1 €',
					'fees_note'          => '€1 flat fee per manual order; savings-plan executions free',
					'fees_note_pt'       => '1 € fixo por ordem manual; planos de poupança sem custo de execução',
					'interest_rate_note' => 'Interest on cash tracks the ECB deposit rate (varies by market)',
					'interest_rate_note_pt' => 'Juros sobre o saldo seguem a taxa de depósito do BCE (varia por mercado)',
					'rating'             => '4.5',
					'official_url'       => 'https://traderepublic.com',
					'affiliate_network'  => 'impact',
					'profile_fit'        => '1,2,3,4,5',
				),
			),
			array(
				'slug'       => 'lightyear',
				'title'      => 'Lightyear',
				'menu_order' => 40,
				'use_cases'  => array( 'beginners', 'etfs', 'interest-on-cash' ),
				'excerpt'    => 'A calm, low-cost European investing app for stocks, ETFs and money-market funds, regulated in Estonia — no CFDs anywhere in the product.',
				'seo'        => array(
					'title' => 'Lightyear review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at Lightyear for investors in Portugal: EU regulation, low-cost stocks and ETFs, money-market funds and interest. Not financial advice.',
				),
				'content'    => self::review_lightyear( false ),
				'pt'         => array(
					'title'   => 'Lightyear — análise',
					'excerpt' => 'Uma app europeia calma e de baixo custo para ações, ETFs e fundos do mercado monetário, regulada na Estónia — sem CFDs em nenhuma parte do produto.',
					'seo'     => array(
						'title' => 'Lightyear opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à Lightyear para quem investe em Portugal: regulação na UE, ações e ETFs de baixo custo, fundos monetários e juros. Não é aconselhamento financeiro.',
					),
					'content' => self::review_lightyear( true ),
				),
				'meta'       => array(
					'regulator'          => 'Finantsinspektsioon (Lightyear Europe AS, Estónia)',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds,interest',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '1 €',
					'fees_note'          => 'Low per-order fees; monthly free ETF allowance; FX fee applies',
					'fees_note_pt'       => 'Comissões baixas por ordem; plafond mensal grátis em ETFs; taxa cambial',
					'interest_rate_note' => 'Money-market Vaults for idle cash (variable rate, in PT since Jun 2026)',
					'interest_rate_note_pt' => 'Vaults de fundos monetários para o saldo (taxa variável, em PT desde jun 2026)',
					'rating'             => '4.0',
					'official_url'       => 'https://lightyear.com/en-eu',
					'affiliate_network'  => 'financeads',
					'profile_fit'        => '1,2,3,4',
				),
			),
			array(
				'slug'       => 'degiro',
				'title'      => 'DEGIRO',
				'menu_order' => 50,
				'use_cases'  => array( 'etfs', 'stocks' ),
				'excerpt'    => 'A veteran low-cost European broker for stocks, ETFs and bonds under flatexDEGIRO Bank, operating in Portugal under EU freedom of services — no CFDs.',
				'seo'        => array(
					'title' => 'DEGIRO review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at DEGIRO for investors in Portugal: German banking group, low-cost stocks and ETFs, a core ETF selection, and no CFDs. Not financial advice.',
				),
				'content'    => self::review_degiro( false ),
				'pt'         => array(
					'title'   => 'DEGIRO — análise',
					'excerpt' => 'Uma corretora europeia veterana e de baixo custo para ações, ETFs e obrigações sob o flatexDEGIRO Bank, a operar em Portugal em livre prestação de serviços — sem CFDs.',
					'seo'     => array(
						'title' => 'DEGIRO opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à DEGIRO para quem investe em Portugal: grupo bancário alemão, ações e ETFs de baixo custo, seleção de ETFs core e sem CFDs. Não é aconselhamento financeiro.',
					),
					'content' => self::review_degiro( true ),
				),
				'meta'       => array(
					'regulator'          => 'BaFin · DNB/AFM (flatexDEGIRO Bank SE); CMVM em livre prestação',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds',
					'asset_classes'      => 'global_equity,bonds,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'Low dealing fees; curated ETF list with reduced fees',
					'fees_note_pt'       => 'Comissões de negociação baixas; lista de ETFs com custos reduzidos',
					'interest_rate_note' => '',
					'interest_rate_note_pt' => '',
					'rating'             => '4.0',
					'official_url'       => 'https://www.degiro.pt',
					'affiliate_network'  => 'tapfiliate',
					'profile_fit'        => '2,3,4,5',
				),
			),
			array(
				'slug'       => 'interactive-brokers',
				'title'      => 'Interactive Brokers',
				'menu_order' => 60,
				'use_cases'  => array( 'stocks', 'etfs', 'interest-on-cash' ),
				'excerpt'    => 'The global heavyweight: huge market and product coverage, institutional-grade tools and interest on idle cash, serving EU clients via IBKR Ireland.',
				'seo'        => array(
					'title' => 'Interactive Brokers review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at Interactive Brokers (IBKR) for investors in Portugal: Irish EU entity, vast market access, low costs and powerful tools. Not financial advice.',
				),
				'content'    => self::review_ibkr( false ),
				'pt'         => array(
					'title'   => 'Interactive Brokers — análise',
					'excerpt' => 'O peso-pesado global: enorme cobertura de mercados e produtos, ferramentas de nível institucional e juros sobre o saldo, a servir clientes da UE via IBKR Ireland.',
					'seo'     => array(
						'title' => 'Interactive Brokers opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à Interactive Brokers (IBKR) para quem investe em Portugal: entidade irlandesa na UE, acesso vasto a mercados, custos baixos e ferramentas potentes. Não é aconselhamento financeiro.',
					),
					'content' => self::review_ibkr( true ),
				),
				'meta'       => array(
					'regulator'          => 'Central Bank of Ireland (IBKR Ireland Ltd)',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds,interest',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'Low per-order pricing across many markets (detailed public price list)',
					'fees_note_pt'       => 'Comissões baixas por ordem em muitos mercados (preçário público detalhado)',
					'interest_rate_note' => 'Pays interest on qualifying idle cash',
					'interest_rate_note_pt' => 'Paga juros sobre saldo elegível não investido',
					'rating'             => '4.5',
					'official_url'       => 'https://www.interactivebrokers.ie',
					'affiliate_network'  => 'own',
					'profile_fit'        => '3,4,5',
				),
			),
			array(
				'slug'       => 'etoro',
				'title'      => 'eToro',
				'menu_order' => 70,
				'use_cases'  => array( 'stocks', 'crypto' ),
				'excerpt'    => 'A social-investing platform with real stocks, ETFs and one of the broadest crypto offers, regulated by CySEC for EU clients — with a significant CFD side.',
				'seo'        => array(
					'title' => 'eToro review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at eToro for investors in Portugal: CySEC regulation, real stocks and crypto, social features — and the CFD risk to understand. Not financial advice.',
				),
				'content'    => self::review_etoro( false ),
				'pt'         => array(
					'title'   => 'eToro — análise',
					'excerpt' => 'Uma plataforma de social investing com ações reais, ETFs e uma das ofertas de cripto mais amplas, regulada pela CySEC para clientes da UE — com uma vertente significativa de CFDs.',
					'seo'     => array(
						'title' => 'eToro opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa à eToro para quem investe em Portugal: regulação CySEC, ações reais e cripto, funções sociais — e o risco de CFDs a compreender. Não é aconselhamento financeiro.',
					),
					'content' => self::review_etoro( true ),
				),
				'meta'       => array(
					'regulator'          => 'CySEC (eToro (Europe) Ltd, licença 109/10)',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,crypto',
					'asset_classes'      => 'global_equity,reits_alt,crypto',
					'min_deposit'        => '',
					'fees_note'          => 'Commission-free stocks (conditions apply); spreads on crypto/FX; withdrawal fee',
					'fees_note_pt'       => 'Ações sem comissões (com condições); spreads em cripto/câmbio; taxa de levantamento',
					'interest_rate_note' => '',
					'interest_rate_note_pt' => '',
					'rating'             => '3.5',
					'official_url'       => 'https://www.etoro.com',
					'affiliate_network'  => 'own',
					'profile_fit'        => '4,5',
				),
			),
			array(
				'slug'       => 'saxo',
				'title'      => 'Saxo Bank',
				'menu_order' => 80,
				'use_cases'  => array( 'stocks', 'etfs' ),
				'excerpt'    => 'A Danish investment bank with premium platforms, deep research and wide market coverage for stocks, ETFs, bonds and funds.',
				'seo'        => array(
					'title' => 'Saxo Bank review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at Saxo Bank for investors in Portugal: Danish banking supervision, premium platforms, broad product coverage. Not financial advice.',
				),
				'content'    => self::review_saxo( false ),
				'pt'         => array(
					'title'   => 'Saxo Bank — análise',
					'excerpt' => 'Um banco de investimento dinamarquês com plataformas premium, research profundo e cobertura ampla de mercados para ações, ETFs, obrigações e fundos.',
					'seo'     => array(
						'title' => 'Saxo Bank opiniões e análise %currentyear% — custos e segurança',
						'desc'  => 'Uma análise educativa ao Saxo Bank para quem investe em Portugal: supervisão bancária dinamarquesa, plataformas premium, cobertura ampla de produtos. Não é aconselhamento financeiro.',
					),
					'content' => self::review_saxo( true ),
				),
				'meta'       => array(
					'regulator'          => 'Autoridade financeira dinamarquesa (Saxo Bank A/S)',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'Tiered pricing, competitive for a bank-grade platform',
					'fees_note_pt'       => 'Preçário por escalões, competitivo para uma plataforma de banco',
					'interest_rate_note' => 'Interest on cash above thresholds (tiered)',
					'interest_rate_note_pt' => 'Juros sobre saldo acima de limiares (por escalões)',
					'rating'             => '4.0',
					'official_url'       => 'https://www.home.saxo',
					'affiliate_network'  => 'own',
					'profile_fit'        => '3,4,5',
				),
			),
			array(
				'slug'       => 'revolut',
				'title'      => 'Revolut',
				'menu_order' => 90,
				'use_cases'  => array( 'beginners', 'interest-on-cash', 'crypto' ),
				'excerpt'    => 'The everyday-money app with a simple investing add-on: fractional stocks, crypto and savings vaults, under an EU banking licence.',
				'seo'        => array(
					'title' => 'Revolut investing review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at investing through Revolut for users in Portugal: EU banking licence, fractional stocks, savings and crypto. Not financial advice.',
				),
				'content'    => self::review_revolut( false ),
				'pt'         => array(
					'title'   => 'Revolut — análise',
					'excerpt' => 'A app do dinheiro do dia-a-dia com um extra de investimento simples: frações de ações, cripto e cofres de poupança, sob licença bancária na UE.',
					'seo'     => array(
						'title' => 'Revolut investimentos %currentyear% — opiniões, custos e para quem é',
						'desc'  => 'Uma análise educativa a investir através da Revolut para utilizadores em Portugal: licença bancária na UE, frações de ações, poupança e cripto. Não é aconselhamento financeiro.',
					),
					'content' => self::review_revolut( true ),
				),
				'meta'       => array(
					'regulator'          => 'Banco da Lituânia (Revolut Securities Europe UAB; depósitos: Revolut Bank UAB)',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds,crypto,interest',
					'asset_classes'      => 'global_equity,bonds,cash,crypto',
					'min_deposit'        => '1 €',
					'fees_note'          => 'Free trades per month by plan tier; fees beyond; crypto spreads',
					'fees_note_pt'       => 'Ordens grátis por mês conforme o plano; custos a partir daí; spreads em cripto',
					'interest_rate_note' => 'Interest-bearing savings vaults (rate varies by plan)',
					'interest_rate_note_pt' => 'Cofres de poupança remunerados (taxa varia por plano)',
					'rating'             => '3.5',
					'official_url'       => 'https://www.revolut.com/pt-PT',
					'affiliate_network'  => 'impact',
					'profile_fit'        => '1,2',
				),
			),
			array(
				'slug'       => 'activobank',
				'title'      => 'ActivoBank',
				'menu_order' => 100,
				'use_cases'  => array( 'beginners', 'stocks' ),
				'excerpt'    => 'A Portuguese digital bank with an investing arm — stocks, ETFs and funds under Banco de Portugal and CMVM supervision, for people who want everything at a domestic bank.',
				'seo'        => array(
					'title' => 'ActivoBank investing review %currentyear% — fees, safety, who it suits',
					'desc'  => 'An educational look at investing through ActivoBank in Portugal: domestic supervision (Banco de Portugal, CMVM), stocks, ETFs and funds. Not financial advice.',
				),
				'content'    => self::review_activobank( false ),
				'pt'         => array(
					'title'   => 'ActivoBank — análise',
					'excerpt' => 'Um banco digital português com vertente de investimento — ações, ETFs e fundos sob supervisão do Banco de Portugal e da CMVM, para quem quer tudo num banco nacional.',
					'seo'     => array(
						'title' => 'ActivoBank investimentos %currentyear% — opiniões, custos e para quem é',
						'desc'  => 'Uma análise educativa a investir através do ActivoBank: supervisão nacional (Banco de Portugal, CMVM), ações, ETFs e fundos. Não é aconselhamento financeiro.',
					),
					'content' => self::review_activobank( true ),
				),
				'meta'       => array(
					'regulator'          => 'Banco de Portugal nº 23 · CMVM nº 116',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'First exchange order each month €0; €5 per order after; monthly custody fee',
					'fees_note_pt'       => '1.ª ordem de bolsa do mês 0 €; 5 € por ordem depois; custódia mensal',
					'interest_rate_note' => '',
					'interest_rate_note_pt' => '',
					'rating'             => '3.5',
					'official_url'       => 'https://www.activobank.pt',
					'affiliate_network'  => 'own',
					'profile_fit'        => '1,2,3',
				),
			),
		);
	}

	/* -------------------------------------------------------------------------
	 * Section pages (pillar + categories + "How we make money").
	 * ---------------------------------------------------------------------- */

	/**
	 * Curated PT slug for a section page.
	 *
	 * @param string $en_slug English slug.
	 * @param string $pt_title PT title (sanitized fallback).
	 */
	public static function page_pt_slug( string $en_slug, string $pt_title ): string {
		if ( str_starts_with( $en_slug, 'how-to-open-an-account-with-' ) ) {
			return 'como-abrir-conta-' . substr( $en_slug, strlen( 'how-to-open-an-account-with-' ) );
		}
		$map = array(
			'best-brokers-in-portugal'                => 'melhores-corretoras-em-portugal',
			'best-brokers-for-beginners-portugal'     => 'melhores-corretoras-para-iniciantes',
			'best-etf-brokers-portugal'               => 'melhores-corretoras-para-etfs',
			'best-stock-brokers-portugal'             => 'melhores-corretoras-para-acoes',
			'best-interest-on-cash-accounts-portugal' => 'melhores-corretoras-com-juros-sobre-o-saldo',
			'best-crypto-brokers-portugal'            => 'melhores-corretoras-para-cripto',
			'how-we-make-money'                       => 'como-ganhamos-dinheiro',
		);
		return $map[ $en_slug ] ?? sanitize_title( $pt_title );
	}

	/**
	 * The comparison section pages. The year in the SEO titles uses RankMath's
	 * %currentyear% variable so the pages stay current without re-seeding;
	 * H1s/slugs are evergreen. Every comparison page's disclosure comes from
	 * the [hti_brokers] shortcode itself.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function pages(): array {
		$sc = static function ( string $category = '' ): string {
			$attr = '' === $category ? '' : ' category="' . $category . '"';
			return '<!-- wp:shortcode -->[hti_brokers' . $attr . ']<!-- /wp:shortcode -->';
		};

		return array(
			array(
				'slug'    => 'best-brokers-in-portugal',
				'title'   => 'Best brokers in Portugal',
				'seo'     => array(
					'title' => 'Best brokers in Portugal %currentyear% — regulated platforms compared',
					'desc'  => 'Factual comparison of regulated investment platforms available to residents of Portugal: costs, products, regulation and who each one tends to suit. Educational, with a public methodology.',
				),
				'content' => self::paragraph( 'This is a factual, editorial comparison of investment platforms available to residents of Portugal. Every platform listed is supervised by a top-tier European regulator, and each card shows the same verifiable facts: regulation, products, minimum, costs and whether it pays interest on cash.' )
					. self::paragraph( 'It is educational information with a public methodology — not financial advice, and not a personal recommendation. Which platform fits depends on what you need it for; the category pages below narrow the list by use.' )
					. $sc(),
				'pt'      => array(
					'title'   => 'Melhores corretoras em Portugal',
					'seo'     => array(
						'title' => 'Melhores corretoras em Portugal %currentyear% — comparação de plataformas reguladas',
						'desc'  => 'Comparação factual de plataformas de investimento reguladas disponíveis para residentes em Portugal: custos, produtos, regulação e para quem cada uma costuma fazer sentido. Educativo, com metodologia pública.',
					),
					'content' => self::paragraph( 'Esta é uma comparação editorial e factual de plataformas de investimento disponíveis para residentes em Portugal. Todas as plataformas listadas são supervisionadas por um regulador europeu de primeira linha, e cada cartão mostra os mesmos factos verificáveis: regulação, produtos, mínimo, custos e se paga juros sobre o saldo.' )
						. self::paragraph( 'É informação educativa com metodologia pública — não é aconselhamento financeiro nem uma recomendação pessoal. A plataforma certa depende do que precisas; as páginas por categoria abaixo estreitam a lista por uso.' )
						. $sc(),
				),
			),
			array(
				'slug'    => 'best-brokers-for-beginners-portugal',
				'title'   => 'Best brokers for beginners in Portugal',
				'seo'     => array(
					'title' => 'Best brokers for beginners in Portugal %currentyear%',
					'desc'  => 'Platforms that tend to suit a first-time investor in Portugal: simple apps, fractional shares, low minimums and clear costs. Educational comparison, not advice.',
				),
				'content' => self::paragraph( 'Starting out, the platform matters less than the habit — but a simple app, fractional shares and a low minimum make the habit easier to build. These are the platforms from our comparison that tend to suit a first-time investor.' )
					. self::paragraph( 'If you have not yet worked out what kind of investor you are, the free investor-profile questionnaire is the calmer place to start; this page is educational information, not a personal recommendation.' )
					. $sc( 'beginners' ),
				'pt'      => array(
					'title'   => 'Melhores corretoras para iniciantes',
					'seo'     => array(
						'title' => 'Melhores corretoras para iniciantes em Portugal %currentyear%',
						'desc'  => 'Plataformas que costumam fazer sentido para quem investe pela primeira vez em Portugal: apps simples, frações de ações, mínimos baixos e custos claros. Comparação educativa, não é aconselhamento.',
					),
					'content' => self::paragraph( 'No início, a plataforma importa menos do que o hábito — mas uma app simples, frações de ações e um mínimo baixo tornam o hábito mais fácil de construir. Estas são as plataformas da nossa comparação que costumam fazer sentido para quem começa.' )
						. self::paragraph( 'Se ainda não sabes que tipo de investidor és, o questionário gratuito de perfil de investidor é o sítio mais calmo para começar; esta página é informação educativa, não uma recomendação pessoal.' )
						. $sc( 'beginners' ),
				),
			),
			array(
				'slug'    => 'best-etf-brokers-portugal',
				'title'   => 'Best ETF brokers in Portugal',
				'seo'     => array(
					'title' => 'Best ETF brokers in Portugal %currentyear% — costs compared',
					'desc'  => 'Where investors in Portugal buy ETFs: platforms compared on ETF costs, savings plans and product range. Educational comparison, not advice.',
				),
				'content' => self::paragraph( 'ETFs are how most long-term portfolios hold whole asset classes with one purchase, so ETF dealing costs and automated savings plans are what separate platforms here. This comparison keeps to the facts each platform publishes.' )
					. $sc( 'etfs' ),
				'pt'      => array(
					'title'   => 'Melhores corretoras para ETFs',
					'seo'     => array(
						'title' => 'Melhores corretoras para ETFs em Portugal %currentyear% — custos comparados',
						'desc'  => 'Onde quem investe em Portugal compra ETFs: plataformas comparadas por custos de ETFs, planos de poupança e gama de produtos. Comparação educativa, não é aconselhamento.',
					),
					'content' => self::paragraph( 'Os ETFs são a forma como a maioria das carteiras de longo prazo detém classes de ativos inteiras numa só compra, por isso os custos de negociação de ETFs e os planos de poupança automáticos são o que separa as plataformas aqui. Esta comparação limita-se aos factos que cada plataforma publica.' )
						. $sc( 'etfs' ),
				),
			),
			array(
				'slug'    => 'best-stock-brokers-portugal',
				'title'   => 'Best stock brokers in Portugal',
				'seo'     => array(
					'title' => 'Best stock brokers in Portugal %currentyear% — compared',
					'desc'  => 'Platforms for buying individual stocks from Portugal: market access, costs and regulation compared. Educational comparison, not advice.',
				),
				'content' => self::paragraph( 'For individual stocks, what varies between platforms is market access, dealing costs and currency-conversion fees. The comparison below shows the platforms from our list that offer real stock investing to residents of Portugal.' )
					. $sc( 'stocks' ),
				'pt'      => array(
					'title'   => 'Melhores corretoras para ações',
					'seo'     => array(
						'title' => 'Melhores corretoras para ações em Portugal %currentyear% — comparadas',
						'desc'  => 'Plataformas para comprar ações individuais a partir de Portugal: acesso a mercados, custos e regulação comparados. Comparação educativa, não é aconselhamento.',
					),
					'content' => self::paragraph( 'Nas ações individuais, o que varia entre plataformas é o acesso a mercados, os custos de negociação e as taxas de conversão cambial. A comparação abaixo mostra as plataformas da nossa lista que oferecem investimento em ações reais a residentes em Portugal.' )
						. $sc( 'stocks' ),
				),
			),
			array(
				'slug'    => 'best-interest-on-cash-accounts-portugal',
				'title'   => 'Best interest on cash at brokers in Portugal',
				'seo'     => array(
					'title' => 'Interest on uninvested cash: brokers compared (Portugal, %currentyear%)',
					'desc'  => 'Which platforms pay interest on uninvested cash for residents of Portugal, and under what conditions. Educational comparison, not advice.',
				),
				'content' => self::paragraph( 'The cash sitting in a brokerage account can earn interest while it waits. Rates and conditions change often — each card links to the platform, where the current rate is published; our figures are qualitative on purpose.' )
					. self::paragraph( 'For cash you never intend to invest, a term deposit can be the simpler home — the term-deposit comparison covers those.' )
					. $sc( 'interest-on-cash' ),
				'pt'      => array(
					'title'   => 'Melhores corretoras com juros sobre o saldo',
					'seo'     => array(
						'title' => 'Juros sobre o saldo não investido: corretoras comparadas (Portugal, %currentyear%)',
						'desc'  => 'Que plataformas pagam juros sobre o saldo não investido a residentes em Portugal, e em que condições. Comparação educativa, não é aconselhamento.',
					),
					'content' => self::paragraph( 'O dinheiro parado numa conta de corretora pode render juros enquanto espera. As taxas e condições mudam com frequência — cada cartão liga à plataforma, onde a taxa atual está publicada; os nossos dados são qualitativos de propósito.' )
						. self::paragraph( 'Para dinheiro que nunca pensas investir, um depósito a prazo pode ser a casa mais simples — o comparador de depósitos a prazo cobre esses.' )
						. $sc( 'interest-on-cash' ),
				),
			),
			array(
				'slug'    => 'best-crypto-brokers-portugal',
				'title'   => 'Best crypto platforms in Portugal',
				'seo'     => array(
					'title' => 'Best regulated crypto platforms in Portugal %currentyear%',
					'desc'  => 'Regulated platforms where residents of Portugal can hold a small crypto slice next to their investments. Educational comparison, not advice.',
				),
				'content' => self::paragraph( 'In the educational portfolios on this site, crypto only ever appears as a tiny, optional slice — it is young and very volatile. For a profile that chooses to hold that slice, these are the regulated platforms from our comparison that offer it next to ordinary investments.' )
					. $sc( 'crypto' ),
				'pt'      => array(
					'title'   => 'Melhores corretoras para cripto',
					'seo'     => array(
						'title' => 'Melhores plataformas reguladas para cripto em Portugal %currentyear%',
						'desc'  => 'Plataformas reguladas onde residentes em Portugal podem deter uma fatia pequena de cripto ao lado dos investimentos. Comparação educativa, não é aconselhamento.',
					),
					'content' => self::paragraph( 'Nas carteiras educativas deste site, a cripto só aparece como uma fatia minúscula e opcional — é jovem e muito volátil. Para um perfil que escolhe ter essa fatia, estas são as plataformas reguladas da nossa comparação que a oferecem ao lado dos investimentos normais.' )
						. $sc( 'crypto' ),
				),
			),
			array(
				'slug'    => 'how-we-make-money',
				'title'   => 'How we make money',
				'seo'     => array(
					'title' => 'How we make money — affiliate disclosure and methodology',
					'desc'  => 'How this site is funded: affiliate partnerships with some of the regulated platforms we compare, disclosed on every page, never changing our comparisons. Full methodology.',
				),
				'content' => self::paragraph( 'HowToInvest is free to use. It is funded in two ways: advertising, and affiliate partnerships with some of the regulated investment platforms we compare. When you open an account through one of our links, the platform may pay us a commission. It costs you nothing extra.' )
					. self::heading( 'What affiliation never changes' )
					. self::paragraph( 'Every platform in our comparison is listed on its merits — platforms we have no deal with appear next to platforms we do, with the same card and the same facts. Affiliate status never affects order, rating or wording, and every card with an active partnership is labelled. Our educational tools (the questionnaire, the portfolio examples, the Learn hub) never name platforms at all.' )
					. self::heading( 'Our methodology' )
					. self::paragraph( 'We only list platforms supervised by a top-tier European regulator. The data on each card — regulation, products, minimums, costs, interest — comes from each platform\'s own published documents, carries the date we last verified it, and excludes anything we could not confirm. Platforms that offer CFDs carry the risk warning wherever they appear.' )
					. self::heading( 'The rules we follow' )
					. self::paragraph( 'Following the CMVM\'s guidance on financial content and affiliation, we disclose the affiliate relationship on every page where links appear, keep comparisons factual rather than promotional, and never present a platform as a personal recommendation — that is a decision for you, ideally with a registered professional. This page is linked from every disclosure.' ),
				'pt'      => array(
					'title'   => 'Como ganhamos dinheiro',
					'seo'     => array(
						'title' => 'Como ganhamos dinheiro — divulgação de afiliação e metodologia',
						'desc'  => 'Como este site se financia: parcerias de afiliação com algumas das plataformas reguladas que comparamos, divulgadas em cada página, sem nunca alterar as comparações. Metodologia completa.',
					),
					'content' => self::paragraph( 'O HowToInvest é gratuito. Financia-se de duas formas: publicidade, e parcerias de afiliação com algumas das plataformas de investimento reguladas que comparamos. Quando abres conta através de um dos nossos links, a plataforma pode pagar-nos uma comissão. Não te custa nada extra.' )
						. self::heading( 'O que a afiliação nunca altera' )
						. self::paragraph( 'Todas as plataformas da nossa comparação estão listadas pelo seu mérito — plataformas sem parceria aparecem ao lado de plataformas com parceria, com o mesmo cartão e os mesmos factos. O estado de afiliação nunca afeta a ordem, a avaliação ou o texto, e todos os cartões com parceria ativa estão rotulados. As nossas ferramentas educativas (o questionário, os exemplos de carteira, o hub Learn) nunca nomeiam plataformas.' )
						. self::heading( 'A nossa metodologia' )
						. self::paragraph( 'Só listamos plataformas supervisionadas por um regulador europeu de primeira linha. Os dados de cada cartão — regulação, produtos, mínimos, custos, juros — vêm dos documentos publicados por cada plataforma, indicam a data em que os verificámos pela última vez, e excluem tudo o que não conseguimos confirmar. Plataformas que oferecem CFDs levam o aviso de risco onde quer que apareçam.' )
						. self::heading( 'As regras que seguimos' )
						. self::paragraph( 'Seguindo o entendimento da CMVM sobre conteúdos financeiros e afiliação, divulgamos a relação de afiliação em cada página onde há links, mantemos as comparações factuais em vez de promocionais, e nunca apresentamos uma plataforma como recomendação pessoal — essa é uma decisão tua, idealmente com um profissional registado. Esta página está ligada a partir de todas as divulgações.' ),
				),
			),
		);
	}

	/* -------------------------------------------------------------------------
	 * "How to open an account" guides — one per broker, from a shared template.
	 * ---------------------------------------------------------------------- */

	/**
	 * Per-broker guide parameters: sign-up channel, rough duration and the one
	 * broker-specific note worth knowing before starting.
	 *
	 * @return array<string,array{channel:string,minutes:int,note_en:string,note_pt:string}>
	 */
	private static function guide_params(): array {
		return array(
			'xtb'                 => array(
				'channel' => 'both',
				'minutes' => 10,
				'note_en' => 'XTB has a Portuguese branch, so support and documents are available in Portuguese.',
				'note_pt' => 'A XTB tem sucursal portuguesa, por isso o apoio e os documentos existem em português.',
			),
			'trading-212'         => array(
				'channel' => 'both',
				'minutes' => 10,
				'note_en' => 'Make sure you pick the Invest account — the CFD account is a separate, high-risk product.',
				'note_pt' => 'Garante que escolhes a conta Invest — a conta CFD é um produto separado e de risco elevado.',
			),
			'trade-republic'      => array(
				'channel' => 'app',
				'minutes' => 10,
				'note_en' => 'Everything happens in the mobile app — there is no desktop sign-up.',
				'note_pt' => 'Tudo acontece na app — não há registo por computador.',
			),
			'lightyear'           => array(
				'channel' => 'both',
				'minutes' => 10,
				'note_en' => 'One of the simplest sign-ups in the comparison — there is no CFD product to steer around.',
				'note_pt' => 'Um dos registos mais simples da comparação — não há produto CFD para evitar.',
			),
			'degiro'              => array(
				'channel' => 'both',
				'minutes' => 15,
				'note_en' => 'You confirm your identity by linking a bank account in your name with a small verification transfer.',
				'note_pt' => 'Confirmas a identidade ao associar uma conta bancária em teu nome com uma pequena transferência de verificação.',
			),
			'interactive-brokers' => array(
				'channel' => 'web',
				'minutes' => 30,
				'note_en' => 'The application is longer than app-first brokers (more regulatory questions) — allow extra time.',
				'note_pt' => 'O processo é mais longo do que nas corretoras app-first (mais perguntas regulatórias) — conta com tempo extra.',
			),
			'etoro'               => array(
				'channel' => 'both',
				'minutes' => 10,
				'note_en' => 'The account is denominated in US dollars, so euro deposits are converted on the way in.',
				'note_pt' => 'A conta é denominada em dólares, por isso os depósitos em euros são convertidos à entrada.',
			),
			'saxo'                => array(
				'channel' => 'web',
				'minutes' => 15,
				'note_en' => 'Saxo runs a bank-grade onboarding; have your tax details at hand.',
				'note_pt' => 'O Saxo tem um onboarding de nível bancário; tem os teus dados fiscais à mão.',
			),
			'revolut'             => array(
				'channel' => 'app',
				'minutes' => 10,
				'note_en' => 'Investing lives inside the Revolut app — you open a Revolut account first, then activate investing.',
				'note_pt' => 'O investimento vive dentro da app Revolut — primeiro abres a conta Revolut, depois ativas o investimento.',
			),
			'activobank'          => array(
				'channel' => 'both',
				'minutes' => 20,
				'note_en' => 'Opening an account means becoming a bank client — it can also be done in person at a Ponto Activo.',
				'note_pt' => 'Abrir conta significa tornares-te cliente do banco — também pode ser feito presencialmente num Ponto Activo.',
			),
		);
	}

	/**
	 * The guide entries (same shape as pages(), plus the no-sidebar template).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function guides(): array {
		$params = self::guide_params();
		$out    = array();

		foreach ( self::brokers() as $b ) {
			$slug  = (string) $b['slug'];
			$brand = (string) $b['title'];
			$p     = $params[ $slug ] ?? array(
				'channel' => 'both',
				'minutes' => 15,
				'note_en' => '',
				'note_pt' => '',
			);

			$out[] = array(
				'slug'          => 'how-to-open-an-account-with-' . $slug,
				'title'         => 'How to open an account with ' . $brand,
				'page_template' => 'page-no-sidebar',
				'seo'           => array(
					'title' => 'How to open an account with ' . $brand . ' (%currentyear% step by step)',
					'desc'  => 'A factual, step-by-step walkthrough of opening a ' . $brand . ' account from Portugal: what you need, identity verification and the first deposit. Educational, not a recommendation.',
				),
				'content'       => self::guide_content( 'en', $brand, $slug, $p ),
				'pt'            => array(
					'title'   => 'Como abrir conta na ' . $brand,
					'seo'     => array(
						'title' => 'Como abrir conta na ' . $brand . ' (%currentyear%, passo a passo)',
						'desc'  => 'Um passo-a-passo factual para abrir conta na ' . $brand . ' a partir de Portugal: o que precisas, verificação de identidade e primeiro depósito. Educativo, não é uma recomendação.',
					),
					'content' => self::guide_content( 'pt', $brand, $slug, $p ),
				),
			);
		}

		return $out;
	}

	/**
	 * Build one guide's content (EN or PT) from the shared template.
	 *
	 * @param string                                                       $lang  'en' or 'pt'.
	 * @param string                                                       $brand Broker display name.
	 * @param string                                                       $slug  Broker slug.
	 * @param array{channel:string,minutes:int,note_en:string,note_pt:string} $p     Guide parameters.
	 */
	private static function guide_content( string $lang, string $brand, string $slug, array $p ): string {
		$pt      = 'pt' === $lang;
		$minutes = (int) $p['minutes'];
		$note    = $pt ? (string) $p['note_pt'] : (string) $p['note_en'];

		$channel_txt = array(
			'app'  => $pt ? 'na app' : 'in the app',
			'web'  => $pt ? 'no site oficial' : 'on the official website',
			'both' => $pt ? 'na app ou no site oficial' : 'in the app or on the official website',
		);
		$where = $channel_txt[ $p['channel'] ] ?? $channel_txt['both'];

		$review_href = $pt ? '/pt/brokers/' . self::pt_slug( $slug ) . '/' : '/brokers/' . $slug . '/';
		$pillar_href = $pt ? '/pt/melhores-corretoras-em-portugal/' : '/best-brokers-in-portugal/';

		$intro = $pt
			? "Abrir conta na {$brand} faz-se {$where} e costuma demorar cerca de {$minutes} minutos, mais o tempo de aprovação. Este é um passo-a-passo factual do processo — informação educativa, não uma recomendação. Se a {$brand} faz sentido para ti é outra pergunta: a análise cobre para quem costuma fazer sentido."
			: "Opening an account with {$brand} is done {$where} and usually takes about {$minutes} minutes, plus approval time. This is a factual walkthrough of the process — educational information, not a recommendation. Whether {$brand} fits you is a different question: the review covers who it tends to suit.";

		$needs = $pt
			? array(
				'Cartão de Cidadão ou passaporte válido.',
				'O teu NIF (número de identificação fiscal).',
				'Comprovativo de morada, se for pedido (fatura recente ou extrato bancário).',
				'Uma conta bancária em teu nome para o primeiro depósito.',
			)
			: array(
				'A valid Citizen Card or passport.',
				'Your NIF (Portuguese tax number).',
				'Proof of address, if requested (a recent utility bill or bank statement).',
				'A bank account in your own name for the first deposit.',
			);

		$steps = $pt
			? array(
				'app' === $p['channel']
					? "Instala a app oficial da {$brand} a partir da App Store ou do Google Play — confirma que é a app oficial, não uma imitação."
					: ( 'web' === $p['channel']
						? "Vai ao site oficial da {$brand} — escreve tu o endereço no browser em vez de seguires anúncios, para evitar páginas falsas."
						: "Instala a app oficial da {$brand} (App Store/Google Play) ou vai ao site oficial — escreve tu o endereço, para evitar páginas falsas." ),
				'Começa o registo com o teu email e uma password forte, e confirma o email.',
				'Preenche os dados pessoais, incluindo o NIF e a residência fiscal.',
				'Verifica a identidade com o Cartão de Cidadão ou passaporte (fotografia ou vídeo curto).',
				'Responde ao questionário de adequação com honestidade — é uma proteção tua, exigida pelas regras europeias, não uma formalidade.',
				'Faz o primeiro depósito por transferência a partir de uma conta em teu nome.',
			)
			: array(
				'app' === $p['channel']
					? "Install the official {$brand} app from the App Store or Google Play — make sure it is the official app, not a lookalike."
					: ( 'web' === $p['channel']
						? "Go to {$brand}'s official website — type the address yourself rather than following ads, to avoid fake pages."
						: "Install the official {$brand} app (App Store/Google Play) or go to the official website — type the address yourself, to avoid fake pages." ),
				'Start the sign-up with your email and a strong password, and confirm the email.',
				'Fill in your personal details, including your NIF and tax residency.',
				'Verify your identity with your Citizen Card or passport (a photo or short video).',
				'Answer the suitability questionnaire honestly — it is a protection for you, required by EU rules, not a formality.',
				'Make the first deposit by bank transfer from an account in your own name.',
			);

		$after = $pt
			? 'A aprovação costuma ser rápida, mas pode demorar mais quando os documentos precisam de revisão manual. Quando a conta abrir, começa pequeno: um primeiro depósito modesto chega para conheceres a plataforma com calma.'
			: 'Approval is usually quick, but can take longer when documents need a manual review. Once the account opens, start small: a modest first deposit is enough to get to know the platform calmly.';

		$costs = $pt
			? "Antes de investir, lê o preçário publicado pela {$brand} — comissões de negociação, custos de conversão cambial e eventuais custos de inatividade. Os custos mudam; o documento oficial é a única fonte a confiar."
			: "Before investing, read {$brand}'s published price list — dealing fees, currency-conversion costs and any inactivity charges. Costs change; the official document is the only source to trust.";

		$content = self::paragraph( $intro );
		if ( '' !== $note ) {
			$content .= self::paragraph( $note );
		}
		$content .= self::heading( $pt ? 'O que precisas' : 'What you need' )
			. self::bullets( $needs )
			. self::heading( $pt ? 'Passo a passo' : 'Step by step' )
			. self::steps( $steps )
			. self::heading( $pt ? 'Depois da aprovação' : 'After approval' )
			. self::paragraph( $after )
			. '<!-- wp:shortcode -->[hti_broker_cta slug="' . $slug . '" location="guide"]<!-- /wp:shortcode -->' . "\n\n"
			. self::heading( $pt ? 'Custos a ter em conta' : 'Costs to keep in mind' )
			. self::paragraph( $costs )
			. self::links_paragraph(
				$pt,
				array(
					array( $review_href, $pt ? 'Análise completa à ' . $brand : 'Full ' . $brand . ' review' ),
					array( $pillar_href, $pt ? 'Comparar todas as corretoras' : 'Compare all brokers' ),
				)
			);

		return $content;
	}

	/**
	 * Block-markup unordered list.
	 *
	 * @param list<string> $items Items.
	 */
	private static function bullets( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . esc_html( $item ) . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $li . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Block-markup ordered list (the numbered steps).
	 *
	 * @param list<string> $items Items.
	 */
	private static function steps( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . esc_html( $item ) . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">' . $li . '</ol><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * A "keep reading" paragraph of internal links.
	 *
	 * @param bool                          $pt    Portuguese?
	 * @param list<array{0:string,1:string}> $links [href, label] pairs.
	 */
	private static function links_paragraph( bool $pt, array $links ): string {
		$parts = array();
		foreach ( $links as $pair ) {
			$parts[] = '<a href="' . esc_url( $pair[0] ) . '">' . esc_html( $pair[1] ) . '</a>';
		}
		$label = $pt ? 'Continuar a ler: ' : 'Keep reading: ';
		return '<!-- wp:paragraph --><p>' . esc_html( $label ) . implode( ' · ', $parts ) . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * Insert one section page (EN). Skips existing.
	 *
	 * @param array<string,mixed> $entry Page entry.
	 * @return int New post ID, or 0 if skipped/failed.
	 */
	private static function insert_page( array $entry ): int {
		if ( get_page_by_path( $entry['slug'], OBJECT, 'page' ) instanceof \WP_Post ) {
			return 0;
		}

		$postarr = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $entry['title'],
			'post_name'    => $entry['slug'],
			'post_content' => $entry['content'],
		);
		if ( ! empty( $entry['page_template'] ) ) {
			$postarr['page_template'] = (string) $entry['page_template'];
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) || 0 === $id ) {
			return 0;
		}
		$id = (int) $id;

		// Same Polylang trap as insert_broker(): tag the EN page explicitly.
		if ( function_exists( 'pll_set_post_language' ) && '' !== self::default_lang() ) {
			pll_set_post_language( $id, self::default_lang() );
		}

		update_post_meta( $id, self::SEED_FLAG, VERSION );
		self::write_seo_meta( $id, (array) ( $entry['seo'] ?? array() ) );

		return $id;
	}

	/* -------------------------------------------------------------------------
	 * Insertion + translation.
	 * ---------------------------------------------------------------------- */

	/**
	 * Insert one broker post (EN) with meta + use-case terms. Skips existing.
	 *
	 * @param array<string,mixed> $entry Broker entry.
	 * @return int New post ID, or 0 if skipped/failed.
	 */
	private static function insert_broker( array $entry ): int {
		if ( get_page_by_path( $entry['slug'], OBJECT, 'broker' ) instanceof \WP_Post ) {
			return 0;
		}

		$postarr = array(
			'post_type'    => 'broker',
			'post_status'  => 'publish',
			'post_title'   => $entry['title'],
			'post_name'    => $entry['slug'],
			'post_content' => $entry['content'],
			'post_excerpt' => $entry['excerpt'] ?? '',
			'menu_order'   => (int) ( $entry['menu_order'] ?? 0 ),
		);

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) || 0 === $id ) {
			return 0;
		}
		$id = (int) $id;

		// Polylang auto-assigns the ADMIN's current language to programmatic
		// inserts. This English record must be tagged as the default language
		// explicitly, or (seeded from a PT admin) it would masquerade as the PT
		// version and block its real translation from ever being created.
		if ( function_exists( 'pll_set_post_language' ) && '' !== self::default_lang() ) {
			pll_set_post_language( $id, self::default_lang() );
		}

		update_post_meta( $id, self::SEED_FLAG, VERSION );
		self::write_seo_meta( $id, (array) ( $entry['seo'] ?? array() ) );
		self::apply_meta( $id, (array) ( $entry['meta'] ?? array() ) );

		$terms = array();
		foreach ( (array) ( $entry['use_cases'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'broker_use_case' );
			if ( $term instanceof \WP_Term ) {
				$terms[] = (int) $term->term_id;
			}
		}
		if ( $terms ) {
			wp_set_object_terms( $id, $terms, 'broker_use_case', false );
		}

		return $id;
	}

	/**
	 * Write the hti_broker_* meta for a seeded record. Defaults keep the record
	 * compliant: no affiliate URL, deal inactive, study verification date.
	 *
	 * @param int                  $id   Post ID.
	 * @param array<string,string> $meta Field key (unprefixed) → value.
	 */
	private static function apply_meta( int $id, array $meta ): void {
		$meta = array_merge(
			array(
				'affiliate_url'    => '',
				'affiliate_active' => '',
				'verified'         => self::VERIFIED,
			),
			$meta
		);
		foreach ( $meta as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			update_post_meta( $id, Broker_Admin::PREFIX . $key, (string) $value );
		}
	}

	/**
	 * Create linked PT translations for the use-case terms and broker posts.
	 * Translations are CONTENT-ONLY: broker meta stays on the EN post (the
	 * single source renderers read), so a deal flip is one edit.
	 *
	 * @return int Number of PT posts created.
	 */
	private static function seed_translations(): int {
		if ( ! self::polylang_active() ) {
			return 0;
		}

		$en = (string) pll_default_language( 'slug' );
		if ( '' === $en ) {
			$en = 'en';
		}
		$pt = self::portuguese_slug( $en );
		if ( '' === $pt || $pt === $en ) {
			return 0;
		}

		self::translate_use_cases( $en, $pt );

		$created = 0;
		foreach ( self::brokers() as $entry ) {
			$en_post = get_page_by_path( $entry['slug'], OBJECT, 'broker' );
			if ( ! $en_post instanceof \WP_Post ) {
				continue;
			}
			$en_id = (int) $en_post->ID;

			// FORCE the default language — never trust what Polylang assigned.
			// Posts seeded from a PT admin got auto-tagged 'pt', which made
			// pll_get_post() below return the post itself as its own "PT
			// translation" and left the PT site serving the English review.
			// Forcing 'en' both prevents that on fresh seeds and repairs
			// already-seeded sites on the next run.
			if ( $en !== (string) pll_get_post_language( $en_id ) ) {
				pll_set_post_language( $en_id, $en );
			}

			if ( pll_get_post( $en_id, $pt ) ) {
				continue;
			}

			$pt_data = (array) ( $entry['pt'] ?? array() );
			if ( empty( $pt_data['title'] ) ) {
				continue;
			}

			$postarr = array(
				'post_type'    => 'broker',
				'post_status'  => 'publish',
				'post_title'   => $pt_data['title'],
				'post_content' => (string) ( $pt_data['content'] ?? '' ),
				'post_excerpt' => (string) ( $pt_data['excerpt'] ?? '' ),
				'menu_order'   => (int) ( $entry['menu_order'] ?? 0 ),
			);

			$pt_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $pt_id ) || 0 === $pt_id ) {
				continue;
			}
			$pt_id = (int) $pt_id;

			pll_set_post_language( $pt_id, $pt );
			wp_update_post(
				array(
					'ID'        => $pt_id,
					'post_name' => self::pt_slug( (string) $entry['slug'] ),
				)
			);
			pll_save_post_translations( array( $en => $en_id, $pt => $pt_id ) );

			update_post_meta( $pt_id, self::SEED_FLAG, VERSION );
			self::write_seo_meta( $pt_id, (array) ( $pt_data['seo'] ?? array() ) );

			// File under the PT twins of the EN use-case terms.
			$terms = array();
			foreach ( (array) ( $entry['use_cases'] ?? array() ) as $slug ) {
				$term = get_term_by( 'slug', $slug . '-' . $pt, 'broker_use_case' );
				if ( $term instanceof \WP_Term ) {
					$terms[] = (int) $term->term_id;
				}
			}
			if ( $terms ) {
				wp_set_object_terms( $pt_id, $terms, 'broker_use_case', false );
			}

			++$created;
		}

		foreach ( array_merge( self::pages(), self::guides() ) as $entry ) {
			$en_post = get_page_by_path( $entry['slug'], OBJECT, 'page' );
			if ( ! $en_post instanceof \WP_Post ) {
				continue;
			}
			$en_id = (int) $en_post->ID;

			// Same forced-language rule as the broker loop (see comment there).
			if ( $en !== (string) pll_get_post_language( $en_id ) ) {
				pll_set_post_language( $en_id, $en );
			}

			if ( pll_get_post( $en_id, $pt ) ) {
				continue;
			}

			$pt_data = (array) ( $entry['pt'] ?? array() );
			if ( empty( $pt_data['title'] ) ) {
				continue;
			}

			$postarr = array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $pt_data['title'],
				'post_content' => (string) ( $pt_data['content'] ?? '' ),
			);
			if ( ! empty( $entry['page_template'] ) ) {
				$postarr['page_template'] = (string) $entry['page_template'];
			}

			$pt_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $pt_id ) || 0 === $pt_id ) {
				continue;
			}
			$pt_id = (int) $pt_id;

			pll_set_post_language( $pt_id, $pt );
			wp_update_post(
				array(
					'ID'        => $pt_id,
					'post_name' => self::page_pt_slug( (string) $entry['slug'], (string) $pt_data['title'] ),
				)
			);
			pll_save_post_translations( array( $en => $en_id, $pt => $pt_id ) );

			update_post_meta( $pt_id, self::SEED_FLAG, VERSION );
			self::write_seo_meta( $pt_id, (array) ( $pt_data['seo'] ?? array() ) );

			++$created;
		}

		return $created;
	}

	/* -------------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------- */

	/* -------------------------------------------------------------------------
	 * Full reviews (financial-analyst + seo-content skills).
	 *
	 * Every number below was re-verified against primary sources on the date in
	 * self::VERIFIED; anything not verifiable that day is written qualitatively
	 * ("published on their price list") instead of as a number. CFD loss
	 * percentages are deliberately NOT hardcoded — the metabox field feeds the
	 * live ESMA warning once confirmed on each broker's own page.
	 * ---------------------------------------------------------------------- */

	/**
	 * The dated "facts checked" line every review carries in its costs section.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function asof( bool $pt ): string {
		return self::phtml(
			$pt
				? '<em>Factos verificados nos documentos oficiais a 27 de agosto de 2026. Custos mudam — o preçário da corretora é sempre a fonte final.</em>'
				: '<em>Facts checked against official documents on 27 August 2026. Costs change — the broker\'s own price list is always the final word.</em>'
		);
	}

	/**
	 * XTB — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_xtb( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A XTB é uma das maiores corretoras cotadas da Europa e a única desta comparação com <strong>sucursal em Lisboa sob supervisão direta da CMVM (registo nº 341)</strong>. Para quem investe a partir de Portugal, junta três coisas raras no mesmo sítio: ações e ETFs sem comissões até um limite mensal, planos de investimento automáticos desde 15 €, e apoio local em português. A vertente de CFDs existe e é grande no marketing da casa — mas é opcional e vive numa conta separada.' )
				. self::heading( 'Quanto custa investir na XTB?' )
				. self::bullets_html(
					array(
						'<strong>Ações e ETFs: 0% de comissão até um limite de volume mensal</strong> publicado no preçário; acima disso aplica-se comissão.',
						'<strong>Planos de Investimento: desde 15 €</strong>, automáticos, com alocação entre ETFs à tua escolha e execução sem comissão adicional.',
						'Conversão cambial: custo percentual em ordens fora do euro — é o custo mais fácil de esquecer.',
						'Juros sobre o saldo não investido: <strong>paga, com taxa variável por moeda</strong>, publicada na plataforma; a sucursal portuguesa trata retenções fiscais na fonte em vários casos (confirma a tua situação).',
						'Opções sobre ações/índices: lançadas em Portugal em 2026, com preçário próprio.',
					)
				)
				. self::asof( true )
				. self::heading( 'A XTB é segura e regulada?' )
				. self::paragraph( 'A XTB S.A. é cotada na bolsa de Varsóvia e supervisionada pelo regulador polaco (KNF); em Portugal opera através de sucursal registada na CMVM com o nº 341 — podes confirmá-lo no registo público de intermediários da CMVM. Os teus instrumentos ficam segregados dos ativos da empresa, e aplica-se o sistema polaco de compensação de investidores, com os limites publicados pela KNF. Ser cotada acrescenta uma camada de transparência: contas auditadas e resultados públicos todos os trimestres.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações e ETFs reais de dezenas de bolsas, planos de investimento automáticos, opções e — numa conta separada — CFDs para traders. Não há cripto real (só exposição via derivados, que este site não cobre). A plataforma xStation é das mais polidas do mercado, em web e app, com conta demo ilimitada e uma secção educativa extensa em português.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Sucursal em Lisboa: apoio em português e supervisão direta da CMVM.',
						'0% de comissões em ações/ETFs até ao limite mensal — suficiente para a maioria de quem investe aos poucos.',
						'Planos automáticos desde 15 €, ideais para reforços mensais.',
						'Juros sobre o saldo e plataforma muito completa.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'O marketing empurra para os CFDs — exige disciplina para ficar só na conta de investimento.',
						'Custos de conversão cambial em ordens fora do euro.',
						'Sem cripto real e sem obrigações diretas.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Um perfil que quer uma plataforma completa, com presença local, automatização e custos baixos, costuma pôr a XTB no topo da lista — é a escolha mais "sem atritos" para quem começa em Portugal. Quem quer cripto real, ou prefere uma casa sem qualquer vertente de trading alavancado, tende a olhar antes para a Trade Republic ou a Lightyear.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'A XTB é regulada em Portugal?' )
				. self::paragraph( 'Sim — opera cá através de sucursal registada na CMVM (nº 341), com a casa-mãe supervisionada pelo regulador polaco. É a única corretora internacional desta comparação com presença física em Lisboa.' )
				. self::h3( 'Tenho de usar CFDs na XTB?' )
				. self::paragraph( 'Não. A conta de investimento em ações e ETFs reais é independente; os CFDs são um produto separado, de risco elevado, dirigido a traders experientes.' )
				. self::h3( 'A XTB paga juros sobre o dinheiro parado?' )
				. self::paragraph( 'Paga, com taxa variável por moeda e condições publicadas na plataforma — vale a pena confirmar a taxa em vigor antes de contar com ela.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-xtb/">como abrir conta na XTB, passo a passo</a> · <a href="/pt/melhores-corretoras-em-portugal/">comparação de todas as corretoras</a> · <a href="/pt/melhores-corretoras-para-etfs/">melhores corretoras para ETFs</a>' );
		}

		return self::phtml( 'XTB is one of Europe\'s largest listed brokers and the only one in this comparison with a <strong>Lisbon branch under direct CMVM supervision (register nº 341)</strong>. For investors in Portugal it combines three things rarely found together: commission-free stocks and ETFs up to a monthly limit, automatic investment plans from €15, and local support in Portuguese. The CFD arm exists and looms large in the marketing — but it is optional and lives in a separate account.' )
			. self::heading( 'How much does investing with XTB cost?' )
			. self::bullets_html(
				array(
					'<strong>Stocks and ETFs: 0% commission up to a monthly volume limit</strong> published on the price list; commission applies above it.',
					'<strong>Investment Plans: from €15</strong>, automatic, with your own ETF allocation and no extra execution fee.',
					'Currency conversion: a percentage cost on non-EUR orders — the easiest cost to forget.',
					'Interest on uninvested cash: <strong>paid, at a variable per-currency rate</strong> published in the platform; the Portuguese branch handles tax withholding at source in several cases (confirm your own situation).',
					'Stock/index options: rolled out in Portugal in 2026, with their own pricing.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is XTB safe and regulated?' )
			. self::paragraph( 'XTB S.A. is listed on the Warsaw Stock Exchange and supervised by Poland\'s KNF; in Portugal it operates through a branch registered with the CMVM under nº 341 — you can confirm it in the CMVM\'s public register. Client instruments are segregated from the firm\'s assets, and the Polish investor-compensation scheme applies, with limits published by the KNF. Being listed adds a transparency layer: audited accounts and public results every quarter.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Real stocks and ETFs across dozens of exchanges, automatic investment plans, options and — in a separate account — CFDs for traders. There is no real crypto (only derivative exposure, which this site does not cover). The xStation platform is among the most polished around, on web and mobile, with an unlimited demo and a large Portuguese-language education section.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Lisbon branch: Portuguese support and direct CMVM supervision.',
					'0% commission on stocks/ETFs up to the monthly limit — enough for most gradual investors.',
					'Automatic plans from €15, ideal for monthly top-ups.',
					'Interest on cash and a very complete platform.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'The marketing pushes CFDs — staying on the investment account takes discipline.',
					'Currency-conversion costs on non-EUR orders.',
					'No real crypto and no direct bonds.',
				)
			)
			. self::heading( 'Who does XTB tend to suit?' )
			. self::paragraph( 'A profile that wants a complete platform with local presence, automation and low costs usually shortlists XTB first — it is the lowest-friction choice for getting started from Portugal. Someone who wants real crypto, or prefers a house with no leveraged-trading arm at all, tends to look at Trade Republic or Lightyear instead.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Is XTB regulated in Portugal?' )
			. self::paragraph( 'Yes — it operates here through a CMVM-registered branch (nº 341), with the parent supervised by Poland\'s KNF. It is the only international broker in this comparison with a physical presence in Lisbon.' )
			. self::h3( 'Do I have to use CFDs at XTB?' )
			. self::paragraph( 'No. The real stock and ETF investment account is independent; CFDs are a separate, high-risk product aimed at experienced traders.' )
			. self::h3( 'Does XTB pay interest on idle cash?' )
			. self::paragraph( 'It does, at a variable per-currency rate with conditions published in the platform — worth confirming the current rate before counting on it.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-xtb/">how to open an XTB account, step by step</a> · <a href="/best-brokers-in-portugal/">the full broker comparison</a> · <a href="/best-etf-brokers-portugal/">best brokers for ETFs</a>' );
	}

	/**
	 * Trading 212 — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_trading212( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A Trading 212 popularizou o investimento sem comissões na Europa e continua a ser das formas mais baratas de comprar ações e ETFs a partir de Portugal: <strong>0% de comissão, frações de ações desde 1 € e "pies" que automatizam a carteira</strong>. A conta é multi-moeda, o saldo rende juros diários, e a vertente CFD — que existe — está bem separada da conta Invest.' )
				. self::heading( 'Quanto custa investir na Trading 212?' )
				. self::bullets_html(
					array(
						'<strong>Ações e ETFs: 0% de comissão</strong>, sem limite de ordens.',
						'<strong>Conversão cambial: 0,15%</strong> ao negociar fora da moeda da conta — a conta multi-moeda (mais de uma dezena de moedas) permite reduzir este custo.',
						'Juros sobre o saldo não investido: <strong>pagos diariamente, taxa variável por moeda</strong>, publicada na app.',
						'Cartão de débito opcional com cashback, com regras próprias (modelo revisto em 2026 — confirma as condições atuais).',
					)
				)
				. self::asof( true )
				. self::heading( 'A Trading 212 é segura e regulada?' )
				. self::paragraph( 'Os clientes portugueses são servidos pela Trading 212 EU GmbH, entidade alemã supervisionada pelo BaFin, com o grupo também regulado pela CySEC e pela FCA. Os instrumentos ficam segregados e aplica-se o regime alemão de compensação de investidores, nos limites legais publicados. Como em qualquer corretora, o dinheiro não investido pode ser distribuído por bancos parceiros e fundos monetários — a app detalha onde.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações e ETFs reais dos principais mercados, frações desde 1 €, e os "pies": carteiras-alvo com percentagens por fatia que a app mantém automaticamente com os teus reforços — na prática, um plano de investimento automático gratuito. A conta CFD é separada e de risco elevado; não há obrigações diretas nem fundos tradicionais.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Dos custos totais mais baixos do mercado para ações e ETFs.',
						'Pies: automatização gratuita e simples de reforços mensais.',
						'Juros diários sobre o saldo e conta multi-moeda.',
						'Frações desde 1 € — dá para começar com muito pouco.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'A conta CFD está a um toque de distância — exige atenção de principiante.',
						'Sem obrigações diretas, fundos ou PPR.',
						'Apoio ao cliente apenas digital.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Quem começa com pouco e quer automatizar reforços mensais ao custo mais baixo possível costuma considerar a Trading 212 primeiro. Quem valoriza presença local e apoio humano tende a preferir a XTB; quem quer eliminar por completo a tentação dos CFDs olha para a Lightyear ou a Trade Republic.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'O que são os "pies" da Trading 212?' )
				. self::paragraph( 'São carteiras-alvo: defines fatias percentuais (por exemplo, três ETFs a 60/30/10) e a app distribui automaticamente cada reforço para manter as proporções. É a forma mais simples de automatizar sem custos.' )
				. self::h3( 'A conta Invest e a conta CFD são a mesma coisa?' )
				. self::paragraph( 'Não. A Invest compra ativos reais; a CFD negoceia derivados alavancados de risco elevado. Para investir a longo prazo, é a Invest que interessa.' )
				. self::h3( 'O saldo em euros rende juros?' )
				. self::paragraph( 'Sim, com pagamento diário e taxa variável publicada na app. Confirma a taxa em vigor e as condições de onde o dinheiro é guardado.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-trading-212/">como abrir conta na Trading 212</a> · <a href="/pt/melhores-corretoras-para-iniciantes/">melhores corretoras para iniciantes</a> · <a href="/pt/brokers/xtb-analise/">análise completa à XTB</a>' );
		}

		return self::phtml( 'Trading 212 popularised commission-free investing in Europe and remains one of the cheapest ways to buy stocks and ETFs from Portugal: <strong>0% commission, fractional shares from €1 and "pies" that automate your portfolio</strong>. The account is multi-currency, idle cash earns daily interest, and the CFD arm — which exists — is well separated from the Invest account.' )
			. self::heading( 'How much does Trading 212 cost?' )
			. self::bullets_html(
				array(
					'<strong>Stocks and ETFs: 0% commission</strong>, with no order limit.',
					'<strong>Currency conversion: 0.15%</strong> when trading outside your account currency — the multi-currency account (a dozen-plus currencies) helps keep this down.',
					'Interest on uninvested cash: <strong>paid daily, variable per-currency rate</strong> published in the app.',
					'Optional debit card with cashback, under its own rules (model revised in 2026 — check current conditions).',
				)
			)
			. self::asof( false )
			. self::heading( 'Is Trading 212 safe and regulated?' )
			. self::paragraph( 'Portuguese clients are served by Trading 212 EU GmbH, a German entity supervised by BaFin, with group entities also regulated by CySEC and the FCA. Instruments are segregated and the German investor-compensation regime applies, within its published legal limits. As at any broker, uninvested cash may sit with partner banks and money-market funds — the app details where.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Real stocks and ETFs from the main markets, fractions from €1, and the "pies": target portfolios with per-slice percentages the app maintains automatically with each top-up — in practice, a free automatic investment plan. The CFD account is separate and high-risk; there are no direct bonds or traditional funds.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Among the lowest all-in costs anywhere for stocks and ETFs.',
					'Pies: free, simple automation of monthly top-ups.',
					'Daily interest on cash and a multi-currency account.',
					'Fractions from €1 — you can start with very little.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'The CFD account is one tap away — beginners need to mind the door.',
					'No direct bonds, funds or pension wrappers.',
					'Digital-only customer support.',
				)
			)
			. self::heading( 'Who does Trading 212 tend to suit?' )
			. self::paragraph( 'Someone starting small who wants to automate monthly top-ups at the lowest possible cost usually considers Trading 212 first. Those who value local presence and human support tend to prefer XTB; those who want zero CFD temptation look at Lightyear or Trade Republic.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'What are Trading 212 "pies"?' )
			. self::paragraph( 'Target portfolios: you set percentage slices (say three ETFs at 60/30/10) and the app spreads every top-up automatically to keep the proportions. It is the simplest free way to automate.' )
			. self::h3( 'Are the Invest and CFD accounts the same thing?' )
			. self::paragraph( 'No. Invest buys real assets; CFD trades leveraged, high-risk derivatives. For long-term investing, Invest is the one that matters.' )
			. self::h3( 'Does the euro balance earn interest?' )
			. self::paragraph( 'Yes, paid daily at a variable rate published in the app. Check the current rate and where the cash is held.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-trading-212/">how to open a Trading 212 account</a> · <a href="/best-brokers-for-beginners-portugal/">best brokers for beginners</a> · <a href="/brokers/xtb/">full XTB review</a>' );
	}

	/**
	 * Trade Republic — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_trade_republic( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A Trade Republic é um <strong>banco alemão com licença plena</strong> que transformou o telemóvel numa conta de investimento: planos de poupança gratuitos em ações, ETFs, obrigações e cripto, <strong>juros sobre o saldo que seguem a taxa de depósito do BCE</strong>, e um cartão que investe parte do que gastas. Não oferece CFDs — o que a torna uma das casas mais "à prova de tentações" desta lista.' )
				. self::heading( 'Quanto custa investir na Trade Republic?' )
				. self::bullets_html(
					array(
						'<strong>Planos de poupança: execução gratuita</strong>, em ações, ETFs, obrigações e cripto, a partir de valores baixos.',
						'<strong>Ordens manuais: 1 € de taxa externa por ordem</strong>, fixo, seja qual for o tamanho.',
						'Juros sobre o saldo: <strong>seguem a taxa de depósito do BCE</strong>, com variações por mercado e promoções pontuais — a taxa em vigor está sempre na app.',
						'Cartão com "Saveback": 1% dos pagamentos volta para os teus planos, até 15 €/mês, com condições próprias.',
					)
				)
				. self::asof( true )
				. self::heading( 'A Trade Republic é segura?' )
				. self::paragraph( 'É um banco alemão de pleno direito, supervisionado pelo BaFin. Na prática isso significa que o saldo em dinheiro beneficia da garantia de depósitos alemã até 100 000 € por titular, e os instrumentos ficam segregados em custódia. Está registada para operar em Portugal e a app existe em português.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações e ETFs (com frações), obrigações — incluindo em plano de poupança —, cripto real, e desde 2026 acesso a investimentos de mercados privados através de parcerias com gestoras como a Apollo e a EQT (um produto para perfis mais avançados; lê as condições com calma). A execução concentra-se numa única praça de negociação, o que simplifica mas limita a escolha de horários e contrapartes.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Planos automáticos gratuitos — provavelmente a forma mais barata de investir todos os meses.',
						'Juros ligados ao BCE sobre o saldo, num banco com garantia de depósitos.',
						'Sem CFDs: a app não te empurra para trading alavancado.',
						'Cartão com Saveback que reforça os planos.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'1 € por cada ordem manual fora dos planos.',
						'Execução numa única praça de negociação.',
						'Apoio ao cliente com fama de lento em horas de ponta (nota editorial, da experiência comum de utilizadores).',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Quem quer pôr a carteira em piloto automático — um plano mensal em ETFs, juros no que sobra, zero decisões de trading — costuma achar a Trade Republic imbatível. Quem negoceia com frequência fora de planos, ou quer escolher bolsas, tende a preferir a DEGIRO ou a Interactive Brokers.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'A Trade Republic é mesmo um banco?' )
				. self::paragraph( 'Sim — tem licença bancária alemã plena e supervisão do BaFin, com o saldo coberto pela garantia de depósitos alemã até 100 000 €.' )
				. self::h3( 'O que custa comprar fora dos planos?' )
				. self::paragraph( 'Cada ordem manual paga 1 € de taxa externa, independentemente do valor. Nos planos de poupança a execução é gratuita.' )
				. self::h3( 'Os juros são fixos?' )
				. self::paragraph( 'Não — seguem a taxa de depósito do BCE e podem mudar com ela; há também variações por mercado e promoções para contas novas. A taxa em vigor está na app.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-trade-republic/">como abrir conta na Trade Republic</a> · <a href="/pt/melhores-corretoras-com-juros-sobre-o-saldo/">corretoras que pagam juros sobre o saldo</a> · <a href="/pt/melhores-corretoras-para-etfs/">melhores corretoras para ETFs</a>' );
		}

		return self::phtml( 'Trade Republic is a <strong>fully licensed German bank</strong> that turned the phone into an investment account: free savings plans across stocks, ETFs, bonds and crypto, <strong>interest on cash that tracks the ECB deposit rate</strong>, and a card that invests part of what you spend. It offers no CFDs — making it one of the most temptation-proof houses on this list.' )
			. self::heading( 'How much does Trade Republic cost?' )
			. self::bullets_html(
				array(
					'<strong>Savings plans: free execution</strong> across stocks, ETFs, bonds and crypto, from small amounts.',
					'<strong>Manual orders: a flat €1 external fee per order</strong>, whatever the size.',
					'Interest on cash: <strong>tracks the ECB deposit facility rate</strong>, with per-market variations and occasional promotions — the live rate is always in the app.',
					'Card with "Saveback": 1% of card spending flows back into your plans, up to €15/month, under its own conditions.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is Trade Republic safe?' )
			. self::paragraph( 'It is a full German bank supervised by BaFin. In practice that means cash balances sit under the German deposit guarantee up to €100,000 per holder, and instruments are held segregated in custody. It is registered to operate in Portugal and the app is available in Portuguese.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Stocks and ETFs (with fractions), bonds — including in savings plans —, real crypto, and since 2026 access to private-market investments through partnerships with managers like Apollo and EQT (a product for more advanced profiles; read the terms calmly). Execution is concentrated on a single trading venue, which simplifies things but limits venue choice.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Free automatic plans — probably the cheapest way to invest every month.',
					'ECB-linked interest on cash, inside a deposit-guaranteed bank.',
					'No CFDs: the app never nudges you toward leveraged trading.',
					'Card Saveback that tops up your plans.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'€1 for every manual order outside the plans.',
					'Execution on a single trading venue.',
					'Customer support has a reputation for slowness at peak times (editorial note, from common user experience).',
				)
			)
			. self::heading( 'Who does Trade Republic tend to suit?' )
			. self::paragraph( 'Anyone who wants the portfolio on autopilot — a monthly ETF plan, interest on what is left over, zero trading decisions — tends to find Trade Republic hard to beat. Frequent manual traders, or those who want to pick exchanges, usually prefer DEGIRO or Interactive Brokers.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Is Trade Republic really a bank?' )
			. self::paragraph( 'Yes — it holds a full German banking licence under BaFin supervision, with cash covered by the German deposit guarantee up to €100,000.' )
			. self::h3( 'What does buying outside the plans cost?' )
			. self::paragraph( 'Each manual order carries a flat €1 external fee, regardless of size. Savings-plan executions are free.' )
			. self::h3( 'Is the interest rate fixed?' )
			. self::paragraph( 'No — it tracks the ECB deposit rate and moves with it; there are also per-market variations and new-account promotions. The live rate is in the app.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-trade-republic/">how to open a Trade Republic account</a> · <a href="/best-interest-on-cash-accounts-portugal/">brokers that pay interest on cash</a> · <a href="/best-etf-brokers-portugal/">best brokers for ETFs</a>' );
	}

	/**
	 * Lightyear — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_lightyear( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A Lightyear é a corretora mais "calma" desta comparação: interface simples, custos baixos e <strong>zero CFDs em qualquer parte do produto</strong> — não há porta errada por onde entrar. Fundada por antigos quadros da Wise e regulada na Estónia, chegou a Portugal com ações, ETFs, planos automáticos e, desde junho de 2026, os <strong>Vaults: fundos do mercado monetário para o dinheiro parado</strong>.' )
				. self::heading( 'Quanto custa investir na Lightyear?' )
				. self::bullets_html(
					array(
						'Ações: <strong>comissões baixas por ordem</strong>, publicadas abertamente no preçário.',
						'ETFs: <strong>plafond mensal de execuções gratuitas</strong>; comissão baixa acima disso.',
						'Conversão cambial: pequena taxa percentual, herança da cultura Wise de câmbio transparente.',
						'Vaults (fundos monetários): <strong>taxa variável, líquida de comissões, publicada na app</strong> — disponíveis em Portugal desde junho de 2026.',
						'Planos automáticos (Auto-Invest): sem custo adicional de execução.',
					)
				)
				. self::asof( true )
				. self::heading( 'A Lightyear é segura e regulada?' )
				. self::paragraph( 'A Lightyear Europe AS é supervisionada pela Finantsinspektsioon, o regulador financeiro da Estónia, e serve clientes em quase toda a UE, incluindo Portugal. Os instrumentos ficam segregados e aplica-se o sistema estónio de proteção de investidores, nos limites legais publicados. É uma empresa jovem (2020) — mais pequena do que os gigantes desta lista, mas com investidores de peso e crescimento consistente de produto.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações dos EUA, Reino Unido e Europa, ETFs, contas multi-moeda, planos Auto-Invest e os Vaults de fundos monetários. Não há CFDs, opções, obrigações diretas nem cripto — e para o público desta casa, essa simplicidade é uma funcionalidade, não uma falta. A app e a versão web são das mais limpas do mercado.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Único produto da lista sem qualquer vertente de trading alavancado.',
						'Custos baixos e transparentes, com plafond gratuito em ETFs.',
						'Vaults: o dinheiro parado rende num fundo monetário, sem sair da app.',
						'Interface calma que não gamifica o investimento.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'Universo de instrumentos mais pequeno do que XTB, DEGIRO ou IBKR.',
						'Empresa mais jovem e mais pequena do que os incumbentes.',
						'Sem obrigações diretas, fundos tradicionais ou cripto.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Um perfil que quer investir a longo prazo sem ruído — e gosta de saber que não há um produto de trading à espreita — costuma sentir-se em casa na Lightyear. Quem precisa de gama ampla (opções, obrigações, muitas bolsas) tende a olhar para a Interactive Brokers ou o Saxo.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'Quem regula a Lightyear?' )
				. self::paragraph( 'A Finantsinspektsioon da Estónia, com passaporte europeu para servir Portugal. Os instrumentos ficam segregados e há proteção de investidores nos termos do regime estónio.' )
				. self::h3( 'O que são os Vaults?' )
				. self::paragraph( 'Uma forma de pôr o dinheiro parado a render num fundo do mercado monetário, com taxa variável líquida de comissões, resgatável a qualquer momento. Chegaram a Portugal em junho de 2026.' )
				. self::h3( 'A Lightyear tem CFDs?' )
				. self::paragraph( 'Não — é a única corretora desta comparação sem qualquer produto alavancado, o que a torna especialmente simples de entender e de explicar.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-lightyear/">como abrir conta na Lightyear</a> · <a href="/pt/melhores-corretoras-para-iniciantes/">melhores corretoras para iniciantes</a> · <a href="/pt/brokers/trade-republic-analise/">análise completa à Trade Republic</a>' );
		}

		return self::phtml( 'Lightyear is the calmest broker in this comparison: a simple interface, low costs and <strong>zero CFDs anywhere in the product</strong> — there is no wrong door to walk through. Founded by ex-Wise leaders and regulated in Estonia, it serves Portugal with stocks, ETFs, automatic plans and, since June 2026, <strong>Vaults: money-market funds for idle cash</strong>.' )
			. self::heading( 'How much does Lightyear cost?' )
			. self::bullets_html(
				array(
					'Stocks: <strong>low per-order commissions</strong>, published openly on the price list.',
					'ETFs: <strong>a monthly allowance of free executions</strong>; a low commission above it.',
					'Currency conversion: a small percentage fee, inherited from the Wise culture of transparent FX.',
					'Vaults (money-market funds): <strong>variable rate, net of fees, published in the app</strong> — available in Portugal since June 2026.',
					'Auto-Invest plans: no extra execution cost.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is Lightyear safe and regulated?' )
			. self::paragraph( 'Lightyear Europe AS is supervised by Finantsinspektsioon, Estonia\'s financial regulator, and serves clients across most of the EU, Portugal included. Instruments are segregated and the Estonian investor-protection scheme applies within its published legal limits. It is a young company (2020) — smaller than the giants on this list, but with heavyweight backers and steady product growth.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'US, UK and European stocks, ETFs, multi-currency accounts, Auto-Invest plans and the money-market Vaults. There are no CFDs, options, direct bonds or crypto — and for this house\'s audience, that simplicity is a feature, not a gap. The app and web version are among the cleanest around.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'The only product on the list with no leveraged-trading arm at all.',
					'Low, transparent costs with a free monthly ETF allowance.',
					'Vaults: idle cash earns in a money-market fund without leaving the app.',
					'A calm interface that never gamifies investing.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'A smaller instrument universe than XTB, DEGIRO or IBKR.',
					'A younger, smaller company than the incumbents.',
					'No direct bonds, traditional funds or crypto.',
				)
			)
			. self::heading( 'Who does Lightyear tend to suit?' )
			. self::paragraph( 'A profile that wants long-term investing without noise — and likes knowing there is no trading product lurking — tends to feel at home at Lightyear. Anyone needing broad range (options, bonds, many exchanges) usually looks at Interactive Brokers or Saxo.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Who regulates Lightyear?' )
			. self::paragraph( 'Estonia\'s Finantsinspektsioon, passported to serve Portugal. Instruments are segregated and investor protection applies under the Estonian regime.' )
			. self::h3( 'What are Vaults?' )
			. self::paragraph( 'A way to put idle cash to work in a money-market fund, at a variable rate net of fees, redeemable any time. They reached Portugal in June 2026.' )
			. self::h3( 'Does Lightyear offer CFDs?' )
			. self::paragraph( 'No — it is the only broker in this comparison with no leveraged product at all, which makes it unusually easy to understand and explain.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-lightyear/">how to open a Lightyear account</a> · <a href="/best-brokers-for-beginners-portugal/">best brokers for beginners</a> · <a href="/brokers/trade-republic/">full Trade Republic review</a>' );
	}

	/**
	 * DEGIRO — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_degiro( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A DEGIRO é a veterana low-cost da Europa: acesso a <strong>dezenas de bolsas com comissões baixas</strong>, uma <strong>seleção "Core" de ETFs sem comissão</strong> (política de uso justo) e a solidez de pertencer ao flatexDEGIRO Bank SE, um banco alemão. Não tem CFDs, não paga juros sobre o saldo, e a interface é mais funcional do que bonita — é uma ferramenta de comprar e guardar, não uma app de lifestyle.' )
				. self::heading( 'Quanto custa investir na DEGIRO?' )
				. self::bullets_html(
					array(
						'ETFs da <strong>Core Selection: sem comissão</strong>, apenas <strong>1 € de taxa de manuseamento</strong> por ordem, sob política de uso justo.',
						'Ações: comissões baixas por ordem + a taxa de manuseamento de 1 €.',
						'<strong>Conversão cambial (AutoFX): 0,25%</strong> — o custo mais relevante para quem compra fora do euro.',
						'Conectividade: <strong>2,50 € por bolsa, por ano</strong>, nas bolsas estrangeiras usadas.',
						'Em 2026 o grupo começou a lançar <strong>preçário zero-commission em praças selecionadas</strong> — confirma no preçário o que já se aplica à tua conta.',
					)
				)
				. self::asof( true )
				. self::heading( 'A DEGIRO é segura e regulada?' )
				. self::paragraph( 'A DEGIRO é o nome comercial do flatexDEGIRO Bank SE (a forma jurídica mudou de AG para SE no final de 2025), supervisionado pelo BaFin, com a sucursal neerlandesa sob olhar do DNB/AFM e registo na CMVM em livre prestação de serviços. Os instrumentos ficam segregados; o dinheiro é tratado no quadro bancário alemão. Sem CFDs em lado nenhum.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações, ETFs, obrigações, fundos e derivados listados em dezenas de bolsas — é das gamas mais amplas do segmento low-cost. A plataforma (web e app) é sóbria e eficiente, com pesquisa boa e pouca decoração. Não há planos automáticos de reforço nativos nem juros sobre o saldo — o dinheiro parado não rende.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Custos totais muito baixos, com a Core Selection de ETFs quase gratuita.',
						'Acesso a muitas bolsas — raro neste nível de preço.',
						'Um banco alemão por trás e nenhuns CFDs.',
						'Duas décadas de história e milhões de clientes na Europa.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'O saldo parado não rende juros.',
						'Estrutura de custos com várias linhas (manuseamento, conectividade, AutoFX) — barata mas menos simples.',
						'Sem planos automáticos nativos; interface funcional q.b.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Quem compra ETFs da Core Selection todos os meses e quer acesso amplo a bolsas ao menor custo costuma ficar muito bem servido. Quem quer juros no saldo e automatização olha antes para a Trade Republic ou a Trading 212.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'O que é a Core Selection de ETFs?' )
				. self::paragraph( 'Uma lista curada de ETFs que se compram sem comissão (fica só a taxa de manuseamento de 1 €), com uma política de uso justo — tipicamente uma compra gratuita por ETF por mês, nas condições publicadas.' )
				. self::h3( 'A DEGIRO paga juros sobre o dinheiro parado?' )
				. self::paragraph( 'Não. Se manter liquidez a render é importante para ti, compara com a Trade Republic, a Trading 212 ou os Vaults da Lightyear.' )
				. self::h3( 'A DEGIRO mudou de dono?' )
				. self::paragraph( 'Não — continua no grupo flatexDEGIRO; apenas a forma jurídica do banco mudou para SE (europeia) no final de 2025, sem impacto para clientes.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-degiro/">como abrir conta na DEGIRO</a> · <a href="/pt/melhores-corretoras-para-etfs/">melhores corretoras para ETFs</a> · <a href="/pt/melhores-corretoras-para-acoes/">melhores corretoras para ações</a>' );
		}

		return self::phtml( 'DEGIRO is Europe\'s low-cost veteran: access to <strong>dozens of exchanges at low commissions</strong>, a <strong>"Core Selection" of commission-free ETFs</strong> (fair-use policy) and the solidity of belonging to flatexDEGIRO Bank SE, a German bank. It has no CFDs, pays no interest on cash, and the interface is more functional than pretty — a buy-and-hold tool, not a lifestyle app.' )
			. self::heading( 'How much does DEGIRO cost?' )
			. self::bullets_html(
				array(
					'<strong>Core Selection ETFs: commission-free</strong>, just a <strong>€1 handling fee</strong> per order, under a fair-use policy.',
					'Stocks: low per-order commissions plus the €1 handling fee.',
					'<strong>Currency conversion (AutoFX): 0.25%</strong> — the cost that matters most outside the euro.',
					'Connectivity: <strong>€2.50 per exchange, per year</strong>, on the foreign exchanges you use.',
					'In 2026 the group began rolling out <strong>zero-commission pricing on selected venues</strong> — check the price list for what applies to your account.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is DEGIRO safe and regulated?' )
			. self::paragraph( 'DEGIRO is the trading name of flatexDEGIRO Bank SE (the legal form changed from AG to SE at the end of 2025), supervised by BaFin, with the Dutch branch also overseen by DNB/AFM and registered with the CMVM under freedom of services. Instruments are segregated; cash is handled within the German banking framework. No CFDs anywhere.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Stocks, ETFs, bonds, funds and listed derivatives across dozens of exchanges — one of the broadest ranges in the low-cost segment. The platform (web and app) is sober and efficient, with good search and little decoration. There are no native auto-invest plans and no interest on cash — idle money earns nothing.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Very low all-in costs, with the near-free Core Selection of ETFs.',
					'Access to many exchanges — rare at this price level.',
					'A German bank behind it and zero CFDs.',
					'Two decades of history and millions of European clients.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'Idle cash earns no interest.',
					'A fee structure with several lines (handling, connectivity, AutoFX) — cheap but less simple.',
					'No native auto-invest; a strictly functional interface.',
				)
			)
			. self::heading( 'Who does DEGIRO tend to suit?' )
			. self::paragraph( 'Someone buying Core Selection ETFs every month who wants broad exchange access at the lowest cost is usually very well served. Anyone wanting interest on cash and automation looks first at Trade Republic or Trading 212.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'What is the ETF Core Selection?' )
			. self::paragraph( 'A curated list of ETFs you can buy commission-free (only the €1 handling fee remains), under a fair-use policy — typically one free purchase per ETF per month, per the published conditions.' )
			. self::h3( 'Does DEGIRO pay interest on idle cash?' )
			. self::paragraph( 'No. If earning on liquidity matters to you, compare Trade Republic, Trading 212 or Lightyear\'s Vaults.' )
			. self::h3( 'Did DEGIRO change owners?' )
			. self::paragraph( 'No — it remains part of the flatexDEGIRO group; only the bank\'s legal form changed to SE (European company) at the end of 2025, with no client impact.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-degiro/">how to open a DEGIRO account</a> · <a href="/best-etf-brokers-portugal/">best brokers for ETFs</a> · <a href="/best-stock-brokers-portugal/">best brokers for stocks</a>' );
	}

	/**
	 * Interactive Brokers — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_ibkr( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A Interactive Brokers (IBKR) é a corretora dos profissionais que também aceita principiantes pacientes: <strong>acesso a mais mercados, moedas e produtos do que qualquer outra desta lista</strong>, custos muito baixos e ferramentas de nível institucional. O preço a pagar não é em euros — é em curva de aprendizagem.' )
				. self::heading( 'Quanto custa investir na IBKR?' )
				. self::bullets_html(
					array(
						'Ações e ETFs: <strong>comissões baixas por ordem, publicadas em detalhe</strong> num preçário extenso (planos fixo e escalonado).',
						'Sem comissões de custódia nem mínimos de atividade.',
						'Conversão cambial ao câmbio interbancário com custo mínimo — das mais baratas do mercado.',
						'Juros sobre o saldo: <strong>paga em saldos elegíveis acima de um limiar</strong>, a taxas competitivas publicadas no site.',
					)
				)
				. self::asof( true )
				. self::heading( 'A IBKR é segura e regulada?' )
				. self::paragraph( 'Os clientes portugueses são servidos pela Interactive Brokers Ireland Ltd, regulada pelo Banco Central da Irlanda, com o regime irlandês de compensação de investidores nos limites publicados. A casa-mãe, Interactive Brokers Group, é cotada nos EUA e tem décadas de história e balanços com excesso de capital — no critério solidez, é referência da indústria. Também oferece CFDs, claramente separados do investimento em ativos reais.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Praticamente tudo o que é negociável: ações e ETFs em dezenas de bolsas, obrigações, opções, futuros, fundos e câmbio. As plataformas vão do simples (GlobalTrader, web) ao profissional (Trader Workstation), com relatórios fiscais e de desempenho muito completos. É demasiado para quem só quer um plano mensal de ETFs — e perfeito para quem quer controlo total.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Acesso global sem rival e custos consistentemente baixos.',
						'Juros competitivos em saldos elegíveis.',
						'Solidez e transparência de um grupo cotado com décadas de história.',
						'Relatórios e ferramentas que nenhuma app-first iguala.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'Curva de aprendizagem real: interface densa e cheia de conceitos.',
						'Juros só acima de um limiar de saldo.',
						'Apoio eficiente mas impessoal; abertura de conta mais demorada.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Um perfil experiente, ou um principiante paciente com vontade de aprender, que quer acesso a tudo e custos mínimos para carteiras que vão crescer, costuma acabar na IBKR. Quem quer simplicidade acima de tudo tende a ser mais feliz na Lightyear ou na Trade Republic.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'A IBKR serve para principiantes?' )
				. self::paragraph( 'Serve, com paciência: as apps simplificadas ajudam, mas o universo de opções é grande. Quem prefere zero fricção tem alternativas mais simples nesta comparação.' )
				. self::h3( 'Que proteção têm os meus ativos?' )
				. self::paragraph( 'Instrumentos segregados na entidade irlandesa, com o regime irlandês de compensação de investidores nos limites publicados, e um grupo cotado com décadas de solidez por trás.' )
				. self::h3( 'A IBKR paga juros sobre o saldo?' )
				. self::paragraph( 'Paga, a taxas competitivas, mas apenas na parte do saldo acima de um limiar de elegibilidade — os detalhes estão publicados no site.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-interactive-brokers/">como abrir conta na IBKR</a> · <a href="/pt/melhores-corretoras-para-acoes/">melhores corretoras para ações</a> · <a href="/pt/brokers/saxo-analise/">análise completa ao Saxo Bank</a>' );
		}

		return self::phtml( 'Interactive Brokers (IBKR) is the professionals\' broker that also welcomes patient beginners: <strong>access to more markets, currencies and products than anything else on this list</strong>, very low costs and institutional-grade tools. The price you pay isn\'t in euros — it\'s in learning curve.' )
			. self::heading( 'How much does IBKR cost?' )
			. self::bullets_html(
				array(
					'Stocks and ETFs: <strong>low per-order commissions, published in detail</strong> across an extensive price list (fixed and tiered plans).',
					'No custody fees and no activity minimums.',
					'Currency conversion at interbank rates with a minimal cost — among the cheapest anywhere.',
					'Interest on cash: <strong>paid on qualifying balances above a threshold</strong>, at competitive published rates.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is IBKR safe and regulated?' )
			. self::paragraph( 'Portuguese clients are served by Interactive Brokers Ireland Ltd, regulated by the Central Bank of Ireland, with the Irish investor-compensation regime within its published limits. The parent, Interactive Brokers Group, is US-listed with decades of history and famously over-capitalised balance sheets — on the solidity test, it is the industry reference. It also offers CFDs, clearly separated from real-asset investing.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Practically everything tradable: stocks and ETFs on dozens of exchanges, bonds, options, futures, funds and FX. Platforms range from simple (GlobalTrader, web) to professional (Trader Workstation), with unusually complete tax and performance reporting. It is overkill for someone who just wants a monthly ETF plan — and perfect for someone who wants full control.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Unrivalled global access and consistently low costs.',
					'Competitive interest on qualifying balances.',
					'The solidity and transparency of a listed group with decades of history.',
					'Reporting and tools no app-first broker matches.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'A real learning curve: a dense, concept-heavy interface.',
					'Interest only above a balance threshold.',
					'Efficient but impersonal support; slower account opening.',
				)
			)
			. self::heading( 'Who does IBKR tend to suit?' )
			. self::paragraph( 'An experienced profile — or a patient beginner willing to learn — who wants access to everything and minimal costs on a portfolio that will grow, usually ends up at IBKR. Anyone prizing simplicity above all tends to be happier at Lightyear or Trade Republic.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Is IBKR suitable for beginners?' )
			. self::paragraph( 'With patience, yes: the simplified apps help, but the option space is huge. Anyone wanting zero friction has simpler alternatives in this comparison.' )
			. self::h3( 'How are my assets protected?' )
			. self::paragraph( 'Instruments are segregated at the Irish entity, the Irish investor-compensation regime applies within its published limits, and a listed group with decades of solidity stands behind it.' )
			. self::h3( 'Does IBKR pay interest on cash?' )
			. self::paragraph( 'It does, at competitive rates, but only on the portion of the balance above an eligibility threshold — details are published on the site.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-interactive-brokers/">how to open an IBKR account</a> · <a href="/best-stock-brokers-portugal/">best brokers for stocks</a> · <a href="/brokers/saxo/">full Saxo Bank review</a>' );
	}

	/**
	 * eToro — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_etoro( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A eToro é a corretora social por excelência: ações e ETFs reais, uma das <strong>ofertas de cripto mais amplas entre corretoras reguladas</strong>, e o copy trading que a tornou famosa. Desde maio de 2025 é <strong>cotada na Nasdaq (ticker ETOR)</strong>, o que acrescentou transparência a uma casa que sempre dividiu opiniões. A conta é em dólares e a vertente de CFDs é relevante — dois detalhes a perceber antes de entrar.' )
				. self::heading( 'Quanto custa investir na eToro?' )
				. self::bullets_html(
					array(
						'Ações e ETFs: <strong>0% de comissão, nas condições publicadas</strong> pela eToro.',
						'Cripto: custo em spread por transação, publicado por moeda.',
						'<strong>Conta denominada em dólares</strong>: depósitos e levantamentos em euros pagam conversão cambial.',
						'<strong>Levantamentos: 5 US$ por operação</strong>, com mínimo de levantamento.',
					)
				)
				. self::asof( true )
				. self::heading( 'A eToro é segura e regulada?' )
				. self::paragraph( 'Na UE opera através da eToro (Europe) Ltd, regulada pela CySEC (licença 109/10), com o sistema cipriota de compensação de investidores nos limites publicados. Ser cotada na Nasdaq desde 2025 obriga a contas auditadas e divulgação trimestral — um upgrade real de transparência. Oferece CFDs em muitos instrumentos; para investir a longo prazo interessa a compra de ativos reais, sem alavancagem.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações e ETFs reais, cripto ampla, e o copy trading: replicar automaticamente a carteira de outros investidores. É uma ideia sedutora que merece cautela educativa — replicar alguém replica também os erros dessa pessoa, e resultados passados não garantem nada. A plataforma é acessível e visual, pensada para descoberta social.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Cripto real ampla ao lado de ações e ETFs, numa casa regulada.',
						'Empresa cotada na Nasdaq — transparência acima da média do setor.',
						'Copy trading e comunidade únicos no mercado.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'Conta em dólares: custos de conversão à entrada e à saída.',
						'Taxa de levantamento e spreads relevantes em cripto.',
						'A fronteira entre ativo real e CFD exige atenção constante (posições alavancadas ou curtas são CFDs).',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Um perfil arrojado que quer cripto e ações no mesmo sítio, e valoriza a dimensão social, costuma considerar a eToro. Para o núcleo duro de uma carteira de longo prazo em euros, os custos cambiais tornam XTB, Trading 212 ou DEGIRO escolhas mais eficientes.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'O copy trading é um atalho seguro?' )
				. self::paragraph( 'Não é um atalho: replica decisões de outra pessoa, com os riscos dela incluídos. Se o usares, trata-o como uma fatia pequena e experimental, nunca como o núcleo da carteira.' )
				. self::h3( 'A conta é em euros?' )
				. self::paragraph( 'Não — é denominada em dólares. Depósitos e levantamentos em euros passam por conversão cambial, um custo real para quem reforça com frequência.' )
				. self::h3( 'Quando compro uma ação na eToro é mesmo uma ação?' )
				. self::paragraph( 'Comprando sem alavancagem e sem posição curta, sim, é o ativo real. Com alavancagem ou a descoberto, passa a ser um CFD — produto de risco elevado.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-etoro/">como abrir conta na eToro</a> · <a href="/pt/melhores-corretoras-para-cripto/">melhores plataformas para cripto</a> · <a href="/pt/melhores-corretoras-em-portugal/">comparação de todas as corretoras</a>' );
		}

		return self::phtml( 'eToro is the social broker par excellence: real stocks and ETFs, one of the <strong>broadest crypto ranges among regulated brokers</strong>, and the copy trading that made it famous. Since May 2025 it is <strong>listed on the Nasdaq (ticker ETOR)</strong>, adding transparency to a house that has always divided opinion. The account is dollar-denominated and the CFD arm is significant — two details to understand before walking in.' )
			. self::heading( 'How much does eToro cost?' )
			. self::bullets_html(
				array(
					'Stocks and ETFs: <strong>0% commission, under eToro\'s published conditions</strong>.',
					'Crypto: a per-trade spread cost, published per coin.',
					'<strong>Dollar-denominated account</strong>: euro deposits and withdrawals pay currency conversion.',
					'<strong>Withdrawals: US$5 per operation</strong>, with a minimum withdrawal amount.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is eToro safe and regulated?' )
			. self::paragraph( 'In the EU it operates through eToro (Europe) Ltd, regulated by CySEC (licence 109/10), with the Cypriot investor-compensation scheme within its published limits. Being Nasdaq-listed since 2025 means audited accounts and quarterly disclosure — a real transparency upgrade. It offers CFDs on many instruments; for long-term investing it is the unleveraged purchase of real assets that matters.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Real stocks and ETFs, broad crypto, and copy trading: automatically replicating another investor\'s portfolio. It is a seductive idea that deserves educational caution — copying someone also copies their mistakes, and past results guarantee nothing. The platform is approachable and visual, built for social discovery.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Broad real crypto next to stocks and ETFs, at a regulated house.',
					'Nasdaq-listed — above-average transparency for the sector.',
					'Copy trading and a community unique in the market.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'Dollar account: conversion costs on the way in and out.',
					'A withdrawal fee and meaningful crypto spreads.',
					'The real-asset/CFD boundary needs constant attention (leveraged or short positions are CFDs).',
				)
			)
			. self::heading( 'Who does eToro tend to suit?' )
			. self::paragraph( 'A bolder profile wanting crypto and stocks in one place, and who values the social layer, tends to consider eToro. For the euro-denominated core of a long-term portfolio, conversion costs make XTB, Trading 212 or DEGIRO more efficient choices.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Is copy trading a safe shortcut?' )
			. self::paragraph( 'It is not a shortcut: it replicates someone else\'s decisions, their risks included. If you use it, treat it as a small, experimental slice — never the core of a portfolio.' )
			. self::h3( 'Is the account in euros?' )
			. self::paragraph( 'No — it is dollar-denominated. Euro deposits and withdrawals go through currency conversion, a real cost for frequent top-ups.' )
			. self::h3( 'When I buy a stock on eToro, is it a real stock?' )
			. self::paragraph( 'Buying without leverage and without shorting, yes, it is the real asset. With leverage or short, it becomes a CFD — a high-risk product.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-etoro/">how to open an eToro account</a> · <a href="/best-crypto-brokers-portugal/">best regulated crypto platforms</a> · <a href="/best-brokers-in-portugal/">the full broker comparison</a>' );
	}

	/**
	 * Saxo Bank — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_saxo( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'O Saxo Bank é o "banco de investimento" desta comparação: plataformas de nível profissional, research profundo e uma gama que vai de ações a obrigações, opções e futuros. Em 2026 mudou de mãos — o grupo suíço de banca privada <strong>J. Safra Sarasin comprou a maioria do capital em março e acordou em julho ficar com 100%</strong> (sujeito a aprovações), reforçando a solidez acionista de uma casa que continua supervisionada pelo regulador dinamarquês.' )
				. self::heading( 'Quanto custa investir no Saxo?' )
				. self::bullets_html(
					array(
						'Comissões por ordem em <strong>escalões (Classic, Platinum, VIP)</strong>, revistas em baixa nos últimos anos — competitivas para uma plataforma de nível bancário, embora raramente as mais baratas.',
						'Conversão cambial e custos por bolsa publicados em detalhe no preçário.',
						'Juros sobre o saldo: <strong>pagos por escalões acima de limiares</strong>, com as taxas em vigor no site.',
					)
				)
				. self::asof( true )
				. self::heading( 'O Saxo é seguro e regulado?' )
				. self::paragraph( 'O Saxo Bank A/S é um banco dinamarquês supervisionado pela autoridade financeira da Dinamarca (DFSA), com garantia de depósitos e proteção de investidores dinamarquesas nos limites publicados. A entrada do grupo J. Safra Sarasin — um dos maiores grupos suíços de banca privada — dá-lhe um acionista de referência conservador e bem capitalizado. Oferece CFDs e produtos alavancados, separados do investimento em ativos reais.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Das gamas mais completas da Europa: ações, ETFs, obrigações, fundos, opções, futuros e câmbio, em dezenas de bolsas. O SaxoInvestor serve quem investe simples; o SaxoTraderGO/PRO serve quem quer profundidade. O research, os screeners e os relatórios estão um degrau acima de qualquer app-first.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Gama e research de nível institucional.',
						'Banco regulado na Dinamarca, agora com um grupo suíço de referência por trás.',
						'Juros por escalões no saldo e preçário mais competitivo do que a fama sugere.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'Onboarding e interface mais "instituição" do que app — pode intimidar.',
						'Raramente o mais barato em nenhuma categoria isolada.',
						'Para carteiras pequenas e simples, é mais casa do que o necessário.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Carteiras maiores e perfis que valorizam research, gama e a solidez de um banco costumam considerar o Saxo naturalmente. Quem procura o custo mínimo por ordem ou uma app minimalista tende a ficar melhor na DEGIRO, Trading 212 ou Lightyear.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'O que muda com a compra pela J. Safra Sarasin?' )
				. self::paragraph( 'Para clientes, nada de imediato: a entidade, a supervisão dinamarquesa e as contas mantêm-se. Muda o acionista — de um conjunto de investidores para um grupo suíço de banca privada, com o fundador a manter funções na presidência do conselho.' )
				. self::h3( 'O Saxo é caro?' )
				. self::paragraph( 'Menos do que já foi: o preçário por escalões tornou-o competitivo, sobretudo a partir dos escalões superiores. Continua a não ser a escolha de custo mínimo para ordens pequenas e esporádicas.' )
				. self::h3( 'Serve para quem está a começar?' )
				. self::paragraph( 'O SaxoInvestor é acessível, mas há casas mais simples nesta comparação para uma primeira carteira; o Saxo brilha quando a exigência sobe.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-saxo/">como abrir conta no Saxo</a> · <a href="/pt/melhores-corretoras-para-acoes/">melhores corretoras para ações</a> · <a href="/pt/brokers/interactive-brokers-analise/">análise completa à Interactive Brokers</a>' );
		}

		return self::phtml( 'Saxo Bank is the "investment bank" of this comparison: professional-grade platforms, deep research and a range spanning stocks to bonds, options and futures. In 2026 it changed hands — Swiss private-banking group <strong>J. Safra Sarasin bought a majority stake in March and agreed in July to reach 100%</strong> (pending approvals), reinforcing the shareholder base of a house still supervised by the Danish regulator.' )
			. self::heading( 'How much does Saxo cost?' )
			. self::bullets_html(
				array(
					'Per-order commissions in <strong>tiers (Classic, Platinum, VIP)</strong>, cut substantially in recent years — competitive for a bank-grade platform, though rarely the outright cheapest.',
					'Currency conversion and per-exchange costs published in detail on the price list.',
					'Interest on cash: <strong>paid in tiers above thresholds</strong>, with live rates on the site.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is Saxo safe and regulated?' )
			. self::paragraph( 'Saxo Bank A/S is a Danish bank supervised by the Danish FSA, with Danish deposit-guarantee and investor-protection schemes within their published limits. The arrival of the J. Safra Sarasin group — one of Switzerland\'s largest private-banking groups — gives it a conservative, well-capitalised anchor shareholder. It offers CFDs and leveraged products, separate from real-asset investing.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'One of Europe\'s most complete ranges: stocks, ETFs, bonds, funds, options, futures and FX across dozens of exchanges. SaxoInvestor serves simple investing; SaxoTraderGO/PRO serves depth. Research, screeners and reporting sit a clear step above any app-first broker.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Institutional-grade range and research.',
					'A Danish-regulated bank, now with a reference Swiss group behind it.',
					'Tiered interest on cash and pricing more competitive than its reputation suggests.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'Onboarding and interface feel more "institution" than app — can intimidate.',
					'Rarely the cheapest in any single category.',
					'More house than needed for small, simple portfolios.',
				)
			)
			. self::heading( 'Who does Saxo tend to suit?' )
			. self::paragraph( 'Larger portfolios and profiles that value research, range and a bank\'s solidity tend to consider Saxo naturally. Those chasing minimum per-order cost or a minimalist app usually do better at DEGIRO, Trading 212 or Lightyear.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'What changes with the J. Safra Sarasin acquisition?' )
			. self::paragraph( 'For clients, nothing immediate: the entity, Danish supervision and accounts continue. What changes is the shareholder — from a group of investors to a Swiss private-banking group, with the founder staying on as chairman.' )
			. self::h3( 'Is Saxo expensive?' )
			. self::paragraph( 'Less than it used to be: tiered pricing has made it competitive, especially from the higher tiers. It is still not the minimum-cost choice for small, sporadic orders.' )
			. self::h3( 'Does it suit someone starting out?' )
			. self::paragraph( 'SaxoInvestor is approachable, but simpler houses exist in this comparison for a first portfolio; Saxo shines as requirements grow.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-saxo/">how to open a Saxo account</a> · <a href="/best-stock-brokers-portugal/">best brokers for stocks</a> · <a href="/brokers/interactive-brokers/">full Interactive Brokers review</a>' );
	}

	/**
	 * Revolut — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_revolut( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'A Revolut não é uma corretora que virou app — é a app do dia-a-dia que foi somando investimentos. Hoje, dentro da mesma conta, dá acesso a <strong>milhares de ações, ETFs, obrigações, fundos monetários, cripto e metais</strong>, com um punhado de ordens gratuitas por mês conforme o plano. Para o primeiro passo da poupança para o investimento, a conveniência é imbatível; para uma carteira séria, há limites a conhecer.' )
				. self::heading( 'Quanto custa investir na Revolut?' )
				. self::bullets_html(
					array(
						'<strong>Ordens gratuitas por mês conforme o plano</strong> (Standard a Ultra); comissão por ordem a partir daí, publicada no preçário.',
						'Cripto e metais: custo em spread/comissão por transação.',
						'Fundos monetários ("Flexible Cash Funds"): taxa variável, líquida de comissões, publicada na app.',
						'Subscrição opcional Trading Pro para quem negoceia mais (limites e custos próprios).',
					)
				)
				. self::asof( true )
				. self::heading( 'A Revolut é segura para investir?' )
				. self::paragraph( 'Aqui vale a pena ser preciso, porque são duas entidades: os investimentos são prestados pela <strong>Revolut Securities Europe UAB</strong>, empresa de investimento regulada pelo Banco da Lituânia, enquanto a conta e os depósitos vivem no Revolut Bank UAB, também lituano e supervisionado no quadro do BCE. Depósitos bancários têm a garantia lituana até 100 000 €; investimentos seguem o regime de proteção de investidores próprio, com limites diferentes — não são a mesma coisa, e é saudável saber a diferença.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Milhares de ações dos EUA e da Europa, ETFs, obrigações (desde 2025), fundos do mercado monetário, cripto, metais preciosos e um robo-advisor em mercados selecionados. Tudo dentro da app que já usas para pagar o café — o que é simultaneamente o argumento e o risco: investir ao lado do saldo do dia-a-dia pede disciplina.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Arranque mais fácil de toda a lista: zero apps novas, frações, montantes pequenos.',
						'Gama que cresceu a sério: obrigações, fundos monetários e robo em mercados selecionados.',
						'Poupanças remuneradas e multi-moeda nativos.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'Ordens gratuitas limitadas pelo plano; custos medianos para quem reforça muito.',
						'Menos profundidade de corretora (relatórios, tipos de ordem, bolsas) do que casas dedicadas.',
						'A fronteira banco/corretora dentro da mesma app pede atenção às proteções de cada parte.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Quem está a dar o primeiro passo e quer testar com valores pequenos, dentro de uma app que já domina, costuma começar bem aqui — e mais tarde somar uma corretora dedicada à medida que a carteira cresce. Quem já reforça todos os meses tende a ficar melhor servido na Trading 212, XTB ou Trade Republic.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'Os investimentos têm a mesma proteção que os depósitos?' )
				. self::paragraph( 'Não. Depósitos no Revolut Bank UAB têm garantia até 100 000 €; investimentos na Revolut Securities Europe UAB seguem o regime de proteção de investidores, com limites próprios e sem garantia de valor.' )
				. self::h3( 'Preciso de um plano pago para investir?' )
				. self::paragraph( 'Não — o plano gratuito inclui ordens sem comissão todos os meses; os planos pagos aumentam esse número e outros limites.' )
				. self::h3( 'Posso comprar ETFs europeus na Revolut?' )
				. self::paragraph( 'Sim, a oferta inclui ETFs europeus e tem vindo a alargar — confirma na app se os que procuras estão disponíveis.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-revolut/">como ativar os investimentos na Revolut</a> · <a href="/pt/melhores-corretoras-para-iniciantes/">melhores corretoras para iniciantes</a> · <a href="/pt/brokers/trading-212-analise/">análise completa à Trading 212</a>' );
		}

		return self::phtml( 'Revolut isn\'t a broker that became an app — it\'s the everyday app that kept adding investments. Inside the same account it now offers <strong>thousands of stocks, ETFs, bonds, money-market funds, crypto and metals</strong>, with a handful of free monthly orders depending on your plan. For the first step from saving to investing, the convenience is unbeatable; for a serious portfolio, there are limits worth knowing.' )
			. self::heading( 'How much does investing with Revolut cost?' )
			. self::bullets_html(
				array(
					'<strong>Free orders per month by plan tier</strong> (Standard to Ultra); a per-order commission beyond that, per the price list.',
					'Crypto and metals: a spread/commission cost per transaction.',
					'Money-market funds ("Flexible Cash Funds"): variable rate, net of fees, published in the app.',
					'Optional Trading Pro subscription for heavier traders (its own limits and costs).',
				)
			)
			. self::asof( false )
			. self::heading( 'Is Revolut safe for investing?' )
			. self::paragraph( 'Precision matters here, because there are two entities: investments are provided by <strong>Revolut Securities Europe UAB</strong>, an investment firm regulated by the Bank of Lithuania, while the account and deposits live at Revolut Bank UAB, also Lithuanian and supervised within the ECB framework. Bank deposits carry the Lithuanian guarantee up to €100,000; investments fall under the separate investor-protection regime with different limits — they are not the same thing, and knowing the difference is healthy.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Thousands of US and European stocks, ETFs, bonds (since 2025), money-market funds, crypto, precious metals and a robo-advisor in selected markets. All inside the app you already pay for coffee with — which is both the argument and the risk: investing next to your day-to-day balance takes discipline.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'The easiest start on this list: no new app, fractions, small amounts.',
					'A range that has grown up: bonds, money-market funds, robo in selected markets.',
					'Native interest-bearing savings and multi-currency.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'Free orders capped by plan; middling costs for frequent top-ups.',
					'Less broker depth (reporting, order types, exchanges) than dedicated houses.',
					'The bank/broker boundary inside one app calls for attention to each side\'s protections.',
				)
			)
			. self::heading( 'Who does Revolut tend to suit?' )
			. self::paragraph( 'Someone taking the first step, testing with small amounts inside an app they already master, tends to start well here — and later adds a dedicated broker as the portfolio grows. Anyone already topping up monthly is usually better served at Trading 212, XTB or Trade Republic.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Do investments get the same protection as deposits?' )
			. self::paragraph( 'No. Deposits at Revolut Bank UAB carry the guarantee up to €100,000; investments at Revolut Securities Europe UAB fall under the investor-protection regime, with its own limits and no guarantee of value.' )
			. self::h3( 'Do I need a paid plan to invest?' )
			. self::paragraph( 'No — the free plan includes commission-free orders every month; paid plans raise that number and other limits.' )
			. self::h3( 'Can I buy European ETFs on Revolut?' )
			. self::paragraph( 'Yes, the range includes European ETFs and keeps widening — check in the app whether the ones you want are available.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-revolut/">how to activate investing on Revolut</a> · <a href="/best-brokers-for-beginners-portugal/">best brokers for beginners</a> · <a href="/brokers/trading-212/">full Trading 212 review</a>' );
	}

	/**
	 * ActivoBank — full review.
	 *
	 * @param bool $pt Portuguese?
	 */
	private static function review_activobank( bool $pt ): string {
		if ( $pt ) {
			return self::phtml( 'O ActivoBank é a via 100% nacional desta comparação: um banco português digital (grupo Millennium bcp) onde a conta à ordem, a poupança e os investimentos vivem debaixo da mesma supervisão do Banco de Portugal e da CMVM. O trunfo atual é claro: <strong>a primeira ordem de bolsa de cada mês custa 0 €</strong> — quem investe uma vez por mês praticamente não paga corretagem. A partir daí, os custos de banco fazem-se sentir.' )
				. self::heading( 'Quanto custa investir no ActivoBank?' )
				. self::bullets_html(
					array(
						'<strong>1.ª ordem de bolsa de cada mês: 0 €</strong>; <strong>ordens seguintes: 5 € cada</strong>, segundo o preçário em vigor.',
						'Comissão de guarda de títulos (custódia) cobrada mensalmente sobre a carteira — o custo silencioso a somar às ordens.',
						'Fundos e PPR com condições próprias no preçário.',
					)
				)
				. self::asof( true )
				. self::heading( 'O ActivoBank é seguro?' )
				. self::paragraph( 'É um banco português de pleno direito: Banco de Portugal nº 23 e CMVM nº 116, com os depósitos cobertos pelo Fundo de Garantia de Depósitos até 100 000 € por titular e o Sistema de Indemnização aos Investidores para os instrumentos, nos limites legais. Sendo instituição nacional, o reporte fiscal à Autoridade Tributária é tratado cá — na prática, o IRS dá menos trabalho do que com corretoras estrangeiras.' )
				. self::heading( 'Produtos e plataforma' )
				. self::paragraph( 'Ações nacionais e internacionais, ETFs (distinguidos pela DECO PROteste na negociação de ETFs, com selo válido até julho de 2026), fundos de investimento e PPR — mais o resto do banco: conta, cartões e apoio presencial nos Pontos Activo. A app é sólida como banco; como corretora, é mais básica do que as especializadas desta lista.' )
				. self::heading( 'Prós e contras' )
				. self::h3( 'Prós' )
				. self::bullets(
					array(
						'Tudo num banco português: supervisão nacional, FGD e IRS simplificado.',
						'1.ª ordem do mês gratuita — excelente para o investidor mensal.',
						'Apoio humano e presencial, raro neste segmento.',
						'Fundos e PPR ao lado das ações e ETFs.',
					)
				)
				. self::h3( 'Contras' )
				. self::bullets(
					array(
						'5 € por ordem a partir da segunda + custódia mensal — caro face às apps internacionais.',
						'Plataforma de investimento menos moderna e com menos mercados.',
						'Sem juros automáticos sobre o saldo à ordem.',
					)
				)
				. self::heading( 'Para quem costuma fazer sentido?' )
				. self::paragraph( 'Um perfil cauteloso que valoriza uma instituição portuguesa, o IRS simplificado e apoio presencial — e que investe ao ritmo de uma ordem por mês — costuma considerar o ActivoBank com naturalidade. Quem reforça várias vezes por mês ou quer custos mínimos absolutos tende a ficar melhor numa XTB ou Trading 212, aceitando a papelada fiscal extra.' )
				. self::heading( 'Perguntas frequentes' )
				. self::h3( 'O ActivoBank compensa face às corretoras internacionais?' )
				. self::paragraph( 'Depende do ritmo: com uma ordem por mês, a corretagem é zero e a diferença estreita-se; com várias ordens e a custódia mensal, as apps internacionais ficam claramente mais baratas.' )
				. self::h3( 'O IRS é mesmo mais simples?' )
				. self::paragraph( 'Sim — as instituições portuguesas reportam à Autoridade Tributária e tratam retenções nos termos legais, evitando os anexos de contas no estrangeiro que as corretoras internacionais implicam.' )
				. self::h3( 'Há comissão de custódia?' )
				. self::paragraph( 'Sim, mensal, sobre a guarda de títulos — consulta o valor no preçário antes de decidir, porque pesa mais em carteiras pequenas.' )
				. self::phtml( 'Continuar a ler: <a href="/pt/como-abrir-conta-activobank/">como abrir conta no ActivoBank</a> · <a href="/pt/melhores-corretoras-em-portugal/">comparação de todas as corretoras</a> · <a href="/pt/comparador-de-depositos-a-prazo/">comparador de depósitos a prazo</a>' );
		}

		return self::phtml( 'ActivoBank is the fully domestic route in this comparison: a Portuguese digital bank (Millennium bcp group) where the current account, savings and investments live under Banco de Portugal and CMVM supervision. Its current headline is clear: <strong>the first stock-exchange order of each month costs €0</strong> — a once-a-month investor pays essentially no brokerage. Beyond that, bank-level costs kick in.' )
			. self::heading( 'How much does investing with ActivoBank cost?' )
			. self::bullets_html(
				array(
					'<strong>First exchange order each month: €0</strong>; <strong>subsequent orders: €5 each</strong>, per the current price list.',
					'A monthly custody fee on the portfolio — the quiet cost to add to per-order pricing.',
					'Funds and pension products (PPR) under their own price-list conditions.',
				)
			)
			. self::asof( false )
			. self::heading( 'Is ActivoBank safe?' )
			. self::paragraph( 'It is a full Portuguese bank: Banco de Portugal nº 23 and CMVM nº 116, with deposits covered by the Portuguese Deposit Guarantee Fund up to €100,000 per holder and the national Investor Compensation Scheme for instruments, within legal limits. Being a domestic institution, tax reporting to the Portuguese tax authority is handled here — in practice, tax season is easier than with foreign brokers.' )
			. self::heading( 'Products and platform' )
			. self::paragraph( 'Domestic and international stocks, ETFs (recognised by DECO PROteste for ETF dealing, with a seal valid until July 2026), investment funds and PPR pension products — plus the rest of the bank: account, cards and in-person support at Ponto Activo branches. The app is solid as a bank; as a broker it is more basic than the specialists on this list.' )
			. self::heading( 'Pros and cons' )
			. self::h3( 'Pros' )
			. self::bullets(
				array(
					'Everything at a Portuguese bank: domestic supervision, deposit guarantee and simpler taxes.',
					'First order each month free — excellent for the monthly investor.',
					'Human, in-person support, rare in this segment.',
					'Funds and PPR next to stocks and ETFs.',
				)
			)
			. self::h3( 'Cons' )
			. self::bullets(
				array(
					'€5 per order from the second one, plus monthly custody — expensive next to international apps.',
					'A less modern investing platform with fewer markets.',
					'No automatic interest on the current-account balance.',
				)
			)
			. self::heading( 'Who does ActivoBank tend to suit?' )
			. self::paragraph( 'A cautious profile that values a Portuguese institution, simpler taxes and in-person support — and invests at the pace of one order a month — tends to consider ActivoBank naturally. Anyone topping up several times a month, or chasing absolute minimum costs, is usually better off at XTB or Trading 212, accepting the extra tax paperwork.' )
			. self::heading( 'Frequently asked questions' )
			. self::h3( 'Is ActivoBank worth it versus international brokers?' )
			. self::paragraph( 'It depends on pace: at one order a month brokerage is zero and the gap narrows; with several orders plus monthly custody, the international apps are clearly cheaper.' )
			. self::h3( 'Are taxes really simpler?' )
			. self::paragraph( 'Yes — Portuguese institutions report to the tax authority and handle withholdings under local rules, avoiding the foreign-account annexes that international brokers imply.' )
			. self::h3( 'Is there a custody fee?' )
			. self::paragraph( 'Yes, monthly, on the safekeeping of securities — check the amount on the price list before deciding, as it weighs more on small portfolios.' )
			. self::phtml( 'Keep reading: <a href="/how-to-open-an-account-with-activobank/">how to open an ActivoBank account</a> · <a href="/best-brokers-in-portugal/">the full broker comparison</a> · <a href="/term-deposit-comparison-portugal/">the term-deposit comparison</a>' );
	}

	/**
	 * Block-markup paragraph (mirrors Seeder::paragraph).
	 *
	 * @param string $text Text.
	 */
	private static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * Block-markup paragraph that keeps authored inline markup (<strong>, <a>,
	 * <em>) — used by the reviews for answer-bolds and internal links
	 * (seo-content). Trusted, curated strings only; never user input.
	 *
	 * @param string $html Authored inline HTML.
	 */
	private static function phtml( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * Block-markup H3 (FAQ questions, pros/cons subheads).
	 *
	 * @param string $text Text.
	 */
	private static function h3( string $text ): string {
		return '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $text ) . '</h3><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * Block-markup unordered list that keeps authored inline markup — used by
	 * the reviews for cost bullets with <strong> answers. Trusted strings only.
	 *
	 * @param list<string> $items Authored inline-HTML items.
	 */
	private static function bullets_html( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $li . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Block-markup H2 (mirrors Seeder::heading).
	 *
	 * @param string $text Text.
	 */
	private static function heading( string $text ): string {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * SEO title/description for RankMath/Yoast (mirrors Seeder::write_seo_meta).
	 *
	 * @param int   $id  Post ID.
	 * @param array $seo {title?:string,desc?:string}.
	 */
	private static function write_seo_meta( int $id, array $seo ): void {
		$title = trim( (string) ( $seo['title'] ?? '' ) );
		$desc  = trim( (string) ( $seo['desc'] ?? '' ) );
		if ( '' !== $title ) {
			update_post_meta( $id, 'rank_math_title', $title );
			update_post_meta( $id, '_yoast_wpseo_title', $title );
		}
		if ( '' !== $desc ) {
			update_post_meta( $id, 'rank_math_description', $desc );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
		}
	}

	/**
	 * The default (English) language slug, '' when Polylang is inactive.
	 */
	private static function default_lang(): string {
		if ( ! function_exists( 'pll_default_language' ) ) {
			return '';
		}
		return (string) pll_default_language( 'slug' );
	}

	/**
	 * Whether Polylang's public API is available.
	 */
	private static function polylang_active(): bool {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_default_language' )
			&& function_exists( 'pll_get_post' );
	}

	/**
	 * Resolve the Portuguese language slug configured in Polylang.
	 *
	 * @param string $default The default language slug (excluded).
	 */
	private static function portuguese_slug( string $default ): string {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return '';
		}
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );

		foreach ( $slugs as $i => $slug ) {
			if ( $slug === $default ) {
				continue;
			}
			if ( 0 === stripos( (string) ( $locales[ $i ] ?? '' ), 'pt' ) ) {
				return (string) $slug;
			}
		}
		foreach ( $slugs as $slug ) {
			if ( $slug !== $default ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/* -------------------------------------------------------------------------
	 * Admin (Tools → Seed brokers).
	 * ---------------------------------------------------------------------- */

	/**
	 * Register the Tools page.
	 */
	public static function admin_menu(): void {
		add_management_page(
			__( 'HowToInvest — Seed brokers', 'hti-engine' ),
			__( 'Seed brokers', 'hti-engine' ),
			'manage_options',
			'hti-seed-brokers',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the seeder form.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'HowToInvest — Seed brokers', 'hti-engine' ); ?></h1>
			<p><?php echo esc_html__( 'Create the broker review skeletons (EN + linked PT) and the use-case terms with the reference-study data. Existing entries (matched by slug) are skipped, so editorial rewrites are safe.', 'hti-engine' ); ?></p>
			<p><?php echo esc_html__( 'Seeded records start with NO affiliate URL and the deal inactive — flip each in the broker\'s "Broker data" box once a deal is signed and verified.', 'hti-engine' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_run_broker_seeder" />
				<?php wp_nonce_field( 'hti_run_broker_seeder' ); ?>
				<?php submit_button( __( 'Run broker seeder', 'hti-engine' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the form submission.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-engine' ) );
		}
		check_admin_referer( 'hti_run_broker_seeder' );

		$report = self::seed();
		set_transient( 'hti_broker_seed_report', $report, 60 );

		wp_safe_redirect( add_query_arg( 'page', 'hti-seed-brokers', admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Show the result notice after seeding.
	 */
	public static function admin_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_hti-seed-brokers' !== $screen->id ) {
			return;
		}

		$report = get_transient( 'hti_broker_seed_report' );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( 'hti_broker_seed_report' );

		$message = sprintf(
			/* translators: 1: brokers created, 2: section pages, 3: PT translations, 4: skipped. */
			__( 'Broker seeding complete: %1$d brokers and %2$d section pages created, %3$d Portuguese translations linked, %4$d skipped (already existed).', 'hti-engine' ),
			(int) $report['brokers_created'],
			(int) ( $report['pages_created'] ?? 0 ),
			(int) $report['translations_created'],
			(int) $report['skipped']
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
