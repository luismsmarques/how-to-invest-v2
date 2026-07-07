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
		// Prefer Polylang's current language (reliable on CPT archives); the bare
		// get_locale() can miss the page language, so the form would post 'en'.
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = (string) pll_current_language( 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) determine_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Register (don't enqueue) the assets; the shortcode enqueues on render so
	 * it works inside post content and block templates (e.g. archives) alike.
	 */
	public static function register_assets(): void {
		$locale = self::locale();
		wp_register_style( 'hti-subscribe', HTI_ENGINE_URL . 'assets/css/subscribe.css', array(), VERSION );
		wp_register_script( 'hti-subscribe', HTI_ENGINE_URL . 'assets/js/subscribe.js', array( 'hti-track' ), VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
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

		$atts   = shortcode_atts( array( 'title' => '', 'intro' => '', 'variant' => 'default' ), is_array( $atts ) ? $atts : array() );
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

		if ( 'digest' === $atts['variant'] ) {
			return self::render_digest( $pt, $cons );
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

	/**
	 * Coral "daily roundup" banner variant (matches the news-hub design).
	 *
	 * @param bool   $pt   Whether Portuguese.
	 * @param string $cons Consent label HTML (with the privacy link).
	 */
	private static function render_digest( bool $pt, string $cons ): string {
		$badge = $pt ? 'Diário · 7h' : 'Daily · 7am';
		$title = $pt ? 'O resumo do dia, nas finanças.' : 'The day’s roundup, in finance.';
		$intro = $pt
			? 'Todas as manhãs, um email curto e calmo com o que aconteceu no mundo das finanças — e o que significa para ti. Sem ruído, sem jargão.'
			: 'Every morning, a short, calm email on what happened in finance — and what it means for you. No noise, no jargon.';
		$ph   = $pt ? 'o-teu-email@exemplo.pt' : 'you@example.com';
		$send = $pt ? 'Subscrever o resumo diário' : 'Subscribe to the daily roundup';
		$fine = $pt ? 'Grátis. Cancelas quando quiseres, num clique.' : 'Free. Unsubscribe anytime, in one click.';
		$lbl  = $pt ? 'O teu email para o resumo diário' : 'Your email for the daily roundup';

		ob_start();
		?>
		<form id="hti-subscribe-form" class="hti-subscribe hti-subscribe--digest" novalidate>
			<div class="hti-digest__text">
				<span class="hti-digest__badge"><span class="hti-digest__dot"></span><?php echo esc_html( $badge ); ?></span>
				<h2 class="hti-digest__title"><?php echo esc_html( $title ); ?></h2>
				<p class="hti-digest__intro"><?php echo esc_html( $intro ); ?></p>
			</div>
			<div class="hti-digest__form">
				<label class="screen-reader-text" for="hti-subscribe-email"><?php echo esc_html( $lbl ); ?></label>
				<input class="hti-digest__input" type="email" id="hti-subscribe-email" name="email" placeholder="<?php echo esc_attr( $ph ); ?>" autocomplete="email" required>
				<button class="hti-digest__submit" type="submit"><?php echo esc_html( $send ); ?></button>
				<p class="hti-subscribe__trap" aria-hidden="true">
					<label for="hti-subscribe-hp"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
					<input type="text" id="hti-subscribe-hp" name="hti_hp" tabindex="-1" autocomplete="off">
				</p>
				<p class="hti-digest__consent">
					<input type="checkbox" id="hti-subscribe-consent" name="consent" value="1" required>
					<label for="hti-subscribe-consent"><?php echo wp_kses( $cons, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
				</p>
				<p class="hti-digest__fine"><?php echo esc_html( $fine ); ?></p>
				<p class="hti-subscribe__status" role="status" aria-live="polite"></p>
			</div>
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

		// Lead magnet: when the form is the ebook gate, remember that intent so
		// the ebook is delivered *after* the newsletter opt-in is confirmed (the
		// PDF is gated behind the double opt-in). The source is set by the ebook
		// form ("ebook-…").
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( str_starts_with( $source, 'ebook' ) ) {
			self::ebook_pending_set( $email );
		}

		return new \WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/**
	 * Durable option holding "these emails are owed the ebook" as
	 * hash => expiry. Kept in the DB (not a transient) so a persistent object
	 * cache under memory pressure can't evict the flag between opt-in and
	 * confirmation and silently downgrade the subscriber to the plain welcome.
	 */
	private const EBOOK_PENDING_OPTION = 'hti_ebook_pending';

	/**
	 * Per-email hash used as the durable-store key.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function ebook_pending_hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * Flag that this email is awaiting the ebook (set on the ebook gate).
	 * Prunes expired entries on write so the option stays small.
	 *
	 * @param string $email Email.
	 */
	private static function ebook_pending_set( string $email ): void {
		$store = get_option( self::EBOOK_PENDING_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$now   = time();
		foreach ( $store as $h => $exp ) {
			if ( (int) $exp < $now ) {
				unset( $store[ $h ] );
			}
		}
		$store[ self::ebook_pending_hash( $email ) ] = $now + WEEK_IN_SECONDS;
		update_option( self::EBOOK_PENDING_OPTION, $store, false );
	}

	/**
	 * Consume the pending-ebook flag: returns whether it was set (and still
	 * valid), removing it either way.
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	private static function ebook_pending_take( string $email ): bool {
		$store = get_option( self::EBOOK_PENDING_OPTION, array() );
		if ( ! is_array( $store ) ) {
			return false;
		}
		$hash  = self::ebook_pending_hash( $email );
		$valid = isset( $store[ $hash ] ) && (int) $store[ $hash ] >= time();
		if ( isset( $store[ $hash ] ) ) {
			unset( $store[ $hash ] );
			update_option( self::EBOOK_PENDING_OPTION, $store, false );
		}
		return $valid;
	}

	/**
	 * Public URL of the ebook PDF for a locale. Themes/plugins can override via
	 * the `hti_ebook_url` filter; defaults to the file bundled in the theme.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function ebook_url( string $locale ): string {
		$file    = 'pt' === $locale ? 'howtoinvest-como-comecar-a-investir.pdf' : 'howtoinvest-how-to-start-investing.pdf';
		$default = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/ebook/' . $file ) : '';
		return (string) apply_filters( 'hti_ebook_url', $default, $locale );
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
				// Now that the subscription is confirmed, deliver the ebook to
				// those who came via the lead-magnet gate; everyone else gets the
				// plain welcome.
				if ( self::ebook_pending_take( $email ) ) {
					self::send_ebook_email( $email, $locale );
				} else {
					self::send_confirmed_email( $email, $locale );
				}
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

		// Report the (no-JS) confirm / unsubscribe outcome to analytics, once the
		// tracking helper has loaded (deferred scripts run before DOMContentLoaded).
		$event = array(
			'confirmed'    => 'newsletter_confirmed',
			'unsubscribed' => 'newsletter_unsubscribe',
		)[ $state ] ?? '';
		if ( '' !== $event ) {
			printf(
				'<script>document.addEventListener("DOMContentLoaded",function(){if(window.HTITrack){window.HTITrack.event(%s);}});</script>',
				wp_json_encode( $event )
			);
		}
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
	 * Deliver the ebook: a branded email with the download button (and the
	 * other language as a secondary link). Sent on the ebook lead-magnet gate.
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_ebook_email( string $email, string $locale ): void {
		$pt  = 'pt' === $locale;
		$url = self::ebook_url( $locale );
		if ( '' === $url ) {
			return;
		}
		$other_locale = $pt ? 'en' : 'pt';
		$other_url    = self::ebook_url( $other_locale );

		$subject = $pt ? 'O teu ebook chegou — Como começar a investir' : 'Your ebook is here — How to start investing';
		$heading = $pt ? 'O teu ebook está pronto' : 'Your ebook is ready';
		$lead    = $pt
			? 'Aqui tens o guia “Como começar a investir” — as bases reunidas num só sítio, sem produtos e sem promessas. Carrega no botão para o descarregar (PDF).'
			: 'Here is your guide “How to start investing” — the essentials in one place, with no products and no promises. Use the button to download it (PDF).';
		$btn   = $pt ? 'Descarregar o ebook (PDF)' : 'Download the ebook (PDF)';
		$other = $pt
			? '<a href="' . esc_url( $other_url ) . '" style="font:400 13px Arial,sans-serif;color:#7C5CFC;">Prefere a versão em inglês? Descarrega aqui.</a>'
			: '<a href="' . esc_url( $other_url ) . '" style="font:400 13px Arial,sans-serif;color:#7C5CFC;">Prefer the Portuguese version? Download here.</a>';
		// This email doubles as the post-confirmation welcome, so it carries the
		// disclaimer + an unsubscribe link.
		$note    = $pt
			? 'Conteúdo educativo, não constitui aconselhamento financeiro. Exemplos só por classe de ativos.'
			: 'Educational content, not financial advice. Examples by asset class only.';
		$unsub   = self::link( 'unsub', $email, $locale );
		$unlabel = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = Emails::row(
			Emails::icon_circle( '&#128214;', '#EFE9FE', '#6A4BE0' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $btn, $url ), '28px 48px 6px', true )
			. Emails::row( $other, '14px 48px 8px', true )
			. Emails::row( Emails::note( $note ), '18px 48px 10px', true )
			. Emails::row( '<a href="' . esc_url( $unsub ) . '" style="font:400 12.5px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unlabel ) . '</a>', '0 48px 44px', true );

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
