<?php
/**
 * Shared, brand-consistent HTML email layout + transactional senders that
 * aren't tied to a single feature (e.g. WordPress's password-reset email).
 *
 * Every email is table-based with inline styles for broad client support, and
 * matches the design system: navy header, accent CTA, educational-disclaimer
 * footer. Bilingual (EN default + PT) driven by the caller's locale.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Branded email layout and shared transactional emails.
 */
class Emails {

	/**
	 * Hook the emails this class owns (WordPress password reset).
	 */
	public static function init(): void {
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'reset_email' ), 10, 4 );
	}

	/**
	 * Wrap inner rows in the full branded shell (header + footer + doc).
	 *
	 * @param string $locale     'en' or 'pt'.
	 * @param string $inner_rows One or more <tr>…</tr> rows for the body table.
	 * @param string $preheader  Optional hidden preview text.
	 */
	public static function layout( string $locale, string $inner_rows, string $preheader = '' ): string {
		$pre = '' !== $preheader
			? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( $preheader ) . '</div>'
			: '';

		return '<!DOCTYPE html><html lang="' . esc_attr( $locale ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#F1F0F6;">'
			. $pre
			. '<table role="presentation" width="100%" style="border-collapse:collapse;background:#F1F0F6;"><tbody><tr><td style="padding:24px 12px;">'
			. '<table role="presentation" align="center" width="600" style="border-collapse:collapse;width:600px;max-width:100%;margin:0 auto;background:#FFFFFF;border-radius:8px;overflow:hidden;"><tbody>'
			. self::header_row()
			. $inner_rows
			. self::footer_row( $locale )
			. '</tbody></table>'
			. '</td></tr></tbody></table></body></html>';
	}

	/**
	 * Navy header with the wordmark.
	 */
	private static function header_row(): string {
		return '<tr><td style="background:#1E2147;padding:24px 36px;font:700 21px Poppins,Arial,sans-serif;color:#fff;letter-spacing:-.01em;">HowToInvest</td></tr>';
	}

	/**
	 * Footer with the educational disclaimer + copyright.
	 *
	 * @param string $locale Locale.
	 */
	private static function footer_row( string $locale ): string {
		$disc = 'pt' === $locale
			? 'Conteúdo educativo sobre literacia financeira. Não é aconselhamento financeiro, de investimento, fiscal ou jurídico. Investir envolve risco, incluindo a perda de capital.'
			: 'Educational content about financial literacy. Not financial, investment, tax or legal advice. Investing involves risk, including loss of capital.';

		return '<tr><td style="background:#14162E;padding:30px 40px;text-align:center;">'
			. '<p style="margin:0 auto;max-width:52ch;font:400 11.5px Arial,sans-serif;color:#6E72A0;line-height:1.55;">' . esc_html( $disc ) . '</p>'
			. '<div style="margin-top:14px;font:400 11.5px Arial,sans-serif;color:#5B5F86;">&copy; 2026 HowToInvest &middot; howtoinvest.pro</div>'
			. '</td></tr>';
	}

	/* ---------- reusable building blocks ---------- */

	/**
	 * A coloured circle holding a glyph (e.g. a check or key), centered.
	 *
	 * @param string $glyph HTML entity / character.
	 * @param string $bg    Background colour.
	 * @param string $fg    Glyph colour.
	 */
	public static function icon_circle( string $glyph, string $bg, string $fg ): string {
		return '<div style="width:72px;height:72px;line-height:72px;margin:0 auto;border-radius:999px;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';font:700 32px Arial,sans-serif;text-align:center;">' . $glyph . '</div>';
	}

	/**
	 * An accent pill button.
	 *
	 * @param string $label Button text.
	 * @param string $url   Destination.
	 */
	public static function button( string $label, string $url ): string {
		return '<table role="presentation" style="border-collapse:collapse;margin:0 auto;"><tbody><tr>'
			. '<td style="background:#FF6B5E;border-radius:999px;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:15px 40px;font:700 15px Arial,sans-serif;color:#fff;text-decoration:none;">' . esc_html( $label ) . '</a>'
			. '</td></tr></tbody></table>';
	}

	/**
	 * A centered H1.
	 *
	 * @param string $text Heading.
	 */
	public static function h1( string $text ): string {
		return '<h1 style="margin:24px 0 0;font:800 28px Poppins,Arial,sans-serif;line-height:1.15;letter-spacing:-.02em;color:#1E2147;">' . esc_html( $text ) . '</h1>';
	}

	/**
	 * A centered lead paragraph (allows a few safe inline tags).
	 *
	 * @param string $html Lead HTML (already escaped/built by the caller).
	 */
	public static function lead( string $html ): string {
		return '<p style="margin:14px auto 0;max-width:44ch;font:400 16px Arial,sans-serif;color:#5C5670;line-height:1.6;">' . $html . '</p>';
	}

	/**
	 * A small muted note paragraph.
	 *
	 * @param string $text Note text.
	 */
	public static function note( string $text ): string {
		return '<p style="margin:0 auto;max-width:48ch;font:400 12.5px Arial,sans-serif;color:#9A93A8;line-height:1.55;">' . esc_html( $text ) . '</p>';
	}

	/**
	 * An uppercase eyebrow/badge label.
	 *
	 * @param string $text  Label.
	 * @param string $color Colour.
	 */
	public static function eyebrow( string $text, string $color = '#FF6B5E' ): string {
		return '<div style="font:700 12px Arial,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:' . esc_attr( $color ) . ';margin-bottom:14px;">' . esc_html( $text ) . '</div>';
	}

	/**
	 * The "if the button doesn't work, copy this URL" fallback box.
	 *
	 * @param string $url URL to show.
	 * @param string $locale Locale.
	 */
	public static function url_fallback( string $url, string $locale ): string {
		$label = 'pt' === $locale
			? 'Se o botão não funcionar, copia e cola este endereço no teu navegador:'
			: 'If the button doesn’t work, copy and paste this address into your browser:';
		return '<div style="background:#F6F4FB;border-radius:12px;padding:16px 20px;font:400 12.5px Arial,sans-serif;color:#8A839A;line-height:1.55;text-align:center;">'
			. esc_html( $label ) . '<br><span style="color:#7C5CFC;font-weight:600;word-break:break-all;">' . esc_html( $url ) . '</span></div>';
	}

	/**
	 * Convenience: a full-width body row with padding.
	 *
	 * @param string $html Cell HTML.
	 * @param string $pad  CSS padding.
	 * @param bool   $center Center the text.
	 */
	public static function row( string $html, string $pad, bool $center = false ): string {
		$align = $center ? 'text-align:center;' : '';
		return '<tr><td style="padding:' . esc_attr( $pad ) . ';' . $align . '">' . $html . '</td></tr>';
	}

	/* ---------- WordPress password reset (template 05) ---------- */

	/**
	 * Brand the core password-reset email (HTML, bilingual by user locale).
	 *
	 * @param array<string,mixed> $defaults    wp_mail args (to/subject/message/headers).
	 * @param string              $key         Reset key.
	 * @param string              $user_login  Login.
	 * @param \WP_User|false      $user_data   User.
	 * @return array<string,mixed>
	 */
	public static function reset_email( array $defaults, string $key, string $user_login, $user_data ): array {
		$locale = 'en';
		if ( $user_data instanceof \WP_User ) {
			$locale = str_starts_with( strtolower( (string) get_user_locale( $user_data->ID ) ), 'pt' ) ? 'pt' : 'en';
		}
		$pt = 'pt' === $locale;

		$url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ), 'login' );

		$heading = $pt ? 'Repor a tua password' : 'Reset your password';
		$lead    = $pt
			? 'Recebemos um pedido para repor a password da conta associada a este email. Clica em baixo para escolher uma nova.'
			: 'We received a request to reset the password for the account linked to this email. Click below to choose a new one.';
		$btn     = $pt ? 'Repor password' : 'Reset password';
		$note    = $pt
			? 'Este link é válido durante 60 minutos. Se não fizeste este pedido, ignora este email — a tua password atual continua segura.'
			: 'This link is valid for 60 minutes. If you didn’t request this, ignore this email — your current password stays safe.';

		$inner = self::row(
			self::icon_circle( '&#128273;', '#EFEBFF', '#7C5CFC' ) . self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. self::row( self::button( $btn, $url ), '28px 48px 6px', true )
			. self::row( self::note( $note ), '22px 48px 8px', true )
			. self::row( self::url_fallback( $url, $locale ), '18px 48px 44px' );

		$defaults['subject'] = $pt ? 'Repor a tua password — HowToInvest' : 'Reset your password — HowToInvest';
		$defaults['message'] = self::layout( $locale, $inner, $heading );
		$headers             = isset( $defaults['headers'] ) ? (array) $defaults['headers'] : array();
		$headers[]           = 'Content-Type: text/html; charset=UTF-8';
		$defaults['headers'] = $headers;

		return $defaults;
	}
}
