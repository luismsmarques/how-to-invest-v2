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
			'image_model'          => 'imagen-4.0-generate-001',
			'image_base_model'     => 'gemini-2.5-flash-image',
			'youtube_api_key'      => '',
			'supadata_api_key'     => '',
		);
	}

	/**
	 * YouTube Data API key: HTI_YOUTUBE_API_KEY constant, else the stored
	 * setting, then the rssai_youtube_api_key filter.
	 */
	public static function youtube_api_key(): string {
		$key = defined( 'HTI_YOUTUBE_API_KEY' ) ? (string) constant( 'HTI_YOUTUBE_API_KEY' ) : '';
		if ( '' === $key ) {
			$key = (string) self::get( 'youtube_api_key', '' );
		}
		return trim( (string) apply_filters( 'rssai_youtube_api_key', $key ) );
	}

	/**
	 * Supadata API key: HTI_SUPADATA_API_KEY constant, else the stored setting,
	 * then the rssai_supadata_api_key filter.
	 */
	public static function supadata_api_key(): string {
		$key = defined( 'HTI_SUPADATA_API_KEY' ) ? (string) constant( 'HTI_SUPADATA_API_KEY' ) : '';
		if ( '' === $key ) {
			$key = (string) self::get( 'supadata_api_key', '' );
		}
		return trim( (string) apply_filters( 'rssai_supadata_api_key', $key ) );
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

		// Keep existing API keys when the field is submitted blank.
		$existing  = (array) get_option( self::OPTION, array() );
		$yt_key    = isset( $input['youtube_api_key'] ) ? trim( sanitize_text_field( $input['youtube_api_key'] ) ) : '';
		$supa_key  = isset( $input['supadata_api_key'] ) ? trim( sanitize_text_field( $input['supadata_api_key'] ) ) : '';

		return array(
			'youtube_api_key'      => '' !== $yt_key ? $yt_key : (string) ( $existing['youtube_api_key'] ?? '' ),
			'supadata_api_key'     => '' !== $supa_key ? $supa_key : (string) ( $existing['supadata_api_key'] ?? '' ),
			'gemini_model'         => isset( $input['gemini_model'] ) ? sanitize_text_field( $input['gemini_model'] ) : 'gemini-2.5-flash',
			'fetch_interval'       => in_array( $input['fetch_interval'] ?? '', $intervals, true ) ? $input['fetch_interval'] : 'hourly',
			'similarity_threshold' => $threshold,
			'max_per_fetch'        => max( 1, absint( $input['max_per_fetch'] ?? 50 ) ),
			'max_generations_day'  => max( 1, absint( $input['max_generations_day'] ?? 10 ) ),
			'default_lang'         => in_array( $input['default_lang'] ?? '', $langs, true ) ? $input['default_lang'] : 'en',
			'image_generate'       => empty( $input['image_generate'] ) ? 0 : 1,
			'image_model'          => isset( $input['image_model'] ) ? sanitize_text_field( $input['image_model'] ) : 'imagen-4.0-generate-001',
			'image_base_model'     => isset( $input['image_base_model'] ) ? sanitize_text_field( $input['image_base_model'] ) : 'gemini-2.5-flash-image',
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
								<?php echo esc_html__( 'Generate an AI featured image about the article topic', 'hti-rss-ai' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Saved as the post’s featured image. The same image is reused inside the downloadable social cards (Social media kit) — no extra AI calls. If unavailable, it falls back to the feed image.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_image_model"><?php echo esc_html__( 'Image model', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[image_model]" id="rssai_image_model" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['image_model'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Text-to-image model, used when there is no feed image. Imagen models (e.g. imagen-4.0-generate-001) use :predict; Gemini image models (e.g. gemini-2.5-flash-image) use :generateContent — both handled automatically. Run ListModels to see what your key supports.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_image_base_model"><?php echo esc_html__( 'Image-to-image model', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[image_base_model]" id="rssai_image_base_model" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['image_base_model'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'When a draft has a feed image, it is used as the base and reimagined into the branded illustration with this model. Must be a Gemini image model (accepts an input image), e.g. gemini-2.5-flash-image. Leave blank to always use plain text-to-image.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_youtube_key"><?php echo esc_html__( 'YouTube Data API key', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[youtube_api_key]" id="rssai_youtube_key" type="password" autocomplete="off" class="regular-text" placeholder="<?php echo esc_attr( '' !== Settings::youtube_api_key() ? '•••••• (leave blank to keep)' : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used to discover a channel’s recent uploads. Prefer defining HTI_YOUTUBE_API_KEY in wp-config.php. Stored server-side, never sent to the browser.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_supadata_key"><?php echo esc_html__( 'Supadata API key', 'hti-rss-ai' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[supadata_api_key]" id="rssai_supadata_key" type="password" autocomplete="off" class="regular-text" placeholder="<?php echo esc_attr( '' !== Settings::supadata_api_key() ? '•••••• (leave blank to keep)' : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used to fetch the transcript of a YouTube video (supadata.ai). Prefer defining HTI_SUPADATA_API_KEY in wp-config.php. Stored server-side.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
