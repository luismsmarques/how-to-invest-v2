<?php
/**
 * Settings → HTI Forex: the affiliate CTA (URL, label, global kill-switch and
 * per-tool toggles), the email-capture toggle, the subid passthrough config
 * and manual exchange-rate overrides.
 *
 * The normalization is pure (no WordPress) so it is unit-testable; the CTA
 * defaults to OFF, so nothing affiliate-related renders until an admin
 * explicitly configures and enables it — and the kill-switch removes every
 * CTA instantly, without a deploy.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page + pure normalizers.
 */
class Settings {

	private const GROUP = 'hti_forex_settings_group';
	private const PAGE  = 'hti-forex';

	public const OPTION = 'hti_forex_settings';

	/**
	 * Tools that can carry the CTA (shortcode `name` values).
	 */
	public const TOOLS = array( 'position_size', 'pip_value', 'sessions', 'profit_loss' );

	/**
	 * Hook the admin page and setting registration.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Defaults. cta_enabled=false is the safety posture: a fresh install
	 * renders pure calculators with no affiliate link anywhere.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'cta_enabled'          => false,
			'cta_url'              => '',
			'cta_label'            => 'See how these numbers behave on a demo account',
			'cta_position_size'    => true,
			'cta_pip_value'        => true,
			'cta_sessions'         => true,
			'cta_profit_loss'      => true,
			'email_enabled'        => true,
			'sub_param'            => 'clickid',
			'sub_sources'          => array( 'clickid', 'utm_campaign' ),
			'rate_override_usdinr' => 0.0,
			'rate_override_usdjpy' => 0.0,
		);
	}

	/**
	 * Current settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/* ---------------------------------------------------------------------
	 * Pure normalizers (unit-tested)
	 * ------------------------------------------------------------------- */

