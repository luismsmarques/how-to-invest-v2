<?php
/**
 * Canonical content table for the educational tools section.
 *
 * One source of truth for the eight calculators: slug, shortcode name, PT slug,
 * titles, page intros and hub card copy. Before this class the same list was
 * spelled out five times inside class-seeder.php (the tool table, the glossary
 * term map, the EN hub bullets, the PT hub bullets and the PT slug map), so a
 * ninth tool meant five edits and any of them could silently drift.
 *
 * Deliberately pure: no WordPress calls, no side effects, no state. That is what
 * lets class-seo.php, class-redirects.php and the WordPress-free test harness
 * all read the same table — the same reason HTI\Forex\Config exists.
 *
 * Bilingual EN+PT inline, not __() and not the theme's t(): the plugin has no
 * dependency on the theme (and must keep none), class-tools.php already carries
 * its labels this way, and a pure array is the only shape the test harness can
 * assert parity on.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Static content tables for the tools hub and the calculators.
 */
class Tools_Content {

	/**
	 * Slug of the hub page the calculators hang off (EN).
	 */
	public const HUB_SLUG = 'tools';

	/**
	 * Slug of the hub page in Portuguese.
	 */
	public const HUB_SLUG_PT = 'ferramentas';

	/**
	 * The eight calculators, keyed by English page slug.
	 *
	 * Keys per entry:
	 * - name     — the [hti_tool name="…"] argument (see class-tools.php).
	 * - pt_slug  — Portuguese page slug (keyword-rich, for SEO).
	 * - tier     — 'core' renders as a big card on the hub, 'more' as a minicard.
	 * - icon     — single glyph for the core card (font-safe, no emoji).
	 * - title_*  — page title / H1.
	 * - intro_*  — the page's opening paragraph.
	 * - card_*   — the one-line description on the hub card.
	 * - terms    — glossary slugs for the page's "Key terms" links.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function tools(): array {
		return array(
			'compound-interest-calculator' => array(
				'name'     => 'compound',
				'pt_slug'  => 'calculadora-de-juro-composto',
				'tier'     => 'core',
				'icon'     => '↗',
				'title_en' => 'Compound interest calculator',
				'title_pt' => 'Calculadora de juro composto',
				'intro_en' => 'See how regular contributions can grow over time. Compound growth means your returns can earn returns too — so time in the market often matters more than the amount. Everything below is illustrative, with a hypothetical rate.',
				'intro_pt' => 'Vê como contribuições regulares podem crescer ao longo do tempo. O juro composto significa que os teus retornos também podem gerar retornos — por isso o tempo no mercado costuma importar mais do que o valor. Tudo abaixo é ilustrativo, com uma taxa hipotética.',
				'card_en'  => 'See how an initial amount plus regular contributions might grow, and how much of the total comes from growth rather than from you.',
				'card_pt'  => 'Vê como um valor inicial mais contribuições regulares podem crescer, e quanto do total vem do crescimento em vez de vir de ti.',
				'terms'    => array( 'compound-interest', 'yield' ),
			),
			'savings-goal-calculator'      => array(
				'name'     => 'savings_goal',
				'pt_slug'  => 'calculadora-de-meta-de-poupanca',
				'tier'     => 'core',
				'icon'     => '◎',
				'title_en' => 'Savings goal calculator',
				'title_pt' => 'Calculadora de meta de poupança',
				'intro_en' => 'Have a target in mind? See roughly how much you might set aside each month to get there over a chosen number of years, assuming a hypothetical return. Illustrative only.',
				'intro_pt' => 'Tens um objetivo em mente? Vê aproximadamente quanto poderias pôr de lado por mês para lá chegar num dado número de anos, assumindo um retorno hipotético. Apenas ilustrativo.',
				'card_en'  => 'Work backwards from a target: roughly how much per month it might take to get there in the years you have.',
				'card_pt'  => 'Parte do objetivo para trás: quanto poderia ser preciso por mês para lá chegar nos anos que tens.',
				'terms'    => array( 'compound-interest' ),
			),
			'inflation-calculator'         => array(
				'name'     => 'inflation',
				'pt_slug'  => 'calculadora-de-inflacao',
				'tier'     => 'core',
				'icon'     => '↘',
				'title_en' => 'Inflation calculator',
				'title_pt' => 'Calculadora de inflação',
				'intro_en' => 'Inflation slowly reduces what your money can buy. This shows how much purchasing power an amount may lose over time — and how much you would need later to keep the same buying power. Illustrative, with a hypothetical inflation rate.',
				'intro_pt' => 'A inflação reduz lentamente o que o teu dinheiro consegue comprar. Isto mostra quanto poder de compra um valor pode perder ao longo do tempo — e quanto precisarias mais tarde para manter o mesmo poder de compra. Ilustrativo, com uma taxa de inflação hipotética.',
				'card_en'  => 'How much buying power an amount may lose over the years, and what it would take later to stand still.',
				'card_pt'  => 'Quanto poder de compra um valor pode perder ao longo dos anos, e o que seria preciso mais tarde só para ficar na mesma.',
				'terms'    => array( 'inflation', 'interest-rate' ),
			),
			'cost-of-waiting-calculator'   => array(
				'name'     => 'cost_of_waiting',
				'pt_slug'  => 'calculadora-do-custo-de-esperar',
				'tier'     => 'more',
				'icon'     => '◷',
				'title_en' => 'The cost of waiting',
				'title_pt' => 'O custo de esperar',
				'intro_en' => 'Starting earlier gives your contributions more time to compound. This compares starting now with waiting a few years — same monthly amount — so you can see what the delay might cost. Illustrative, with a hypothetical rate.',
				'intro_pt' => 'Começar mais cedo dá às tuas contribuições mais tempo para compor. Isto compara começar já com esperar alguns anos — o mesmo valor mensal — para veres o que o atraso pode custar. Ilustrativo, com uma taxa hipotética.',
				'card_en'  => 'What delaying a few years might cost.',
				'card_pt'  => 'O que adiar alguns anos pode custar.',
				'terms'    => array( 'compound-interest' ),
			),
			'emergency-fund-calculator'    => array(
				'name'     => 'emergency_fund',
				'pt_slug'  => 'calculadora-de-fundo-de-emergencia',
				'tier'     => 'more',
				'icon'     => '◆',
				'title_en' => 'Emergency fund calculator',
				'title_pt' => 'Calculadora de fundo de emergência',
				'intro_en' => 'An emergency fund usually comes before any investing — money kept somewhere safe so a surprise never forces you to sell at a bad time. See a target based on your essential expenses, and roughly how long it might take to get there. Illustrative only.',
				'intro_pt' => 'Um fundo de emergência costuma vir antes de qualquer investimento — dinheiro guardado em segurança para que um imprevisto nunca te obrigue a vender num mau momento. Vê um objetivo com base nas tuas despesas essenciais e, aproximadamente, quanto tempo pode demorar a lá chegar. Apenas ilustrativo.',
				'card_en'  => 'The safety cushion that usually comes before investing.',
				'card_pt'  => 'A almofada de segurança que costuma vir antes de investir.',
				'terms'    => array( 'inflation', 'diversification' ),
			),
			'rule-of-72-calculator'        => array(
				'name'     => 'rule_of_72',
				'pt_slug'  => 'calculadora-da-regra-dos-72',
				'tier'     => 'more',
				'icon'     => '72',
				'title_en' => 'Rule of 72 calculator',
				'title_pt' => 'Calculadora da regra dos 72',
				'intro_en' => 'The rule of 72 is a quick mental shortcut: divide 72 by an annual return to estimate how many years money might take to double. See the estimate, how many times it could double over a period, and the resulting multiple. Illustrative, with a hypothetical rate.',
				'intro_pt' => 'A regra dos 72 é um atalho mental rápido: divide 72 por um retorno anual para estimar em quantos anos o dinheiro pode duplicar. Vê a estimativa, quantas vezes pode duplicar num período e o múltiplo resultante. Ilustrativo, com uma taxa hipotética.',
				'card_en'  => 'A quick estimate of how long money takes to double.',
				'card_pt'  => 'Uma estimativa rápida do tempo que o dinheiro leva a duplicar.',
				'terms'    => array( 'compound-interest', 'yield' ),
			),
			'fee-impact-calculator'        => array(
				'name'     => 'fee_impact',
				'pt_slug'  => 'calculadora-do-impacto-das-comissoes',
				'tier'     => 'more',
				'icon'     => '%',
				'title_en' => 'Fee impact calculator',
				'title_pt' => 'Calculadora do impacto das comissões',
				'intro_en' => 'Small annual fees can quietly add up over decades. This compares the same illustrative portfolio with and without a yearly fee, so you can see how much the fee might cost over time. Illustrative, with a hypothetical rate — not advice.',
				'intro_pt' => 'Pequenas comissões anuais podem somar silenciosamente ao longo de décadas. Isto compara a mesma carteira ilustrativa com e sem uma comissão anual, para veres quanto a comissão pode custar ao longo do tempo. Ilustrativo, com uma taxa hipotética — não é aconselhamento.',
				'card_en'  => 'How much a yearly fee might cost over decades.',
				'card_pt'  => 'Quanto uma comissão anual pode custar ao longo de décadas.',
				'terms'    => array( 'compound-interest', 'investment-fund' ),
			),
			'allocation-visualizer'        => array(
				'name'     => 'allocation',
				'pt_slug'  => 'visualizador-de-alocacao',
				'tier'     => 'more',
				'icon'     => '◕',
				'title_en' => 'Allocation visualizer',
				'title_pt' => 'Visualizador de alocação',
				'intro_en' => 'Pick one of the five educational investor profiles and see its illustrative allocation by asset class as a donut. The numbers come from our curated profiles — always by asset class, never named instruments, and never advice.',
				'intro_pt' => 'Escolhe um dos cinco perfis educativos de investidor e vê a sua alocação ilustrativa por classes de ativos num gráfico. Os números vêm dos nossos perfis curados — sempre por classes de ativos, nunca instrumentos nomeados, e nunca aconselhamento.',
				'card_en'  => 'Each investor profile by asset class.',
				'card_pt'  => 'Cada perfil de investidor por classes de ativos.',
				'terms'    => array( 'diversification', 'portfolio' ),
			),
		);
	}

	/**
	 * The English page slugs, in hub order.
	 *
	 * @return array<int,string>
	 */
	public static function slugs(): array {
		return array_keys( self::tools() );
	}

	/**
	 * Hierarchical path of a calculator page ("tools/compound-interest-…").
	 * This is what get_page_by_path() needs once the pages are children of the
	 * hub; the bare slug stays the post_name.
	 *
	 * @param string $slug English page slug.
	 */
	public static function path( string $slug ): string {
		return self::HUB_SLUG . '/' . $slug;
	}

	/**
	 * EN slug => PT slug, for the seeder's translated-slug map.
	 *
	 * @return array<string,string>
	 */
	public static function pt_slugs(): array {
		$map = array();
		foreach ( self::tools() as $slug => $tool ) {
			$map[ $slug ] = (string) $tool['pt_slug'];
		}
		return $map;
	}
}
