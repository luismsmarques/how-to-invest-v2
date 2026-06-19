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

	/**
	 * Hook the account-security emails and the email-change confirm link.
	 */
	public static function init(): void {
		// Brand WordPress's own "password changed" email (profile updates).
		add_filter( 'password_change_email', array( __CLASS__, 'brand_password_email' ), 10, 2 );
		// The reset flow doesn't email the user by default — send our alert.
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
		// Email-change double opt-in confirmation link.
		add_action( 'template_redirect', array( __CLASS__, 'handle_email_change' ) );
	}

	/**
	 * Locale for a user (PT when their profile language is Portuguese).
	 *
	 * @param int $user_id User id.
	 */
	private static function user_locale( int $user_id ): string {
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
