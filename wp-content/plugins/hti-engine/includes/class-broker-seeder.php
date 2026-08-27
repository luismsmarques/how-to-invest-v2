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

		foreach ( array_merge( self::pages(), self::guides() ) as $entry ) {
			$en_post = get_page_by_path( $entry['slug'], OBJECT, 'page' );
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
