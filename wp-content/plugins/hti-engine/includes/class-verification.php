<?php
/**
 * Email verification (double opt-in) — removes account enumeration on register.
 *
 * /register always responds identically ("check your email"); whether the
 * email is new, an unverified retry, or an existing account, the user only ever
 * learns the outcome through their inbox. Accounts created here stay
 * unverified (login blocked) until the emailed link is followed, which then
 * signs them in and claims their pending profile.
 *
 * Brevo (or wp_mail) does the sending; see class-mailer.php.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, sends and checks email-verification tokens; gates unverified logins.
 */
class Verification {

	public const META_VERIFIED = 'hti_email_verified';
	private const META_TOKEN   = 'hti_verify_token';
	private const META_EXPIRES = 'hti_verify_expires';
	private const META_PENDING = 'hti_pending_claim';
	private const TTL          = DAY_IN_SECONDS; // 24 hours.

	/**
	 * Hook the verify endpoint and the login gate.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'gate_login' ), 10, 1 );
	}

	/**
	 * Whether a user is an unverified registration created here.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_unverified( int $user_id ): bool {
		return '0' === get_user_meta( $user_id, self::META_VERIFIED, true );
	}

	/**
	 * Begin (or resend) verification for an email. Same outcome in every branch
	 * so the caller can return one neutral, non-enumerating response.
	 *
	 * @param string $email         Email.
	 * @param string $password      Chosen password.
	 * @param string $session_token Anonymous profile to claim after verifying.
	 * @param string $locale        Email locale ('en'/'pt').
	 */
	public static function start( string $email, string $password, string $session_token, string $locale ): void {
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof \WP_User ) {
			if ( self::is_unverified( $existing->ID ) ) {
				wp_set_password( $password, $existing->ID ); // Honour the latest intent.
				update_user_meta( $existing->ID, self::META_VERIFIED, '0' );
				self::store_pending( $existing->ID, $session_token );
				self::send( $existing, self::issue_token( $existing->ID ), $locale );
			} else {
				self::send_existing_notice( $existing, $locale );
			}
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return; // Stay neutral.
		}

