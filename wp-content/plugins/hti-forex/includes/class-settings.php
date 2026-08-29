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
	 * Longest label that still reads as a button. Above this it is treated as
	 * an offer sentence and moved to the headline (see cta_for()).
	 */
	public const LABEL_MAX = 48;

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
			'cta_brand'            => '',
			'cta_logo_url'         => '',
			'cta_position_size'    => true,
			'cta_pip_value'        => true,
			'cta_sessions'         => true,
			'cta_profit_loss'      => true,
			'email_enabled'        => true,
			'telegram_url'         => '',
			'conversion_block'     => 'telegram',
			'ads_enabled'          => false,
			'ad_code_desktop'      => '',
			'ad_code_mobile'       => '',
			'ad_code_top'          => '',
			'ad_code_top_mobile'   => '',
			'sub_param'            => 'clickid',
			'sub_sources'          => array( 'clickid', 'utm_campaign' ),
			'propeller_partner'    => '',
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

		foreach ( array( 'cta_enabled', 'cta_position_size', 'cta_pip_value', 'cta_sessions', 'cta_profit_loss', 'email_enabled', 'ads_enabled' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		// Ad codes are third-party banner HTML (iframe/script from the ad
		// network) pasted by an admin: stored as-is apart from a trim and a
		// size cap — never printed anywhere except the forex ad slots, and
		// only editable with manage_options.
		foreach ( array( 'ad_code_desktop', 'ad_code_mobile', 'ad_code_top', 'ad_code_top_mobile' ) as $slot ) {
			$code = trim( (string) ( $input[ $slot ] ?? '' ) );
			if ( strlen( $code ) > 10000 ) {
				$code     = '';
				$errors[] = sprintf( '%s is longer than 10,000 characters — cleared (paste one banner tag, not a page).', $slot );
			}
			$out[ $slot ] = $code;
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
			$out['cta_label'] = mb_substr( $label, 0, 160 );
		}

		// Partner name: the logo's alt text, and the wordmark shown when no
		// logo image is configured.
		$brand             = sanitize_text_field( (string) ( $input['cta_brand'] ?? '' ) );
		$out['cta_brand']  = mb_substr( $brand, 0, 24 );

		// Partner logo: https image URL, taken from the affiliate panel. Same
		// posture as the affiliate URL — anything that is not https is dropped
		// and reported, and the CTA simply falls back to the wordmark.
		$logo = trim( (string) ( $input['cta_logo_url'] ?? '' ) );
		if ( '' === $logo ) {
			$out['cta_logo_url'] = '';
		} elseif ( ! preg_match( '#^https://#i', $logo ) ) {
			$out['cta_logo_url'] = '';
			$errors[]            = 'The partner logo URL must start with https:// — it was cleared.';
		} else {
			$out['cta_logo_url'] = esc_url_raw( $logo );
		}

		// Telegram channel: our own public channel, so unlike the affiliate URL
		// there is nothing to hide — but the host is checked as well as the
		// scheme, because a mistyped setting here sends campaign traffic
		// somewhere we did not intend.
		$out['telegram_url'] = self::normalize_telegram_url( (string) ( $input['telegram_url'] ?? '' ) );
		if ( '' === $out['telegram_url'] && '' !== trim( (string) ( $input['telegram_url'] ?? '' ) ) ) {
			$errors[] = 'The Telegram URL must be an https:// link to t.me or telegram.me — it was cleared.';
		}

		$out['conversion_block'] = self::normalize_conversion_block( (string) ( $input['conversion_block'] ?? '' ) );

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

		// Propeller Ads partner id: the 64-hex hash from their audience tag.
		// Only the id is stored — the pixel markup itself is rendered
		// canonically by the plugin, never free-form HTML.
		$partner = strtolower( trim( (string) ( $input['propeller_partner'] ?? '' ) ) );
		if ( '' === $partner ) {
			$out['propeller_partner'] = '';
		} elseif ( preg_match( '/^[0-9a-f]{64}$/', $partner ) ) {
			$out['propeller_partner'] = $partner;
		} else {
			$out['propeller_partner'] = '';
			$errors[]                 = 'The Propeller partner id must be the 64-character hex hash from their tag — cleared.';
		}

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
	 * A Telegram channel URL, or '' when the setting is unusable.
	 *
	 * Pure. Both the scheme and the host are checked: this URL is printed
	 * straight into the page and is where campaign traffic is sent, so a typo
	 * here quietly points paid clicks at somewhere we never chose.
	 *
	 * @param string $url Raw setting value.
	 */
	public static function normalize_telegram_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https://#i', $url ) ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! in_array( $host, array( 't.me', 'telegram.me' ), true ) ) {
			return '';
		}

		// A bare host with no channel or invite hash is a half-filled setting.
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Which conversion block the /forex/ pages carry.
	 *
	 * Pure. 'telegram' is the default because that is the arm being tested;
	 * anything unrecognised falls back to it rather than rendering nothing.
	 *
	 * @param string $value Raw setting value.
	 * @return string 'telegram' | 'email' | 'both'
	 */
	public static function normalize_conversion_block( string $value ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, array( 'telegram', 'email', 'both' ), true ) ? $value : 'telegram';
	}

	/**
	 * Which conversion blocks actually render, given the settings.
	 *
	 * Pure, and the single place the decision is made — the renderer asks this
	 * rather than re-deriving it, so the tool pages and the hub can never
	 * disagree about which arm of the test a visitor is in.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 * @return array{telegram:bool,email:bool}
	 */
	public static function conversion_blocks( array $settings ): array {
		// Merge over the defaults so a partial array behaves exactly like a
		// full one. Without this a caller passing only the keys it cared about
		// would read email_enabled as absent, i.e. off, and the page could end
		// up with no conversion block at all.
		$settings = array_merge( self::defaults(), $settings );

		$mode = self::normalize_conversion_block( (string) ( $settings['conversion_block'] ?? '' ) );
		$url  = self::normalize_telegram_url( (string) ( $settings['telegram_url'] ?? '' ) );

		$telegram = '' !== $url && in_array( $mode, array( 'telegram', 'both' ), true );

		// Without a usable Telegram URL the telegram-only setting would leave
		// the page with no conversion block at all; fall back to email rather
		// than silently dropping both.
		$email = ! empty( $settings['email_enabled'] )
			&& ( in_array( $mode, array( 'email', 'both' ), true ) || ! $telegram );

		return array(
			'telegram' => $telegram,
			'email'    => $email,
		);
	}

	/**
	 * CTA config for one tool, or null when the CTA must not render.
	 * Null when: global kill-switch off, the tool's toggle off, or no URL.
	 *
	 * Short labels stay on the button. A long one is an offer sentence, not a
	 * button: it becomes the headline and the button falls back to a plain
	 * action, which is what stops a 90-character label from stretching the
	 * button past the edge of its card.
	 *
	 * @param string                   $tool     Tool name (position_size|pip_value|sessions).
	 * @param array<string,mixed>|null $settings Optional settings (defaults to stored).
	 * @return array{url:string,label:string,headline:string,brand:string,logo:string}|null
	 */
	public static function cta_for( string $tool, ?array $settings = null ): ?array {
		$s = $settings ?? self::settings();

		if ( empty( $s['cta_enabled'] ) || empty( $s['cta_url'] ) ) {
			return null;
		}
		if ( ! in_array( $tool, self::TOOLS, true ) || empty( $s[ 'cta_' . $tool ] ) ) {
			return null;
		}

		$label    = (string) $s['cta_label'];
		$headline = '';
		if ( mb_strlen( $label ) > self::LABEL_MAX ) {
			$headline = $label;
			$label    = 'Open a free account';
		}

		return array(
			'url'      => (string) $s['cta_url'],
			'label'    => $label,
			'headline' => $headline,
			'brand'    => (string) ( $s['cta_brand'] ?? '' ),
			'logo'     => (string) ( $s['cta_logo_url'] ?? '' ),
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
							<input type="text" id="hti-fx-cta-label" class="large-text" name="<?php echo esc_attr( self::OPTION ); ?>[cta_label]" value="<?php echo esc_attr( $s['cta_label'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %d: maximum characters that still fit on a button. */
									esc_html__( 'Up to %d characters stays on the button. Anything longer is an offer sentence, so it moves to the headline above the button and the button reads "Open a free account" — that is what keeps a long offer from stretching the button past the card.', 'hti-forex' ),
									(int) self::LABEL_MAX
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-cta-brand"><?php esc_html_e( 'Partner name', 'hti-forex' ); ?></label></th>
						<td>
							<input type="text" id="hti-fx-cta-brand" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[cta_brand]" value="<?php echo esc_attr( (string) $s['cta_brand'] ); ?>" placeholder="XM" />
							<p class="description"><?php esc_html_e( 'Shown as the wordmark when no logo image is set, and used as the logo\'s alt text.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-cta-logo"><?php esc_html_e( 'Partner logo URL (https)', 'hti-forex' ); ?></label></th>
						<td>
							<input type="url" id="hti-fx-cta-logo" class="large-text code" name="<?php echo esc_attr( self::OPTION ); ?>[cta_logo_url]" value="<?php echo esc_attr( (string) $s['cta_logo_url'] ); ?>" placeholder="https://…/logo.svg" />
							<p class="description"><?php esc_html_e( 'The partner logo from their affiliate panel (SVG or PNG, transparent or dark background). Displayed at 26px tall inside the CTA card. Leave empty to show the partner name as text.', 'hti-forex' ); ?></p>
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

				<h2><?php esc_html_e( 'Banner ads (XM)', 'hti-forex' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show banners', 'hti-forex' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[ads_enabled]" value="1" <?php checked( ! empty( $s['ads_enabled'] ) ); ?> />
								<?php esc_html_e( 'Render the banner slots on the forex pages (top slot and below-the-tool slot)', 'hti-forex' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-ad-top"><?php esc_html_e( 'Top banner — desktop (600×90)', 'hti-forex' ); ?></label></th>
						<td>
							<textarea id="hti-fx-ad-top" class="large-text code" rows="4" name="<?php echo esc_attr( self::OPTION ); ?>[ad_code_top]"><?php echo esc_textarea( (string) $s['ad_code_top'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Paste the 600×90 banner tag. It renders right below the hero chips on the /forex/ hub and above the calculator on each tool page. Hidden below 620px, where 600px does not fit.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-ad-top-mobile"><?php esc_html_e( 'Top banner — mobile', 'hti-forex' ); ?></label></th>
						<td>
							<textarea id="hti-fx-ad-top-mobile" class="large-text code" rows="4" name="<?php echo esc_attr( self::OPTION ); ?>[ad_code_top_mobile]"><?php echo esc_textarea( (string) $s['ad_code_top_mobile'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional replacement for the top slot below 620px (320×100 or 320×50). Leave empty to show no top banner on phones.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-ad-desktop"><?php esc_html_e( 'Banner code — desktop', 'hti-forex' ); ?></label></th>
						<td>
							<textarea id="hti-fx-ad-desktop" class="large-text code" rows="4" name="<?php echo esc_attr( self::OPTION ); ?>[ad_code_desktop]"><?php echo esc_textarea( (string) $s['ad_code_desktop'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Paste the banner tag from the ad network (468×60 or 300×250 fit the 680px content column — 728×90 does not).', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-ad-mobile"><?php esc_html_e( 'Banner code — mobile', 'hti-forex' ); ?></label></th>
						<td>
							<textarea id="hti-fx-ad-mobile" class="large-text code" rows="4" name="<?php echo esc_attr( self::OPTION ); ?>[ad_code_mobile]"><?php echo esc_textarea( (string) $s['ad_code_mobile'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Mobile size (300×250, 320×100 or 320×50). Leave one field empty to show the other everywhere.', 'hti-forex' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Conversion, email capture & campaign tracking', 'hti-forex' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-fx-telegram"><?php esc_html_e( 'Telegram channel URL', 'hti-forex' ); ?></label></th>
						<td>
							<input type="url" id="hti-fx-telegram" class="large-text code" name="<?php echo esc_attr( self::OPTION ); ?>[telegram_url]" value="<?php echo esc_attr( (string) $s['telegram_url'] ); ?>" placeholder="https://t.me/…" />
							<p class="description"><?php esc_html_e( 'https:// link to t.me or telegram.me. Prefer a named invite link created in the channel settings — Telegram counts joins per link, which is the only way to tell how many of these clicks became followers.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Conversion block', 'hti-forex' ); ?></th>
						<td>
							<?php
							$modes = array(
								'telegram' => __( 'Telegram only — the channel replaces the email form', 'hti-forex' ),
								'email'    => __( 'Email only — the newsletter form, as before', 'hti-forex' ),
								'both'     => __( 'Both — the channel and the email form', 'hti-forex' ),
							);
							$current = self::normalize_conversion_block( (string) $s['conversion_block'] );
							foreach ( $modes as $value => $label ) :
								?>
								<label style="display:block;margin-bottom:4px">
									<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[conversion_block]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'What the /forex/ hub and tool pages carry in the slot after the calculator. Switching is instant and reversible — the cheat-sheet lead magnet stays wired either way, so going back to email loses nothing. Without a valid Telegram URL this falls back to the email form.', 'hti-forex' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email capture', 'hti-forex' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[email_enabled]" value="1" <?php checked( ! empty( $s['email_enabled'] ) ); ?> />
								<?php esc_html_e( 'Allow the newsletter form (uses the existing double opt-in). Unticking it hides the form whatever the setting above says.', 'hti-forex' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-fx-propeller"><?php esc_html_e( 'Propeller partner id', 'hti-forex' ); ?></label></th>
						<td>
							<input type="text" id="hti-fx-propeller" class="large-text code" name="<?php echo esc_attr( self::OPTION ); ?>[propeller_partner]" value="<?php echo esc_attr( (string) $s['propeller_partner'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The 64-character hash from the Propeller Ads audience tag (partner=…). The pixel loads ONLY on the /forex/ pages, without a consent gate — campaign traffic is targeted outside the EU. Leave empty to disable.', 'hti-forex' ); ?></p>
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
