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
	 * The study's verification date, seeded into every record.
	 */
	private const VERIFIED = '2026-06-26';

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

		$report['translations_created'] = self::seed_translations();

		return $report;
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
			if ( ! pll_get_term_language( $en_term->term_id ) ) {
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
					'title' => 'XTB review — fees, safety and who it suits',
					'desc'  => 'An educational look at XTB for investors in Portugal: regulation (CMVM-registered branch), products, costs and who tends to consider it. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'XTB',
					'XTB is a Polish-listed broker supervised by the KNF, operating in Portugal through a local branch registered with the CMVM (nº 341). It offers real stocks and ETFs, investment plans and interest on uninvested cash, alongside a separate CFD arm aimed at traders.',
					'Stock and ETF investing is commission-free up to a monthly volume limit, with costs published on XTB\'s site. As always, currency conversion and other fees can apply — the review keeps to what XTB publicly documents.',
					'A profile that values a local branch, a regulated European framework and a platform that covers the core asset classes often shortlists XTB. The CFD side is a separate, higher-risk product aimed at experienced traders — it is not part of the educational picture here.'
				),
				'pt'         => array(
					'title'   => 'XTB — análise',
					'excerpt' => 'Uma grande corretora europeia com sucursal em Portugal registada na CMVM, investimento em ações e ETFs sem comissões dentro de limites, e juros sobre o saldo não investido.',
					'seo'     => array(
						'title' => 'XTB opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à XTB para quem investe em Portugal: regulação (sucursal registada na CMVM), produtos, custos e quem costuma considerá-la. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'XTB',
						'A XTB é uma corretora cotada na Polónia e supervisionada pela KNF, a operar em Portugal através de uma sucursal registada na CMVM (nº 341). Oferece ações e ETFs reais, planos de investimento e juros sobre o saldo não investido, além de uma vertente separada de CFDs dirigida a traders.',
						'O investimento em ações e ETFs é isento de comissões até um limite de volume mensal, com os custos publicados no site da XTB. Como sempre, podem aplicar-se custos de conversão cambial e outras comissões — esta análise limita-se ao que a XTB documenta publicamente.',
						'Um perfil que valoriza uma sucursal local, um enquadramento regulado europeu e uma plataforma que cobre as classes de ativos principais costuma incluir a XTB na lista. A vertente de CFDs é um produto separado e de risco elevado, dirigido a traders experientes — não faz parte do quadro educativo aqui.'
					),
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
					'title' => 'Trading 212 review — fees, safety and who it suits',
					'desc'  => 'An educational look at Trading 212 for investors in Portugal: EU regulation, commission-free investing, pies and interest on cash. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Trading 212',
					'Trading 212 serves EU clients through Trading 212 EU GmbH (supervised by BaFin, with group entities under CySEC and the FCA). The Invest account offers real stocks and ETFs with fractional shares, automated portfolios ("pies") and interest on uninvested cash; a separate CFD account exists for traders.',
					'Investing in stocks and ETFs carries no commission; a small currency-conversion fee applies when trading outside your account currency, per Trading 212\'s published pricing.',
					'A profile starting out with small, regular amounts often considers Trading 212 for the fractional shares and automated pies. The CFD account is a separate, high-risk product — not part of the educational picture here.'
				),
				'pt'         => array(
					'title'   => 'Trading 212 — análise',
					'excerpt' => 'Uma app sem comissões para ações e ETFs com "pies" automáticos, frações de ações e juros sobre o saldo, a operar na UE através da Trading 212 EU GmbH.',
					'seo'     => array(
						'title' => 'Trading 212 opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à Trading 212 para quem investe em Portugal: regulação na UE, investimento sem comissões, pies e juros sobre o saldo. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Trading 212',
						'A Trading 212 serve clientes da UE através da Trading 212 EU GmbH (supervisionada pelo BaFin, com entidades do grupo sob a CySEC e a FCA). A conta Invest oferece ações e ETFs reais com frações, carteiras automáticas ("pies") e juros sobre o saldo não investido; existe uma conta CFD separada para traders.',
						'Investir em ações e ETFs não tem comissão; aplica-se uma pequena taxa de conversão cambial ao negociar fora da moeda da conta, segundo o preçário publicado pela Trading 212.',
						'Um perfil a começar com montantes pequenos e regulares costuma considerar a Trading 212 pelas frações de ações e pelos pies automáticos. A conta CFD é um produto separado e de risco elevado — não faz parte do quadro educativo aqui.'
					),
				),
				'meta'       => array(
					'regulator'          => 'BaFin (Trading 212 EU GmbH) · CySEC',
					'cfd'                => '1',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,interest,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '1 €',
					'fees_note'          => 'Commission-free stocks/ETFs; FX fee on non-base-currency trades',
					'fees_note_pt'       => 'Ações/ETFs sem comissões; taxa cambial fora da moeda da conta',
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
					'title' => 'Trade Republic review — fees, safety and who it suits',
					'desc'  => 'An educational look at Trade Republic for investors in Portugal: German banking licence, ETF savings plans, interest on cash and crypto. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Trade Republic',
					'Trade Republic Bank GmbH is a fully licensed German bank supervised by BaFin, available in Portugal. It offers stocks, ETFs, automated commission-free ETF savings plans, crypto and interest on cash, all through a mobile-first app. It does not offer CFDs.',
					'Trades carry a small flat external fee, while automated savings-plan executions are free, per Trade Republic\'s published pricing. Deposits are held with a German banking framework and the usual EU deposit-guarantee rules for cash.',
					'A profile that wants to automate a monthly ETF plan and earn interest on the cash cushion often shortlists Trade Republic. Crypto is available as a small optional extra — a profile like this usually keeps it a tiny slice, if at all.'
				),
				'pt'         => array(
					'title'   => 'Trade Republic — análise',
					'excerpt' => 'Um banco-corretora alemão com planos de poupança em ETFs automáticos e gratuitos, frações, juros sobre o saldo e cripto, supervisionado pelo BaFin.',
					'seo'     => array(
						'title' => 'Trade Republic opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à Trade Republic para quem investe em Portugal: licença bancária alemã, planos de ETFs, juros sobre o saldo e cripto. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Trade Republic',
						'A Trade Republic Bank GmbH é um banco alemão com licença plena, supervisionado pelo BaFin e disponível em Portugal. Oferece ações, ETFs, planos de poupança em ETFs automáticos e sem comissões, cripto e juros sobre o saldo, tudo numa app mobile-first. Não oferece CFDs.',
						'As ordens têm uma pequena taxa externa fixa, enquanto as execuções dos planos de poupança são gratuitas, segundo o preçário publicado pela Trade Republic. O dinheiro é detido num enquadramento bancário alemão, com as regras europeias habituais de garantia de depósitos para o saldo.',
						'Um perfil que quer automatizar um plano mensal de ETFs e receber juros sobre a almofada de liquidez costuma incluir a Trade Republic na lista. A cripto está disponível como extra opcional — um perfil assim costuma mantê-la como fatia minúscula, se a tiver.'
					),
				),
				'meta'       => array(
					'regulator'          => 'BaFin (Trade Republic Bank GmbH, banco alemão)',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,crypto,interest,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt,crypto',
					'min_deposit'        => '1 €',
					'fees_note'          => 'Small flat fee per trade; free ETF savings-plan executions',
					'fees_note_pt'       => 'Pequena taxa fixa por ordem; planos de ETFs sem custo de execução',
					'interest_rate_note' => 'Pays interest on cash (rate varies)',
					'interest_rate_note_pt' => 'Paga juros sobre o saldo (taxa variável)',
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
					'title' => 'Lightyear review — fees, safety and who it suits',
					'desc'  => 'An educational look at Lightyear for investors in Portugal: EU regulation, low-cost stocks and ETFs, money-market funds and interest. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Lightyear',
					'Lightyear Europe AS is supervised by Estonia\'s Finantsinspektsioon and serves most EU countries, including Portugal. It offers real stocks, ETFs, money-market funds and interest on cash, with a deliberately simple interface — and no CFDs anywhere in the product.',
					'Costs are low and published openly: small per-order fees for stocks with a monthly free allowance on ETFs, plus a currency-conversion fee, per Lightyear\'s pricing page.',
					'A calmer profile that wants a simple, long-term app without any trading-product noise often considers Lightyear — the absence of CFDs makes it one of the simplest products to understand.'
				),
				'pt'         => array(
					'title'   => 'Lightyear — análise',
					'excerpt' => 'Uma app europeia calma e de baixo custo para ações, ETFs e fundos do mercado monetário, regulada na Estónia — sem CFDs em nenhuma parte do produto.',
					'seo'     => array(
						'title' => 'Lightyear opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à Lightyear para quem investe em Portugal: regulação na UE, ações e ETFs de baixo custo, fundos monetários e juros. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Lightyear',
						'A Lightyear Europe AS é supervisionada pela Finantsinspektsioon da Estónia e serve a maioria dos países da UE, incluindo Portugal. Oferece ações reais, ETFs, fundos do mercado monetário e juros sobre o saldo, com uma interface deliberadamente simples — e sem CFDs em nenhuma parte do produto.',
						'Os custos são baixos e publicados abertamente: pequenas comissões por ordem em ações com um plafond mensal gratuito em ETFs, além de uma taxa de conversão cambial, segundo o preçário da Lightyear.',
						'Um perfil mais calmo, que quer uma app simples e de longo prazo sem ruído de produtos de trading, costuma considerar a Lightyear — a ausência de CFDs torna-a um dos produtos mais fáceis de perceber.'
					),
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
					'interest_rate_note' => 'Interest on cash and money-market funds (rate varies)',
					'interest_rate_note_pt' => 'Juros sobre o saldo e fundos monetários (taxa variável)',
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
					'title' => 'DEGIRO review — fees, safety and who it suits',
					'desc'  => 'An educational look at DEGIRO for investors in Portugal: German banking group, low-cost stocks and ETFs, a core ETF selection, and no CFDs. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'DEGIRO',
					'DEGIRO is part of flatexDEGIRO Bank AG, supervised by BaFin with oversight from the Dutch regulators, and operates in Portugal under EU freedom of services (registered with the CMVM). It offers stocks, ETFs and bonds — and no CFDs.',
					'DEGIRO is known for low dealing costs, including a curated list of ETFs tradable with reduced fees under its published conditions. Connectivity and exchange fees apply per its price list.',
					'A cost-conscious profile comfortable with a slightly more traditional interface often shortlists DEGIRO for plain stock and ETF investing across many exchanges.'
				),
				'pt'         => array(
					'title'   => 'DEGIRO — análise',
					'excerpt' => 'Uma corretora europeia veterana e de baixo custo para ações, ETFs e obrigações sob o flatexDEGIRO Bank, a operar em Portugal em livre prestação de serviços — sem CFDs.',
					'seo'     => array(
						'title' => 'DEGIRO opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à DEGIRO para quem investe em Portugal: grupo bancário alemão, ações e ETFs de baixo custo, seleção de ETFs core e sem CFDs. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'DEGIRO',
						'A DEGIRO faz parte do flatexDEGIRO Bank AG, supervisionado pelo BaFin com supervisão dos reguladores neerlandeses, e opera em Portugal em livre prestação de serviços (registada na CMVM). Oferece ações, ETFs e obrigações — e não oferece CFDs.',
						'A DEGIRO é conhecida pelos custos de negociação baixos, incluindo uma lista curada de ETFs negociáveis com comissões reduzidas nas condições publicadas. Aplicam-se taxas de conectividade e de bolsa segundo o preçário.',
						'Um perfil atento aos custos e confortável com uma interface um pouco mais tradicional costuma incluir a DEGIRO na lista para investir em ações e ETFs em muitas bolsas.'
					),
				),
				'meta'       => array(
					'regulator'          => 'BaFin · DNB/AFM (flatexDEGIRO Bank AG); CMVM em livre prestação',
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
					'title' => 'Interactive Brokers review — fees, safety and who it suits',
					'desc'  => 'An educational look at Interactive Brokers (IBKR) for investors in Portugal: Irish EU entity, vast market access, low costs and powerful tools. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Interactive Brokers',
					'Interactive Brokers serves EU clients through Interactive Brokers Ireland Ltd, regulated by the Central Bank of Ireland. It is one of the largest brokers in the world, with access to a very wide range of markets, currencies and product types, and pays interest on qualifying idle cash.',
					'Costs are low by industry standards and published in detail; the platform\'s depth (order types, reporting, tools) is aimed at people who want full control.',
					'An experienced profile that wants broad market access and granular control often shortlists IBKR. A first-time investor may find the platform more complex than app-first alternatives — comfort with the tools matters more than any feature list.'
				),
				'pt'         => array(
					'title'   => 'Interactive Brokers — análise',
					'excerpt' => 'O peso-pesado global: enorme cobertura de mercados e produtos, ferramentas de nível institucional e juros sobre o saldo, a servir clientes da UE via IBKR Ireland.',
					'seo'     => array(
						'title' => 'Interactive Brokers opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à Interactive Brokers (IBKR) para quem investe em Portugal: entidade irlandesa na UE, acesso vasto a mercados, custos baixos e ferramentas potentes. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Interactive Brokers',
						'A Interactive Brokers serve clientes da UE através da Interactive Brokers Ireland Ltd, regulada pelo Banco Central da Irlanda. É uma das maiores corretoras do mundo, com acesso a uma gama muito ampla de mercados, moedas e tipos de produto, e paga juros sobre saldo elegível não investido.',
						'Os custos são baixos para o padrão do setor e publicados em detalhe; a profundidade da plataforma (tipos de ordem, relatórios, ferramentas) destina-se a quem quer controlo total.',
						'Um perfil experiente que quer acesso amplo a mercados e controlo granular costuma incluir a IBKR na lista. Quem investe pela primeira vez pode achar a plataforma mais complexa do que as alternativas app-first — o conforto com as ferramentas importa mais do que qualquer lista de funcionalidades.'
					),
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
					'title' => 'eToro review — fees, safety and who it suits',
					'desc'  => 'An educational look at eToro for investors in Portugal: CySEC regulation, real stocks and crypto, social features — and the CFD risk to understand. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'eToro',
					'eToro serves EU clients through eToro (Europe) Ltd, regulated by CySEC (licence 109/10). It mixes real assets — stocks, ETFs and a broad crypto range — with social features like copy-investing, and also offers CFDs on many instruments.',
					'Stock investing is commission-free within eToro\'s published conditions; crypto and FX carry spreads, and a withdrawal fee applies. The account is USD-denominated, so currency conversion is part of the picture for EU depositors.',
					'A profile drawn to the social side and to holding some crypto alongside stocks sometimes considers eToro. It is important to know which product you are using at any moment — the CFD side is high-risk and behaves nothing like holding the real asset.'
				),
				'pt'         => array(
					'title'   => 'eToro — análise',
					'excerpt' => 'Uma plataforma de social investing com ações reais, ETFs e uma das ofertas de cripto mais amplas, regulada pela CySEC para clientes da UE — com uma vertente significativa de CFDs.',
					'seo'     => array(
						'title' => 'eToro opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa à eToro para quem investe em Portugal: regulação CySEC, ações reais e cripto, funções sociais — e o risco de CFDs a compreender. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'eToro',
						'A eToro serve clientes da UE através da eToro (Europe) Ltd, regulada pela CySEC (licença 109/10). Combina ativos reais — ações, ETFs e uma gama ampla de cripto — com funções sociais como o copy-investing, e oferece também CFDs sobre muitos instrumentos.',
						'Investir em ações é isento de comissões nas condições publicadas pela eToro; a cripto e o câmbio têm spreads, e aplica-se uma taxa de levantamento. A conta é denominada em dólares, pelo que a conversão cambial faz parte do quadro para quem deposita em euros.',
						'Um perfil atraído pela vertente social e por deter alguma cripto ao lado de ações por vezes considera a eToro. É importante saber sempre que produto se está a usar — a vertente de CFDs é de risco elevado e não se comporta nada como deter o ativo real.'
					),
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
					'title' => 'Saxo Bank review — fees, safety and who it suits',
					'desc'  => 'An educational look at Saxo Bank for investors in Portugal: Danish banking supervision, premium platforms, broad product coverage. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Saxo Bank',
					'Saxo Bank A/S is a Danish investment bank supervised by the Danish FSA, serving clients across the EU. It offers stocks, ETFs, bonds and funds through its SaxoInvestor and SaxoTraderGO platforms, alongside leveraged products including CFDs for traders.',
					'Pricing was overhauled in recent years and is competitive for a bank-grade platform, with a published tiered structure. Research, screeners and reporting are a step above most app-first brokers.',
					'A profile that values bank-grade infrastructure, research depth and a broad product shelf often shortlists Saxo — typically people investing larger amounts who use the extra tooling.'
				),
				'pt'         => array(
					'title'   => 'Saxo Bank — análise',
					'excerpt' => 'Um banco de investimento dinamarquês com plataformas premium, research profundo e cobertura ampla de mercados para ações, ETFs, obrigações e fundos.',
					'seo'     => array(
						'title' => 'Saxo Bank opiniões e análise — custos, segurança e para quem é',
						'desc'  => 'Uma análise educativa ao Saxo Bank para quem investe em Portugal: supervisão bancária dinamarquesa, plataformas premium, cobertura ampla de produtos. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Saxo Bank',
						'O Saxo Bank A/S é um banco de investimento dinamarquês supervisionado pela autoridade financeira da Dinamarca, a servir clientes em toda a UE. Oferece ações, ETFs, obrigações e fundos através das plataformas SaxoInvestor e SaxoTraderGO, além de produtos alavancados, incluindo CFDs, para traders.',
						'O preçário foi renovado nos últimos anos e é competitivo para uma plataforma de nível bancário, com uma estrutura por escalões publicada. O research, os screeners e os relatórios estão um degrau acima da maioria das corretoras app-first.',
						'Um perfil que valoriza infraestrutura de banco, profundidade de research e uma prateleira ampla de produtos costuma incluir o Saxo na lista — tipicamente quem investe montantes maiores e usa as ferramentas extra.'
					),
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
					'title' => 'Revolut investing review — fees, safety and who it suits',
					'desc'  => 'An educational look at investing through Revolut for users in Portugal: EU banking licence, fractional stocks, savings and crypto. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'Revolut',
					'Revolut Bank UAB holds a Lithuanian (ECB-supervised) banking licence and serves Portugal across the EEA. Investing is an add-on inside the everyday app: fractional stocks, crypto and interest-bearing savings, with limits and fees that depend on your plan tier.',
					'A number of commission-free trades is included per month depending on the plan, with fees beyond them; crypto carries spreads. It is a convenience product rather than a full brokerage.',
					'A profile taking a first step from saving to investing, inside an app it already uses daily, sometimes starts here — and later adds a dedicated broker as amounts grow.'
				),
				'pt'         => array(
					'title'   => 'Revolut — análise',
					'excerpt' => 'A app do dinheiro do dia-a-dia com um extra de investimento simples: frações de ações, cripto e cofres de poupança, sob licença bancária na UE.',
					'seo'     => array(
						'title' => 'Revolut investimentos — opiniões e análise: custos e para quem é',
						'desc'  => 'Uma análise educativa a investir através da Revolut para utilizadores em Portugal: licença bancária na UE, frações de ações, poupança e cripto. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'Revolut',
						'A Revolut Bank UAB tem licença bancária lituana (supervisão do BCE) e serve Portugal em todo o EEE. O investimento é um extra dentro da app do dia-a-dia: frações de ações, cripto e poupanças remuneradas, com limites e custos que dependem do plano subscrito.',
						'Cada plano inclui um número de ordens sem comissão por mês, com custos a partir daí; a cripto tem spreads. É um produto de conveniência, mais do que uma corretora completa.',
						'Um perfil a dar o primeiro passo da poupança para o investimento, dentro de uma app que já usa todos os dias, por vezes começa aqui — e mais tarde acrescenta uma corretora dedicada à medida que os montantes crescem.'
					),
				),
				'meta'       => array(
					'regulator'          => 'Banco da Lituânia / BCE (Revolut Bank UAB)',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,crypto,interest',
					'asset_classes'      => 'global_equity,cash,crypto',
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
					'title' => 'ActivoBank investing review — fees, safety and who it suits',
					'desc'  => 'An educational look at investing through ActivoBank in Portugal: domestic supervision (Banco de Portugal, CMVM), stocks, ETFs and funds. Not financial advice.',
				),
				'content'    => self::review_skeleton(
					'en',
					'ActivoBank',
					'ActivoBank is a Portuguese digital bank (Banco de Portugal nº 23, CMVM nº 116) whose investment arm offers stocks, ETFs and funds alongside everyday banking. For some people, keeping banking and investing under one domestic, Portuguese-supervised roof is the deciding factor.',
					'Trading costs are generally higher than app-first international brokers — the trade-off for a domestic bank relationship, local support and simpler tax reporting.',
					'A cautious profile that values a Portuguese institution, in-person support and having everything in one place often considers ActivoBank, accepting higher dealing costs in exchange.'
				),
				'pt'         => array(
					'title'   => 'ActivoBank — análise',
					'excerpt' => 'Um banco digital português com vertente de investimento — ações, ETFs e fundos sob supervisão do Banco de Portugal e da CMVM, para quem quer tudo num banco nacional.',
					'seo'     => array(
						'title' => 'ActivoBank investimentos — opiniões e análise: custos e para quem é',
						'desc'  => 'Uma análise educativa a investir através do ActivoBank: supervisão nacional (Banco de Portugal, CMVM), ações, ETFs e fundos. Não é aconselhamento financeiro.',
					),
					'content' => self::review_skeleton(
						'pt',
						'ActivoBank',
						'O ActivoBank é um banco digital português (Banco de Portugal nº 23, CMVM nº 116) cuja vertente de investimento oferece ações, ETFs e fundos ao lado do banco do dia-a-dia. Para algumas pessoas, ter o banco e os investimentos debaixo do mesmo teto nacional e supervisionado em Portugal é o fator decisivo.',
						'Os custos de negociação são em geral mais altos do que nas corretoras internacionais app-first — a contrapartida por uma relação bancária nacional, apoio local e um reporte fiscal mais simples.',
						'Um perfil cauteloso que valoriza uma instituição portuguesa, apoio presencial e ter tudo no mesmo sítio costuma considerar o ActivoBank, aceitando custos de negociação mais altos em troca.'
					),
				),
				'meta'       => array(
					'regulator'          => 'Banco de Portugal nº 23 · CMVM nº 116',
					'cfd'                => '',
					'cfd_risk_pct'       => '',
					'products'           => 'stocks,etf,funds,savings',
					'asset_classes'      => 'global_equity,bonds,cash,reits_alt',
					'min_deposit'        => '0 €',
					'fees_note'          => 'Bank-level dealing fees (higher than app-first brokers)',
					'fees_note_pt'       => 'Comissões de banco (mais altas do que corretoras app-first)',
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

			if ( ! pll_get_post_language( $en_id ) ) {
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

		return $created;
	}

	/* -------------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------- */

	/**
	 * Standard review skeleton: intro + the three sections every review keeps
	 * (costs, safety, who it suits), in the calm, conditional house voice.
	 *
	 * @param string $lang   'en' or 'pt'.
	 * @param string $brand  Broker display name.
	 * @param string $intro  Intro paragraph (regulation + product overview).
	 * @param string $costs  Costs paragraph.
	 * @param string $fit    "Who tends to consider it" paragraph.
	 */
	private static function review_skeleton( string $lang, string $brand, string $intro, string $costs, string $fit ): string {
		$pt = 'pt' === $lang;
		return self::paragraph( $intro )
			. self::heading( $pt ? 'Custos' : 'Costs' )
			. self::paragraph( $costs )
			. self::heading( $pt ? 'Segurança e regulação' : 'Safety and regulation' )
			. self::paragraph(
				$pt
					? "A secção acima resume as entidades e supervisores relevantes. Como sempre, confirma o registo da {$brand} junto do regulador antes de abrires conta — os registos oficiais são públicos e gratuitos."
					: "The section above sums up the relevant entities and supervisors. As always, confirm {$brand}'s registration with the regulator before opening an account — the official registers are public and free."
			)
			. self::heading( $pt ? 'Para quem costuma fazer sentido' : 'Who it tends to suit' )
			. self::paragraph( $fit );
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
			/* translators: 1: brokers created, 2: PT translations, 3: skipped. */
			__( 'Broker seeding complete: %1$d brokers created, %2$d Portuguese translations linked, %3$d skipped (already existed).', 'hti-engine' ),
			(int) $report['brokers_created'],
			(int) $report['translations_created'],
			(int) $report['skipped']
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
