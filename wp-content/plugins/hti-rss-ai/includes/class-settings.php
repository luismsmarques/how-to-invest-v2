<?php
/**
 * Admin menu + settings for HTI RSS AI Feed.
 *
 * Registers the top-level "RSS AI Feed" menu (later milestones add Feeds /
 * Drafts / Groups / Review under it) and a Settings page with the knobs the
 * pipeline needs. The Gemini API key is NOT stored here — it is reused from
 * HTI Engine (constant HTI_GEMINI_API_KEY or its Connectors setting).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen and option access.
 */
class Settings {

	private const OPTION = 'rssai_settings';
	private const GROUP  = 'rssai_settings_group';
	public const MENU_SLUG = 'rssai';

	/**
	 * Hook the admin menu and Settings API.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Defaults merged with stored settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'gemini_model'         => 'gemini-2.5-flash',
			'fetch_interval'       => 'hourly',
			'similarity_threshold' => 0.5,
			'max_per_fetch'        => 50,
			'max_generations_day'  => 10,
			'default_lang'         => 'en',
			'image_generate'       => 1,
			'image_model'          => 'imagen-3.0-generate-002',
		);
	}

	/**
	 * Get one setting (falling back to its default).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional explicit fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all      = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$defaults = self::defaults();
		return $all[ $key ] ?? $default ?? ( $defaults[ $key ] ?? null );
	}

	/**
	 * Register the top-level menu and the Settings page.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'RSS AI Feed', 'hti-rss-ai' ),
			__( 'RSS AI Feed', 'hti-rss-ai' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-rss',
			81
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'hti-rss-ai' ),
			__( 'Settings', 'hti-rss-ai' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the setting + sanitization.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$intervals = array( 'hourly', 'twicedaily', 'daily' );
		$langs     = array( 'en', 'pt' );

		$threshold = isset( $input['similarity_threshold'] ) ? (float) $input['similarity_threshold'] : 0.5;
		$threshold = max( 0.0, min( 1.0, $threshold ) );

		return array(
			'gemini_model'         => isset( $input['gemini_model'] ) ? sanitize_text_field( $input['gemini_model'] ) : 'gemini-2.5-flash',
			'fetch_interval'       => in_array( $input['fetch_interval'] ?? '', $intervals, true ) ? $input['fetch_interval'] : 'hourly',
			'similarity_threshold' => $threshold,
			'max_per_fetch'        => max( 1, absint( $input['max_per_fetch'] ?? 50 ) ),
			'max_generations_day'  => max( 1, absint( $input['max_generations_day'] ?? 10 ) ),
			'default_lang'         => in_array( $input['default_lang'] ?? '', $langs, true ) ? $input['default_lang'] : 'en',
			'image_generate'       => empty( $input['image_generate'] ) ? 0 : 1,
			'image_model'          => isset( $input['image_model'] ) ? sanitize_text_field( $input['image_model'] ) : 'imagen-3.0-generate-002',
		);
	}

	/**
	 * Render the Settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$has_key  = Gemini_Client::available();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'RSS AI Feed — Settings', 'hti-rss-ai' ); ?></h1>

			<p>
				<?php echo esc_html__( 'Gemini API key:', 'hti-rss-ai' ); ?>
				<strong><?php echo $has_key ? esc_html__( 'detected (from HTI Engine)', 'hti-rss-ai' ) : esc_html__( 'not configured', 'hti-rss-ai' ); ?></strong>
			</p>
			<?php if ( ! $has_key ) : ?>
				<p class="description">
					<?php echo esc_html__( 'Grounded generation needs a raw Gemini key. Define HTI_GEMINI_API_KEY in wp-config.php (recommended) or paste a key in HTI Engine → Settings. A key held only in WordPress “Connectors” (the AI Client) cannot be reused here.', 'hti-rss-ai' ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rssai_model"><?php echo esc_html__( 'Gemini model', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[gemini_model]" id="rssai_model" type="text" class="regular-text" value="<?php echo esc_attr( $s['gemini_model'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_interval"><?php echo esc_html__( 'Fetch interval', 'hti-rss-ai' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[fetch_interval]" id="rssai_interval">
								<?php
								foreach ( array(
									'hourly'     => __( 'Hourly', 'hti-rss-ai' ),
									'twicedaily' => __( 'Twice daily', 'hti-rss-ai' ),
									'daily'      => __( 'Daily', 'hti-rss-ai' ),
								) as $value => $label ) {
									printf(
										'<option value="%1$s"%2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $s['fetch_interval'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<p class="description"><?php echo esc_html__( 'Used when the fetch cron is enabled (M2).', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_threshold"><?php echo esc_html__( 'Similarity threshold', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[similarity_threshold]" id="rssai_threshold" type="number" step="0.05" min="0" max="1" value="<?php echo esc_attr( (string) $s['similarity_threshold'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'How alike two items must be to land in the same group (0–1).', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_maxfetch"><?php echo esc_html__( 'Max items per fetch', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[max_per_fetch]" id="rssai_maxfetch" type="number" min="1" value="<?php echo esc_attr( (string) $s['max_per_fetch'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_maxgen"><?php echo esc_html__( 'Max generations per day', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[max_generations_day]" id="rssai_maxgen" type="number" min="1" value="<?php echo esc_attr( (string) $s['max_generations_day'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_lang"><?php echo esc_html__( 'Default language', 'hti-rss-ai' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[default_lang]" id="rssai_lang">
								<option value="en"<?php selected( $s['default_lang'], 'en' ); ?>>English</option>
								<option value="pt"<?php selected( $s['default_lang'], 'pt' ); ?>>Português</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Featured image', 'hti-rss-ai' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( self::OPTION ); ?>[image_generate]" type="checkbox" value="1" <?php checked( ! empty( $s['image_generate'] ) ); ?> />
								<?php echo esc_html__( 'Generate a branded featured image (square 1080×1080) on each article', 'hti-rss-ai' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'The card layout is rendered locally for brand consistency; only the photo inside it is produced by AI. If image generation is unavailable, it falls back to the feed image, then a branded gradient.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_image_model"><?php echo esc_html__( 'Image model', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[image_model]" id="rssai_image_model" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['image_model'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Google image model (Imagen) used for the photo. Requires a billing-enabled key with image generation access.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