		update_user_meta( $user_id, self::META_VERIFIED, '0' );
		self::store_pending( $user_id, $session_token );
		self::send( get_user_by( 'id', $user_id ), self::issue_token( $user_id ), $locale );
	}

	/**
	 * Store the anonymous session token to claim once verified.
	 *
	 * @param int    $user_id       User id.
	 * @param string $session_token Token.
	 */
	private static function store_pending( int $user_id, string $session_token ): void {
		if ( '' !== $session_token ) {
			update_user_meta( $user_id, self::META_PENDING, $session_token );
		}
	}

	/**
	 * Issue a fresh token (stores its hash + expiry), returning the plaintext.
	 *
	 * @param int $user_id User id.
	 */
	private static function issue_token( int $user_id ): string {
		$plain = wp_generate_password( 40, false );
		update_user_meta( $user_id, self::META_TOKEN, hash( 'sha256', $plain ) );
		update_user_meta( $user_id, self::META_EXPIRES, time() + self::TTL );
		return $plain;
	}

	/**
	 * The verification URL for a user + plaintext token.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Plaintext token.
	 */
	private static function verify_url( int $user_id, string $token ): string {
		return add_query_arg(
			array(
				'hti_verify' => rawurlencode( $token ),
				'hti_uid'    => $user_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle the verification link: validate → activate → sign in → claim.
	 */
	public static function handle_link(): void {
		if ( empty( $_GET['hti_verify'] ) || empty( $_GET['hti_uid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}

		$uid   = absint( wp_unslash( $_GET['hti_uid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['hti_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dest  = home_url( '/my-account/' );
		$user  = $uid ? get_user_by( 'id', $uid ) : false;

		if ( $user instanceof \WP_User && self::token_valid( $uid, $token ) ) {
			update_user_meta( $uid, self::META_VERIFIED, '1' );
			delete_user_meta( $uid, self::META_TOKEN );
			delete_user_meta( $uid, self::META_EXPIRES );

			wp_set_current_user( $uid );
			wp_set_auth_cookie( $uid, true );

			$pending = (string) get_user_meta( $uid, self::META_PENDING, true );
			if ( '' !== $pending ) {
				REST::claim_for_user( $uid, $pending );
				delete_user_meta( $uid, self::META_PENDING );
			}

			wp_safe_redirect( add_query_arg( 'verified', '1', $dest ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'verify_error', '1', $dest ) );
		exit;
	}

	/**
	 * Constant-time token check against the stored hash, within expiry.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Plaintext token.
	 */
	private static function token_valid( int $user_id, string $token ): bool {
		$stored  = (string) get_user_meta( $user_id, self::META_TOKEN, true );
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );
		return '' !== $stored && time() < $expires && hash_equals( $stored, hash( 'sha256', $token ) );
	}

	/**
	 * Block sign-in for accounts that are still unverified.
	 *
	 * @param \WP_User|\WP_Error $user Authenticated user or error.
	 * @return \WP_User|\WP_Error
	 */
	public static function gate_login( $user ) {
		if ( $user instanceof \WP_User && self::is_unverified( $user->ID ) ) {
			return new \WP_Error( 'hti_unverified', __( 'Please confirm your email first — check your inbox for the link.', 'hti-engine' ) );
		}
		return $user;
	}

	/* ---------- emails ---------- */

	/**
	 * Send the verification email.
	 *
	 * @param \WP_User $user   User.
	 * @param string   $token  Plaintext token.
	 * @param string   $locale Locale.
	 */
	private static function send( \WP_User $user, string $token, string $locale ): void {
		$pt   = 'pt' === $locale;
		$url  = self::verify_url( $user->ID, $token );
		$site = get_bloginfo( 'name' );

		$subject = $pt ? 'Confirma o teu email — HowToInvest' : 'Confirm your email — HowToInvest';
		$intro   = $pt
			? 'Para guardar o teu perfil e criar a conta, confirma o teu email clicando no botão abaixo. O link é válido por 24 horas.'
			: 'To save your profile and finish creating your account, confirm your email using the button below. The link is valid for 24 hours.';
		$btn     = $pt ? 'Confirmar email' : 'Confirm email';
		$ignore  = $pt
			? 'Se não foste tu a pedir isto, podes ignorar esta mensagem em segurança.'
			: "If you didn't request this, you can safely ignore this message.";

		self::deliver( $user->user_email, $subject, $intro, $btn, $url, $ignore, $site );
	}

	/**
	 * Send the "you already have an account" notice (keeps register neutral).
	 *
	 * @param \WP_User $user   User.
	 * @param string   $locale Locale.
	 */
	private static function send_existing_notice( \WP_User $user, string $locale ): void {
		$pt      = 'pt' === $locale;
		$site    = get_bloginfo( 'name' );
		$subject = $pt ? 'Já tens uma conta — HowToInvest' : 'You already have an account — HowToInvest';
		$intro   = $pt
			? 'Alguém (talvez tu) tentou criar uma conta com este email, mas já existe uma. Podes entrar com a tua palavra-passe ou recuperá-la na página de login.'
			: 'Someone (maybe you) tried to create an account with this email, but one already exists. You can sign in with your password, or reset it on the login page.';
		$btn     = $pt ? 'Ir para o login' : 'Go to sign in';
		$ignore  = $pt ? 'Se não foste tu, podes ignorar esta mensagem.' : "If this wasn't you, you can ignore this message.";

		self::deliver( $user->user_email, $subject, $intro, $btn, wp_login_url(), $ignore, $site );
	}

	/**
	 * Render a simple HTML email and hand it to the mailer.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $intro   Intro paragraph.
	 * @param string $btn     Button label.
	 * @param string $url     Button URL.
	 * @param string $ignore  "ignore" footnote.
	 * @param string $site    Site name.
	 */
	private static function deliver( string $to, string $subject, string $intro, string $btn, string $url, string $ignore, string $site ): void {
		$html = '<div style="font-family:system-ui,Arial,sans-serif;max-width:480px;margin:0 auto;color:#14241d;line-height:1.6">'
			. '<h2 style="color:#1b7a5a">' . esc_html( $site ) . '</h2>'
			. '<p>' . esc_html( $intro ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1b7a5a;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600">' . esc_html( $btn ) . '</a></p>'
			. '<p style="font-size:13px;color:#5b6b64;word-break:break-all">' . esc_url( $url ) . '</p>'
			. '<p style="font-size:13px;color:#5b6b64">' . esc_html( $ignore ) . '</p>'
			. '</div>';

		Mailer::send( $to, $subject, $html );
	}

	/* ---------- cleanup ---------- */

	/**
	 * Delete unverified accounts older than two days (abuse cleanup).
	 */
	public static function prune_unverified(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$users = get_users(
			array(
				'meta_key'   => self::META_VERIFIED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 100,
				'fields'     => array( 'ID', 'user_registered' ),
			)
		);

		$cutoff = time() - ( 2 * DAY_IN_SECONDS );
		foreach ( $users as $user ) {
			if ( strtotime( $user->user_registered . ' UTC' ) < $cutoff ) {
				wp_delete_user( (int) $user->ID );
			}
		}
	}
}
