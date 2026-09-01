<?php
/**
 * The forex tools: `[hti_forex_tool name="position_size|pip_value|profit_loss|sessions"]`
 * and the `/forex/` hub (`[hti_forex_hub]`).
 *
 * Implements the "Ferramenta Forex" / "Forex Hub" design handoff: one unified
 * tool card (inputs left, gradient result panel right), skeleton before the
 * first calculation, risk bar, below-micro state replacing the result, the
 * IST session clock with live status, and the fixed conversion hierarchy
 * tool → email → partner CTA (the CTA never renders above the result).
 *
 * Server-rendered and enhanced by forex.js (HTIForex core). Pure client-side
 * math over server-provided reference rates. English-only by design (the
 * /forex/ section is the documented exemption to the bilingual invariant).
 * Every tool ends with the risk/education block; the affiliate CTA renders
 * only when Settings::cta_for() allows it.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes + asset wiring for the forex tools.
 */
class Tools {

	private const SHORTCODE     = 'hti_forex_tool';
	private const SHORTCODE_HUB = 'hti_forex_hub';

	/**
	 * Hook the shortcodes, assets and the campaign pixel.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( self::SHORTCODE_HUB, array( __CLASS__, 'render_hub' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_pixel' ) );
	}

	/**
	 * Whether the current singular view embeds a forex tool or the hub.
	 */
	private static function is_forex_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return has_shortcode( $post->post_content, self::SHORTCODE ) || has_shortcode( $post->post_content, self::SHORTCODE_HUB );
	}

	/**
	 * Print the Propeller Ads audience pixel — ONLY on the forex pages (the
	 * paid-campaign landers) and only when a partner id is configured.
	 * Deliberately not consent-gated: the campaigns target outside the EU and
	 * the owner accepted the residual exposure for EU visitors to /forex/
	 * (decision recorded 2026-08; the rest of the site stays consent-gated).
	 */
	public static function print_pixel(): void {
		if ( ! self::is_forex_page() ) {
			return;
		}
		$partner = (string) ( Settings::settings()['propeller_partner'] ?? '' );
		if ( '' === $partner ) {
			return;
		}
		echo self::pixel_html( $partner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pixel_html().
	}

	/**
	 * The canonical Propeller sync tag (script + noscript fallback) for a
	 * validated partner id. Pure so tests can assert the exact markup.
	 *
	 * @param string $partner 64-hex partner id (validated by Settings).
	 */
	public static function pixel_html( string $partner ): string {
		$base = 'https://my.rtmark.net/';
		$qs   = 'f=sync&lr=1&partner=' . rawurlencode( $partner );
		return '<script src="' . esc_url( $base . 'p.js?' . $qs ) . '" defer></script>'
			. '<noscript><img src="' . esc_url( $base . 'img.gif?' . $qs ) . '" width="1" height="1" alt="" /></noscript>' . "\n";
	}

	/**
	 * Enqueue the tool assets only where a forex tool/hub is present.
	 */
	public static function enqueue(): void {
		if ( ! self::is_forex_page() ) {
			return;
		}

		wp_enqueue_style( 'hti-forex', HTI_FOREX_URL . 'assets/css/forex.css', array(), VERSION );
		wp_register_script( 'hti-forex-core', HTI_FOREX_URL . 'assets/js/forex-core.js', array(), VERSION, array( 'in_footer' => true ) );

		// forex.js calls HTITrack.event() for forex_tool_use, so it must load
		// after hti-track or the call is a silent no-op. Declared only when the
		// handle exists: hti-engine provides it, and a missing dependency would
		// make WordPress drop our script entirely, taking the calculators with
		// it.
		$deps = array( 'hti-forex-core' );
		if ( wp_script_is( 'hti-track', 'registered' ) ) {
			$deps[] = 'hti-track';
		}

		wp_enqueue_script(
			'hti-forex',
			HTI_FOREX_URL . 'assets/js/forex.js',
			$deps,
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$rates    = Rates::effective();
		$settings = Settings::settings();

		wp_localize_script(
			'hti-forex',
			'HTI_FOREX',
			array(
				'rates'        => $rates['rates'],
				'ratesDate'    => $rates['date'],
				'ratesStale'   => $rates['stale'],
				'ratesSource'  => $rates['source'],
				// sub_param is deliberately NOT exposed: the browser hands the
				// campaign id to our own redirector as `cid`, and the partner's
				// sub-id parameter is applied server-side in Go::destination().
				'subSources'   => array_values( (array) $settings['sub_sources'] ),
				'subscribeUrl' => rest_url( 'htinvest/v1/subscribe' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * `[hti_forex_tool name="position_size|pip_value|profit_loss|sessions"]`.
	 *
	 * Optional default-overrides let the variant pages preconfigure the same
	 * tool: `pair` (validated against Config::pairs()) and the numeric
	 * `balance`, `risk`, `stop` and `lots` (clamped to the field's min/max).
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'name'     => 'position_size',
				'pair'     => '',
				'balance'  => '',
				'risk'     => '',
				'stop'     => '',
				'lots'     => '',
				'leverage' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$name = in_array( $atts['name'], Settings::TOOLS, true ) ? (string) $atts['name'] : 'position_size';

		if ( 'sessions' === $name ) {
			$body = self::render_sessions();
		} else {
			$body = self::render_calculator( $name, $atts );
		}

		// Conversion order: tool → partner CTA → channel/email capture, with
		// the banner closing the zone. The partner CTA and the capture block
		// swapped places on 31 Aug 2026 at the owner's call — monetization now
		// takes the slot directly under the result, and list-building follows
		// it. The design handoff had them the other way round, so the trade
		// being made is a warmer moment for the partner against a colder one
		// for the channel; whichever way they sit, `cta_click` on
		// `forex_telegram_{tool}` and the partner placement are counted
		// separately, so the swap is measurable rather than a matter of taste.
		//
		// What does not move: no CTA is ever above the result. The top banner
		// sits above the tool but is a banner, not a CTA, so that rule holds.
		return self::ad_block_top() . $body . self::cta_block( $name ) . self::conversion_block( $name, 'row' ) . self::ad_block();
	}

	/* ---------------------------------------------------------------------
	 * Calculators — unified composition (inputs left, result panel right)
	 * ------------------------------------------------------------------- */

	/**
	 * Render one calculator as the unified card.
	 *
	 * @param string              $name position_size|pip_value|profit_loss.
	 * @param array<string,mixed> $atts Shortcode attributes (default-overrides).
	 */
	private static function render_calculator( string $name, array $atts = array() ): string {
		$rates = Rates::effective();
		$cfg   = self::config( $rates );
		$tool  = self::apply_overrides( $cfg[ $name ], $atts );

		// `leverage="1"`: the position-size tool grows a margin section
		// (entry price + leverage → notional ₹ + margin ₹ tiles).
		$with_margin = 'position_size' === $name && in_array( (string) ( $atts['leverage'] ?? '' ), array( '1', 'true', 'yes' ), true );
		if ( $with_margin ) {
			$tool = self::add_margin_extension( $tool );
		}

		// alignwide, for the reason hti-engine's own tools already carry it
		// (class-tools.php::shell): these pages are seeded with no page
		// template, so they render through page.html and inherit
		// contentSize: 680px — while the card inside is a 1fr/380px grid. The
		// inputs column was landing at ~300px on a desktop, and .hti-fx-fields
		// then put two fields in ~135px each. The two sections are meant to
		// read as one product; this is the half that was not asking for the
		// width it needs.
		$out  = '<div class="hti-fx-shell alignwide">';
		$out .= '<form class="hti-fx-tool" data-tool="' . esc_attr( $name ) . '"' . ( $with_margin ? ' data-variant="leverage"' : '' ) . ' novalidate>';
		$out .= '<div class="hti-fx-card hti-fx-card--tool">';

		// --- Inputs column -------------------------------------------------
		$out .= '<div class="hti-fx-inputs">';
		$out .= '<span class="hti-fx-kicker">' . esc_html( 'YOUR TRADE' ) . '</span>';
		$out .= '<div class="hti-fx-fields">';
		foreach ( $tool['fields'] as $key => $f ) {
			$out .= self::field_html( $key, $f );
		}
		$out .= '</div></div>';

		// --- Result panel --------------------------------------------------
		$primary = null;
		$tiles   = array();
		$slots   = array();
		foreach ( $tool['outputs'] as $key => $o ) {
			if ( ! empty( $o['primary'] ) && null === $primary ) {
				$primary = array( $key, $o );
			} elseif ( ! empty( $o['slot'] ) ) {
				$slots[ (string) $o['slot'] ] = array( $key, $o );
			} else {
				$tiles[ $key ] = $o;
			}
		}

		$out .= '<div class="hti-fx-panel" aria-live="polite">';
		$out .= '<span class="hti-fx-kicker">' . esc_html( 'RESULT' ) . '</span>';

		// Skeleton — shown before the first calculation (never a misleading 0.00).
		$out .= '<div class="hti-fx-skel" data-skeleton aria-hidden="true"><span></span><span></span><span></span></div>';

		$out .= '<div class="hti-fx-panelbody" data-panelbody hidden>';

		if ( null !== $primary ) {
			$out .= '<div class="hti-fx-primary">'
				. '<span class="hti-fx-primary__value" data-out="' . esc_attr( $primary[0] ) . '" data-format="' . esc_attr( $primary[1]['format'] ) . '">—</span>';
			if ( isset( $slots['sub'] ) ) {
				$out .= '<span class="hti-fx-primary__sub"><span data-out="' . esc_attr( $slots['sub'][0] ) . '" data-format="' . esc_attr( $slots['sub'][1]['format'] ) . '">—</span> ' . esc_html( $slots['sub'][1]['label'] ) . '</span>';
			}
			$out .= '</div>';
		}

		// Risk bar (position size): rupees at risk on a 0–2% scale.
		if ( isset( $slots['riskline'] ) ) {
			$rk   = $slots['riskline'];
			$out .= '<div class="hti-fx-riskbar" data-riskbar>'
				. '<div class="hti-fx-riskbar__line"><span>' . esc_html( $rk[1]['label'] ) . '</span>'
				. '<span class="hti-fx-riskbar__nums"><span data-out="' . esc_attr( $rk[0] ) . '" data-format="' . esc_attr( $rk[1]['format'] ) . '">—</span> of <span data-risk-of>—</span></span></div>'
				. '<div class="hti-fx-riskbar__track"><div class="hti-fx-riskbar__fill" data-riskfill></div></div>'
				. '<div class="hti-fx-riskbar__scale"><span>0%</span><span data-risk-target>target 1.0%</span><span>2%</span></div>'
				. '</div>';
		}

		if ( array() !== $tiles ) {
			$out .= '<div class="hti-fx-tiles">';
			foreach ( $tiles as $key => $o ) {
				$out .= '<div class="hti-fx-tile"><span class="hti-fx-tile__label">' . esc_html( $o['label'] ) . '</span>'
					. '<span class="hti-fx-tile__value" data-out="' . esc_attr( $key ) . '" data-format="' . esc_attr( $o['format'] ) . '">—</span></div>';
			}
			$out .= '</div>';
		}

		$out .= '</div>'; // /panelbody

		// Sub-micro-lot state: replaces the whole result (design: amber box).
		if ( 'position_size' === $name ) {
			$out .= '<div class="hti-fx-toosmall" data-toosmall hidden>'
				. '<span class="hti-fx-toosmall__title">' . esc_html( 'Below one micro lot' ) . '</span>'
				. '<span class="hti-fx-toosmall__body">' . esc_html( 'At this risk and stop distance the position is under 0.01 lots — the smallest size most brokers allow. A wider stop budget or a higher balance would be needed. An observation about the arithmetic, not a suggestion.' ) . '</span>'
				. '</div>';
		}

		$out .= '<span class="hti-fx-panelnote">'
			. esc_html( 'Educational tool — an illustration of the arithmetic, not investment advice. Forex and CFDs are high-risk; most retail accounts lose money. Regulated in India under FEMA — see the ' )
			. '<a href="' . esc_url( home_url( '/forex/' ) ) . '">forex tools hub</a>.'
			. '</span>';

		$out .= '</div>'; // /panel
		$out .= '</div>'; // /card

		$out .= '<noscript><p class="hti-fx-note">' . esc_html( 'Enable JavaScript to use this calculator.' ) . '</p></noscript>';
		$out .= '</form>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * One input field (number or select), with optional caption and inline
	 * error message.
	 *
	 * @param string              $key Field key.
	 * @param array<string,mixed> $f   Field config.
	 */
	private static function field_html( string $key, array $f ): string {
		$label = $f['label'] . ( '' !== ( $f['unit'] ?? '' ) ? ' (' . $f['unit'] . ')' : '' );
		$cls   = 'hti-fx-field' . ( empty( $f['class'] ) ? '' : ' ' . $f['class'] );

		$out = '<label class="' . esc_attr( $cls ) . '"><span class="hti-fx-field__label">' . esc_html( $label ) . '</span>';

		if ( 'select' === ( $f['type'] ?? 'number' ) ) {
			$out .= '<select data-field="' . esc_attr( $key ) . '">';
			foreach ( $f['options'] as $value => $option_label ) {
				$out .= '<option value="' . esc_attr( $value ) . '"' . selected( $value, $f['default'], false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			$out .= '</select>';
		} else {
			$attrs  = 'data-field="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $f['default'] ) . '"';
			$attrs .= ' min="' . esc_attr( (string) $f['min'] ) . '" step="' . esc_attr( (string) $f['step'] ) . '"';
			if ( isset( $f['max'] ) ) {
				$attrs .= ' max="' . esc_attr( (string) $f['max'] ) . '"';
			}
			$out .= '<input type="number" inputmode="decimal" ' . $attrs . ' />';
		}

		if ( ! empty( $f['errmsg'] ) ) {
			$out .= '<span class="hti-fx-field__err" data-err hidden>' . esc_html( $f['errmsg'] ) . '</span>';
		}
		if ( ! empty( $f['caption'] ) ) {
			$cap_cls = 'hti-fx-field__caption' . ( empty( $f['caption_stale'] ) ? '' : ' hti-fx-field__caption--stale' );
			$out    .= '<span class="' . esc_attr( $cap_cls ) . '"' . ( ! empty( $f['caption_attr'] ) ? ' ' . $f['caption_attr'] : '' ) . '>'
				. ( empty( $f['caption_stale'] ) ? '' : '<span class="hti-fx-bang" aria-hidden="true">!</span>' )
				. esc_html( $f['caption'] ) . '</span>';
		}

		return $out . '</label>';
	}

	/**
	 * Apply the shortcode default-overrides to a tool config. `pair` must be
	 * a known symbol; numeric overrides are clamped to the field's min/max so
	 * a page can preconfigure, never break, the tool.
	 *
	 * @param array<string,mixed> $tool Tool config.
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return array<string,mixed>
	 */
	private static function apply_overrides( array $tool, array $atts ): array {
		$pair = strtoupper( sanitize_text_field( (string) ( $atts['pair'] ?? '' ) ) );
		if ( '' !== $pair && isset( $tool['fields']['pair'] ) && isset( Config::pairs()[ $pair ] ) ) {
			$tool['fields']['pair']['default'] = $pair;
		}

		foreach ( array( 'balance', 'risk', 'stop', 'lots' ) as $key ) {
			$raw = (string) ( $atts[ $key ] ?? '' );
			if ( '' === $raw || ! is_numeric( $raw ) || ! isset( $tool['fields'][ $key ] ) ) {
				continue;
			}
			$value = (float) $raw;
			$field = $tool['fields'][ $key ];
			$value = max( (float) $field['min'], $value );
			if ( isset( $field['max'] ) ) {
				$value = min( (float) $field['max'], $value );
			}
			$tool['fields'][ $key ]['default'] = $value;
		}

		return $tool;
	}

	/**
	 * Add the margin tiles/fields to the position-size tool. Price sits
	 * after the pair select (JS hides it for USD-base pairs, where notional
	 * is price-independent); leverage defaults to the 1:500 common at
	 * offshore platforms.
	 *
	 * @param array<string,mixed> $tool Position-size tool config.
	 * @return array<string,mixed>
	 */
	private static function add_margin_extension( array $tool ): array {
		$fields = array();
		foreach ( $tool['fields'] as $key => $field ) {
			$fields[ $key ] = $field;
			if ( 'pair' === $key ) {
				$fields['price']    = array(
					'label'   => 'Entry price',
					'default' => '1.1650',
					'min'     => 0,
					'step'    => 'any',
					'unit'    => '',
					'class'   => 'hti-fx-field--price',
				);
				$fields['leverage'] = array(
					'label'   => 'Leverage',
					'default' => 500,
					'min'     => 1,
					'max'     => 3000,
					'step'    => 1,
					'unit'    => '×',
				);
			}
		}
		$tool['fields'] = $fields;

		$tool['outputs']['notional_inr'] = array( 'label' => 'POSITION VALUE (₹)', 'format' => 'inr0' );
		$tool['outputs']['margin_inr']   = array( 'label' => 'MARGIN REQUIRED (₹)', 'format' => 'inr0' );

		return $tool;
	}

	/**
	 * Per-tool field/output configuration. Rate fields are prefilled with the
	 * effective server-side rate and stay editable — a stale rate degrades
	 * the caption, never the tool.
	 *
	 * @param array<string,mixed> $rates Rates::effective() result.
	 * @return array<string,array<string,mixed>>
	 */
	private static function config( array $rates ): array {
		$pair_options = array();
		foreach ( Config::pairs() as $symbol => $spec ) {
			$pair_options[ $symbol ] = $spec['label'];
		}

		$stale        = ! empty( $rates['stale'] ) || 'fallback' === $rates['source'];
		$rate_caption = 'fallback' === $rates['source']
			? 'Indicative rate — edit if needed.'
			: sprintf(
				'%s reference rate%s%s — edit if needed.',
				'override' === $rates['source'] ? 'Site' : 'ECB',
				$rates['date'] ? ' · ' . $rates['date'] : '',
				$rates['stale'] ? ' (stale)' : ''
			);

		$rate_fields = array(
			'rate_usdinr' => array(
				'label'         => 'USD/INR rate',
				'default'       => number_format( (float) $rates['rates']['USDINR'], 4, '.', '' ),
				'min'           => 1,
				'step'          => 0.0001,
				'unit'          => '',
				'caption'       => $rate_caption,
				'caption_attr'  => 'data-rate-caption',
				'caption_stale' => $stale,
				'class'         => 'hti-fx-field--wide',
			),
			'rate_usdjpy' => array(
				'label'   => 'USD/JPY rate',
				'default' => number_format( (float) $rates['rates']['USDJPY'], 4, '.', '' ),
				'min'     => 1,
				'step'    => 0.0001,
				'unit'    => '',
				'class'   => 'hti-fx-field--jpy',
			),
		);

		return array(
			'position_size' => array(
				'fields'  => array_merge(
					array(
						'balance' => array( 'label' => 'Account balance', 'default' => 100000, 'min' => 0, 'step' => 1000, 'unit' => '₹' ),
						'risk'    => array( 'label' => 'Risk per trade', 'default' => 1, 'min' => 0.1, 'max' => 10, 'step' => 0.1, 'unit' => '%', 'errmsg' => 'Max 10% — above that the tool stops being a risk tool.' ),
						'stop'    => array( 'label' => 'Stop-loss', 'default' => 20, 'min' => 1, 'step' => 1, 'unit' => 'pips' ),
						'pair'    => array( 'label' => 'Pair', 'type' => 'select', 'default' => 'EURUSD', 'options' => $pair_options, 'unit' => '' ),
					),
					$rate_fields
				),
				'outputs' => array(
					'lots'     => array( 'label' => 'Position size', 'format' => 'lots', 'primary' => true ),
					'units'    => array( 'label' => 'units', 'format' => 'int', 'slot' => 'sub' ),
					'risk_inr' => array( 'label' => 'Rupees at risk', 'format' => 'inr0', 'slot' => 'riskline' ),
					'pip_inr'  => array( 'label' => 'PIP VALUE / LOT', 'format' => 'inr' ),
					'risk_pip' => array( 'label' => 'RISK / PIP', 'format' => 'inr' ),
				),
			),
			'pip_value'     => array(
				'fields'  => array_merge(
					array(
						'pair' => array( 'label' => 'Pair', 'type' => 'select', 'default' => 'EURUSD', 'options' => $pair_options, 'unit' => '' ),
						'lots' => array( 'label' => 'Position size', 'default' => 1, 'min' => 0.01, 'step' => 0.01, 'unit' => 'lots' ),
					),
					$rate_fields
				),
				'outputs' => array(
					'pip_inr'  => array( 'label' => '1 pip in Indian rupees', 'format' => 'inr', 'primary' => true ),
					'pip_usd'  => array( 'label' => 'in US dollars', 'format' => 'usd', 'slot' => 'sub' ),
					'standard' => array( 'label' => 'PER STANDARD LOT', 'format' => 'inr' ),
					'mini'     => array( 'label' => 'PER MINI LOT', 'format' => 'inr' ),
					'micro'    => array( 'label' => 'PER MICRO LOT', 'format' => 'inr' ),
				),
			),
			'profit_loss'   => array(
				'fields'  => array_merge(
					array(
						'pair'      => array( 'label' => 'Pair', 'type' => 'select', 'default' => 'EURUSD', 'options' => $pair_options, 'unit' => '' ),
						'direction' => array(
							'label'   => 'Direction',
							'type'    => 'select',
							'default' => 'buy',
							'options' => array(
								'buy'  => 'Buy (long)',
								'sell' => 'Sell (short)',
							),
							'unit'    => '',
						),
						'lots'      => array( 'label' => 'Position size', 'default' => 0.10, 'min' => 0.01, 'step' => 0.01, 'unit' => 'lots' ),
						'entry'     => array( 'label' => 'Entry price', 'default' => '1.1650', 'min' => 0, 'step' => 'any', 'unit' => '' ),
						'exit'      => array( 'label' => 'Exit price', 'default' => '1.1670', 'min' => 0, 'step' => 'any', 'unit' => '' ),
					),
					$rate_fields
				),
				'outputs' => array(
					'pl_inr' => array( 'label' => 'Profit / loss in ₹', 'format' => 'inr_signed', 'primary' => true ),
					'pips'   => array( 'label' => 'pips moved', 'format' => 'pips', 'slot' => 'sub' ),
					'pl_usd' => array( 'label' => 'PROFIT / LOSS ($)', 'format' => 'usd_signed' ),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * IST session clock
	 * ------------------------------------------------------------------- */

	/**
	 * Server-rendered session card in IST for today — gradient clock header,
	 * status column and overlap pill — enhanced by forex.js into a live
	 * clock. Works with JavaScript off (status computed server-side too).
	 */
	private static function render_sessions(): string {
		$ist     = new \DateTimeZone( 'Asia/Kolkata' );
		$now     = new \DateTimeImmutable( 'now' );
		$now_ist = $now->setTimezone( $ist );
		$windows = Config::session_windows_ist( $now );
		$overlap = Config::overlap_london_ny_ist( $now );

		// Server-side open state (JS keeps it fresh): open/close instants per
		// session, weekend closed everywhere.
		$states = array();
		$date   = $now_ist->format( 'Y-m-d' );
		foreach ( Config::sessions() as $id => $s ) {
			$tz      = new \DateTimeZone( $s['tz'] );
			$open    = new \DateTimeImmutable( $date . ' ' . $s['open'], $tz );
			$close   = new \DateTimeImmutable( $date . ' ' . $s['close'], $tz );
			$weekend = in_array( (int) $now->setTimezone( $tz )->format( 'N' ), array( 6, 7 ), true );
			$states[ $id ] = ! $weekend && $now >= $open && $now < $close;
		}
		$overlap_live = ( $states['london'] ?? false ) && ( $states['new_york'] ?? false );

		$out  = '<div class="hti-fx-tool hti-fx-sessions" data-tool="sessions">';
		$out .= '<div class="hti-fx-card hti-fx-card--sessions">';

		// Clock header.
		$out .= '<div class="hti-fx-clockhead">'
			. '<div class="hti-fx-clockhead__left">'
			. '<span class="hti-fx-clockhead__time"><span data-clock-hm>' . esc_html( $now_ist->format( 'H:i' ) ) . '</span><span class="hti-fx-clockhead__sec" data-clock-s>:' . esc_html( $now_ist->format( 's' ) ) . '</span></span>'
			. '<span class="hti-fx-clockhead__label">' . esc_html( 'IST — Indian Standard Time (UTC+5:30)' ) . '</span>'
			. '</div>'
			. '<div class="hti-fx-clockhead__right">'
			. '<span class="hti-fx-overlap-pill" data-overlap-pill' . ( $overlap_live ? '' : ' hidden' ) . '><span class="hti-fx-overlap-pill__dot"></span>' . esc_html( 'London–NY overlap live' ) . '</span>'
			. '<span class="hti-fx-overlap-line" data-overlap>' . esc_html( $overlap['start_ist'] . '–' . $overlap['end_ist'] . ' IST · busiest hours' ) . '</span>'
			. '</div></div>';

		// Session table.
		$out .= '<table class="hti-fx-table">';
		$out .= '<caption class="screen-reader-text">' . esc_html( 'Forex market sessions in IST — ' . $now_ist->format( 'D, j M Y' ) ) . '</caption>';
		$out .= '<thead><tr><th scope="col">Session</th><th scope="col">Opens (IST)</th><th scope="col">Closes (IST)</th><th scope="col">Status</th></tr></thead><tbody>';

		foreach ( $windows as $w ) {
			$open_now = $states[ $w['id'] ] ?? false;
			$close    = $w['close_ist'] . ( $w['closes_next_day'] ? ' +1' : '' );
			$out     .= '<tr data-session="' . esc_attr( $w['id'] ) . '"' . ( $open_now ? ' class="is-open"' : '' ) . '>'
				. '<th scope="row">' . esc_html( $w['label'] ) . '</th>'
				. '<td data-open>' . esc_html( $w['open_ist'] ) . '</td>'
				. '<td data-close>' . esc_html( $close ) . '</td>'
				. '<td class="hti-fx-status ' . ( $open_now ? 'hti-fx-status--open' : 'hti-fx-status--closed' ) . '" data-status>' . esc_html( $open_now ? '● Open' : '— Closed' ) . '</td>'
				. '</tr>';
		}

		$out .= '</tbody></table>';

		$out .= '<span class="hti-fx-note">' . esc_html( 'India does not observe daylight saving — it is the foreign sessions that shift each March and late October. Times computed for today. Market closed on weekends.' ) . '</span>';

		$out .= '</div>'; // /card
		$out .= self::risk_block( 'sessions' );
		$out .= '<noscript><p class="hti-fx-note">' . esc_html( "The table shows today's times; enable JavaScript for the live clock." ) . '</p></noscript>';
		$out .= '</div>';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Hub — [hti_forex_hub]
	 * ------------------------------------------------------------------- */

	/**
	 * The /forex/ hub: hero, core tool cards, banner slot, compact variant
	 * cards, About + FEMA note, FAQ (from the same Config::faqs('hub') the
	 * schema uses) and the email card. The page's own title stays the H1.
	 */
	public static function render_hub(): string {
		$url = static function ( string $path ): string {
			$page = get_page_by_path( 'forex/' . $path, OBJECT, 'page' );
			return $page instanceof \WP_Post ? (string) get_permalink( $page ) : home_url( '/forex/' . $path . '/' );
		};

		$core = array(
			array( '₹', 'Position size calculator', 'How many lots fit your ₹ balance, risk % and stop-loss — with the exact rupees at risk.', $url( 'position-size-calculator' ) ),
			array( '¤', 'Pip value calculator', 'What one pip is worth in ₹ for EURUSD, GBPUSD, yen pairs and gold (XAUUSD).', $url( 'pip-value-calculator' ) ),
			array( '◷', 'Market hours in IST', 'Live session clock in Indian time, with the London–New York overlap highlighted.', $url( 'market-hours-ist' ) ),
		);
		$more = array(
			array( 'XAUUSD lot size', 'Gold sizing with the 100 oz contract.', $url( 'xauusd-lot-size-calculator' ) ),
			array( 'Profit / loss', 'Entry to exit, result in ₹.', $url( 'profit-calculator' ) ),
			array( 'Size with leverage', 'Adds margin required at your leverage.', $url( 'lot-size-calculator-with-leverage' ) ),
			array( 'Size for a $100 account', 'Micro-lot sizing for small accounts.', $url( 'lot-size-for-100-dollar-account' ) ),
		);

		$out = '<section class="hti-fx-hub">';

		// Hero (the page title above stays the H1).
		$out .= '<div class="hti-fx-hero">'
			. '<span class="hti-fx-hero__badge">' . esc_html( 'Free tools · Built for India' ) . '</span>'
			. '<p class="hti-fx-hero__lede">' . esc_html( 'Your account in ₹, market hours in IST, and the lot conventions global platforms actually use. Educational, free, no sign-up — every tool works on your phone.' ) . '</p>'
			. '<div class="hti-fx-hero__chips">'
			. '<span>' . esc_html( '₹ INR account currency' ) . '</span>'
			. '<span>' . esc_html( 'IST market hours' ) . '</span>'
			. '<span>' . esc_html( 'No sign-up' ) . '</span>'
			. '</div></div>';

		// Top banner slot — directly under the hero chips.
		$out .= self::ad_block_top();

		// Core tools.
		$out .= '<div class="hti-fx-hub__core">';
		foreach ( $core as $t ) {
			$out .= '<a class="hti-fx-toolcard" href="' . esc_url( $t[3] ) . '">'
				. '<span class="hti-fx-toolcard__icon" aria-hidden="true">' . esc_html( $t[0] ) . '</span>'
				. '<span class="hti-fx-toolcard__name">' . esc_html( $t[1] ) . '</span>'
				. '<span class="hti-fx-toolcard__desc">' . esc_html( $t[2] ) . '</span>'
				. '<span class="hti-fx-toolcard__go">' . esc_html( 'Open tool' ) . ' →</span>'
				. '</a>';
		}
		$out .= '</div>';

		// Banner slot — mid-fold on the hub, between core and variant cards.
		$out .= self::ad_block();

		// Variant tools.
		$out .= '<div class="hti-fx-hub__moretitle">' . esc_html( 'More calculators' ) . '</div>';
		$out .= '<div class="hti-fx-hub__more">';
		foreach ( $more as $t ) {
			$out .= '<a class="hti-fx-minicard" href="' . esc_url( $t[2] ) . '">'
				. '<span class="hti-fx-minicard__name">' . esc_html( $t[0] ) . '</span>'
				. '<span class="hti-fx-minicard__desc">' . esc_html( $t[1] ) . '</span>'
				. '</a>';
		}
		$out .= '</div>';

		// About + legal note.
		$out .= '<div class="hti-fx-hub__prose">'
			. '<h2>' . esc_html( 'About these tools' ) . '</h2>'
			. '<p>' . esc_html( 'Every calculator here is an illustration of the arithmetic — how position sizing, pip values and session times work — not advice about what, when or whether to trade. Forex and CFDs are leveraged, high-risk products; most retail accounts lose money.' ) . '</p>'
			. '<div class="hti-fx-legalnote"><span class="hti-fx-bang" aria-hidden="true">!</span><div>'
			. '<strong>' . esc_html( 'Is forex trading legal in India?' ) . '</strong> '
			. esc_html( 'Retail forex in India is regulated under FEMA, and the RBI publishes an Alert List of unauthorised platforms. These tools are education — check any platform against the RBI list before opening an account.' )
			. '</div></div></div>';

		// FAQ — same source as the FAQPage schema (native <details>, no JS).
		// Every item starts collapsed, so the section reads as a compact list
		// of questions rather than opening on one answer.
		$faqs = Config::faqs( 'hub' );
		if ( array() !== $faqs ) {
			$out .= '<div class="hti-fx-hub__prose hti-fx-faq"><h2>' . esc_html( 'Frequently asked' ) . '</h2>';
			foreach ( $faqs as $faq ) {
				$out .= '<details class="hti-fx-faq__item">'
					. '<summary>' . esc_html( $faq['q'] ) . '<span class="hti-fx-faq__marker" aria-hidden="true"></span></summary>'
					. '<p>' . esc_html( $faq['a'] ) . '</p>'
					. '</details>';
			}
			$out .= '</div>';
		}

		// Same order as the tool pages: partner first, capture after. The hub
		// carried no partner CTA at all until now, so its foot went straight
		// from the FAQ to the channel box.
		$out .= self::cta_block( 'hub' );
		$out .= self::conversion_block( 'hub', 'card' );
		$out .= '</section>';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Conversion blocks (email → CTA → banner) + shared pieces
	 * ------------------------------------------------------------------- */

	/**
	 * The affiliate CTA bar. Renders NOTHING unless Settings::cta_for()
	 * allows it (global kill-switch on, per-tool toggle on, https URL) — no
	 * hidden markup, no dead tracking attributes. The click is tracked by the
	 * site-wide hti-track delegated listener via the data attributes alone;
	 * forex.js appends the campaign sub-id read from the landing URL.
	 *
	 * @param string $tool Tool name.
	 */
	private static function cta_block( string $tool ): string {
		$cta = Settings::cta_for( $tool );
		if ( null === $cta ) {
			return '';
		}

		$brand = '' !== $cta['brand'] ? $cta['brand'] : 'Partner';
		$mark  = '' !== $cta['logo']
			? '<img class="hti-fx-cta__logo" src="' . esc_url( $cta['logo'] ) . '" alt="' . esc_attr( $brand ) . '" height="26" loading="lazy" decoding="async" />'
			: '<span class="hti-fx-cta__wordmark">' . esc_html( $brand ) . '</span>';

		$out = '<div class="hti-fx-cta">'
			. '<div class="hti-fx-cta__head">' . $mark
			. '<span class="hti-fx-cta__badge">' . esc_html( 'PARTNER · AD' ) . '</span>'
			. '</div>';

		if ( '' !== $cta['headline'] ) {
			$out .= '<p class="hti-fx-cta__headline">' . esc_html( $cta['headline'] ) . '</p>';
		}

		// The href is OUR redirector, never the affiliate URL: /forex/go/{slot}
		// resolves the partner server-side at click time, so the page source
		// carries no affiliate link and the click is counted there (which is
		// also why this anchor no longer carries data-hti-track — Go::maybe_redirect()
		// bumps cta_click as forex_go_{slot}, and two taggers would double-count).
		$out .= '<a class="hti-fx-cta__btn" href="' . esc_url( Go::url( $cta['slot'] ) ) . '" target="_blank" rel="sponsored nofollow noopener"'
			. ' data-hti-fx-cta>'
			. esc_html( $cta['label'] )
			. '</a>';

		return $out
			. '<span class="hti-fx-cta__risk">' . esc_html( 'Forex and CFDs are high-risk leveraged products; most retail accounts lose money. Educational content — not investment advice. We may be paid if you open an account, at no cost to you.' ) . '</span>'
			. '</div>';
	}

	/**
	 * The conversion slot that follows the calculator.
	 *
	 * Which block lands here is a setting, because it is a live experiment:
	 * the /forex/ audience is Indian and reads Telegram daily, so the channel
	 * may convert better than asking a foreign site for an email — or it may
	 * not, and the email list is an asset the channel is not (it is ours, and
	 * it reaches an inbox). Settings::conversion_blocks() is the single place
	 * the choice is made, so the hub and the tool pages can never disagree
	 * about which arm a visitor is in.
	 *
	 * @param string $tool    Tool name, or 'hub'.
	 * @param string $variant 'row' on tool pages, 'card' on the hub.
	 */
	private static function conversion_block( string $tool, string $variant ): string {
		$blocks = Settings::conversion_blocks( Settings::settings() );

		$out = '';
		if ( $blocks['telegram'] ) {
			$out .= self::telegram_block( $tool, $variant );
		}
		if ( $blocks['email'] ) {
			$out .= self::email_block( $tool, $variant );
		}
		return $out;
	}

	/**
	 * The Telegram channel block.
	 *
	 * The offer is the cheat sheet, not the channel: "join us" converts far
	 * below "the PDF you came for is pinned in there". It is the same lead
	 * magnet the email form promises, moved to where this audience already
	 * lives — which is why swapping the two blocks is a fair comparison rather
	 * than trading a real offer for a vague one.
	 *
	 * Styled as one of ours, deliberately not as the dark partner card: that
	 * one exists to read as advertising at a glance, and this is our own
	 * educational channel. No rel="sponsored" either, for the same reason.
	 *
	 * @param string $tool    Tool name, or 'hub'.
	 * @param string $variant 'row' | 'card'.
	 */
	private static function telegram_block( string $tool, string $variant ): string {
		$url = Settings::normalize_telegram_url( (string) ( Settings::settings()['telegram_url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}

		// Closed vocabulary: the funnel's CTA map is the one breakdown with no
		// cardinality cap, so these keys are derived from the tool list and
		// never from anything a visitor controls.
		$location = 'forex_telegram_' . ( in_array( $tool, Settings::TOOLS, true ) ? $tool : 'hub' );

		$plane = '<svg class="hti-fx-tg__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>';

		return '<div class="hti-fx-tg hti-fx-tg--' . esc_attr( $variant ) . '">'
			. '<div class="hti-fx-tg__copy">'
			. '<span class="hti-fx-tg__title">' . esc_html( 'The INR cheat sheet is pinned in our Telegram channel' ) . '</span>'
			. '<span class="hti-fx-tg__sub">' . esc_html( 'Pip values in ₹, the position-size formula and market hours in IST, on one printable sheet — pinned to the top of the channel. Free, and no sign-up.' ) . '</span>'
			. '</div>'
			. '<a class="hti-fx-tg__btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"'
			. ' data-hti-track="cta_click" data-htip-location="' . esc_attr( $location ) . '">'
			. $plane . esc_html( 'Open the channel' )
			. '</a>'
			. '<span class="hti-fx-tg__note">' . esc_html( 'Educational notes on the arithmetic and the market calendar — no signals, no trade calls, no tips.' ) . '</span>'
			. '</div>';
	}

	/**
	 * Email capture: posts to hti-engine's existing double-opt-in endpoint
	 * (htinvest/v1/subscribe) with source "forex-<tool>" — consent checkbox,
	 * honeypot and rate limiting all come from that stack. Forex opt-ins
	 * receive the INR lot-size cheat sheet after confirming. No PII is
	 * stored by this plugin.
	 *
	 * @param string $tool    Tool name (feeds the source).
	 * @param string $variant 'row' (horizontal, tool pages) or 'card' (hub).
	 */
	private static function email_block( string $tool, string $variant = 'row' ): string {
		$settings = Settings::settings();
		if ( empty( $settings['email_enabled'] ) ) {
			return '';
		}

		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( '' === $privacy ) {
			$privacy = home_url( '/privacy-policy/' );
		}

		// Attribute the opt-in to the PAGE, not the tool: three variant pages
		// all render position_size, so a tool-keyed source collapses them in
		// Brevo. The forex- prefix stays (the hti_lead_magnet filter keys on
		// it); data-location keeps the tool (bounded metrics breakdown keys).
		$page_slug = is_singular( 'page' ) ? (string) get_post_field( 'post_name', get_queried_object_id() ) : '';
		$source    = 'forex-' . ( '' !== $page_slug ? $page_slug : $tool );

		return '<div class="hti-fx-email hti-fx-email--' . esc_attr( $variant ) . '" data-email data-source="' . esc_attr( $source ) . '" data-location="forex_' . esc_attr( $tool ) . '">'
			. '<div class="hti-fx-email__copy">'
			. '<span class="hti-fx-email__title">' . esc_html( 'Get the free INR lot-size cheat sheet (PDF)' ) . '</span>'
			. '<span class="hti-fx-email__sub">' . esc_html( 'Pip values in ₹, the position-size formula and market hours in IST on one printable sheet — sent after you confirm. Unsubscribe anytime.' ) . '</span>'
			. '</div>'
			. '<form class="hti-fx-email__form" novalidate>'
			. '<input type="email" name="email" autocomplete="email" required placeholder="you@example.com" aria-label="Email address" />'
			. '<input type="text" name="hti_hp" class="hti-fx-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />'
			. '<button type="submit"><span class="hti-fx-email__btnlabel">' . esc_html( 'Subscribe' ) . '</span></button>'
			. '</form>'
			. '<label class="hti-fx-email__consent"><input type="checkbox" data-consent /> '
			. esc_html( 'I agree to receive these emails, as described in the ' )
			. '<a href="' . esc_url( $privacy ) . '">privacy policy</a>.'
			. '</label>'
			. '<p class="hti-fx-email__status" role="status" aria-live="polite"></p>'
			. '</div>';
	}

	/**
	 * The banner-ad slot. Renders NOTHING unless the toggle is on and at
	 * least one code is configured. The codes are third-party banner tags
	 * pasted by an admin in Settings → HTI Forex (stored raw,
	 * manage_options-only) and are echoed as-is on purpose — an escaped ad
	 * tag is a dead ad tag. With both codes set they swap at the 560px
	 * breakpoint; with one, it shows everywhere.
	 */
	private static function ad_block(): string {
		$s = Settings::settings();
		if ( empty( $s['ads_enabled'] ) ) {
			return '';
		}

		$desktop = (string) $s['ad_code_desktop'];
		$mobile  = (string) $s['ad_code_mobile'];
		if ( '' === $desktop && '' === $mobile ) {
			return '';
		}

		$out = '<div class="hti-fx-ad"><span class="hti-fx-ad__label">' . esc_html( 'Advertisement' ) . '</span>';

		if ( '' !== $desktop && '' !== $mobile ) {
			$out .= '<div class="hti-fx-ad__slot hti-fx-ad__slot--desktop">' . $desktop . '</div>';
			$out .= '<div class="hti-fx-ad__slot hti-fx-ad__slot--mobile">' . $mobile . '</div>';
		} else {
			$out .= '<div class="hti-fx-ad__slot">' . ( '' !== $desktop ? $desktop : $mobile ) . '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * The top banner slot (600×90): under the hero chips on the hub, above the
	 * calculator on the tool pages. Same rules as ad_block() — the global
	 * toggle gates it, the codes are third-party tags echoed as-is, and
	 * nothing renders without a code. The desktop code hides below 620px,
	 * where a 600px banner no longer fits; the optional mobile code takes over
	 * there. A mobile code alone shows everywhere (320px always fits).
	 */
	private static function ad_block_top(): string {
		$s = Settings::settings();
		if ( empty( $s['ads_enabled'] ) ) {
			return '';
		}

		$desktop = (string) $s['ad_code_top'];
		$mobile  = (string) $s['ad_code_top_mobile'];
		if ( '' === $desktop && '' === $mobile ) {
			return '';
		}

		$out = '<div class="hti-fx-ad hti-fx-ad--top"><span class="hti-fx-ad__label">' . esc_html( 'Advertisement' ) . '</span>';

		if ( '' !== $desktop ) {
			$out .= '<div class="hti-fx-ad__slot hti-fx-ad__slot--desktop">' . $desktop . '</div>';
		}
		if ( '' !== $mobile ) {
			$out .= '<div class="hti-fx-ad__slot' . ( '' !== $desktop ? ' hti-fx-ad__slot--mobile' : '' ) . '">' . $mobile . '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * The risk/education block for surfaces without the result panel (the
	 * sessions page). Lives inside the shortcode output on purpose: it
	 * cannot be edited away in the editor.
	 *
	 * @param string $tool Tool name (unused for now; kept for per-tool copy).
	 */
	private static function risk_block( string $tool ): string {
		unset( $tool );

		$hub = esc_url( home_url( '/forex/' ) );

		return '<p class="hti-fx-note hti-fx-risk">'
			. esc_html( 'Educational tool — an illustration of the arithmetic, not investment advice. Forex and CFDs are leveraged, high-risk products; most retail accounts lose money. Forex is regulated in India under FEMA — see the note on the ' )
			. '<a href="' . $hub . '">forex tools hub</a>.'
			. '</p>';
	}
}
