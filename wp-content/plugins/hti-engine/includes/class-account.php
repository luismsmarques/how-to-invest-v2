<?php
/**
 * Account lifecycle security + self-service: the password-changed alert
 * (template 09), and (added in later phases) email change, scheduled
 * deletion and preferences.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Account security alerts and self-service flows.
 */
class Account {

	private const META_PENDING_EMAIL = 'hti_pending_email';
	private const META_EMAIL_TOKEN   = 'hti_email_token';
	private const META_EMAIL_EXPIRES = 'hti_email_expires';
	public const META_DELETE_AT      = 'hti_delete_at';
	private const GRACE_DAYS         = 30;
	public const DELETE_HOOK         = 'hti_account_deletions';
	private const META_LAST_LOGIN    = 'hti_last_login';
	private const META_REACTIVATED   = 'hti_reactivated_at';
	private const INACTIVE_DAYS      = 90;
	public const REACTIVATE_HOOK     = 'hti_reactivation';

	/**
	 * Hook the account-security emails, confirm links and the deletion cron.
	 */
	public static function init(): void {
		// Brand WordPress's own "password changed" email (profile updates).
		add_filter( 'password_change_email', array( __CLASS__, 'brand_password_email' ), 10, 2 );
		// The reset flow doesn't email the user by default — send our alert.
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
		// Email-change + cancel-deletion confirmation links.
		add_action( 'template_redirect', array( __CLASS__, 'handle_email_change' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_cancel_deletion' ) );
		// Daily sweep that erases due accounts; weekly re-engagement sweep.
		add_action( self::DELETE_HOOK, array( __CLASS__, 'run_due_deletions' ) );
		add_action( self::REACTIVATE_HOOK, array( __CLASS__, 'run_reactivation' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		// Track last login for the re-engagement sweep.
		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );
		// Admin page to read the onboarding questions (content research).
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_questions_csv', array( __CLASS__, 'export_questions_csv' ) );
	}

	/**
	 * Ensure the deletion-sweep and reactivation crons exist.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DELETE_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 4:00' ), 'daily', self::DELETE_HOOK );
		}
		if ( ! wp_next_scheduled( self::REACTIVATE_HOOK ) ) {
			wp_schedule_event( strtotime( 'next tuesday 10:00' ), 'weekly', self::REACTIVATE_HOOK );
		}
	}

	/**
	 * Clear our crons (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DELETE_HOOK );
		wp_clear_scheduled_hook( self::REACTIVATE_HOOK );
	}

	/**
	 * Record the last-login timestamp; clears any prior reactivation flag.
	 *
	 * @param string    $login User login.
	 * @param \WP_User  $user  User.
	 */
	public static function record_login( $login, $user = null ): void {
		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
			delete_user_meta( $user->ID, self::META_REACTIVATED );
		}
	}

	/**
	 * Cron: email lapsed users (inactive > 90 days, not yet re-pinged) once.
	 */
	public static function run_reactivation(): void {
		$cutoff = time() - self::INACTIVE_DAYS * DAY_IN_SECONDS;
		$users  = get_users(
			array(
				'number'       => 50,
				'fields'       => array( 'ID' ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => self::META_LAST_LOGIN,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_REACTIVATED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $users as $row ) {
			$user = get_user_by( 'id', (int) $row->ID );
			if ( ! $user instanceof \WP_User || Verification::is_unverified( $user->ID ) ) {
				continue;
			}
			$locale = self::user_locale( $user->ID );
			$html   = Emails::reactivation( $locale, self::latest_news( $locale, 3 ), home_url( 'pt' === $locale ? '/pt/' : '/' ) );
			$subject = 'pt' === $locale ? 'Sentimos a tua falta — HowToInvest' : 'We’ve missed you — HowToInvest';
			Mailer::send( $user->user_email, $subject, $html );
			update_user_meta( $user->ID, self::META_REACTIVATED, time() );
		}
	}

	/**
	 * Latest published news for "what's new", in the user's language.
	 *
	 * @param string $locale Language.
	 * @param int    $max    Max items.
	 * @return array<int,array{title:string,url:string,excerpt:string}>
	 */
	private static function latest_news( string $locale, int $max ): array {
		$args = array(
			'post_type'      => 'news',
			'post_status'    => 'publish',
			'posts_per_page' => $max,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( function_exists( 'pll_default_language' ) ) {
			$args['lang'] = $locale;
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'title'   => (string) get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'excerpt' => '',
			);
		}
		wp_reset_postdata();
		return $items;
	}

	/**
	 * Locale for a user: their explicit onboarding choice if set, else the WP
	 * profile language. Drives every lifecycle email and their newsletter list.
	 *
	 * @param int $user_id User id.
	 */
	private static function user_locale( int $user_id ): string {
		$pref = self::pref_locale( $user_id );
		if ( '' !== $pref ) {
			return $pref;
		}
		return str_starts_with( strtolower( (string) get_user_locale( $user_id ) ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Current request details for a security email: time, device, IP.
	 *
	 * @param string $locale Locale (for date formatting/labels).
	 * @return array{when:string,device:string,ip:string}
	 */
	public static function request_meta( string $locale ): array {
		$when = function_exists( 'wp_date' ) ? (string) wp_date( 'pt' === $locale ? 'j \d\e F \d\e Y, H:i' : 'j F Y, H:i' ) : gmdate( 'c' );
		return array(
			'when'   => $when,
			'device' => self::device(),
			'ip'     => self::client_ip(),
		);
	}

	/**
	 * Best-effort client IP (filterable, mirrors the rate limiter).
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/** This filter is documented in includes/class-rate-limit.php */
		return (string) apply_filters( 'hti_client_ip', $ip );
	}

	/**
	 * A short, human device string parsed from the user agent.
	 */
	private static function device(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return '';
		}
		$os = str_contains( $ua, 'Windows' ) ? 'Windows'
			: ( str_contains( $ua, 'Mac OS' ) || str_contains( $ua, 'Macintosh' ) ? 'macOS'
			: ( str_contains( $ua, 'Android' ) ? 'Android'
			: ( ( str_contains( $ua, 'iPhone' ) || str_contains( $ua, 'iPad' ) ) ? 'iOS'
			: ( str_contains( $ua, 'Linux' ) ? 'Linux' : '' ) ) ) );
		$browser = str_contains( $ua, 'Edg' ) ? 'Edge'
			: ( str_contains( $ua, 'Chrome' ) ? 'Chrome'
			: ( str_contains( $ua, 'Firefox' ) ? 'Firefox'
			: ( str_contains( $ua, 'Safari' ) ? 'Safari' : '' ) ) );
		$parts = array_filter( array( $browser, $os ) );
		return empty( $parts ) ? '' : implode( ' · ', $parts );
	}

	/**
	 * Build + send the security alert to a user.
	 *
	 * @param \WP_User $user User.
	 */
	private static function send_alert( \WP_User $user ): void {
		$locale = self::user_locale( $user->ID );
		$html   = Emails::security_alert( $locale, self::request_meta( $locale ), wp_lostpassword_url() );
		$subject = 'pt' === $locale ? 'Alerta de segurança: password alterada — HowToInvest' : 'Security alert: password changed — HowToInvest';
		Mailer::send( $user->user_email, $subject, $html );
	}

	/**
	 * Replace WordPress's plain password-change email with our branded alert.
	 *
	 * @param array<string,mixed> $email Default email parts.
	 * @param array<string,mixed> $user  The user array (old data).
	 * @return array<string,mixed>
	 */
	public static function brand_password_email( array $email, $user ): array {
		$user_id = is_array( $user ) ? (int) ( $user['ID'] ?? 0 ) : 0;
		$locale  = $user_id ? self::user_locale( $user_id ) : 'en';
		$email['subject'] = 'pt' === $locale ? 'Alerta de segurança: password alterada — HowToInvest' : 'Security alert: password changed — HowToInvest';
		$email['message'] = Emails::security_alert( $locale, self::request_meta( $locale ), wp_lostpassword_url() );
		$headers          = isset( $email['headers'] ) ? (array) $email['headers'] : array();
		$headers[]        = 'Content-Type: text/html; charset=UTF-8';
		$email['headers'] = $headers;
		return $email;
	}

	/**
	 * Send the security alert after a password reset.
	 *
	 * @param \WP_User $user User.
	 */
	public static function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::send_alert( $user );
		}
	}

	/* ---------- onboarding (post-activation) ---------- */

	private const META_PREF_LOCALE = 'hti_pref_locale';
	private const META_ONBOARDED   = 'hti_onboarded';
	private const META_QUESTION    = 'hti_invest_question';
	private const OPTION_QUESTIONS = 'hti_invest_questions';

	/**
	 * The user's chosen language ('en'|'pt'), or '' if not chosen yet.
	 *
	 * @param int $user_id User id.
	 */
	public static function pref_locale( int $user_id ): string {
		$l = (string) get_user_meta( $user_id, self::META_PREF_LOCALE, true );
		return in_array( $l, array( 'en', 'pt' ), true ) ? $l : '';
	}

	/**
	 * Whether the user has completed onboarding.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_onboarded( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_ONBOARDED, true );
	}

	/**
	 * REST: POST /onboarding — save language + newsletter + the open question.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_onboarding( \WP_REST_Request $request ) {
		$user       = wp_get_current_user();
		$lang       = 'pt' === $request->get_param( 'language' ) ? 'pt' : 'en';
		$newsletter = (bool) rest_sanitize_boolean( $request->get_param( 'newsletter' ) );
		$frequency  = 'daily' === $request->get_param( 'frequency' ) ? 'daily' : 'weekly';
		$question   = trim( sanitize_textarea_field( (string) $request->get_param( 'question' ) ) );
		if ( strlen( $question ) > 1000 ) {
			$question = substr( $question, 0, 1000 );
		}

		update_user_meta( $user->ID, self::META_PREF_LOCALE, $lang );
		update_user_meta( $user->ID, self::META_ONBOARDED, '1' );
		$prev = self::get_prefs( $user->ID );
		update_user_meta( $user->ID, self::META_PREFS, array( 'newsletter' => $newsletter, 'frequency' => $frequency, 'categories' => $prev['categories'] ) );
		if ( '' !== $question ) {
			update_user_meta( $user->ID, self::META_QUESTION, $question );
			self::log_question( $user->ID, $question, $lang );
		}

		// Sync the contact in Brevo (language drives the list).
		$list = Brevo::list_id( $lang );
		$attrs = array( 'LANGUAGE' => strtoupper( $lang ), 'NEWSLETTER' => $newsletter ? 'yes' : 'no', 'FREQUENCY' => strtoupper( $frequency ) );
		if ( '' !== $question ) {
			$attrs['QUESTION'] = $question;
		}
		if ( $newsletter ) {
			Brevo::upsert_contact( $user->user_email, $attrs, array_filter( array( $list ) ) );
		} else {
			Brevo::upsert_contact( $user->user_email, $attrs );
		}

		return new \WP_REST_Response(
			array( 'saved' => true, 'redirect' => home_url( 'pt' === $lang ? '/pt/' : '/' ) ),
			200
		);
	}

	/**
	 * Append an onboarding question to the research log (capped, no PII beyond uid).
	 *
	 * @param int    $user_id  User id.
	 * @param string $question Free text.
	 * @param string $lang     Language.
	 */
	private static function log_question( int $user_id, string $question, string $lang ): void {
		$log = get_option( self::OPTION_QUESTIONS, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array( 'uid' => $user_id, 'q' => $question, 'lang' => $lang, 'at' => time() );
		if ( count( $log ) > 2000 ) {
			$log = array_slice( $log, -2000 );
		}
		update_option( self::OPTION_QUESTIONS, $log, false );
	}

	/**
	 * The logged onboarding questions, most recent first.
	 *
	 * @return array<int,array{uid:int,q:string,lang:string,at:int}>
	 */
	public static function questions(): array {
		$log = get_option( self::OPTION_QUESTIONS, array() );
		$log = is_array( $log ) ? array_reverse( $log ) : array();
		return $log;
	}

	/* ---------- admin: read the onboarding questions ---------- */

	/**
	 * Register the "HTI Insights" admin page.
	 */
	public static function admin_menu(): void {
		add_options_page(
			__( 'HTI Insights', 'hti-engine' ),
			__( 'HTI Insights', 'hti-engine' ),
			'manage_options',
			'hti-insights',
			array( __CLASS__, 'render_insights_page' )
		);
	}

	/**
	 * Render the insights page: the users' biggest investing doubts.
	 */
	public static function render_insights_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = self::questions();
		$csv  = wp_nonce_url( admin_url( 'admin-post.php?action=hti_questions_csv' ), 'hti_questions_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Insights', 'hti-engine' ); ?></h1>
			<p><?php
				/* translators: %d: number of responses. */
				printf( esc_html__( 'What users say is their biggest investing doubt (%d responses). Use it to plan content.', 'hti-engine' ), count( $rows ) );
			?></p>
			<?php if ( ! empty( $rows ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Download CSV', 'hti-engine' ); ?></a></p>
				<table class="widefat striped">
					<thead><tr>
						<th style="width:140px"><?php esc_html_e( 'Date', 'hti-engine' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'Lang', 'hti-engine' ); ?></th>
						<th style="width:220px"><?php esc_html_e( 'User', 'hti-engine' ); ?></th>
						<th><?php esc_html_e( 'Question', 'hti-engine' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $rows, 0, 500 ) as $row ) :
						$user = get_userdata( (int) ( $row['uid'] ?? 0 ) );
						?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $row['at'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( strtoupper( (string) ( $row['lang'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( $user ? $user->user_email : '#' . (int) ( $row['uid'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['q'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No responses yet.', 'hti-engine' ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Stream the questions as a CSV download.
	 */
	public static function export_questions_csv(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_questions_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="hti-insights.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'date', 'language', 'email', 'question' ) );
		foreach ( self::questions() as $row ) {
			$user = get_userdata( (int) ( $row['uid'] ?? 0 ) );
			fputcsv(
				$out,
				array(
					gmdate( 'Y-m-d H:i', (int) ( $row['at'] ?? 0 ) ),
					(string) ( $row['lang'] ?? '' ),
					$user ? $user->user_email : '#' . (int) ( $row['uid'] ?? 0 ),
					(string) ( $row['q'] ?? '' ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------- preferences (template 13) ---------- */

	private const META_PREFS = 'hti_prefs';

	/**
	 * A user's saved email preferences (with defaults).
	 *
	 * @param int $user_id User id.
	 * @return array{newsletter:bool,frequency:string,categories:array<int,string>}
	 */
	public static function get_prefs( int $user_id ): array {
		$p = get_user_meta( $user_id, self::META_PREFS, true );
		$p = is_array( $p ) ? $p : array();
		return array(
			'newsletter' => (bool) ( $p['newsletter'] ?? false ),
			'frequency'  => 'daily' === ( $p['frequency'] ?? 'weekly' ) ? 'daily' : 'weekly',
			'categories' => array_values( array_filter( array_map( 'sanitize_title', (array) ( $p['categories'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Available news categories for the preferences UI, in the user's language.
	 *
	 * @param string $locale Locale.
	 * @return array<int,array{slug:string,name:string}>
	 */
	public static function categories_list( string $locale ): array {
		if ( ! taxonomy_exists( 'news_category' ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => 'news_category', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			// Skip the alternate-language duplicate terms when Polylang is active.
			if ( function_exists( 'pll_get_term_language' ) ) {
				$tl = pll_get_term_language( (int) $term->term_id );
				if ( $tl && $tl !== $locale ) {
					continue;
				}
			}
			$out[] = array( 'slug' => $term->slug, 'name' => $term->name );
		}
		return $out;
	}

	/**
	 * REST: GET /preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_get_prefs( \WP_REST_Request $request ) {
		return new \WP_REST_Response( self::get_prefs( get_current_user_id() ), 200 );
	}

	/**
	 * REST: POST /preferences — save, sync to Brevo, and email a confirmation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_save_prefs( \WP_REST_Request $request ) {
		$user      = wp_get_current_user();
		$newsletter = (bool) rest_sanitize_boolean( $request->get_param( 'newsletter' ) );
		$frequency = 'daily' === $request->get_param( 'frequency' ) ? 'daily' : 'weekly';
		$cats      = array_values( array_filter( array_map( 'sanitize_title', (array) $request->get_param( 'categories' ) ) ) );

		update_user_meta( $user->ID, self::META_PREFS, array( 'newsletter' => $newsletter, 'frequency' => $frequency, 'categories' => $cats ) );

		$locale = self::user_locale( $user->ID );
		$list   = Brevo::list_id( $locale );
		if ( $newsletter ) {
			Brevo::upsert_contact(
				$user->user_email,
				array(
					'NEWSLETTER' => 'yes',
					'FREQUENCY'  => strtoupper( $frequency ),
					'CATEGORIES' => implode( ',', $cats ),
					'LANGUAGE'   => strtoupper( $locale ),
				),
				array_filter( array( $list ) )
			);
		} else {
			Brevo::upsert_contact( $user->user_email, array( 'NEWSLETTER' => 'no' ) );
			if ( $list > 0 ) {
				Brevo::remove_from_list( $user->user_email, $list );
			}
		}

		// Confirmation email (template 13).
		$names   = array();
		foreach ( self::categories_list( $locale ) as $cat ) {
			if ( in_array( $cat['slug'], $cats, true ) ) {
				$names[] = $cat['name'];
			}
		}
		$subject = 'pt' === $locale ? 'Preferências atualizadas — HowToInvest' : 'Preferences updated — HowToInvest';
		Mailer::send( $user->user_email, $subject, Emails::preferences( $locale, $newsletter, $frequency, $names ) );

		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/* ---------- email change (template 10) ---------- */

	/**
	 * REST: POST /change-email — start the email-change confirmation flow.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_change_email( \WP_REST_Request $request ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return new \WP_Error( 'hti_unauthorized', __( 'You must be signed in.', 'hti-engine' ), array( 'status' => 401 ) );
		}
		if ( RateLimit::exceeded( 'change_email' ) ) {
			return new \WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment.', 'hti-engine' ), array( 'status' => 429 ) );
		}

		$new = sanitize_email( (string) $request->get_param( 'new_email' ) );
		if ( ! is_email( $new ) ) {
			return new \WP_Error( 'hti_invalid_email', __( 'Please enter a valid email.', 'hti-engine' ), array( 'status' => 422 ) );
		}
		if ( strtolower( $new ) === strtolower( $user->user_email ) ) {
			return new \WP_Error( 'hti_same_email', __( 'That is already your email.', 'hti-engine' ), array( 'status' => 422 ) );
		}
		if ( email_exists( $new ) ) {
			return new \WP_Error( 'hti_email_taken', __( 'That email can’t be used. Try another.', 'hti-engine' ), array( 'status' => 409 ) );
		}

		$plain = wp_generate_password( 40, false );
		update_user_meta( $user->ID, self::META_PENDING_EMAIL, $new );
		update_user_meta( $user->ID, self::META_EMAIL_TOKEN, hash( 'sha256', $plain ) );
		update_user_meta( $user->ID, self::META_EMAIL_EXPIRES, time() + DAY_IN_SECONDS );

		$locale = self::user_locale( $user->ID );
		$url    = add_query_arg(
			array( 'hti_email_change' => rawurlencode( $plain ), 'u' => $user->ID ),
			home_url( '/' )
		);

		// Confirm to the NEW address; alert the OLD address.
		$subject = 'pt' === $locale ? 'Confirma o teu novo email — HowToInvest' : 'Confirm your new email — HowToInvest';
		Mailer::send( $new, $subject, Emails::email_change( $locale, $user->user_email, $new, $url ) );
		self::send_old_email_notice( $user, $new, $locale );

		return new \WP_REST_Response( array( 'pending' => true ), 200 );
	}

	/**
	 * Notify the current address that a change was requested ("wasn't you?").
	 *
	 * @param \WP_User $user   User.
	 * @param string   $new    Requested new email.
	 * @param string   $locale Locale.
	 */
	private static function send_old_email_notice( \WP_User $user, string $new, string $locale ): void {
		$pt      = 'pt' === $locale;
		$heading = $pt ? 'Pedido de alteração de email' : 'Email change requested';
		$lead    = $pt
			? sprintf( 'Foi pedida a alteração do email da tua conta para %s. Se foste tu, confirma no email que enviámos para o novo endereço.', $new )
			: sprintf( 'A request was made to change your account email to %s. If this was you, confirm via the email we sent to the new address.', $new );
		$note = $pt
			? 'Se não foste tu, repõe a tua password e contacta-nos — o email não muda sem a confirmação no novo endereço.'
			: 'If this wasn’t you, reset your password and contact us — your email won’t change without the confirmation on the new address.';
		$inner = self::email_layout_simple( $locale, '&#9888;', '#FDF3E2', '#B7791F', $heading, $lead, $note );
		$subject = $pt ? 'Pedido de alteração de email — HowToInvest' : 'Email change requested — HowToInvest';
		Mailer::send( $user->user_email, $subject, $inner );
	}

	/**
	 * Handle the email-change confirmation link.
	 */
	public static function handle_email_change(): void {
		if ( empty( $_GET['hti_email_change'] ) || empty( $_GET['u'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}
		$uid   = absint( wp_unslash( $_GET['u'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['hti_email_change'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dest  = home_url( '/my-account/' );

		$pending = (string) get_user_meta( $uid, self::META_PENDING_EMAIL, true );
		$stored  = (string) get_user_meta( $uid, self::META_EMAIL_TOKEN, true );
		$expires = (int) get_user_meta( $uid, self::META_EMAIL_EXPIRES, true );

		$valid = $uid && '' !== $pending && '' !== $stored
			&& time() < $expires
			&& hash_equals( $stored, hash( 'sha256', $token ) )
			&& is_email( $pending ) && ! email_exists( $pending );

		if ( $valid ) {
			wp_update_user( array( 'ID' => $uid, 'user_email' => $pending ) );
			delete_user_meta( $uid, self::META_PENDING_EMAIL );
			delete_user_meta( $uid, self::META_EMAIL_TOKEN );
			delete_user_meta( $uid, self::META_EMAIL_EXPIRES );
			wp_safe_redirect( add_query_arg( 'email_changed', '1', $dest ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'email_error', '1', $dest ) );
		exit;
	}

	/* ---------- scheduled account deletion (template 11) ---------- */

	/**
	 * Schedule a user's account for deletion after the grace period and email
	 * them the date + cancel/download links. Returns the deletion timestamp.
	 *
	 * @param int $user_id User id.
	 */
	public static function schedule_deletion( int $user_id ): int {
		$at = time() + self::GRACE_DAYS * DAY_IN_SECONDS;
		update_user_meta( $user_id, self::META_DELETE_AT, $at );

		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof \WP_User ) {
			$locale = self::user_locale( $user_id );
			$date   = function_exists( 'wp_date' ) ? (string) wp_date( 'pt' === $locale ? 'j \d\e F \d\e Y' : 'j F Y', $at ) : gmdate( 'Y-m-d', $at );
			$cancel = self::cancel_link( $user_id, $at );
			$html   = Emails::deletion_scheduled( $locale, $date, $cancel, home_url( '/my-account/' ) );
			$subject = 'pt' === $locale ? 'A tua conta vai ser eliminada — HowToInvest' : 'Your account is scheduled for deletion — HowToInvest';
			Mailer::send( $user->user_email, $subject, $html );
		}
		return $at;
	}

	/**
	 * Cancel a scheduled deletion.
	 *
	 * @param int $user_id User id.
	 */
	public static function cancel_deletion( int $user_id ): void {
		delete_user_meta( $user_id, self::META_DELETE_AT );
	}

	/**
	 * The scheduled deletion timestamp for a user (0 if none).
	 *
	 * @param int $user_id User id.
	 */
	public static function deletion_at( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_DELETE_AT, true );
	}

	/**
	 * Stateless cancel link (bound to the user + scheduled time).
	 *
	 * @param int $user_id User id.
	 * @param int $at      Deletion timestamp.
	 */
	private static function cancel_link( int $user_id, int $at ): string {
		$token = substr( hash_hmac( 'sha256', $user_id . '|' . $at . '|cancel', wp_salt( 'auth' ) ), 0, 40 );
		return add_query_arg(
			array( 'hti_cancel_delete' => $token, 'u' => $user_id ),
			home_url( '/' )
		);
	}

	/**
	 * Handle the cancel-deletion link from the email.
	 */
	public static function handle_cancel_deletion(): void {
		if ( empty( $_GET['hti_cancel_delete'] ) || empty( $_GET['u'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}
		$uid   = absint( wp_unslash( $_GET['u'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['hti_cancel_delete'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$at    = self::deletion_at( $uid );
		$valid = $at > 0 && hash_equals( substr( hash_hmac( 'sha256', $uid . '|' . $at . '|cancel', wp_salt( 'auth' ) ), 0, 40 ), $token );

		if ( $valid ) {
			self::cancel_deletion( $uid );
			wp_safe_redirect( add_query_arg( 'delete_cancelled', '1', home_url( '/my-account/' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'delete_error', '1', home_url( '/my-account/' ) ) );
		exit;
	}

	/**
	 * Cron: hard-delete accounts whose grace period has elapsed.
	 */
	public static function run_due_deletions(): void {
		$users = get_users(
			array(
				'meta_key'   => self::META_DELETE_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'number'     => 50,
				'fields'     => array( 'ID' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_DELETE_AT,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $users as $user ) {
			self::hard_delete( (int) $user->ID );
		}
	}

	/**
	 * Erase a user and all their saved profiles (RGPD cascade). Irreversible.
	 *
	 * @param int $user_id User id.
	 */
	public static function hard_delete( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user  = get_userdata( $user_id );
		$email = $user ? (string) $user->user_email : '';

		$profiles = get_posts(
			array(
				'post_type'   => 'htinvest_profile',
				'post_status' => 'any',
				'author'      => $user_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $profiles as $id ) {
			wp_delete_post( (int) $id, true );
		}

		// Newsletter processor (Brevo holds the email off-site): erase the
		// contact entirely; if the API key lacks delete rights, at least remove
		// it from every list. Never let this block the account deletion.
		if ( '' !== $email && Brevo::configured() ) {
			if ( ! Brevo::delete_contact( $email ) ) {
				foreach ( Brevo::lists_by_language() as $list_id ) {
					Brevo::remove_from_list( $email, (int) $list_id );
				}
			}
		}

		// Global options keyed by uid that wp_delete_user does NOT clear.
		self::forget_questions( $user_id );
		NPS::forget( $user_id );

		wp_delete_user( $user_id );
	}

	/**
	 * Drop a user's free-text onboarding answers from the research log option.
	 *
	 * @param int $user_id User id.
	 */
	private static function forget_questions( int $user_id ): void {
		$log = get_option( self::OPTION_QUESTIONS, array() );
		if ( ! is_array( $log ) ) {
			return;
		}
		$filtered = array_values( array_filter( $log, static fn( $q ) => (int) ( $q['uid'] ?? 0 ) !== $user_id ) );
		if ( count( $filtered ) !== count( $log ) ) {
			update_option( self::OPTION_QUESTIONS, $filtered, false );
		}
	}

	/**
	 * A minimal one-message email (icon + heading + lead + note) on the layout.
	 *
	 * @param string $locale  Locale.
	 * @param string $glyph   Icon glyph.
	 * @param string $bg      Icon background.
	 * @param string $fg      Icon colour.
	 * @param string $heading Heading.
	 * @param string $lead    Lead text.
	 * @param string $note    Footnote.
	 */
	private static function email_layout_simple( string $locale, string $glyph, string $bg, string $fg, string $heading, string $lead, string $note ): string {
		$inner = Emails::row(
			Emails::icon_circle( $glyph, $bg, $fg ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::note( $note ), '22px 48px 44px', true );
		return Emails::layout( $locale, $inner, $heading );
	}
}
