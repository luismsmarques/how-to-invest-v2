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
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'name' => 'position_size' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$name = in_array( $atts['name'], Settings::TOOLS, true ) ? (string) $atts['name'] : 'position_size';

		if ( 'sessions' === $name ) {
			$body = self::render_sessions();
		} else {
			$body = self::render_calculator( $name );
		}

		// Conversion blocks are siblings of the tool (the calculator is a
		// <form>; nesting the email form inside it would be invalid HTML).
		return $body . self::cta_block( $name ) . self::email_block( $name );
	}

	/* ---------------------------------------------------------------------
	 * Calculators
	 * ------------------------------------------------------------------- */

	/**
	 * Render one calculator form.
	 *
	 * @param string $name position_size|pip_value.
	 */
	private static function render_calculator( string $name ): string {
		$rates = Rates::effective();
		$cfg   = self::config( $rates );
		$tool  = $cfg[ $name ];

		$out = '<form class="hti-fx-tool" data-tool="' . esc_attr( $name ) . '" novalidate>';

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
			. '<p class="hti-fx-email__title">' . esc_html( 'Get new free tools by email' ) . '</p>'
			. '<p class="hti-fx-email__sub">' . esc_html( 'An occasional email when a new free calculator or India-focused guide goes live. Double opt-in, unsubscribe anytime.' ) . '</p>'
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
