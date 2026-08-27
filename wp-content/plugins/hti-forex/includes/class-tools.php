<?php
/**
 * The forex tools: `[hti_forex_tool name="position_size|pip_value|sessions"]`.
 *
 * Server-rendered, accessible forms enhanced by forex.js (HTIForex core).
 * Pure client-side math over server-provided reference rates — no network
 * calls from the calculators. English-only by design (the /forex/ section is
 * the documented exemption to the site's bilingual invariant). Every tool
 * ends with the risk/education block; the affiliate CTA renders only when
 * Settings::cta_for() allows it.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode + asset wiring for the forex tools.
 */
class Tools {

	private const SHORTCODE = 'hti_forex_tool';

	/**
	 * Hook the shortcode and assets.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the current singular view embeds a forex tool.
	 */
	private static function is_forex_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the tool assets only where a forex tool is present.
	 */
	public static function enqueue(): void {
		if ( ! self::is_forex_page() ) {
			return;
		}

		wp_enqueue_style( 'hti-forex', HTI_FOREX_URL . 'assets/css/forex.css', array(), VERSION );
		wp_register_script( 'hti-forex-core', HTI_FOREX_URL . 'assets/js/forex-core.js', array(), VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script(
			'hti-forex',
			HTI_FOREX_URL . 'assets/js/forex.js',
			array( 'hti-forex-core' ),
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
				'subParam'     => (string) $settings['sub_param'],
				'subSources'   => array_values( (array) $settings['sub_sources'] ),
				'subscribeUrl' => rest_url( 'htinvest/v1/subscribe' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * `[hti_forex_tool name="position_size|pip_value|sessions"]`.
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

		// Conversion blocks are siblings of the tool (the calculator is a
		// <form>; nesting the email form inside it would be invalid HTML).
		return $body . self::cta_block( $name ) . self::email_block( $name ) . self::ad_block();
	}

	/**
	 * The banner-ad slot, after the conversion blocks. Renders NOTHING unless
	 * the toggle is on and at least one code is configured. The codes are
	 * third-party banner tags pasted by an admin in Settings → HTI Forex
	 * (stored raw, manage_options-only) and are echoed as-is on purpose —
	 * an escaped ad tag is a dead ad tag. With both codes set they swap at
	 * the 560px breakpoint; with one, it shows everywhere.
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

	/* ---------------------------------------------------------------------
	 * Calculators
	 * ------------------------------------------------------------------- */

	/**
	 * Render one calculator form.
	 *
	 * @param string              $name position_size|pip_value.
	 * @param array<string,mixed> $atts Shortcode attributes (default-overrides).
	 */
	private static function render_calculator( string $name, array $atts = array() ): string {
		$rates = Rates::effective();
		$cfg   = self::config( $rates );
		$tool  = self::apply_overrides( $cfg[ $name ], $atts );

		// `leverage="1"`: the position-size tool grows a margin section
		// (entry price + leverage → notional ₹ + margin ₹).
		$with_margin = 'position_size' === $name && in_array( (string) ( $atts['leverage'] ?? '' ), array( '1', 'true', 'yes' ), true );
		if ( $with_margin ) {
			$tool = self::add_margin_extension( $tool );
		}

		$out = '<form class="hti-fx-tool" data-tool="' . esc_attr( $name ) . '"' . ( $with_margin ? ' data-variant="leverage"' : '' ) . ' novalidate>';

		// Inputs.
		$out .= '<div class="hti-fx-fields">';
		foreach ( $tool['fields'] as $key => $f ) {
			$label = $f['label'] . ( '' !== ( $f['unit'] ?? '' ) ? ' (' . $f['unit'] . ')' : '' );
			$cls   = 'hti-fx-field' . ( empty( $f['class'] ) ? '' : ' ' . $f['class'] );

			$out .= '<label class="' . esc_attr( $cls ) . '"><span class="hti-fx-field__label">' . esc_html( $label ) . '</span>';

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

			if ( ! empty( $f['caption'] ) ) {
				$out .= '<span class="hti-fx-field__caption"' . ( ! empty( $f['caption_attr'] ) ? ' ' . $f['caption_attr'] : '' ) . '>' . esc_html( $f['caption'] ) . '</span>';
			}

			$out .= '</label>';
		}
		$out .= '</div>';

		// Results (live region).
		$out .= '<div class="hti-fx-results" aria-live="polite">';
		foreach ( $tool['outputs'] as $key => $o ) {
			$cls  = 'hti-fx-out' . ( ! empty( $o['primary'] ) ? ' hti-fx-out--primary' : '' );
			$out .= '<div class="' . $cls . '"><span class="hti-fx-out__label">' . esc_html( $o['label'] ) . '</span>'
				. '<span class="hti-fx-out__value" data-out="' . esc_attr( $key ) . '" data-format="' . esc_attr( $o['format'] ) . '">—</span></div>';
		}
		$out .= '</div>';

		// Sub-micro-lot state: shown by forex.js instead of a misleading 0.00.
		if ( 'position_size' === $name ) {
			$out .= '<p class="hti-fx-toosmall" data-toosmall hidden>'
				. esc_html( 'At this risk and stop distance the position is below one micro lot (0.01) — the smallest size most brokers allow. A wider account, a smaller stop or a higher risk percentage would be needed before any position fits, which is an observation about the arithmetic, not a suggestion.' )
				. '</p>';
		}

		$out .= self::risk_block( $name );
		$out .= '<noscript><p class="hti-fx-note">' . esc_html( 'Enable JavaScript to use this calculator.' ) . '</p></noscript>';
		$out .= '</form>';

		return $out;
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
	 * Add the margin fields/outputs to the position-size tool. Price sits
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
					'default' => '1.0900',
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

		$tool['outputs']['notional_inr'] = array( 'label' => 'Position value (notional ₹)', 'format' => 'inr0' );
		$tool['outputs']['margin_inr']   = array( 'label' => 'Margin required (₹)', 'format' => 'inr0' );

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
				'label'        => 'USD/INR rate',
				'default'      => number_format( (float) $rates['rates']['USDINR'], 4, '.', '' ),
				'min'          => 1,
				'step'         => 0.0001,
				'unit'         => '',
				'caption'      => $rate_caption,
				'caption_attr' => 'data-rate-caption',
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
						'risk'    => array( 'label' => 'Risk per trade', 'default' => 1, 'min' => 0.1, 'max' => 10, 'step' => 0.1, 'unit' => '%' ),
						'stop'    => array( 'label' => 'Stop-loss', 'default' => 20, 'min' => 1, 'step' => 1, 'unit' => 'pips' ),
						'pair'    => array( 'label' => 'Pair', 'type' => 'select', 'default' => 'EURUSD', 'options' => $pair_options, 'unit' => '' ),
					),
					$rate_fields
				),
				'outputs' => array(
					'lots'     => array( 'label' => 'Position size', 'format' => 'lots', 'primary' => true ),
					'units'    => array( 'label' => 'Units', 'format' => 'int' ),
					'risk_inr' => array( 'label' => 'Actually at risk', 'format' => 'inr0' ),
					'pip_inr'  => array( 'label' => 'Pip value (₹ per lot)', 'format' => 'inr' ),
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
					'pip_usd'  => array( 'label' => '1 pip in US dollars', 'format' => 'usd' ),
					'standard' => array( 'label' => 'Per standard lot (₹)', 'format' => 'inr' ),
					'mini'     => array( 'label' => 'Per mini lot (₹)', 'format' => 'inr' ),
					'micro'    => array( 'label' => 'Per micro lot (₹)', 'format' => 'inr' ),
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
						'entry'     => array( 'label' => 'Entry price', 'default' => '1.0900', 'min' => 0, 'step' => 'any', 'unit' => '' ),
						'exit'      => array( 'label' => 'Exit price', 'default' => '1.0920', 'min' => 0, 'step' => 'any', 'unit' => '' ),
					),
					$rate_fields
				),
				'outputs' => array(
					'pl_inr' => array( 'label' => 'Profit / loss in ₹', 'format' => 'inr_signed', 'primary' => true ),
					'pl_usd' => array( 'label' => 'Profit / loss in $', 'format' => 'usd_signed' ),
					'pips'   => array( 'label' => 'Pips moved', 'format' => 'pips' ),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * IST session clock
	 * ------------------------------------------------------------------- */

	/**
	 * Server-rendered session table in IST for today, enhanced by forex.js
	 * into a live clock with open/closed state. Works with JavaScript off.
	 */
	private static function render_sessions(): string {
		$ist     = new \DateTimeZone( 'Asia/Kolkata' );
		$now     = ( new \DateTimeImmutable( 'now' ) )->setTimezone( $ist );
		$windows = Config::session_windows_ist( $now );
		$overlap = Config::overlap_london_ny_ist( $now );

		$out = '<div class="hti-fx-tool hti-fx-sessions" data-tool="sessions">';

		$out .= '<div class="hti-fx-clock" data-clock hidden>'
			. '<span class="hti-fx-clock__time" data-clock-time>—</span>'
			. '<span class="hti-fx-clock__label">IST — Indian Standard Time (UTC+5:30)</span>'
			. '</div>';

		$out .= '<table class="hti-fx-table">';
		$out .= '<caption>Forex market sessions in IST — ' . esc_html( $now->format( 'D, j M Y' ) ) . '</caption>';
		$out .= '<thead><tr><th scope="col">Session</th><th scope="col">Opens (IST)</th><th scope="col">Closes (IST)</th>'
			. '<th scope="col" class="hti-fx-status-col" data-status-col hidden>Status</th></tr></thead><tbody>';

		foreach ( $windows as $w ) {
			$close = $w['close_ist'] . ( $w['closes_next_day'] ? ' +1' : '' );
			$out  .= '<tr data-session="' . esc_attr( $w['id'] ) . '">'
				. '<th scope="row">' . esc_html( $w['label'] ) . '</th>'
				. '<td data-open>' . esc_html( $w['open_ist'] ) . '</td>'
				. '<td data-close>' . esc_html( $close ) . '</td>'
				. '<td class="hti-fx-status-col" data-status hidden>—</td>'
				. '</tr>';
		}

		$out .= '</tbody></table>';

		$out .= '<p class="hti-fx-overlap" data-overlap>London–New York overlap today: <strong>'
			. esc_html( $overlap['start_ist'] . '–' . $overlap['end_ist'] ) . ' IST</strong>'
			. ' — historically the busiest hours of the trading day.</p>';

		$out .= '<p class="hti-fx-note">India does not observe daylight saving — IST is fixed at UTC+5:30, so it is the foreign'
			. ' sessions that shift by an hour each March and late October/November. The times above are computed for today,'
			. ' including those transitions. The market is closed on weekends.</p>';

		$out .= self::risk_block( 'sessions' );
		$out .= '<noscript><p class="hti-fx-note">The table shows today\'s times; enable JavaScript for the live clock and open/closed status.</p></noscript>';
		$out .= '</div>';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Shared blocks
	 * ------------------------------------------------------------------- */

	/**
	 * The affiliate CTA. Renders NOTHING unless Settings::cta_for() allows it
	 * (global kill-switch on, per-tool toggle on, https URL configured) — no
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

		return '<div class="hti-fx-cta">'
			. '<a class="hti-fx-cta__btn" href="' . esc_url( $cta['url'] ) . '" target="_blank" rel="sponsored nofollow noopener"'
			. ' data-hti-track="cta_click" data-htip-location="forex_' . esc_attr( $tool ) . '" data-hti-fx-cta>'
			. esc_html( $cta['label'] )
			. '</a>'
			. '<p class="hti-fx-cta__risk">' . esc_html( 'Partner link. Forex and CFDs are high-risk leveraged products; most retail accounts lose money. Educational content — not investment advice.' ) . '</p>'
			. '</div>';
	}

	/**
	 * Email capture: posts to hti-engine's existing double-opt-in endpoint
	 * (htinvest/v1/subscribe) with source "forex-<tool>" — consent checkbox,
	 * honeypot and rate limiting all come from that stack. No PII is stored
	 * by this plugin.
	 *
	 * @param string $tool Tool name.
	 */
	private static function email_block( string $tool ): string {
		$settings = Settings::settings();
		if ( empty( $settings['email_enabled'] ) ) {
			return '';
		}

		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( '' === $privacy ) {
			$privacy = home_url( '/privacy-policy/' );
		}

		return '<div class="hti-fx-email" data-email data-source="forex-' . esc_attr( $tool ) . '" data-location="forex_' . esc_attr( $tool ) . '">'
			. '<p class="hti-fx-email__title">' . esc_html( 'Get the free INR lot-size cheat sheet (PDF)' ) . '</p>'
			. '<p class="hti-fx-email__sub">' . esc_html( 'Pip values in ₹, the position-size formula and market hours in IST on one printable sheet — sent after you confirm. Plus an occasional email when a new free tool goes live. Unsubscribe anytime.' ) . '</p>'
			. '<form class="hti-fx-email__form" novalidate>'
			. '<input type="email" name="email" autocomplete="email" required placeholder="you@example.com" aria-label="Email address" />'
			. '<input type="text" name="hti_hp" class="hti-fx-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />'
			. '<button type="submit">' . esc_html( 'Subscribe' ) . '</button>'
			. '</form>'
			. '<label class="hti-fx-email__consent"><input type="checkbox" data-consent /> '
			. esc_html( 'I agree to receive these emails, as described in the ' )
			. '<a href="' . esc_url( $privacy ) . '">privacy policy</a>.'
			. '</label>'
			. '<p class="hti-fx-email__status" role="status" aria-live="polite"></p>'
			. '</div>';
	}

	/**
	 * The risk/education block every tool ends with. Lives inside the
	 * shortcode output on purpose: it cannot be edited away in the editor.
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
