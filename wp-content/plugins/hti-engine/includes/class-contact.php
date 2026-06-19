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
				'sending' => 'A enviar…',
				'sent'    => 'Obrigado! A tua mensagem foi enviada. Respondemos em breve.',
				'invalid' => 'Preenche o nome, um email válido e a mensagem.',
				'consent' => 'Para continuares, aceita o tratamento dos teus dados.',
				'error'   => 'Não foi possível enviar a mensagem. Tenta novamente ou escreve-nos diretamente.',
				'rate'    => 'Demasiadas tentativas. Aguarda um momento e tenta novamente.',
			);
		}
		return array(
			'sending' => 'Sending…',
			'sent'    => 'Thank you! Your message has been sent. We’ll get back to you soon.',
			'invalid' => 'Please fill in your name, a valid email and a message.',
			'consent' => 'Please accept the processing of your data to continue.',
			'error'   => 'Your message could not be sent. Please try again or email us directly.',
			'rate'    => 'Too many attempts. Please wait a moment and try again.',
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
				'name'        => 'Nome',
				'email'       => 'Email',
				'subject'     => 'Assunto',
				'message'     => 'Mensagem',
				'send'        => 'Enviar mensagem',
				/* translators: %1$s/%2$s wrap the Privacy Policy link. */
				'consent'     => 'Concordo que os meus dados sejam usados para responder ao meu pedido, de acordo com a %1$sPolítica de Privacidade%2$s.',
				'noscript'    => 'Para usar este formulário, ativa o JavaScript — ou escreve-nos diretamente para',
			);
		}
		return array(
			'name'        => 'Name',
			'email'       => 'Email',
			'subject'     => 'Subject',
			'message'     => 'Message',
			'send'        => 'Send message',
			/* translators: %1$s/%2$s wrap the Privacy Policy link. */
			'consent'     => 'I agree that my details will be used to respond to my enquiry, in line with the %1$sPrivacy Policy%2$s.',
			'noscript'    => 'To use this form, enable JavaScript — or email us directly at',
		);
	}

	/**
	 * `[hti_contact]` output: an accessible form. Works as plain HTML; the
	 * script enhances it with inline status and no page reload.
	 */
	public static function render(): string {
		$pt      = 'pt' === self::locale();
		$l       = self::labels( $pt );
		$to      = self::recipient();
		$to_esc  = esc_html( $to );
		$privacy = get_privacy_policy_url();
		$consent = $privacy
			? sprintf(
				$l['consent'],
				'<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">',
				'</a>'
			)
			: sprintf( $l['consent'], '', '' );

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
				<label class="hti-contact__label" for="hti-contact-subject"><?php echo esc_html( $l['subject'] ); ?></label>
				<input class="hti-contact__input" type="text" id="hti-contact-subject" name="subject" required>
			</p>
			<p class="hti-contact__field">
				<label class="hti-contact__label" for="hti-contact-message"><?php echo esc_html( $l['message'] ); ?></label>
				<textarea class="hti-contact__input" id="hti-contact-message" name="message" rows="6" required></textarea>
			</p>
			<?php // Honeypot: a non-semantic name so browser autofill never fills it; bots that do are dropped. ?>
			<p class="hti-contact__trap" aria-hidden="true">
				<label for="hti-contact-hp"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
				<input type="text" id="hti-contact-hp" name="hti_hp" tabindex="-1" autocomplete="off">
			</p>
			<p class="hti-contact__consent">
				<input class="hti-contact__checkbox" type="checkbox" id="hti-contact-consent" name="consent" value="1" required>
				<label for="hti-contact-consent"><?php echo wp_kses( $consent, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
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
	 * Build and send the team notification email. Returns whether it was accepted.
	 *
	 * @param string $name    Visitor name.
	 * @param string $email   Visitor email (used as Reply-To).
	 * @param string $subject Visitor's subject line.
	 * @param string $message Message body (plain text).
	 */
	public static function deliver( string $name, string $email, string $subject, string $message ): bool {
		$mail_subject = sprintf(
			/* translators: 1: subject, 2: sender name. */
			__( '[Contact] %1$s — from %2$s', 'hti-engine' ),
			'' !== $subject ? $subject : __( '(no subject)', 'hti-engine' ),
			$name
		);

		$html = '<p><strong>' . esc_html__( 'Name', 'hti-engine' ) . ':</strong> ' . esc_html( $name ) . '</p>'
			. '<p><strong>' . esc_html__( 'Email', 'hti-engine' ) . ':</strong> ' . esc_html( $email ) . '</p>'
			. '<p><strong>' . esc_html__( 'Subject', 'hti-engine' ) . ':</strong> ' . esc_html( $subject ) . '</p>'
			. '<p><strong>' . esc_html__( 'Message', 'hti-engine' ) . ':</strong></p>'
			. '<p>' . nl2br( esc_html( $message ) ) . '</p>'
			. '<hr><p style="color:#888;font-size:12px">'
			. esc_html__( 'Sent from the HowToInvest contact form. The sender consented to data processing.', 'hti-engine' ) . '</p>';

		return Mailer::send( self::recipient(), $mail_subject, $html, $email );
	}

	/**
	 * Send the branded auto-reply to the visitor, in their language. Best-effort
	 * (a failure here never fails the contact submission).
	 *
	 * @param string $name    Visitor name.
	 * @param string $email   Visitor email (recipient).
	 * @param string $subject Visitor's subject line.
	 * @param string $locale  'en' or 'pt' (from the request URL).
	 */
	public static function auto_reply( string $name, string $email, string $subject, string $locale ): bool {
		$pt = 'pt' === $locale;

		$t = $pt
			? array(
				'subject'  => 'Recebemos a tua mensagem — HowToInvest',
				'heading'  => 'Recebemos a tua mensagem',
				'greeting' => sprintf( 'Olá %s, obrigado por nos contactares. A tua mensagem chegou à nossa equipa e vamos responder o mais rápido possível.', $name ),
				'subj_lbl' => 'Assunto',
				'eta_lbl'  => 'Resposta prevista',
				'eta_val'  => 'até 24 horas úteis',
				'note'     => 'Esta é uma resposta automática — não precisas de responder. Se for urgente, responde a este email.',
				'discl'    => 'Conteúdo educativo sobre literacia financeira. Não é aconselhamento financeiro, de investimento, fiscal ou jurídico. Investir envolve risco, incluindo a perda de capital.',
			)
			: array(
				'subject'  => 'We’ve received your message — HowToInvest',
				'heading'  => 'We’ve received your message',
				'greeting' => sprintf( 'Hi %s, thanks for reaching out. Your message has reached our team and we’ll get back to you as soon as we can.', $name ),
				'subj_lbl' => 'Subject',
				'eta_lbl'  => 'Expected reply',
				'eta_val'  => 'within 24 business hours',
				'note'     => 'This is an automatic reply — there’s no need to respond. If it’s urgent, just reply to this email.',
				'discl'    => 'Educational content about financial literacy. Not financial, investment, tax or legal advice. Investing involves risk, including loss of capital.',
			);

		$html = self::auto_reply_html( $t, $subject, $locale );

		// Reply-To the team so a visitor reply lands in the shared inbox.
		return Mailer::send( $email, $t['subject'], $html, self::recipient() );
	}

	/**
	 * Render the auto-reply HTML (table-based, inline styles for email clients),
	 * matching the brand: navy header, green confirmation, summary card, footer.
	 *
	 * @param array<string,string> $t       Localized strings.
	 * @param string               $subject Visitor's subject line.
	 */
	private static function auto_reply_html( array $t, string $subject, string $locale ): string {
		$rows = '';
		if ( '' !== $subject ) {
			$rows .= '<tr><td style="padding:18px 22px;border-bottom:1px solid #EBE6F4;">'
				. '<table role="presentation" width="100%" style="border-collapse:collapse;"><tbody><tr>'
				. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;">' . esc_html( $t['subj_lbl'] ) . '</td>'
				. '<td style="text-align:right;font:700 14px Arial,sans-serif;color:#1E2147;">' . esc_html( $subject ) . '</td>'
				. '</tr></tbody></table></td></tr>';
		}
		$rows .= '<tr><td style="padding:18px 22px;">'
			. '<table role="presentation" width="100%" style="border-collapse:collapse;"><tbody><tr>'
			. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;">' . esc_html( $t['eta_lbl'] ) . '</td>'
			. '<td style="text-align:right;font:700 14px Arial,sans-serif;color:#147A57;">' . esc_html( $t['eta_val'] ) . '</td>'
			. '</tr></tbody></table></td></tr>';

		$card = '<table role="presentation" width="100%" style="border-collapse:collapse;background:#F6F4FB;border-radius:14px;"><tbody>' . $rows . '</tbody></table>';

		$inner = Emails::row(
			Emails::icon_circle( '&#10003;', '#EAF6F0', '#147A57' ) . Emails::h1( $t['heading'] ) . Emails::lead( esc_html( $t['greeting'] ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( $card, '30px 48px 0' )
			. Emails::row( Emails::note( $t['note'] ), '26px 48px 44px', true );

		return Emails::layout( $locale, $inner, $t['heading'] );
	}
}
