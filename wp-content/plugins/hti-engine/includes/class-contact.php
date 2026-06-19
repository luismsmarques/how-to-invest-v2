<?php
/**
 * Contact form: the `[hti_contact]` shortcode + email delivery.
 *
 * Renders a small, accessible, server-rendered form (progressively enhanced by
 * contact.js) that posts to `/wp-json/htinvest/v1/contact`. Messages are
 * emailed to the configured recipient (default info@howtoinvest.pro) via the
 * Brevo-backed Mailer, with the visitor set as Reply-To. The form is anonymous
 * (no data retained), nonce-protected and rate-limited.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The public contact form.
 */
class Contact {

	private const SHORTCODE = 'hti_contact';

	/**
	 * Hook the shortcode and its assets.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Recipient of contact messages. Constant/option override, else default.
	 */
	public static function recipient(): string {
		$email = 'info@howtoinvest.pro';
		if ( defined( 'HTI_CONTACT_EMAIL' ) && is_string( HTI_CONTACT_EMAIL ) && is_email( HTI_CONTACT_EMAIL ) ) {
			$email = HTI_CONTACT_EMAIL;
		} elseif ( function_exists( 'get_option' ) ) {
			$settings = get_option( 'htinvest_settings' );
			if ( is_array( $settings ) && ! empty( $settings['contact_email'] ) && is_email( (string) $settings['contact_email'] ) ) {
				$email = (string) $settings['contact_email'];
			}
		}
		/**
		 * Filter the contact-form recipient address.
		 *
		 * @param string $email Recipient email.
		 */
		return (string) apply_filters( 'hti_contact_recipient', $email );
	}

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Whether the current singular view contains the shortcode.
	 */
	private static function is_contact_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the form script/styles only on the contact page.
	 */
	public static function enqueue(): void {
		if ( ! self::is_contact_page() ) {
			return;
		}
		$locale = self::locale();

		wp_enqueue_style( 'hti-contact', HTI_ENGINE_URL . 'assets/css/contact.css', array(), VERSION );

		wp_register_script(
			'hti-contact',
			HTI_ENGINE_URL . 'assets/js/contact.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'hti-contact',
			'HTI_CONTACT',
			array(
				'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/contact' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => $locale,
				'strings' => self::strings( 'pt' === $locale ),
			)
		);
		wp_enqueue_script( 'hti-contact' );
	}

	/**
	 * Front-end strings (status messages).
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,string>
	 */
	private static function strings( bool $pt ): array {
		if ( $pt ) {
			return array(
				'sending'    => 'A enviar…',
				'sent'       => 'Obrigado! A tua mensagem foi enviada. Respondemos em breve.',
				'invalid'    => 'Preenche o nome, um email válido e a mensagem.',
				'error'      => 'Não foi possível enviar a mensagem. Tenta novamente ou escreve-nos diretamente.',
				'rate'       => 'Demasiadas tentativas. Aguarda um momento e tenta novamente.',
			);
		}
		return array(
			'sending'    => 'Sending…',
			'sent'       => 'Thank you! Your message has been sent. We’ll get back to you soon.',
			'invalid'    => 'Please fill in your name, a valid email and a message.',
			'error'      => 'Your message could not be sent. Please try again or email us directly.',
			'rate'       => 'Too many attempts. Please wait a moment and try again.',
		);
	}

	/**
	 * Labels for the server-rendered form, by language.
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,string>
	 */
	private static function labels( bool $pt ): array {
		if ( $pt ) {
			return array(
				'name'     => 'Nome',
				'email'    => 'Email',
				'message'  => 'Mensagem',
				'send'     => 'Enviar mensagem',
				'noscript' => 'Para usar este formulário, ativa o JavaScript — ou escreve-nos diretamente para',
			);
		}
		return array(
			'name'     => 'Name',
			'email'    => 'Email',
			'message'  => 'Message',
			'send'     => 'Send message',
			'noscript' => 'To use this form, enable JavaScript — or email us directly at',
		);
	}

	/**
	 * `[hti_contact]` output: an accessible form. Works as plain HTML; the
	 * script enhances it with inline status and no page reload.
	 */
	public static function render(): string {
		$pt     = 'pt' === self::locale();
		$l      = self::labels( $pt );
		$to     = self::recipient();
		$to_esc = esc_html( $to );

		ob_start();
		?>
		<form id="hti-contact-form" class="hti-contact" novalidate>
			<p class="hti-contact__field">
				<label class="hti-contact__label" for="hti-contact-name"><?php echo esc_html( $l['name'] ); ?></label>
				<input class="hti-contact__input" type="text" id="hti-contact-name" name="name" autocomplete="name" required>
			</p>
			<p class="hti-contact__field">
				<label class="hti-contact__label" for="hti-contact-email"><?php echo esc_html( $l['email'] ); ?></label>
				<input class="hti-contact__input" type="email" id="hti-contact-email" name="email" autocomplete="email" required>
			</p>
			<p class="hti-contact__field">
				<label class="hti-contact__label" for="hti-contact-message"><?php echo esc_html( $l['message'] ); ?></label>
				<textarea class="hti-contact__input" id="hti-contact-message" name="message" rows="6" required></textarea>
			</p>
			<?php // Honeypot: hidden from humans; bots that fill it are dropped. ?>
			<p class="hti-contact__trap" aria-hidden="true">
				<label for="hti-contact-company">Company</label>
				<input type="text" id="hti-contact-company" name="company" tabindex="-1" autocomplete="off">
			</p>
			<p class="hti-contact__actions">
				<button class="hti-contact__submit" type="submit"><?php echo esc_html( $l['send'] ); ?></button>
			</p>
			<p class="hti-contact__status" role="status" aria-live="polite"></p>
		</form>
		<noscript>
			<p class="hti-contact__noscript"><?php echo esc_html( $l['noscript'] ); ?>
				<a href="mailto:<?php echo esc_attr( $to ); ?>"><?php echo $to_esc; ?></a>.</p>
		</noscript>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build and send the contact email. Returns whether it was accepted.
	 *
	 * @param string $name    Visitor name.
	 * @param string $email   Visitor email (used as Reply-To).
	 * @param string $message Message body (plain text).
	 */
	public static function deliver( string $name, string $email, string $message ): bool {
		$subject = sprintf(
			/* translators: %s: sender name. */
			__( 'New contact message from %s', 'hti-engine' ),
			$name
		);

		$html = '<p><strong>' . esc_html__( 'Name', 'hti-engine' ) . ':</strong> ' . esc_html( $name ) . '</p>'
			. '<p><strong>' . esc_html__( 'Email', 'hti-engine' ) . ':</strong> ' . esc_html( $email ) . '</p>'
			. '<p><strong>' . esc_html__( 'Message', 'hti-engine' ) . ':</strong></p>'
			. '<p>' . nl2br( esc_html( $message ) ) . '</p>'
			. '<hr><p style="color:#888;font-size:12px">'
			. esc_html__( 'Sent from the HowToInvest contact form.', 'hti-engine' ) . '</p>';

		return Mailer::send( self::recipient(), $subject, $html, $email );
	}
}
