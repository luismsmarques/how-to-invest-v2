<?php
/**
 * Educational calculators and their hub: `[hti_tool name="…"]` and
 * `[hti_tools_hub]`.
 *
 * Server-rendered, accessible forms enhanced by tools.js (HTITools core). Pure
 * client-side math — no network. Illustrative only: hypothetical rates, no
 * advice, by concept/asset class. Indexable (SEO is the goal).
 *
 * The hub is a rendered component rather than seeded blocks, for the same
 * reason /forex/ is: the card grid, the FAQ and the disclaimer stay in code
 * where they can be changed once, instead of frozen into a page body that the
 * create-only seeder can never revisit.
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

	private const SHORTCODE_HUB = 'hti_tools_hub';

	/**
	 * Hook the shortcodes and assets.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( self::SHORTCODE_HUB, array( __CLASS__, 'render_hub' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Whether the current singular view embeds one of our shortcodes.
	 *
	 * @param string $shortcode Shortcode tag.
	 */
	private static function embeds( string $shortcode ): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, $shortcode );
	}

	/**
	 * Whether the current singular view embeds a calculator.
	 */
	private static function is_tool_page(): bool {
		return self::embeds( self::SHORTCODE );
	}

	/**
	 * Enqueue the tools assets.
	 *
	 * The stylesheet is shared by the calculators and the hub; the JavaScript
	 * is not, because the hub has no calculator to drive. Until the hub became
	 * a rendered component the gate looked only for [hti_tool], which is why it
	 * never received any styling at all.
	 */
	public static function enqueue(): void {
		$tool = self::is_tool_page();
		$hub  = self::embeds( self::SHORTCODE_HUB );
		if ( ! $tool && ! $hub ) {
			return;
		}

		wp_enqueue_style( 'hti-tools', HTI_ENGINE_URL . 'assets/css/tools.css', array(), VERSION );

		if ( ! $tool ) {
			return;
		}

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
	 * Permalink of a calculator page in the current language.
	 *
	 * Resolves the English child by its hierarchical path, then hands it to
	 * Polylang, and falls back to the canonical path so the hub never prints a
	 * dead link — all of which Links::page_url() does for every page in the
	 * plugin.
	 *
	 * @param string $slug English page slug.
	 */
	private static function tool_url( string $slug ): string {
		return Links::page_url( Tools_Content::path( $slug ) );
	}

	/**
	 * `[hti_tools_hub]` — the /tools/ landing page.
	 *
	 * Hero, three feature cards, the remaining calculators as minicards, the
	 * "About" prose with its disclaimer, the FAQ, and the opt-in. The page
	 * title stays the H1, so the hero deliberately emits no heading.
	 */
	public static function render_hub(): string {
		$lang = self::locale();
		$copy = Tools_Content::hub()[ $lang ];
		$open = 'pt' === $lang ? 'Abrir ferramenta' : 'Open tool';

		$core = array();
		$more = array();
		foreach ( Tools_Content::tools() as $slug => $tool ) {
			if ( 'core' === $tool['tier'] ) {
				$core[ $slug ] = $tool;
			} else {
				$more[ $slug ] = $tool;
			}
		}

		// alignwide lets the card grids reach the theme's wide size; without it
		// WordPress's constrained layout caps them at contentSize (680px) and
		// the three-column grid never appears.
		$out = '<section class="hti-tools-hub alignwide">';

		// Hero.
		$out .= '<div class="hti-tools-hero">'
			. '<span class="hti-tools-hero__badge">' . esc_html( (string) $copy['badge'] ) . '</span>'
			. '<p class="hti-tools-hero__lede">' . esc_html( (string) $copy['lede'] ) . '</p>'
			. '<div class="hti-tools-hero__chips">';
		foreach ( (array) $copy['chips'] as $chip ) {
			$out .= '<span>' . esc_html( (string) $chip ) . '</span>';
		}
		$out .= '</div></div>';

		// Feature cards.
		$out .= '<div class="hti-tools-hub__core">';
		foreach ( $core as $slug => $tool ) {
			$out .= '<a class="hti-toolcard" href="' . esc_url( self::tool_url( $slug ) ) . '">'
				. '<span class="hti-toolcard__icon" aria-hidden="true">' . esc_html( (string) $tool['icon'] ) . '</span>'
				. '<span class="hti-toolcard__name">' . esc_html( (string) $tool[ 'title_' . $lang ] ) . '</span>'
				. '<span class="hti-toolcard__desc">' . esc_html( (string) $tool[ 'card_' . $lang ] ) . '</span>'
				. '<span class="hti-toolcard__go">' . esc_html( $open ) . ' →</span>'
				. '</a>';
		}
		$out .= '</div>';

		// The rest.
		$out .= '<div class="hti-tools-hub__moretitle">' . esc_html( (string) $copy['more_title'] ) . '</div>';
		$out .= '<div class="hti-tools-hub__more">';
		foreach ( $more as $slug => $tool ) {
			$out .= '<a class="hti-minicard" href="' . esc_url( self::tool_url( $slug ) ) . '">'
				. '<span class="hti-minicard__name">' . esc_html( (string) $tool[ 'title_' . $lang ] ) . '</span>'
				. '<span class="hti-minicard__desc">' . esc_html( (string) $tool[ 'card_' . $lang ] ) . '</span>'
				. '</a>';
		}
		$out .= '</div>';

		// About + the disclaimer. Emitted here rather than seeded as a block,
		// so no edit in the editor can leave the hub without one.
		$out .= '<div class="hti-tools-hub__prose">'
			. '<h2>' . esc_html( (string) $copy['prose_title'] ) . '</h2>'
			. '<p>' . esc_html( (string) $copy['prose_body'] ) . '</p>'
			. '<div class="hti-tools-note"><span class="hti-tools-bang" aria-hidden="true">!</span><div>'
			. esc_html( (string) $copy['note'] )
			. '</div></div></div>';

		// FAQ — the same array the FAQPage schema serialises, so the copy and
		// the structured data cannot drift. Native <details>, no JS, every item
		// closed so the section reads as a list of questions.
		$faqs = Tools_Content::faqs( 'hub' )[ $lang ] ?? array();
		if ( array() !== $faqs ) {
			$out .= '<div class="hti-tools-hub__prose hti-tools-faq"><h2>' . esc_html( (string) $copy['faq_title'] ) . '</h2>';
			foreach ( $faqs as $faq ) {
				$out .= '<details class="hti-tools-faq__item">'
					. '<summary>' . esc_html( (string) $faq['q'] ) . '<span class="hti-tools-faq__marker" aria-hidden="true"></span></summary>'
					. '<p>' . esc_html( (string) $faq['a'] ) . '</p>'
					. '</details>';
			}
			$out .= '</div>';
		}

		$out .= '<div class="hti-tools-hub__email">' . do_shortcode( '[hti_subscribe source="tools-hub"]' ) . '</div>';

		return $out . '</section>';
	}

	/**
	 * `[hti_tool name="…"]` — one calculator, plus the opt-in beneath it.
	 *
	 * Composition mirrors the forex tools: inputs on the left, a gradient
	 * result panel on the right, and the conversion order fixed in code
	 * (tool → email → the questionnaire CTA that lives in the page body). The
	 * disclaimer is emitted here rather than seeded, so no edit in the editor
	 * can leave a calculator without one.
	 *
	 * No skeleton state, unlike forex: these tools ship sensible defaults and
	 * tools.js computes once on load, so the panel is populated on first paint
	 * and a placeholder would only flash.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'name' => 'compound' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		// The allocation visualiser is a selector, not a numeric form.
		if ( 'allocation' === $atts['name'] ) {
			return self::shell( self::render_allocation() . self::email_block( 'allocation' ) );
		}

		$cfg  = self::config();
		$name = isset( $cfg[ $atts['name'] ] ) ? (string) $atts['name'] : 'compound';
		$tool = $cfg[ $name ];
		$pt   = 'pt' === self::locale();
		$l    = $pt ? 'pt' : 'en';

		$out  = '<form class="hti-tool" data-tool="' . esc_attr( $name ) . '" data-locale="' . esc_attr( $pt ? 'pt-PT' : 'en' ) . '" novalidate>';
		$out .= '<div class="hti-tool__card">';

		// --- Inputs ---------------------------------------------------------
		$out .= '<div class="hti-tool__inputs">';
		$out .= '<span class="hti-tool__kicker">' . esc_html( $pt ? 'OS TEUS NÚMEROS' : 'YOUR NUMBERS' ) . '</span>';
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
		$out .= '</div></div>';

		// --- Result panel ---------------------------------------------------
		// Same partition the forex card uses: the output flagged `primary`
		// becomes the headline figure, everything else a tile.
		$primary = null;
		$tiles   = array();
		foreach ( $tool['outputs'] as $key => $o ) {
			if ( ! empty( $o['primary'] ) && null === $primary ) {
				$primary = array( $key, $o );
			} else {
				$tiles[ $key ] = $o;
			}
		}

		$out .= '<div class="hti-tool__panel" aria-live="polite">';
		$out .= '<span class="hti-tool__kicker">' . esc_html( $pt ? 'RESULTADO' : 'RESULT' ) . '</span>';

		if ( null !== $primary ) {
			$fmt  = isset( $primary[1]['format'] ) ? ' data-format="' . esc_attr( (string) $primary[1]['format'] ) . '"' : '';
			$out .= '<div class="hti-tool__primary">'
				. '<span class="hti-tool__primary-value" data-out="' . esc_attr( $primary[0] ) . '"' . $fmt . '>—</span>'
				. '<span class="hti-tool__primary-label">' . esc_html( (string) $primary[1][ $l ] ) . '</span>'
				. '</div>';
		}

		if ( array() !== $tiles ) {
			$out .= '<div class="hti-tool__tiles">';
			foreach ( $tiles as $key => $o ) {
				$fmt  = isset( $o['format'] ) ? ' data-format="' . esc_attr( (string) $o['format'] ) . '"' : '';
				$out .= '<div class="hti-tool__tile">'
					. '<span class="hti-tool__tile-label">' . esc_html( (string) $o[ $l ] ) . '</span>'
					. '<span class="hti-tool__tile-value" data-out="' . esc_attr( $key ) . '"' . $fmt . '>—</span>'
					. '</div>';
			}
			$out .= '</div>';
		}

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

		$note = $pt
			? 'Exemplo ilustrativo com uma taxa hipotética. Não é aconselhamento nem previsão — investir envolve risco, incluindo a perda de capital.'
			: 'Illustrative example with a hypothetical rate. Not advice or a forecast — investing involves risk, including loss of capital.';
		$out .= '<span class="hti-tool__panelnote">' . esc_html( $note ) . '</span>';

		$out .= '</div>'; // /panel
		$out .= '</div>'; // /card

		$nojs = $pt ? 'Ativa o JavaScript para usar esta calculadora.' : 'Enable JavaScript to use this calculator.';
		$out .= '<noscript><p class="hti-tool__note">' . esc_html( $nojs ) . '</p></noscript>';
		$out .= '</form>';

		return self::shell( $out . self::email_block( $name ) );
	}

	/**
	 * Wrap a tool's output in an aligned shell.
	 *
	 * The two-column card needs more than the theme's 680px contentSize, which
	 * is what a shortcode's output gets by default inside a constrained
	 * layout — at that width the card would always be stacked and the redesign
	 * would be invisible. `alignwide` opens it up to the theme's wide size; the
	 * stylesheet then caps it at a width that still reads as a page element
	 * rather than a banner.
	 *
	 * @param string $inner Rendered tool markup.
	 */
	private static function shell( string $inner ): string {
		return '<div class="hti-tool-shell alignwide">' . $inner . '</div>';
	}

	/**
	 * The opt-in that follows a calculator.
	 *
	 * Attributed to the PAGE slug rather than the tool name: one tool can back
	 * several pages, and a tool-keyed source collapses them into one row in
	 * Brevo — the mistake the forex section already made and corrected.
	 *
	 * @param string $tool Tool name, used only as a fallback.
	 */
	private static function email_block( string $tool ): string {
		$slug = is_singular( 'page' ) ? (string) get_post_field( 'post_name', get_queried_object_id() ) : '';
		$source = 'tools-' . ( '' !== $slug ? $slug : $tool );

		return '<div class="hti-tool__email">'
			. do_shortcode( '[hti_subscribe source="' . esc_attr( $source ) . '"]' )
			. '</div>';
	}

	/**
	 * Per-tool field/output/legend configuration (bilingual labels).
	 *
	 * Public so the contract test can cross-check these keys against the
	 * [data-field] / [data-out] identifiers tools.js reads: rename one on
	 * either side and the calculator goes quietly dead, which is exactly the
	 * kind of break a redesign causes and nothing else would catch.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function config(): array {
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

		// Same card chrome as the calculators — profile picker where their
		// inputs go, the donut in the result panel — so the section reads as
		// one system. The widget is a selector, not a form, so it keeps its own
		// inner markup; tools.js queries descendants, so the nesting is free.
		$out  = '<div class="hti-alloc" data-allocations="' . esc_attr( (string) $json ) . '" data-center="' . esc_attr( $center ) . '" data-sub="' . esc_attr( $sub ) . '">';
		$out .= '<div class="hti-tool__card hti-tool__card--alloc">';

		$out .= '<div class="hti-tool__inputs">';
		$out .= '<span class="hti-tool__kicker">' . esc_html( $pt ? 'PERFIL' : 'PROFILE' ) . '</span>';
		$out .= '<div class="hti-alloc__tabs" role="tablist" aria-label="' . esc_attr( $pt ? 'Perfis de investidor' : 'Investor profiles' ) . '">' . $tabs . '</div>';
		$out .= '</div>';

		$out .= '<div class="hti-tool__panel">';
		$out .= '<span class="hti-tool__kicker">' . esc_html( $pt ? 'ALOCAÇÃO' : 'ALLOCATION' ) . '</span>';
		$out .= '<div class="hti-alloc__view">';
		$out .= '<div class="hti-alloc__donut" data-donut aria-hidden="true"></div>';
		$out .= '<ul class="hti-alloc__list" data-list aria-live="polite"></ul>';
		$out .= '</div>';

		$note = $pt
			? 'Exemplo ilustrativo por classes de ativos, não recomendação. Não nomeia instrumentos. Investir envolve risco, incluindo a perda de capital.'
			: 'Illustrative example by asset class, not a recommendation. No named instruments. Investing involves risk, including loss of capital.';
		$out .= '<span class="hti-tool__panelnote">' . esc_html( $note ) . '</span>';
		$out .= '</div>'; // /panel

		$out .= '</div>'; // /card

		$nojs = $pt ? 'Ativa o JavaScript para explorar os perfis.' : 'Enable JavaScript to explore the profiles.';
		$out .= '<noscript><p class="hti-tool__note">' . esc_html( $nojs ) . '</p></noscript>';
		$out .= '</div>';

		return $out;
	}
}
