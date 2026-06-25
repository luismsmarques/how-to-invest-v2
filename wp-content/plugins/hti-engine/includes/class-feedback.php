<?php
/**
 * On-site feedback survey: the `[hti_feedback]` shortcode + storage + admin.
 *
 * Renders an accessible, server-rendered multi-question survey (progressively
 * enhanced by feedback.js) that posts to `/wp-json/htinvest/v1/feedback`.
 * Responses are anonymous (no name/email retained) — only the answers, the
 * language and, when available, the visitor's investor archetype (not personal
 * data). Stored in a custom table; aggregates + a CSV export live in the admin.
 *
 * RGPD: anonymous by design (invariant 5). Nonce-protected, rate-limited and
 * honeypot-guarded like the contact form.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The public feedback survey.
 */
class Feedback {

	private const SHORTCODE = 'hti_feedback';
	private const PAGE      = 'hti-feedback';

	/**
	 * Bump to trigger a dbDelta upgrade of the responses table.
	 */
	private const DB_VERSION        = 1;
	private const DB_VERSION_OPTION = 'hti_feedback_db_version';

	/**
	 * Allowed values for the closed-answer questions (server-side validation).
	 */
	private const EASE_KEYS  = array( 'very_easy', 'easy', 'neutral', 'difficult', 'very_difficult' );
	private const SCALE_KEYS = array( 'yes_a_lot', 'somewhat', 'not_really', 'no' );

	/**
	 * Hook the shortcode, assets, REST route, admin screen and table install.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_feedback_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
	}

	/* ---------- storage ---------- */

	/**
	 * Fully-qualified responses table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hti_feedback';
	}

	/**
	 * Create/upgrade the responses table when the schema version changes.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the responses table via dbDelta. Safe to run repeatedly.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			lang VARCHAR(5) NOT NULL DEFAULT 'en',
			archetype VARCHAR(64) NOT NULL DEFAULT '',
			satisfaction TINYINT UNSIGNED NOT NULL DEFAULT 0,
			ease VARCHAR(20) NOT NULL DEFAULT '',
			helped VARCHAR(20) NOT NULL DEFAULT '',
			portfolio VARCHAR(20) NOT NULL DEFAULT '',
			trust VARCHAR(20) NOT NULL DEFAULT '',
			nps TINYINT NOT NULL DEFAULT -1,
			most_valuable TEXT NULL,
			improve TEXT NULL,
			comments TEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY lang (lang)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert one validated response. Returns the row id or 0 on failure.
	 *
	 * @param array<string,mixed> $row Sanitized fields.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'lang'          => $row['lang'],
				'archetype'     => $row['archetype'],
				'satisfaction'  => $row['satisfaction'],
				'ease'          => $row['ease'],
				'helped'        => $row['helped'],
				'portfolio'     => $row['portfolio'],
				'trust'         => $row['trust'],
				'nps'           => $row['nps'],
				'most_valuable' => $row['most_valuable'],
				'improve'       => $row['improve'],
				'comments'      => $row['comments'],
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ---------- front-end ---------- */

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = (string) pll_current_language( 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) determine_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Whether the current singular view contains the shortcode.
	 */
	private static function is_feedback_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the survey script/styles only on the feedback page.
	 */
	public static function enqueue(): void {
		if ( ! self::is_feedback_page() ) {
			return;
		}
		$pt = 'pt' === self::locale();

		wp_enqueue_style( 'hti-feedback', HTI_ENGINE_URL . 'assets/css/feedback.css', array(), VERSION );

		wp_register_script(
			'hti-feedback',
			HTI_ENGINE_URL . 'assets/js/feedback.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'hti-feedback',
			'HTI_FEEDBACK',
			array(
				'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/feedback' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => $pt ? 'pt' : 'en',
				'strings' => self::js_strings( $pt ),
			)
		);
		wp_enqueue_script( 'hti-feedback' );
	}