	/**
	 * Normalize/validate submitted settings.
	 *
	 * @param array<string,mixed> $input    Raw submitted settings.
	 * @param array<string,mixed> $defaults Defaults (self::defaults()).
	 * @return array{value:array<string,mixed>,errors:list<string>}
	 */
	public static function normalize_settings( array $input, array $defaults ): array {
		$errors = array();
		$out    = $defaults;

		foreach ( array( 'cta_enabled', 'cta_position_size', 'cta_pip_value', 'cta_sessions', 'cta_profit_loss', 'email_enabled' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		// Affiliate URL: https only. Anything else is dropped (and reported),
		// which also force-disables the CTA via cta_for()'s empty-URL check.
		$url = trim( (string) ( $input['cta_url'] ?? '' ) );
		if ( '' === $url ) {
			$out['cta_url'] = '';
		} elseif ( ! preg_match( '#^https://#i', $url ) ) {
			$out['cta_url'] = '';
			$errors[]       = 'The affiliate URL must start with https:// — it was cleared.';
		} else {
			$clean = esc_url_raw( $url );
			if ( '' === $clean ) {
				$errors[] = 'The affiliate URL could not be validated — it was cleared.';
			}
			$out['cta_url'] = $clean;
		}

		$label = sanitize_text_field( (string) ( $input['cta_label'] ?? '' ) );
		if ( '' !== $label ) {
			$out['cta_label'] = mb_substr( $label, 0, 120 );
		}

		$param            = sanitize_key( (string) ( $input['sub_param'] ?? '' ) );
		$out['sub_param'] = ( '' !== $param && strlen( $param ) <= 32 ) ? $param : $defaults['sub_param'];

		// Comma-separated list of URL params to read the campaign id from.
		$raw_sources = $input['sub_sources'] ?? '';
		if ( is_array( $raw_sources ) ) {
			$raw_sources = implode( ',', $raw_sources );
		}
		$sources = array();
		foreach ( explode( ',', (string) $raw_sources ) as $candidate ) {
			$candidate = sanitize_key( trim( $candidate ) );
			if ( '' !== $candidate && strlen( $candidate ) <= 32 && ! in_array( $candidate, $sources, true ) ) {
				$sources[] = $candidate;
			}
			if ( count( $sources ) >= 5 ) {
				break;
			}
		}
		$out['sub_sources'] = $sources ? $sources : $defaults['sub_sources'];

		// Manual rate overrides: 0 (or blank) = automatic; out-of-bounds values
		// are rejected so a typo can never silently distort every calculation.
		foreach ( array(
			'rate_override_usdinr' => array( 30.0, 300.0 ),
			'rate_override_usdjpy' => array( 50.0, 400.0 ),
		) as $key => $bounds ) {
			$value = (float) ( $input[ $key ] ?? 0 );
			if ( $value <= 0 ) {
				$out[ $key ] = 0.0;
			} elseif ( $value < $bounds[0] || $value > $bounds[1] ) {
				$out[ $key ] = 0.0;
				$errors[]    = sprintf( '%s override %s is outside the plausible range (%s–%s) — cleared, automatic rate will be used.', $key, $value, $bounds[0], $bounds[1] );
			} else {
				$out[ $key ] = round( $value, 4 );
			}
		}

		return array(
			'value'  => $out,
			'errors' => $errors,
		);
	}

	/**
	 * CTA config for one tool, or null when the CTA must not render.
	 * Null when: global kill-switch off, the tool's toggle off, or no URL.
	 *
	 * @param string                   $tool     Tool name (position_size|pip_value|sessions).
	 * @param array<string,mixed>|null $settings Optional settings (defaults to stored).
	 * @return array{url:string,label:string}|null
	 */
	public static function cta_for( string $tool, ?array $settings = null ): ?array {
		$s = $settings ?? self::settings();

		if ( empty( $s['cta_enabled'] ) || empty( $s['cta_url'] ) ) {
			return null;
		}
		if ( ! in_array( $tool, self::TOOLS, true ) || empty( $s[ 'cta_' . $tool ] ) ) {
			return null;
		}

		return array(
			'url'   => (string) $s['cta_url'],
			'label' => (string) $s['cta_label'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	/**
	 * Add the options page under Settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'HTI Forex', 'hti-forex' ),
			__( 'HTI Forex', 'hti-forex' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option with its sanitize callback.
	 */
	public static function register(): void {
		register_setting( self::GROUP, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Sanitize callback: pure normalization + settings errors.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$result = self::normalize_settings( is_array( $input ) ? $input : array(), self::defaults() );
		foreach ( $result['errors'] as $i => $message ) {
			add_settings_error( self::OPTION, 'hti_forex_' . $i, esc_html( $message ) );
		}
		return $result['value'];
	}

	/**
	 * Render the settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Forex', 'hti-forex' ); ?></h1>
			<p>
				<?php esc_html_e( 'India-focused forex calculators (/forex/). The affiliate CTA is OFF by default; the global switch below is the kill-switch — unticking it removes every CTA immediately, without a deploy.', 'hti-forex' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Affiliate CTA', 'hti-forex' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable CTA (kill-switch)', 'hti-forex' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[cta_enabled]" value="1" <?php checked( ! empty( $s['cta_enabled'] ) ); ?> />
								<?php esc_html_e( 'Render the affiliate button on the tool pages', 'hti-forex' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-cta-url"><?php esc_html_e( 'Affiliate URL (https)', 'hti-forex' ); ?></label></th>
						<td>
							<input type="url" id="hti-fx-cta-url" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[cta_url]" value="<?php echo esc_attr( $s['cta_url'] ); ?>" placeholder="https://…" />
							<p class="description"><?php esc_html_e( 'The full partner link. Rendered with rel="sponsored nofollow noopener".', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-cta-label"><?php esc_html_e( 'CTA label', 'hti-forex' ); ?></label></th>
						<td>
							<input type="text" id="hti-fx-cta-label" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[cta_label]" value="<?php echo esc_attr( $s['cta_label'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Keep it conditional and demo-first — never imperative "trade/invest now" copy.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show CTA on', 'hti-forex' ); ?></th>
						<td>
							<?php
							$tool_labels = array(
								'position_size' => __( 'Position size calculator', 'hti-forex' ),
								'pip_value'     => __( 'Pip value calculator', 'hti-forex' ),
								'sessions'      => __( 'Market hours (IST)', 'hti-forex' ),
								'profit_loss'   => __( 'Profit/loss calculator', 'hti-forex' ),
							);
							foreach ( $tool_labels as $tool => $label ) :
								?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[cta_<?php echo esc_attr( $tool ); ?>]" value="1" <?php checked( ! empty( $s[ 'cta_' . $tool ] ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email capture & campaign tracking', 'hti-forex' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email capture', 'hti-forex' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[email_enabled]" value="1" <?php checked( ! empty( $s['email_enabled'] ) ); ?> />
								<?php esc_html_e( 'Show the newsletter form on the tool pages (uses the existing double opt-in)', 'hti-forex' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-sub-param"><?php esc_html_e( 'Affiliate sub-id parameter', 'hti-forex' ); ?></label></th>
						<td>
							<input type="text" id="hti-fx-sub-param" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[sub_param]" value="<?php echo esc_attr( $s['sub_param'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Query parameter appended to the affiliate URL with the campaign id (e.g. clickid).', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-sub-sources"><?php esc_html_e( 'Read campaign id from', 'hti-forex' ); ?></label></th>
						<td>
							<input type="text" id="hti-fx-sub-sources" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[sub_sources]" value="<?php echo esc_attr( implode( ',', (array) $s['sub_sources'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated landing-page URL parameters, first non-empty wins (e.g. clickid,utm_campaign).', 'hti-forex' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Exchange-rate overrides', 'hti-forex' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-fx-ovr-inr"><?php esc_html_e( 'USD/INR override', 'hti-forex' ); ?></label></th>
						<td>
							<input type="number" step="0.0001" min="0" id="hti-fx-ovr-inr" name="<?php echo esc_attr( self::OPTION ); ?>[rate_override_usdinr]" value="<?php echo esc_attr( $s['rate_override_usdinr'] > 0 ? $s['rate_override_usdinr'] : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty for the automatic ECB reference rate.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-ovr-jpy"><?php esc_html_e( 'USD/JPY override', 'hti-forex' ); ?></label></th>
						<td>
							<input type="number" step="0.0001" min="0" id="hti-fx-ovr-jpy" name="<?php echo esc_attr( self::OPTION ); ?>[rate_override_usdjpy]" value="<?php echo esc_attr( $s['rate_override_usdjpy'] > 0 ? $s['rate_override_usdjpy'] : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty for the automatic ECB reference rate.', 'hti-forex' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php
			/**
			 * Extra panels (rates status + fetch-now, seeder button) hook in
			 * here so each feature owns its own admin surface.
			 */
			do_action( 'hti_forex_settings_panels' );
			?>
		</div>
		<?php
	}
}
