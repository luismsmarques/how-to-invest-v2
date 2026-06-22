<?php
/**
 * Educational calculators (the Tools hub): `[hti_tool name="…"]`.
 *
 * Server-rendered, accessible forms enhanced by tools.js (HTITools core). Pure
 * client-side math — no network. Illustrative only: hypothetical rates, no
 * advice, by concept/asset class. Indexable (SEO is the goal).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode + asset wiring for the calculators.
 */
class Tools {

	private const SHORTCODE = 'hti_tool';

	/**
	 * Hook the shortcode and assets.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Whether the current singular view embeds a tool.
	 */
	private static function is_tool_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the calculator assets only where a tool is present.
	 */
	public static function enqueue(): void {
		if ( ! self::is_tool_page() ) {
			return;
		}
		wp_enqueue_style( 'hti-tools', HTI_ENGINE_URL . 'assets/css/tools.css', array(), VERSION );
		wp_register_script( 'hti-tools-core', HTI_ENGINE_URL . 'assets/js/tools-core.js', array(), VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script(
			'hti-tools',
			HTI_ENGINE_URL . 'assets/js/tools.js',
			array( 'hti-tools-core' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * `[hti_tool name="compound|inflation|savings_goal|cost_of_waiting"]`.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'name' => 'compound' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		// The allocation visualiser is a selector (not a numeric form).
		if ( 'allocation' === $atts['name'] ) {
			return self::render_allocation();
		}

		$cfg  = self::config();
		$name = isset( $cfg[ $atts['name'] ] ) ? (string) $atts['name'] : 'compound';
		$tool = $cfg[ $name ];
		$pt   = 'pt' === self::locale();
		$l    = $pt ? 'pt' : 'en';

		$out = '<form class="hti-tool" data-tool="' . esc_attr( $name ) . '" data-locale="' . esc_attr( $pt ? 'pt-PT' : 'en' ) . '" novalidate>';

		// Inputs.
		$out .= '<div class="hti-tool__fields">';
		foreach ( $tool['fields'] as $key => $f ) {
			$unit  = '' !== $f['unit'] ? ' (' . esc_html( $f['unit'] ) . ')' : '';
			$attrs = 'data-field="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $f['default'] ) . '"';
			$attrs .= ' min="' . esc_attr( (string) $f['min'] ) . '" step="' . esc_attr( (string) $f['step'] ) . '"';
			if ( isset( $f['max'] ) ) {
				$attrs .= ' max="' . esc_attr( (string) $f['max'] ) . '"';
			}
			$out .= '<label class="hti-field"><span class="hti-field__label">' . esc_html( $f[ $l ] ) . $unit . '</span>'
				. '<input type="number" inputmode="decimal" ' . $attrs . ' /></label>';
		}
		$out .= '</div>';

		// Results (live region).
		$out .= '<div class="hti-tool__results" aria-live="polite">';
		foreach ( $tool['outputs'] as $key => $o ) {
			$cls  = 'hti-out' . ( ! empty( $o['primary'] ) ? ' hti-out--primary' : '' );
			$fmt  = isset( $o['format'] ) ? ' data-format="' . esc_attr( (string) $o['format'] ) . '"' : '';
			$out .= '<div class="' . $cls . '"><span class="hti-out__label">' . esc_html( $o[ $l ] ) . '</span>'
				. '<span class="hti-out__value" data-out="' . esc_attr( $key ) . '"' . $fmt . '>—</span></div>';
		}
		$out .= '</div>';

		// Chart + legend.
		if ( ! empty( $tool['chart'] ) ) {
			$out .= '<div class="hti-tool__chart" data-chart></div>';
			if ( ! empty( $tool['legend'] ) ) {
				$out .= '<div class="hti-tool__legend">';
				foreach ( $tool['legend'] as $item ) {
					$out .= '<span class="hti-legend"><span class="hti-legend__dot" style="background:' . esc_attr( $item['color'] ) . '"></span>' . esc_html( $item[ $l ] ) . '</span>';
				}
				$out .= '</div>';
			}
		}

		$note  = $pt
			? 'Exemplo ilustrativo com uma taxa hipotética. Não é aconselhamento nem previsão — investir envolve risco, incluindo a perda de capital.'
			: 'Illustrative example with a hypothetical rate. Not advice or a forecast — investing involves risk, including loss of capital.';
		$out  .= '<p class="hti-tool__note">' . esc_html( $note ) . '</p>';

		$nojs  = $pt ? 'Ativa o JavaScript para usar esta calculadora.' : 'Enable JavaScript to use this calculator.';
		$out  .= '<noscript><p class="hti-tool__note">' . esc_html( $nojs ) . '</p></noscript>';

		$out .= '</form>';

		return $out;
	}

	/**
	 * Per-tool field/output/legend configuration (bilingual labels).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function config(): array {
		return array(
			'compound'        => array(
				'fields'  => array(
					'initial' => array( 'en' => 'Initial amount', 'pt' => 'Valor inicial', 'default' => 1000, 'min' => 0, 'step' => 100, 'unit' => '€' ),
					'monthly' => array( 'en' => 'Monthly contribution', 'pt' => 'Contribuição mensal', 'default' => 100, 'min' => 0, 'step' => 10, 'unit' => '€' ),
					'rate'    => array( 'en' => 'Annual return (hypothetical)', 'pt' => 'Retorno anual (hipotético)', 'default' => 5, 'min' => 0, 'max' => 15, 'step' => 0.5, 'unit' => '%' ),
					'years'   => array( 'en' => 'Years', 'pt' => 'Anos', 'default' => 20, 'min' => 1, 'max' => 50, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'value'       => array( 'en' => 'Estimated value', 'pt' => 'Valor estimado', 'primary' => true ),
					'contributed' => array( 'en' => 'You put in', 'pt' => 'Investido por ti' ),
					'growth'      => array( 'en' => 'From growth', 'pt' => 'Vindo do crescimento' ),
				),
				'chart'   => true,
				'legend'  => array(
					array( 'color' => '#FF6B5E', 'en' => 'Portfolio value', 'pt' => 'Valor da carteira' ),
					array( 'color' => '#7C5CFC', 'en' => 'You put in', 'pt' => 'Investido' ),
				),
			),
			'inflation'       => array(
				'fields'  => array(
					'amount' => array( 'en' => 'Amount today', 'pt' => 'Valor hoje', 'default' => 10000, 'min' => 0, 'step' => 500, 'unit' => '€' ),
					'rate'   => array( 'en' => 'Inflation per year', 'pt' => 'Inflação por ano', 'default' => 3, 'min' => 0, 'max' => 20, 'step' => 0.5, 'unit' => '%' ),
					'years'  => array( 'en' => 'Years', 'pt' => 'Anos', 'default' => 20, 'min' => 1, 'max' => 50, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'power'  => array( 'en' => "What it will buy then (today's money)", 'pt' => 'O que vai comprar então (em dinheiro de hoje)', 'primary' => true ),
					'lost'   => array( 'en' => 'Purchasing power lost', 'pt' => 'Poder de compra perdido' ),
					'needed' => array( 'en' => 'Needed then to match today', 'pt' => 'Necessário então para igualar hoje' ),
				),
			),
			'savings_goal'    => array(
				'fields'  => array(
					'goal'    => array( 'en' => 'Goal amount', 'pt' => 'Valor do objetivo', 'default' => 100000, 'min' => 0, 'step' => 1000, 'unit' => '€' ),
					'initial' => array( 'en' => 'Starting amount', 'pt' => 'Valor de partida', 'default' => 0, 'min' => 0, 'step' => 100, 'unit' => '€' ),
					'rate'    => array( 'en' => 'Annual return (hypothetical)', 'pt' => 'Retorno anual (hipotético)', 'default' => 5, 'min' => 0, 'max' => 15, 'step' => 0.5, 'unit' => '%' ),
					'years'   => array( 'en' => 'Years', 'pt' => 'Anos', 'default' => 20, 'min' => 1, 'max' => 50, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'monthly'     => array( 'en' => 'Save per month', 'pt' => 'Poupar por mês', 'primary' => true ),
					'contributed' => array( 'en' => 'You will put in', 'pt' => 'Vais investir' ),
					'growth'      => array( 'en' => 'From growth', 'pt' => 'Vindo do crescimento' ),
				),
			),
			'cost_of_waiting' => array(
				'fields'  => array(
					'monthly' => array( 'en' => 'Monthly contribution', 'pt' => 'Contribuição mensal', 'default' => 200, 'min' => 0, 'step' => 10, 'unit' => '€' ),
					'rate'    => array( 'en' => 'Annual return (hypothetical)', 'pt' => 'Retorno anual (hipotético)', 'default' => 5, 'min' => 0, 'max' => 15, 'step' => 0.5, 'unit' => '%' ),
					'years'   => array( 'en' => 'Total years', 'pt' => 'Anos no total', 'default' => 30, 'min' => 1, 'max' => 50, 'step' => 1, 'unit' => '' ),
					'delay'   => array( 'en' => 'Years you wait', 'pt' => 'Anos que esperas', 'default' => 5, 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'cost'    => array( 'en' => 'Cost of waiting', 'pt' => 'Custo de esperar', 'primary' => true ),
					'now'     => array( 'en' => 'If you start now', 'pt' => 'Se começares já' ),
					'delayed' => array( 'en' => 'If you wait', 'pt' => 'Se esperares' ),
				),
				'chart'   => true,
				'legend'  => array(
					array( 'color' => '#FF6B5E', 'en' => 'Start now', 'pt' => 'Começar já' ),
					array( 'color' => '#7C5CFC', 'en' => 'Wait', 'pt' => 'Esperar' ),
				),
			),
			'emergency_fund'  => array(
				'fields'  => array(
					'expenses' => array( 'en' => 'Essential monthly expenses', 'pt' => 'Despesas essenciais por mês', 'default' => 1500, 'min' => 0, 'step' => 50, 'unit' => '€' ),
					'months'   => array( 'en' => 'Months to cover', 'pt' => 'Meses a cobrir', 'default' => 6, 'min' => 1, 'max' => 24, 'step' => 1, 'unit' => '' ),
					'saved'    => array( 'en' => 'Already saved', 'pt' => 'Já poupado', 'default' => 2000, 'min' => 0, 'step' => 100, 'unit' => '€' ),
					'monthly'  => array( 'en' => 'Save per month', 'pt' => 'Poupar por mês', 'default' => 200, 'min' => 0, 'step' => 10, 'unit' => '€' ),
				),
				'outputs' => array(
					'target' => array( 'en' => 'Emergency fund target', 'pt' => 'Objetivo do fundo de emergência', 'primary' => true ),
					'gap'    => array( 'en' => 'Still to save', 'pt' => 'Ainda por poupar' ),
					'time'   => array( 'en' => 'Time to reach it', 'pt' => 'Tempo até lá chegar', 'format' => 'months' ),
				),
			),
			'rule_of_72'      => array(
				'fields'  => array(
					'rate'  => array( 'en' => 'Annual return (hypothetical)', 'pt' => 'Retorno anual (hipotético)', 'default' => 6, 'min' => 0.5, 'max' => 30, 'step' => 0.5, 'unit' => '%' ),
					'years' => array( 'en' => 'Years to project', 'pt' => 'Anos a projetar', 'default' => 24, 'min' => 1, 'max' => 60, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'double'    => array( 'en' => 'Years to double', 'pt' => 'Anos para duplicar', 'primary' => true, 'format' => 'years' ),
					'doublings' => array( 'en' => 'Times it doubles', 'pt' => 'Vezes que duplica', 'format' => 'times' ),
					'multiple'  => array( 'en' => 'Final multiple', 'pt' => 'Múltiplo final', 'format' => 'multiple' ),
				),
			),
			'fee_impact'      => array(
				'fields'  => array(
					'initial' => array( 'en' => 'Initial amount', 'pt' => 'Valor inicial', 'default' => 10000, 'min' => 0, 'step' => 100, 'unit' => '€' ),
					'monthly' => array( 'en' => 'Monthly contribution', 'pt' => 'Contribuição mensal', 'default' => 200, 'min' => 0, 'step' => 10, 'unit' => '€' ),
					'rate'    => array( 'en' => 'Gross annual return (hypothetical)', 'pt' => 'Retorno anual bruto (hipotético)', 'default' => 6, 'min' => 0, 'max' => 15, 'step' => 0.5, 'unit' => '%' ),
					'fee'     => array( 'en' => 'Annual fee', 'pt' => 'Comissão anual', 'default' => 1, 'min' => 0, 'max' => 3, 'step' => 0.1, 'unit' => '%' ),
					'years'   => array( 'en' => 'Years', 'pt' => 'Anos', 'default' => 25, 'min' => 1, 'max' => 50, 'step' => 1, 'unit' => '' ),
				),
				'outputs' => array(
					'net'   => array( 'en' => 'Value after fees', 'pt' => 'Valor após comissões', 'primary' => true ),
					'gross' => array( 'en' => 'Value without fees', 'pt' => 'Valor sem comissões' ),
					'lost'  => array( 'en' => 'Lost to fees', 'pt' => 'Perdido em comissões' ),
				),
				'chart'   => true,
				'legend'  => array(
					array( 'color' => '#FF6B5E', 'en' => 'After fees', 'pt' => 'Após comissões' ),
					array( 'color' => '#7C5CFC', 'en' => 'Without fees', 'pt' => 'Sem comissões' ),
				),
			),
		);
	}

	/**
	 * Allocation visualiser: pick an investor archetype and see its illustrative
	 * allocation by asset class as a donut. Numbers come straight from the
	 * deterministic engine (Config + Engine::allocate) — never the LLM, never
	 * named instruments. Illustrative only.
	 */
	private static function render_allocation(): string {
		$pt        = 'pt' === self::locale();
		$l         = $pt ? 'pt' : 'en';
		$archetypes = Config::archetypes();

		$labels = array(
			'global_equity' => array( 'en' => 'Global equities', 'pt' => 'Ações globais', 'color' => '#FF6B5E' ),
			'bonds'         => array( 'en' => 'Bonds', 'pt' => 'Obrigações', 'color' => '#7C5CFC' ),
			'reits_alt'     => array( 'en' => 'REITs & alternatives', 'pt' => 'Imobiliário e alternativos', 'color' => '#D69A1E' ),
			'cash'          => array( 'en' => 'Cash', 'pt' => 'Liquidez', 'color' => '#B7AEC4' ),
			'crypto'        => array( 'en' => 'Crypto', 'pt' => 'Cripto', 'color' => '#22C3A6' ),
		);

		// Build each archetype's illustrative allocation, server-side.
		$data = array();
		foreach ( $archetypes as $id => $arch ) {
			try {
				$slices = Engine::allocate( $arch['ranges'], false );
			} catch ( \Throwable $e ) {
				continue;
			}
			$out_slices = array();
			foreach ( $slices as $slice ) {
				$cls = $slice['class'];
				$out_slices[] = array(
					'class' => $cls,
					'pct'   => (int) $slice['pct'],
					'label' => $labels[ $cls ][ $l ] ?? $cls,
					'color' => $labels[ $cls ]['color'] ?? '#B7AEC4',
				);
			}
			$data[] = array(
				'id'    => (int) $id,
				'label' => is_array( $arch['label'] ) ? ( $arch['label'][ $l ] ?? $arch['label']['en'] ) : (string) $arch['label'],
				'slices' => $out_slices,
			);
		}

		$json = wp_json_encode( $data );

		$tabs = '';
		foreach ( $data as $i => $arch ) {
			$tabs .= '<button type="button" class="hti-alloc__tab" role="tab" data-arch="' . esc_attr( (string) $arch['id'] )
				. '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '">' . esc_html( $arch['label'] ) . '</button>';
		}

		$center = $pt ? 'Exemplo' : 'Example';
		$sub    = $pt ? 'por classes' : 'by class';

		$out  = '<div class="hti-alloc" data-allocations="' . esc_attr( (string) $json ) . '" data-center="' . esc_attr( $center ) . '" data-sub="' . esc_attr( $sub ) . '">';
		$out .= '<div class="hti-alloc__tabs" role="tablist" aria-label="' . esc_attr( $pt ? 'Perfis de investidor' : 'Investor profiles' ) . '">' . $tabs . '</div>';
		$out .= '<div class="hti-alloc__view">';
		$out .= '<div class="hti-alloc__donut" data-donut aria-hidden="true"></div>';
		$out .= '<ul class="hti-alloc__list" data-list aria-live="polite"></ul>';
		$out .= '</div>';

		$note = $pt
			? 'Exemplo ilustrativo por classes de ativos, não recomendação. Não nomeia instrumentos. Investir envolve risco, incluindo a perda de capital.'
			: 'Illustrative example by asset class, not a recommendation. No named instruments. Investing involves risk, including loss of capital.';
		$out .= '<p class="hti-tool__note">' . esc_html( $note ) . '</p>';

		$nojs = $pt ? 'Ativa o JavaScript para explorar os perfis.' : 'Enable JavaScript to explore the profiles.';
		$out .= '<noscript><p class="hti-tool__note">' . esc_html( $nojs ) . '</p></noscript>';
		$out .= '</div>';

		return $out;
	}
}