	/**
	 * Status/interaction strings for the script.
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,string>
	 */
	private static function js_strings( bool $pt ): array {
		if ( $pt ) {
			return array(
				'sending' => 'A enviar…',
				'sent'    => 'Obrigado pelo teu feedback! 🙏',
				'invalid' => 'Por favor responde às perguntas obrigatórias (marcadas com *).',
				'error'   => 'Não foi possível enviar. Tenta novamente daqui a pouco.',
				'rate'    => 'Demasiadas tentativas. Aguarda um momento e tenta novamente.',
				'star'    => '%d de 5 estrelas',
			);
		}
		return array(
			'sending' => 'Sending…',
			'sent'    => 'Thank you for your feedback! 🙏',
			'invalid' => 'Please answer the required questions (marked with *).',
			'error'   => 'We couldn’t send your feedback. Please try again shortly.',
			'rate'    => 'Too many attempts. Please wait a moment and try again.',
			'star'    => '%d of 5 stars',
		);
	}

	/**
	 * All localized survey copy (intro + questions + options + labels).
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,mixed>
	 */
	private static function copy( bool $pt ): array {
		if ( $pt ) {
			return array(
				'title'    => 'Adorávamos o teu feedback',
				'intro'    => 'A tua opinião ajuda-nos a melhorar o HowToInvest. Demora só 2-3 minutos e é confidencial.',
				'optional' => '(Opcional)',
				'submit'   => 'Enviar feedback',
				'privacy'  => '🔒 O teu feedback é anónimo e usado apenas para melhorar o serviço.',
				'q1'       => 'Em geral, qual o teu grau de satisfação com o HowToInvest?',
				'q2'       => 'Quão fácil foi usar o site?',
				'q3'       => 'O questionário ajudou-te a perceber o teu perfil de investidor?',
				'q4'       => 'O exemplo de carteira por classes de ativos foi claro e útil?',
				'q5'       => 'O tom educativo passou-te confiança (sem parecer venda)?',
				'q6'       => 'O que achaste mais valioso no HowToInvest?',
				'q7'       => 'O que podemos melhorar ou que funcionalidades gostarias de ver?',
				'q8'       => 'Qual a probabilidade de recomendares o HowToInvest a um amigo? (0-10)',
				'q9'       => 'Algum comentário adicional?',
				'ph'       => 'Escreve a tua resposta aqui…',
				'ease'     => array(
					'very_easy'      => 'Muito fácil',
					'easy'           => 'Fácil',
					'neutral'        => 'Neutro',
					'difficult'      => 'Difícil',
					'very_difficult' => 'Muito difícil',
				),
				'helped'   => array(
					'yes_a_lot'  => 'Sim, ajudou muito',
					'somewhat'   => 'Ajudou um pouco',
					'not_really' => 'Nem por isso',
					'no'         => 'Não ajudou',
				),
				'portfolio' => array(
					'yes_a_lot'  => 'Sim, muito claro',
					'somewhat'   => 'Razoavelmente claro',
					'not_really' => 'Pouco claro',
					'no'         => 'Confuso',
				),
				'trust'    => array(
					'yes_a_lot'  => 'Sim, totalmente',
					'somewhat'   => 'Na maioria',
					'not_really' => 'Em parte',
					'no'         => 'Não',
				),
			);
		}
		return array(
			'title'    => 'We’d love your feedback',
			'intro'    => 'Your input helps us improve HowToInvest. It only takes 2-3 minutes and is confidential.',
			'optional' => '(Optional)',
			'submit'   => 'Submit feedback',
			'privacy'  => '🔒 Your feedback is anonymous and used only to improve the service.',
			'q1'       => 'Overall, how satisfied are you with HowToInvest?',
			'q2'       => 'How easy was it to use the site?',
			'q3'       => 'Did the questionnaire help you understand your investor profile?',
			'q4'       => 'Was the example portfolio by asset class clear and useful?',
			'q5'       => 'Did the educational tone feel trustworthy (not salesy)?',
			'q6'       => 'What did you find most valuable about HowToInvest?',
			'q7'       => 'What could we improve, or what features would you like to see?',
			'q8'       => 'How likely are you to recommend HowToInvest to a friend? (0-10)',
			'q9'       => 'Any additional comments?',
			'ph'       => 'Type your answer here…',
			'ease'     => array(
				'very_easy'      => 'Very easy',
				'easy'           => 'Easy',
				'neutral'        => 'Neutral',
				'difficult'      => 'Difficult',
				'very_difficult' => 'Very difficult',
			),
			'helped'   => array(
				'yes_a_lot'  => 'Yes, a lot',
				'somewhat'   => 'Somewhat',
				'not_really' => 'Not really',
				'no'         => 'No',
			),
			'portfolio' => array(
				'yes_a_lot'  => 'Yes, very clear',
				'somewhat'   => 'Reasonably clear',
				'not_really' => 'Not very clear',
				'no'         => 'Confusing',
			),
			'trust'    => array(
				'yes_a_lot'  => 'Yes, completely',
				'somewhat'   => 'Mostly',
				'not_really' => 'Partly',
				'no'         => 'No',
			),
		);
	}

