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

	/**
	 * Hook the account-security emails.
	 */
	public static function init(): void {
		// Brand WordPress's own "password changed" email (profile updates).
		add_filter( 'password_change_email', array( __CLASS__, 'brand_password_email' ), 10, 2 );
		// The reset flow doesn't email the user by default — send our alert.
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
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
}
