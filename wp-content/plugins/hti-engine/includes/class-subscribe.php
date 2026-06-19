<?php
/**
 * Newsletter subscription with double opt-in, backed by Brevo contacts.
 *
 * Flow: the [hti_subscribe] form posts to /subscribe → we email a branded
 * confirmation with a stateless HMAC link → following it upserts the contact
 * into the Brevo list and sends a "confirmed" email. Every newsletter email
 * carries an unsubscribe link that removes the contact from the list. No
 * subscriber data is stored on the site; Brevo is the source of truth.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribe form, double opt-in confirm/unsubscribe, and the related emails.
 */
class Subscribe {

	private const SHORTCODE = 'hti_subscribe';

	/**
	 * Hook the shortcode, assets, and the confirm/unsubscribe link handler.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'result_toast' ) );
	}

	/* ---------- stateless tokens ---------- */

	/**
	 * An email-bound HMAC token for an action ('optin' | 'unsub').
	 *
	 * @param string $email  Email.
	 * @param string $action Action.
	 */
	public static function token( string $email, string $action ): string {
		return substr( hash_hmac( 'sha256', $action . '|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Verify a token in constant time.
	 *
	 * @param string $email  Email.
	 * @param string $action Action.
	 * @param string $token  Provided token.
	 */
	private static function token_valid( string $email, string $action, string $token ): bool {
		return '' !== $token && hash_equals( self::token( $email, $action ), $token );
	}

	/**
	 * The confirm/unsubscribe link for an email.
	 *
	 * @param string $action 'optin' | 'unsub'.
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	public static function link( string $action, string $email, string $locale ): string {
		return add_query_arg(
			array(
				'hti_sub' => $action,
				'e'       => rawurlencode( $email ),
				't'       => self::token( $email, $action ),
				'l'       => 'pt' === $locale ? 'pt' : 'en',
			),
			home_url( '/' )
		);
	}

	/* ---------- assets + form ---------- */

	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Register (don't enqueue) the assets; the shortcode enqueues on render so
	 * it works inside post content and block templates (e.g. archives) alike.
	 */
	public static function register_assets(): void {
		$locale = self::locale();
		wp_register_style( 'hti-subscribe', HTI_ENGINE_URL . 'assets/css/subscribe.css', array(), VERSION );
		wp_register_script( 'hti-subscribe', HTI_ENGINE_URL . 'assets/js/subscribe.js', array(), VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'hti-subscribe',
			'HTI_SUBSCRIBE',
			array(
				'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/subscribe' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => $locale,
				'strings' => self::js_strings( 'pt' === $locale ),
			)
		);
	}

	/**
	 * JS status strings.
	 *
	 * @param bool $pt Portuguese.
	 * @return array<string,string>
	 */
	private static function js_strings( bool $pt ): array {
		return $pt
			? array(
				'sending' => 'A enviar…',
				'sent'    => 'Quase! Confirma a subscrição no email que te enviámos.',
				'invalid' => 'Introduz um email válido.',
				'consent' => 'Para subscreveres, aceita receber os emails.',
				'error'   => 'Não foi possível subscrever. Tenta novamente.',
				'rate'    => 'Demasiadas tentativas. Aguarda um momento.',
			)
			: array(
				'sending' => 'Sending…',
				'sent'    => 'Almost there! Confirm your subscription in the email we just sent.',
				'invalid' => 'Please enter a valid email.',
				'consent' => 'Please agree to receive the emails to subscribe.',
				'error'   => 'Could not subscribe. Please try again.',
				'rate'    => 'Too many attempts. Please wait a moment.',
			);
	}

	/**
	 * `[hti_subscribe]` form. Attributes: title, intro (optional overrides).
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		wp_enqueue_style( 'hti-subscribe' );
		wp_enqueue_script( 'hti-subscribe' );

		$atts   = shortcode_atts( array( 'title' => '', 'intro' => '' ), is_array( $atts ) ? $atts : array() );
		$pt     = 'pt' === self::locale();
		$title  = '' !== $atts['title'] ? $atts['title'] : ( $pt ? 'Recebe o resumo na tua caixa de entrada' : 'Get the roundup in your inbox' );
		$intro  = '' !== $atts['intro'] ? $atts['intro'] : ( $pt ? 'Notícias e aprendizagem financeira, sem jargão. Podes cancelar quando quiseres.' : 'Financial news and learning, jargon-free. Unsubscribe anytime.' );
		$email  = $pt ? 'O teu email' : 'Your email';
		$send   = $pt ? 'Subscrever' : 'Subscribe';
		$cons   = $pt ? 'Aceito receber emails da HowToInvest e li a Política de Privacidade.' : 'I agree to receive emails from HowToInvest and have read the Privacy Policy.';
		$privacy = get_privacy_policy_url();
		if ( $privacy ) {
			$cons = $pt
				? sprintf( 'Aceito receber emails da HowToInvest e li a <a href="%s" target="_blank" rel="noopener">Política de Privacidade</a>.', esc_url( $privacy ) )
				: sprintf( 'I agree to receive emails from HowToInvest and have read the <a href="%s" target="_blank" rel="noopener">Privacy Policy</a>.', esc_url( $privacy ) );
		}

		ob_start();
		?>
		<form id="hti-subscribe-form" class="hti-subscribe" novalidate>
			<h2 class="hti-subscribe__title"><?php echo esc_html( $title ); ?></h2>
			<p class="hti-subscribe__intro"><?php echo esc_html( $intro ); ?></p>
			<div class="hti-subscribe__row">
				<label class="screen-reader-text" for="hti-subscribe-email"><?php echo esc_html( $email ); ?></label>
				<input class="hti-subscribe__input" type="email" id="hti-subscribe-email" name="email" placeholder="<?php echo esc_attr( $email ); ?>" autocomplete="email" required>
				<button class="hti-subscribe__submit" type="submit"><?php echo esc_html( $send ); ?></button>
			</div>
			<p class="hti-subscribe__trap" aria-hidden="true">
				<label for="hti-subscribe-hp"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
				<input type="text" id="hti-subscribe-hp" name="hti_hp" tabindex="-1" autocomplete="off">
			</p>
			<p class="hti-subscribe__consent">
				<input type="checkbox" id="hti-subscribe-consent" name="consent" value="1" required>
				<label for="hti-subscribe-consent"><?php echo wp_kses( $cons, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
			</p>
			<p class="hti-subscribe__status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- REST: request the double opt-in email ---------- */

	/**
	 * POST /subscribe — validate and email a double opt-in confirmation.
	 * Neutral response (never reveals whether the email is already subscribed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function request_optin( \WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'subscribe' ) ) {
			return new \WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment.', 'hti-engine' ), array( 'status' => 429 ) );
		}
		if ( '' !== trim( (string) $request->get_param( 'hti_hp' ) ) ) {
			return new \WP_REST_Response( array( 'sent' => true ), 200 ); // Honeypot.
		}
		if ( true !== rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return new \WP_Error( 'hti_no_consent', __( 'Please agree to receive the emails to subscribe.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$locale = str_starts_with( strtolower( (string) $request->get_param( 'locale' ) ), 'pt' ) ? 'pt' : 'en';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'hti_invalid_email', __( 'Please enter a valid email.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		self::send_optin_email( $email, $locale );
		return new \WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/* ---------- confirm / unsubscribe links ---------- */

	/**
	 * Handle the opt-in confirmation and unsubscribe links.
	 */
	public static function handle_link(): void {
		$action = isset( $_GET['hti_sub'] ) ? sanitize_key( wp_unslash( $_GET['hti_sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
		if ( 'optin' !== $action && 'unsub' !== $action ) {
			return;
		}
		$email  = isset( $_GET['e'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['e'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = ( isset( $_GET['l'] ) && 'pt' === $_GET['l'] ) ? 'pt' : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_email( $email ) || ! self::token_valid( $email, $action, $token ) ) {
			self::redirect_result( 'error', $locale );
		}

		if ( 'optin' === $action ) {
			$ok = Brevo::upsert_contact(
				$email,
				array( 'LANGUAGE' => strtoupper( $locale ), 'OPTIN_AT' => gmdate( 'Y-m-d' ) ),
				array_filter( array( Brevo::list_id( $locale ) ) )
			);
			if ( $ok ) {
				self::send_confirmed_email( $email, $locale );
			}
			self::redirect_result( $ok ? 'confirmed' : 'error', $locale );
		}

		// Unsubscribe from the language list they subscribed via.
		$ok = Brevo::remove_from_list( $email, Brevo::list_id( $locale ) );
		self::redirect_result( $ok ? 'unsubscribed' : 'error', $locale );
	}

	/**
	 * Redirect home with a flag the footer toast renders, then stop.
	 *
	 * @param string $state  Result state.
	 * @param string $locale Locale.
	 */
	private static function redirect_result( string $state, string $locale ): void {
		wp_safe_redirect( add_query_arg( array( 'hti_sub_done' => $state, 'l' => $locale ), home_url( '/' ) ) );
		exit;
	}

	/**
	 * Render a small fixed toast after a confirm/unsubscribe redirect.
	 */
	public static function result_toast(): void {
		$state = isset( $_GET['hti_sub_done'] ) ? sanitize_key( wp_unslash( $_GET['hti_sub_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $state ) {
			return;
		}
		$pt  = isset( $_GET['l'] ) && 'pt' === $_GET['l']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'confirmed'    => $pt ? 'Subscrição confirmada. Bem-vindo!' : 'Subscription confirmed. Welcome aboard!',
			'unsubscribed' => $pt ? 'Subscrição cancelada. Não voltarás a receber estes emails.' : 'You’ve been unsubscribed. You won’t receive these emails again.',
			'error'        => $pt ? 'Esse link é inválido ou expirou.' : 'That link is invalid or has expired.',
		);
		$msg = $map[ $state ] ?? '';
		if ( '' === $msg ) {
			return;
		}
		$bg = 'error' === $state ? '#C0392B' : '#147A57';
		printf(
			'<div role="status" style="position:fixed;left:50%%;bottom:24px;transform:translateX(-50%%);z-index:9999;max-width:90vw;background:%s;color:#fff;font:600 14px system-ui,Arial,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 6px 24px rgba(0,0,0,.18);">%s</div>',
			esc_attr( $bg ),
			esc_html( $msg )
		);
	}

	/* ---------- emails ---------- */

	/**
	 * Send the branded double opt-in confirmation email.
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_optin_email( string $email, string $locale ): void {
		$pt   = 'pt' === $locale;
		$url  = self::link( 'optin', $email, $locale );

		$subject = $pt ? 'Confirma a tua subscrição — HowToInvest' : 'Confirm your subscription — HowToInvest';
		$heading = $pt ? 'Confirma a tua subscrição' : 'Confirm your subscription';
		$lead    = $pt
			? 'Falta só um passo. Confirma que queres receber as novidades da HowToInvest no botão abaixo.'
			: 'Just one step left. Confirm you want to receive HowToInvest updates using the button below.';
		$btn     = $pt ? 'Confirmar subscrição' : 'Confirm subscription';
		$note    = $pt
			? 'Se não foste tu a pedir isto, podes ignorar este email com segurança.'
			: "If you didn't request this, you can safely ignore this email.";

		$inner = Emails::row(
			Emails::icon_circle( '&#9993;', '#EAF6F0', '#147A57' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $btn, $url ), '28px 48px 6px', true )
			. Emails::row( Emails::url_fallback( $url, $locale ), '18px 48px 8px' )
			. Emails::row( Emails::note( $note ), '18px 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}

	/**
	 * Send the "you're subscribed" welcome email (with an unsubscribe link).
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_confirmed_email( string $email, string $locale ): void {
		$pt    = 'pt' === $locale;
		$unsub = self::link( 'unsub', $email, $locale );

		$subject = $pt ? 'Estás subscrito — HowToInvest' : "You're subscribed — HowToInvest";
		$heading = $pt ? 'Estás dentro!' : 'You’re in!';
		$lead    = $pt
			? 'Obrigado por confirmares. A partir de agora vais receber as nossas novidades e aprendizagem financeira, sem jargão.'
			: 'Thanks for confirming. From now on you’ll receive our updates and jargon-free financial learning.';
		$unlabel = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = Emails::row(
			Emails::icon_circle( '&#10003;', '#EAF6F0', '#147A57' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $pt ? 'Explorar a plataforma' : 'Explore the platform', home_url( $pt ? '/pt/' : '/' ) ), '28px 48px 8px', true )
			. Emails::row( '<a href="' . esc_url( $unsub ) . '" style="font:400 12.5px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unlabel ) . '</a>', '8px 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}
}
