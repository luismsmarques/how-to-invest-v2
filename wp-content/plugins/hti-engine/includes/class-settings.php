<?php
/**
 * Admin settings (req. 6.7) — edit the Gemini key/model and the curated
 * scoring + archetype allocation ranges without a deploy.
 *
 * The normalization/validation of scoring and archetypes is pure (no WordPress)
 * so it is unit-testable and guarantees the engine can always produce a valid
 * 100% allocation: invalid input is rejected and the previous/default config is
 * kept. The Gemini key prefers wp-config/env and is never echoed back.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page + pure config normalizers.
 */
class Settings {

	private const GROUP = 'hti_engine_settings';
	private const PAGE  = 'hti-settings';

	private const NON_CRYPTO = array( 'global_equity', 'bonds', 'reits_alt', 'cash' );
	private const CLASSES    = array( 'global_equity', 'bonds', 'reits_alt', 'cash', 'crypto' );

	/**
	 * Hook the admin page and setting registration.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Add the options page under Settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'HowToInvest Engine', 'hti-engine' ),
			__( 'HowToInvest', 'hti-engine' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the three options with their sanitize callbacks.
	 */
	public static function register(): void {
		register_setting( self::GROUP, 'htinvest_settings', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
		register_setting( self::GROUP, Config::OPTION_SCORING, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_scoring' ) ) );
		register_setting( self::GROUP, Config::OPTION_ARCHETYPES, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_archetypes' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure normalizers (unit-tested) — return [ 'value' => ..., 'errors' => [] ]
	 * ------------------------------------------------------------------- */

	/**
	 * Normalize/validate scoring input against the default skeleton.
	 *
	 * @param array<string,mixed> $input    Raw submitted scoring.
	 * @param array<string,mixed> $defaults Default scoring.
	 * @return array{value:array<string,mixed>,errors:list<string>}
	 */
	public static function normalize_scoring( array $input, array $defaults ): array {
		$errors  = array();
		$weights = array();

		foreach ( $defaults['weights'] as $question => $options ) {
			foreach ( $options as $opt => $default_value ) {
				$v = isset( $input['weights'][ $question ][ $opt ] ) ? (int) $input['weights'][ $question ][ $opt ] : (int) $default_value;
				$weights[ $question ][ $opt ] = max( 0, min( 20, $v ) );
			}
		}

		// Maximum reachable score given the (possibly edited) weights.
		$max_score = 0;
		foreach ( $weights as $options ) {
			$max_score += max( $options );
		}

		$thresholds = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$lo = isset( $input['thresholds'][ $i ][0] ) ? (int) $input['thresholds'][ $i ][0] : (int) $defaults['thresholds'][ $i ][0];
			$hi = isset( $input['thresholds'][ $i ][1] ) ? (int) $input['thresholds'][ $i ][1] : (int) $defaults['thresholds'][ $i ][1];
			$thresholds[ $i ] = array( $lo, $hi );
		}

		$valid = ( 0 === $thresholds[1][0] ) && ( $max_score === $thresholds[5][1] );
		for ( $i = 1; $i <= 5 && $valid; $i++ ) {
			if ( $thresholds[ $i ][0] > $thresholds[ $i ][1] ) {
				$valid = false;
			}
		}
		for ( $i = 1; $i < 5 && $valid; $i++ ) {
			if ( $thresholds[ $i ][1] + 1 !== $thresholds[ $i + 1 ][0] ) {
				$valid = false;
			}
		}
		if ( ! $valid ) {
			$errors[]   = sprintf( 'Thresholds must be contiguous and cover 0 to %d; reverted to defaults.', $max_score );
			$thresholds = $defaults['thresholds'];
		}

		return array(
			'value'  => array(
				'weights'    => $weights,
				'thresholds' => $thresholds,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Normalize/validate archetype input so the engine can always allocate 100%.
	 *
	 * @param array<int,mixed> $input    Raw submitted archetypes.
	 * @param array<int,mixed> $defaults Default archetypes.
	 * @return array{value:array<int,mixed>,errors:list<string>}
	 */
	public static function normalize_archetypes( array $input, array $defaults ): array {
		$errors = array();
		$out    = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$def    = $defaults[ $i ];
			$bad    = false;
			$ranges = array();

			foreach ( self::CLASSES as $class ) {
				$lo = isset( $input[ $i ]['ranges'][ $class ][0] ) ? (int) $input[ $i ]['ranges'][ $class ][0] : (int) $def['ranges'][ $class ][0];
				$hi = isset( $input[ $i ]['ranges'][ $class ][1] ) ? (int) $input[ $i ]['ranges'][ $class ][1] : (int) $def['ranges'][ $class ][1];
				$lo = max( 0, min( 100, $lo ) );
				$hi = max( 0, min( 100, $hi ) );
				// Crypto must allow exclusion (its slice can be 0).
				if ( 'crypto' === $class ) {
					$lo = 0;
				}
				if ( $lo > $hi ) {
					$bad = true;
				}
				$ranges[ $class ] = array( $lo, $hi );
			}

			// The four core classes must be able to fill both the granted and
			// ungranted budgets within their ranges.
			$sum_min = $ranges['global_equity'][0] + $ranges['bonds'][0] + $ranges['reits_alt'][0] + $ranges['cash'][0];
			$sum_max = $ranges['global_equity'][1] + $ranges['bonds'][1] + $ranges['reits_alt'][1] + $ranges['cash'][1];
			$grant   = $ranges['crypto'][1] > 0 ? Engine::CRYPTO_GRANT : 0;

			if ( $bad || $sum_min > ( 100 - $grant ) || $sum_max < 100 ) {
				$errors[] = sprintf( 'Archetype %d ranges cannot form a valid 100%% allocation; reverted to previous values.', $i );
				$ranges   = $def['ranges'];
			}

			$label_en = isset( $input[ $i ]['label']['en'] ) ? trim( wp_strip_all_tags( (string) $input[ $i ]['label']['en'] ) ) : '';
			$label_pt = isset( $input[ $i ]['label']['pt'] ) ? trim( wp_strip_all_tags( (string) $input[ $i ]['label']['pt'] ) ) : '';

			$out[ $i ] = array(
				'label'  => array(
					'en' => '' !== $label_en ? $label_en : $def['label']['en'],
					'pt' => '' !== $label_pt ? $label_pt : $def['label']['pt'],
				),
				'ranges' => $ranges,
			);
		}

		return array(
			'value'  => $out,
			'errors' => $errors,
		);
	}

	/* ---------------------------------------------------------------------
	 * WordPress sanitize callbacks (wrap the pure normalizers)
	 * ------------------------------------------------------------------- */

	/**
	 * Sanitize general settings. The Gemini key prefers wp-config/env and is
	 * only stored from the form when those are absent and a value is entered.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( 'htinvest_settings' );
		$out     = is_array( $current ) ? $current : array();

		$out['gemini_model'] = isset( $input['gemini_model'] ) && '' !== trim( (string) $input['gemini_model'] )
			? sanitize_text_field( $input['gemini_model'] )
			: ( $out['gemini_model'] ?? 'gemini-2.5-flash' );

		if ( ! self::key_is_managed() ) {
			$key = isset( $input['gemini_api_key'] ) ? trim( (string) $input['gemini_api_key'] ) : '';
			if ( '' !== $key ) {
				$out['gemini_api_key'] = $key; // Only overwrite when a new key is entered.
			}
		}

		// Brevo (transactional email).
		$out['brevo_sender_email'] = isset( $input['brevo_sender_email'] ) ? sanitize_email( $input['brevo_sender_email'] ) : ( $out['brevo_sender_email'] ?? '' );
		$out['brevo_sender_name']  = isset( $input['brevo_sender_name'] ) ? sanitize_text_field( $input['brevo_sender_name'] ) : ( $out['brevo_sender_name'] ?? '' );
		$out['brevo_list_id']      = isset( $input['brevo_list_id'] ) ? absint( $input['brevo_list_id'] ) : ( $out['brevo_list_id'] ?? 0 );
		$out['brevo_list_id_en']   = isset( $input['brevo_list_id_en'] ) ? absint( $input['brevo_list_id_en'] ) : ( $out['brevo_list_id_en'] ?? 0 );
		$out['brevo_list_id_pt']   = isset( $input['brevo_list_id_pt'] ) ? absint( $input['brevo_list_id_pt'] ) : ( $out['brevo_list_id_pt'] ?? 0 );

		if ( ! self::brevo_key_managed() ) {
			$brevo = isset( $input['brevo_api_key'] ) ? trim( (string) $input['brevo_api_key'] ) : '';
			if ( '' !== $brevo ) {
				$out['brevo_api_key'] = $brevo;
			}
		}

		// Analytics.
		$out['ga_id'] = isset( $input['ga_id'] ) ? sanitize_text_field( $input['ga_id'] ) : ( $out['ga_id'] ?? '' );

		// Google OAuth.
		$out['google_client_id'] = isset( $input['google_client_id'] ) ? sanitize_text_field( $input['google_client_id'] ) : ( $out['google_client_id'] ?? '' );
		if ( ! self::google_secret_managed() ) {
			$gsecret = isset( $input['google_client_secret'] ) ? trim( (string) $input['google_client_secret'] ) : '';
			if ( '' !== $gsecret ) {
				$out['google_client_secret'] = $gsecret;
			}
		}

		return $out;
	}

	/**
	 * Whether the Google secret is provided by wp-config constant or env var.
	 */
	private static function google_secret_managed(): bool {
		return ( defined( 'HTI_GOOGLE_CLIENT_SECRET' ) && '' !== (string) HTI_GOOGLE_CLIENT_SECRET )
			|| ( is_string( getenv( 'HTI_GOOGLE_CLIENT_SECRET' ) ) && '' !== trim( (string) getenv( 'HTI_GOOGLE_CLIENT_SECRET' ) ) );
	}

	/**
	 * Whether the Brevo key is provided by wp-config constant or env var.
	 */
	private static function brevo_key_managed(): bool {
		return ( defined( 'HTI_BREVO_API_KEY' ) && '' !== (string) HTI_BREVO_API_KEY )
			|| ( is_string( getenv( 'BREVO_API_KEY' ) ) && '' !== trim( (string) getenv( 'BREVO_API_KEY' ) ) );
	}

	/**
	 * Sanitize scoring (delegates to the pure normalizer).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize_scoring( $input ): array {
		$result = self::normalize_scoring( is_array( $input ) ? $input : array(), Config::default_scoring() );
		foreach ( $result['errors'] as $error ) {
			add_settings_error( Config::OPTION_SCORING, 'hti_scoring', $error );
		}
		return $result['value'];
	}

	/**
	 * Sanitize archetypes (delegates to the pure normalizer).
	 *
	 * @param mixed $input Raw input.
	 * @return array<int,mixed>
	 */
	public static function sanitize_archetypes( $input ): array {
		$result = self::normalize_archetypes( is_array( $input ) ? $input : array(), Config::default_archetypes() );
		foreach ( $result['errors'] as $error ) {
			add_settings_error( Config::OPTION_ARCHETYPES, 'hti_archetypes', $error );
		}
		return $result['value'];
	}

	/**
	 * Whether the Gemini key is provided by wp-config constant or env var.
	 */
	private static function key_is_managed(): bool {
		return ( defined( 'HTI_GEMINI_API_KEY' ) && '' !== (string) HTI_GEMINI_API_KEY )
			|| ( is_string( getenv( 'GEMINI_API_KEY' ) ) && '' !== trim( (string) getenv( 'GEMINI_API_KEY' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		$settings   = get_option( 'htinvest_settings' );
		$settings   = is_array( $settings ) ? $settings : array();
		$scoring    = Config::scoring();
		$archetypes = Config::archetypes();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'HowToInvest Engine', 'hti-engine' ); ?></h1>
			<p><?php echo esc_html__( 'Edit the LLM connection and the deterministic scoring & allocation. Invalid scoring/allocation is rejected so the engine always produces a valid 100% allocation.', 'hti-engine' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php echo esc_html__( 'LLM (Gemini)', 'hti-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-model"><?php echo esc_html__( 'Model', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[gemini_model]" id="hti-model" type="text" class="regular-text"
							value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-2.5-flash' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-key"><?php echo esc_html__( 'API key', 'hti-engine' ); ?></label></th>
						<td>
						<?php if ( self::key_is_managed() ) : ?>
							<input id="hti-key" type="text" class="regular-text" value="" disabled
								placeholder="<?php echo esc_attr__( 'Defined in wp-config.php / environment', 'hti-engine' ); ?>" />
							<p class="description"><?php echo esc_html__( 'The key is provided via wp-config.php or an environment variable and cannot be edited here (recommended).', 'hti-engine' ); ?></p>
						<?php else : ?>
							<input name="htinvest_settings[gemini_api_key]" id="hti-key" type="password" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( ! empty( $settings['gemini_api_key'] ) ? esc_attr__( '•••••• (leave blank to keep)', 'hti-engine' ) : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Stored server-side, never sent to the browser. Prefer defining HTI_GEMINI_API_KEY in wp-config.php.', 'hti-engine' ); ?></p>
						<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Email (Brevo)', 'hti-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-brevo-key"><?php echo esc_html__( 'Brevo API key', 'hti-engine' ); ?></label></th>
						<td>
						<?php if ( self::brevo_key_managed() ) : ?>
							<input id="hti-brevo-key" type="text" class="regular-text" value="" disabled
								placeholder="<?php echo esc_attr__( 'Defined in wp-config.php / environment', 'hti-engine' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Provided via wp-config.php or an environment variable (recommended).', 'hti-engine' ); ?></p>
						<?php else : ?>
							<input name="htinvest_settings[brevo_api_key]" id="hti-brevo-key" type="password" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( ! empty( $settings['brevo_api_key'] ) ? esc_attr__( '•••••• (leave blank to keep)', 'hti-engine' ) : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used for verification emails. Stored server-side, never sent to the browser. If empty, the site falls back to wp_mail().', 'hti-engine' ); ?></p>
						<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-brevo-from"><?php echo esc_html__( 'Sender email', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[brevo_sender_email]" id="hti-brevo-from" type="email" class="regular-text"
							value="<?php echo esc_attr( $settings['brevo_sender_email'] ?? '' ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-brevo-name"><?php echo esc_html__( 'Sender name', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[brevo_sender_name]" id="hti-brevo-name" type="text" class="regular-text"
							value="<?php echo esc_attr( $settings['brevo_sender_name'] ?? '' ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'blogname' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-brevo-list-en"><?php echo esc_html__( 'Newsletter list ID (EN)', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[brevo_list_id_en]" id="hti-brevo-list-en" type="number" min="0" step="1" class="small-text"
							value="<?php echo esc_attr( (string) ( $settings['brevo_list_id_en'] ?? '' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Brevo list for English subscribers (Brevo → Contacts → Lists).', 'hti-engine' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-brevo-list-pt"><?php echo esc_html__( 'Newsletter list ID (PT)', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[brevo_list_id_pt]" id="hti-brevo-list-pt" type="number" min="0" step="1" class="small-text"
							value="<?php echo esc_attr( (string) ( $settings['brevo_list_id_pt'] ?? '' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Brevo list for Portuguese subscribers. Leave both blank to use a single list (legacy).', 'hti-engine' ); ?></p></td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Analytics (Google Analytics)', 'hti-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hti-ga"><?php echo esc_html__( 'GA4 Measurement ID', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[ga_id]" id="hti-ga" type="text" class="regular-text"
							value="<?php echo esc_attr( $settings['ga_id'] ?? '' ); ?>"
							placeholder="<?php echo esc_attr( Analytics::measurement_id() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Loaded only after the visitor accepts analytics in the consent banner. Leave blank to disable.', 'hti-engine' ); ?></p></td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Sign in with Google (OAuth)', 'hti-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Redirect URI', 'hti-engine' ); ?></th>
						<td><code><?php echo esc_html( Google::redirect_uri() ); ?></code>
							<p class="description"><?php echo esc_html__( 'Add this exact URI to your OAuth client in the Google Cloud console (Authorized redirect URIs).', 'hti-engine' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-gcid"><?php echo esc_html__( 'Client ID', 'hti-engine' ); ?></label></th>
						<td><input name="htinvest_settings[google_client_id]" id="hti-gcid" type="text" class="regular-text"
							value="<?php echo esc_attr( $settings['google_client_id'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-gcsecret"><?php echo esc_html__( 'Client secret', 'hti-engine' ); ?></label></th>
						<td>
						<?php if ( self::google_secret_managed() ) : ?>
							<input id="hti-gcsecret" type="text" class="regular-text" value="" disabled
								placeholder="<?php echo esc_attr__( 'Defined in wp-config.php / environment', 'hti-engine' ); ?>" />
						<?php else : ?>
							<input name="htinvest_settings[google_client_secret]" id="hti-gcsecret" type="password" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( ! empty( $settings['google_client_secret'] ) ? esc_attr__( '•••••• (leave blank to keep)', 'hti-engine' ) : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Stored server-side, never sent to the browser. Prefer HTI_GOOGLE_CLIENT_SECRET in wp-config.php.', 'hti-engine' ); ?></p>
						<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Scoring — thresholds (score → archetype)', 'hti-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( sprintf( '%d — %s', $i, $archetypes[ $i ]['label']['en'] ?? '' ) ); ?></th>
							<td>
								<?php echo esc_html__( 'min', 'hti-engine' ); ?>
								<input type="number" min="0" max="100" style="width:5em"
									name="<?php echo esc_attr( Config::OPTION_SCORING ); ?>[thresholds][<?php echo (int) $i; ?>][0]"
									value="<?php echo esc_attr( $scoring['thresholds'][ $i ][0] ); ?>" />
								<?php echo esc_html__( 'max', 'hti-engine' ); ?>
								<input type="number" min="0" max="100" style="width:5em"
									name="<?php echo esc_attr( Config::OPTION_SCORING ); ?>[thresholds][<?php echo (int) $i; ?>][1]"
									value="<?php echo esc_attr( $scoring['thresholds'][ $i ][1] ); ?>" />
							</td>
						</tr>
					<?php endfor; ?>
				</table>

				<h2><?php echo esc_html__( 'Scoring — weights (P1–P5)', 'hti-engine' ); ?></h2>
				<?php foreach ( $scoring['weights'] as $question => $options ) : ?>
					<h4><?php echo esc_html( $question ); ?></h4>
					<p>
					<?php foreach ( $options as $opt => $value ) : ?>
						<label style="display:inline-block;margin:0 1em 0.5em 0">
							<?php echo esc_html( $opt ); ?>
							<input type="number" min="0" max="20" style="width:4em"
								name="<?php echo esc_attr( Config::OPTION_SCORING ); ?>[weights][<?php echo esc_attr( $question ); ?>][<?php echo esc_attr( $opt ); ?>]"
								value="<?php echo esc_attr( $value ); ?>" />
						</label>
					<?php endforeach; ?>
					</p>
				<?php endforeach; ?>

				<h2><?php echo esc_html__( 'Archetypes — labels & allocation ranges (%)', 'hti-engine' ); ?></h2>
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<h4><?php echo esc_html( sprintf( __( 'Archetype %d', 'hti-engine' ), $i ) ); ?></h4>
					<p>
						EN <input type="text"
							name="<?php echo esc_attr( Config::OPTION_ARCHETYPES ); ?>[<?php echo (int) $i; ?>][label][en]"
							value="<?php echo esc_attr( $archetypes[ $i ]['label']['en'] ?? '' ); ?>" />
						PT <input type="text"
							name="<?php echo esc_attr( Config::OPTION_ARCHETYPES ); ?>[<?php echo (int) $i; ?>][label][pt]"
							value="<?php echo esc_attr( $archetypes[ $i ]['label']['pt'] ?? '' ); ?>" />
					</p>
					<p>
					<?php foreach ( self::CLASSES as $class ) : ?>
						<label style="display:inline-block;margin:0 1em 0.5em 0">
							<?php echo esc_html( $class ); ?>
							<input type="number" min="0" max="100" style="width:4em"
								name="<?php echo esc_attr( Config::OPTION_ARCHETYPES ); ?>[<?php echo (int) $i; ?>][ranges][<?php echo esc_attr( $class ); ?>][0]"
								value="<?php echo esc_attr( $archetypes[ $i ]['ranges'][ $class ][0] ); ?>" />
							–
							<input type="number" min="0" max="100" style="width:4em"
								name="<?php echo esc_attr( Config::OPTION_ARCHETYPES ); ?>[<?php echo (int) $i; ?>][ranges][<?php echo esc_attr( $class ); ?>][1]"
								value="<?php echo esc_attr( $archetypes[ $i ]['ranges'][ $class ][1] ); ?>" />
						</label>
					<?php endforeach; ?>
					</p>
				<?php endfor; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