	/**
	 * Render a radio-group question as accessible fieldset rows.
	 *
	 * @param string                $name    Field name.
	 * @param array<string,string>  $options key => label.
	 * @return string
	 */
	private static function radio_rows( string $name, array $options ): string {
		$out = '';
		foreach ( $options as $key => $label ) {
			$id   = 'hti-fb-' . $name . '-' . $key;
			$out .= '<label class="hti-fb-opt" for="' . esc_attr( $id ) . '">'
				. '<input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $key ) . '">'
				. '<span class="hti-fb-opt__txt">' . esc_html( $label ) . '</span>'
				. '</label>';
		}
		return $out;
	}

	/**
	 * `[hti_feedback]` output: an accessible, progressively-enhanced survey.
	 */
	public static function render(): string {
		$pt = 'pt' === self::locale();
		$c  = self::copy( $pt );
		$nq = 1;

		ob_start();
		?>
		<form id="hti-feedback-form" class="hti-fb" novalidate>
			<header class="hti-fb__head">
				<h2 class="hti-fb__title"><?php echo esc_html( $c['title'] ); ?> 💬</h2>
				<p class="hti-fb__intro"><?php echo esc_html( $c['intro'] ); ?></p>
			</header>

			<?php // Q1 — satisfaction stars (1-5). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q1'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></legend>
				<div class="hti-fb-stars" data-stars="satisfaction" role="radiogroup" aria-label="<?php echo esc_attr( $c['q1'] ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<label class="hti-fb-star" for="hti-fb-sat-<?php echo (int) $i; ?>">
							<input type="radio" id="hti-fb-sat-<?php echo (int) $i; ?>" name="satisfaction" value="<?php echo (int) $i; ?>">
							<span class="hti-fb-star__glyph" aria-hidden="true">★</span>
							<span class="screen-reader-text"><?php echo (int) $i; ?></span>
						</label>
					<?php endfor; ?>
				</div>
			</fieldset>

			<?php // Q2 — ease (radio). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q2'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></legend>
				<div class="hti-fb__opts"><?php echo self::radio_rows( 'ease', $c['ease'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</fieldset>

			<?php // Q3 — questionnaire helped (radio). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q3'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></legend>
				<div class="hti-fb__opts"><?php echo self::radio_rows( 'helped', $c['helped'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</fieldset>

			<?php // Q4 — example portfolio clear (radio). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q4'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></legend>
				<div class="hti-fb__opts"><?php echo self::radio_rows( 'portfolio', $c['portfolio'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</fieldset>

			<?php // Q5 — trust (radio, optional). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q5'] ); ?> <span class="hti-fb__opt-tag"><?php echo esc_html( $c['optional'] ); ?></span></legend>
				<div class="hti-fb__opts"><?php echo self::radio_rows( 'trust', $c['trust'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</fieldset>

			<?php // Q6 — most valuable (textarea, required). ?>
			<div class="hti-fb__q">
				<label class="hti-fb__legend" for="hti-fb-most"><?php echo (int) $nq++ . '. ' . esc_html( $c['q6'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></label>
				<textarea class="hti-fb__textarea" id="hti-fb-most" name="most_valuable" rows="3" maxlength="2000" placeholder="<?php echo esc_attr( $c['ph'] ); ?>"></textarea>
			</div>

			<?php // Q7 — improve (textarea, optional). ?>
			<div class="hti-fb__q">
				<label class="hti-fb__legend" for="hti-fb-improve"><?php echo (int) $nq++ . '. ' . esc_html( $c['q7'] ); ?> <span class="hti-fb__opt-tag"><?php echo esc_html( $c['optional'] ); ?></span></label>
				<textarea class="hti-fb__textarea" id="hti-fb-improve" name="improve" rows="3" maxlength="2000" placeholder="<?php echo esc_attr( $c['ph'] ); ?>"></textarea>
			</div>

			<?php // Q8 — NPS (0-10, required). ?>
			<fieldset class="hti-fb__q">
				<legend class="hti-fb__legend"><?php echo (int) $nq++ . '. ' . esc_html( $c['q8'] ); ?> <span class="hti-fb__req" aria-hidden="true">*</span></legend>
				<div class="hti-fb-nps" role="radiogroup" aria-label="<?php echo esc_attr( $c['q8'] ); ?>">
					<?php for ( $i = 0; $i <= 10; $i++ ) : ?>
						<label class="hti-fb-nps__btn" for="hti-fb-nps-<?php echo (int) $i; ?>">
							<input type="radio" id="hti-fb-nps-<?php echo (int) $i; ?>" name="nps" value="<?php echo (int) $i; ?>">
							<span class="hti-fb-nps__num"><?php echo (int) $i; ?></span>
						</label>
					<?php endfor; ?>
				</div>
			</fieldset>

			<?php // Q9 — comments (textarea, optional). ?>
			<div class="hti-fb__q">
				<label class="hti-fb__legend" for="hti-fb-comments"><?php echo (int) $nq++ . '. ' . esc_html( $c['q9'] ); ?> <span class="hti-fb__opt-tag"><?php echo esc_html( $c['optional'] ); ?></span></label>
				<textarea class="hti-fb__textarea" id="hti-fb-comments" name="comments" rows="3" maxlength="2000" placeholder="<?php echo esc_attr( $c['ph'] ); ?>"></textarea>
			</div>

			<?php // Honeypot. ?>
			<div class="hti-fb__trap" aria-hidden="true">
				<label for="hti-fb-hp"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
				<input type="text" id="hti-fb-hp" name="hti_hp" tabindex="-1" autocomplete="off">
			</div>
			<input type="hidden" name="archetype" value="">

			<div class="hti-fb__actions">
				<button class="hti-fb__submit" type="submit"><?php echo esc_html( $c['submit'] ); ?></button>
			</div>
			<p class="hti-fb__status" role="status" aria-live="polite"></p>
			<p class="hti-fb__privacy"><?php echo esc_html( $c['privacy'] ); ?></p>
		</form>
		<noscript>
			<p class="hti-fb__noscript"><?php esc_html_e( 'Please enable JavaScript to submit feedback.', 'hti-engine' ); ?></p>
		</noscript>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- REST ---------- */

	/**
	 * Register the public submission route (anonymous, nonce-checked).
	 */
	public static function register_route(): void {
		register_rest_route(
			'htinvest/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( REST::class, 'check_nonce' ),
			)
		);
	}

	/**
	 * POST /feedback — validate and store one anonymous response.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( \WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'feedback' ) ) {
			return new \WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'hti-engine' ), array( 'status' => 429 ) );
		}

		// Honeypot: a filled hidden field means a bot — drop it, report success.
		if ( '' !== trim( (string) $request->get_param( 'hti_hp' ) ) ) {
			return new \WP_REST_Response( array( 'saved' => true ), 200 );
		}

		$satisfaction = (int) $request->get_param( 'satisfaction' );
		$nps          = $request->get_param( 'nps' );
		$nps          = ( null === $nps || '' === $nps ) ? -1 : (int) $nps;
		$ease         = sanitize_key( (string) $request->get_param( 'ease' ) );
		$helped       = sanitize_key( (string) $request->get_param( 'helped' ) );
		$portfolio    = sanitize_key( (string) $request->get_param( 'portfolio' ) );
		$trust        = sanitize_key( (string) $request->get_param( 'trust' ) );
		$most         = sanitize_textarea_field( (string) $request->get_param( 'most_valuable' ) );

		// Required questions must be present and valid.
		$valid = $satisfaction >= 1 && $satisfaction <= 5
			&& in_array( $ease, self::EASE_KEYS, true )
			&& in_array( $helped, self::SCALE_KEYS, true )
			&& in_array( $portfolio, self::SCALE_KEYS, true )
			&& $nps >= 0 && $nps <= 10
			&& '' !== trim( $most );

		if ( ! $valid ) {
			return new \WP_Error( 'hti_invalid_feedback', __( 'Please answer the required questions.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		$row = array(
			'lang'          => str_starts_with( strtolower( (string) $request->get_param( 'locale' ) ), 'pt' ) ? 'pt' : 'en',
			'archetype'     => substr( sanitize_key( (string) $request->get_param( 'archetype' ) ), 0, 64 ),
			'satisfaction'  => $satisfaction,
			'ease'          => $ease,
			'helped'        => $helped,
			'portfolio'     => $portfolio,
			'trust'         => in_array( $trust, self::SCALE_KEYS, true ) ? $trust : '',
			'nps'           => $nps,
			'most_valuable' => mb_substr( $most, 0, 2000 ),
			'improve'       => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'improve' ) ), 0, 2000 ),
			'comments'      => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'comments' ) ), 0, 2000 ),
		);

		if ( ! self::insert( $row ) ) {
			return new \WP_Error( 'hti_feedback_failed', __( 'Could not save your feedback. Please try again.', 'hti-engine' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/* ---------- admin ---------- */

	/**
	 * Register the admin page.
	 */
	public static function menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'HTI Feedback', 'hti-engine' ),
			__( 'HTI Feedback', 'hti-engine' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Aggregate metrics across all responses.
	 *
	 * @return array<string,mixed>
	 */
	public static function aggregates(): array {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( 0 === $count ) {
			return array( 'count' => 0, 'satisfaction' => 0.0, 'nps' => 0 );
		}
		$sat = (float) $wpdb->get_var( "SELECT AVG(satisfaction) FROM {$table} WHERE satisfaction > 0" );
		$pro = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE nps >= 9" );
		$det = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE nps BETWEEN 0 AND 6" );
		$nq  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE nps >= 0" );
		// phpcs:enable

		return array(
			'count'        => $count,
			'satisfaction' => round( $sat, 1 ),
			'nps'          => $nq > 0 ? (int) round( ( $pro - $det ) / $nq * 100 ) : 0,
		);
	}

	/**
	 * The most recent responses.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,object>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * Render the admin page: aggregates + recent responses + export.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$a    = self::aggregates();
		$rows = self::recent( 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Feedback', 'hti-engine' ); ?></h1>

			<h2><?php esc_html_e( 'Summary', 'hti-engine' ); ?></h2>
			<table class="widefat striped" style="max-width:480px">
				<tbody>
					<tr><td><strong><?php esc_html_e( 'Responses', 'hti-engine' ); ?></strong></td><td><?php echo esc_html( (string) $a['count'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Average satisfaction (1–5)', 'hti-engine' ); ?></td><td><?php echo esc_html( (string) $a['satisfaction'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'NPS score', 'hti-engine' ); ?></td><td><?php echo esc_html( (string) $a['nps'] ); ?></td></tr>
				</tbody>
			</table>

			<p style="margin-top:16px">
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hti_feedback_export' ), 'hti_feedback_export' ) ); ?>">
					<?php esc_html_e( 'Export all to CSV', 'hti-engine' ); ?>
				</a>
			</p>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Recent responses', 'hti-engine' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No feedback yet.', 'hti-engine' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'Lang', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'Sat.', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'NPS', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'Ease', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'Most valuable', 'hti-engine' ); ?></th>
							<th><?php esc_html_e( 'Improve', 'hti-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $r->created_at ); ?></td>
								<td><?php echo esc_html( strtoupper( (string) $r->lang ) ); ?></td>
								<td><?php echo esc_html( (string) $r->satisfaction ); ?></td>
								<td><?php echo esc_html( (int) $r->nps >= 0 ? (string) $r->nps : '—' ); ?></td>
								<td><?php echo esc_html( (string) $r->ease ); ?></td>
								<td><?php echo esc_html( (string) $r->most_valuable ); ?></td>
								<td><?php echo esc_html( (string) $r->improve ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Stream all responses as a CSV download.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_feedback_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=hti-feedback-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out     = fopen( 'php://output', 'w' );
		$headers = array( 'id', 'created_at', 'lang', 'archetype', 'satisfaction', 'ease', 'helped', 'portfolio', 'trust', 'nps', 'most_valuable', 'improve', 'comments' );
		fputcsv( $out, $headers );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $headers as $col ) {
				$line[] = $r[ $col ] ?? '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
