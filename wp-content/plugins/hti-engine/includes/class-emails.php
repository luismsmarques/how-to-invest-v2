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

	/* ---------- newsletter / digest (templates 03 & 06) ---------- */

	/**
	 * Render a campaign email (weekly newsletter / daily digest) from a list of
	 * articles, with a Brevo unsubscribe link in the body.
	 *
	 * @param string                                                     $locale 'en'|'pt'.
	 * @param string                                                     $title  Big title.
	 * @param string                                                     $intro  Lead text.
	 * @param array<int,array{title:string,url:string,excerpt:string}>   $items  Articles.
	 * @param string                                                     $cta_url "See all" URL.
	 */
	public static function campaign( string $locale, string $title, string $intro, array $items, string $cta_url ): string {
		$pt = 'pt' === $locale;

		$cards = '';
		foreach ( $items as $item ) {
			$url   = (string) ( $item['url'] ?? '' );
			$cards .= '<tr><td style="padding:0 0 18px;">'
				. '<a href="' . esc_url( $url ) . '" style="text-decoration:none;display:block;border:1px solid #EEEAF4;border-radius:14px;padding:18px 20px;">'
				. '<div style="font:700 17px Poppins,Arial,sans-serif;color:#1E2147;line-height:1.3;">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</div>'
				. ( '' !== (string) ( $item['excerpt'] ?? '' )
					? '<div style="margin-top:6px;font:400 14px Arial,sans-serif;color:#5C5670;line-height:1.55;">' . esc_html( (string) $item['excerpt'] ) . '</div>'
					: '' )
				. '<div style="margin-top:10px;font:700 13px Arial,sans-serif;color:#FF6B5E;">' . esc_html( $pt ? 'Ler →' : 'Read →' ) . '</div>'
				. '</a></td></tr>';
		}
		$list_html = '<table role="presentation" width="100%" style="border-collapse:collapse;"><tbody>' . $cards . '</tbody></table>';

		$see_all = $pt ? 'Ver todas as notícias' : 'See all the news';
		$unsub   = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = self::row(
			self::eyebrow( $pt ? 'HowToInvest' : 'HowToInvest', '#7C5CFC' )
				. '<h1 style="margin:0;font:800 28px Poppins,Arial,sans-serif;line-height:1.12;letter-spacing:-.02em;color:#1E2147;">' . esc_html( $title ) . '</h1>'
				. '<p style="margin:12px 0 0;font:400 15px Arial,sans-serif;color:#5C5670;line-height:1.6;">' . esc_html( $intro ) . '</p>',
			'40px 44px 8px'
		)
			. self::row( $list_html, '20px 44px 0' )
			. self::row( self::button( $see_all, $cta_url ), '14px 44px 8px', true )
			// Brevo replaces {{ unsubscribe }} with the per-recipient link.
			. self::row( '<a href="{{ unsubscribe }}" style="font:400 12px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unsub ) . '</a>', '10px 44px 40px', true );

		return self::layout( $locale, $inner, $intro );
	}

	/**
	 * Render a simple "platform notice" campaign (template 04).
	 *
	 * @param string $locale    Locale.
	 * @param string $title     Heading.
	 * @param string $body_html Body HTML (sanitized by the caller).
	 */
	public static function notice( string $locale, string $title, string $body_html ): string {
		$pt    = 'pt' === $locale;
		$unsub = $pt ? 'Cancelar subscrição' : 'Unsubscribe';
		$inner = self::row( self::h1( $title ), '44px 48px 6px', true )
			. self::row( '<div style="font:400 15px Arial,sans-serif;color:#4C4660;line-height:1.7;">' . $body_html . '</div>', '6px 48px 8px' )
			. self::row( '<a href="{{ unsubscribe }}" style="font:400 12px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unsub ) . '</a>', '18px 48px 40px', true );
		return self::layout( $locale, $inner, $title );
	}

	/* ---------- investor-profile email (template 07) ---------- */

	/**
	 * Render the investor-profile result email.
	 *
	 * @param string                                $locale     'en' or 'pt'.
	 * @param string                                $label      Archetype label.
	 * @param array<int,array{class:string,pct:int}> $allocation Allocation slices.
	 * @param string                                $cta_url    "Explore" CTA URL.
	 */
	public static function profile_email( string $locale, string $label, array $allocation, string $cta_url ): string {
		$pt = 'pt' === $locale;

		$class_names = $pt
			? array(
				'global_equity' => 'Ações globais',
				'bonds'         => 'Obrigações',
				'cash'          => 'Liquidez',
				'reits_alt'     => 'Imobiliário e alternativos',
				'crypto'        => 'Cripto',
			)
			: array(
				'global_equity' => 'Global equities',
				'bonds'         => 'Bonds',
				'cash'          => 'Cash',
				'reits_alt'     => 'Real estate & alternatives',
				'crypto'        => 'Crypto',
			);

		$rows = '';
		foreach ( $allocation as $slice ) {
			$key = (string) ( $slice['class'] ?? '' );
			$pct = (int) ( $slice['pct'] ?? 0 );
			if ( '' === $key ) {
				continue;
			}
			$rows .= '<tr>'
				. '<td style="padding:11px 0;border-bottom:1px solid #EEEAF4;font:600 14px Arial,sans-serif;color:#1E2147;">' . esc_html( $class_names[ $key ] ?? $key ) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid #EEEAF4;text-align:right;font:700 14px Arial,sans-serif;color:#5A3FD6;">' . $pct . '%</td>'
				. '</tr>';
		}
		$alloc_table = '<table role="presentation" width="100%" style="border-collapse:collapse;"><tbody>' . $rows . '</tbody></table>';

		$eyebrow  = $pt ? 'Questionário concluído' : 'Questionnaire complete';
		$heading  = $pt ? 'O teu perfil de investidor' : 'Your investor profile';
		$intro    = $pt ? 'Com base nas tuas respostas, o teu perfil é:' : 'Based on your answers, your profile is:';
		$badge     = '<div style="display:inline-block;background:#EFEBFF;border-radius:999px;padding:12px 30px;font:800 22px Poppins,Arial,sans-serif;color:#5A3FD6;letter-spacing:-.01em;">' . esc_html( $label ) . '</div>';
		$alloc_lbl = $pt ? 'Exemplo de estrutura por classe de ativos' : 'Example structure by asset class';
		$disc      = $pt
			? 'Exemplo ilustrativo e apenas por classe de ativos — nunca produtos específicos. Não é aconselhamento financeiro.'
			: 'Illustrative example, by asset class only — never specific products. This is not financial advice.';
		$btn       = $pt ? 'Explorar a plataforma' : 'Explore the platform';

		$inner = self::row(
			self::eyebrow( $eyebrow, '#7C5CFC' ) . self::h1( $heading ) . self::lead( esc_html( $intro ) ),
			'42px 48px 0',
			true
		)
			. self::row( $badge, '22px 48px 0', true )
			. self::row(
				'<div style="font:700 11.5px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#8A8676;margin-bottom:6px;">' . esc_html( $alloc_lbl ) . '</div>' . $alloc_table,
				'30px 48px 0'
			)
			. self::row( self::note( $disc ), '22px 48px 4px', true )
			. self::row( self::button( $btn, $cta_url ), '24px 48px 46px', true );

		return self::layout( $locale, $inner, $heading );
	}

	/* ---------- NPS survey (template 14) ---------- */

	/**
	 * Render the NPS survey email with a clickable 0–10 scale.
	 *
	 * @param string $locale 'en'|'pt'.
	 * @param int    $uid    User id (carried in the link).
	 * @param string $token  Per-user token.
	 */
	public static function nps( string $locale, int $uid, string $token ): string {
		$pt = 'pt' === $locale;

		$heading = $pt ? 'Como tem sido a tua experiência?' : 'How’s your experience been?';
		$lead    = $pt
			? 'Numa escala de 0 a 10, qual a probabilidade de recomendares a HowToInvest a um amigo?'
			: 'On a scale of 0 to 10, how likely are you to recommend HowToInvest to a friend?';

		$cells = '';
		for ( $n = 0; $n <= 10; $n++ ) {
			$url    = add_query_arg(
				array( 'hti_nps' => 1, 'u' => $uid, 't' => $token, 'score' => $n ),
				home_url( '/' )
			);
			$cells .= '<td style="padding:3px;">'
				. '<a href="' . esc_url( $url ) . '" style="display:block;width:34px;height:38px;line-height:38px;text-align:center;border:1px solid #E6E0F2;border-radius:8px;background:#F4F1FA;font:700 15px Arial,sans-serif;color:#1E2147;text-decoration:none;">' . $n . '</a>'
				. '</td>';
		}
		$scale = '<table role="presentation" align="center" style="border-collapse:collapse;margin:0 auto;"><tbody><tr>' . $cells . '</tr></tbody></table>';
		$ends  = '<table role="presentation" width="100%" style="border-collapse:collapse;max-width:430px;margin:8px auto 0;"><tbody><tr>'
			. '<td style="font:400 11.5px Arial,sans-serif;color:#9A93A8;text-align:left;">' . esc_html( $pt ? 'Nada provável' : 'Not likely' ) . '</td>'
			. '<td style="font:400 11.5px Arial,sans-serif;color:#9A93A8;text-align:right;">' . esc_html( $pt ? 'Muito provável' : 'Very likely' ) . '</td>'
			. '</tr></tbody></table>';

		$inner = self::row(
			self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'46px 40px 0',
			true
		)
			. self::row( $scale . $ends, '28px 24px 46px', true );

		return self::layout( $locale, $inner, $heading );
	}

	/* ---------- preferences updated (template 13) ---------- */

	/**
	 * Render the "preferences updated" confirmation.
	 *
	 * @param string            $locale     'en'|'pt'.
	 * @param bool              $newsletter Newsletter on/off.
	 * @param string            $frequency  'weekly'|'daily'.
	 * @param array<int,string> $categories Chosen category names.
	 */
	public static function preferences( string $locale, bool $newsletter, string $frequency, array $categories ): string {
		$pt = 'pt' === $locale;

		$heading = $pt ? 'Preferências atualizadas' : 'Preferences updated';
		$lead    = $pt ? 'Guardámos as tuas preferências de email. Aqui fica o resumo:' : 'We’ve saved your email preferences. Here’s the summary:';

		$rowsrc = array(
			( $pt ? 'Newsletter' : 'Newsletter' )   => $newsletter ? ( $pt ? 'Ativa' : 'On' ) : ( $pt ? 'Desativada' : 'Off' ),
			( $pt ? 'Frequência' : 'Frequency' )     => 'daily' === $frequency ? ( $pt ? 'Diária' : 'Daily' ) : ( $pt ? 'Semanal' : 'Weekly' ),
			( $pt ? 'Categorias' : 'Categories' )    => empty( $categories ) ? ( $pt ? 'Todas' : 'All' ) : implode( ', ', $categories ),
		);
		$rows  = '';
		$keys  = array_keys( $rowsrc );
		$last  = end( $keys );
		foreach ( $rowsrc as $label => $value ) {
			$border = $label !== $last ? 'border-bottom:1px solid #EBE6F4;' : '';
			$rows  .= '<tr><td style="padding:16px 22px;' . $border . '"><table role="presentation" width="100%"><tbody><tr>'
				. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;vertical-align:top;">' . esc_html( $label ) . '</td>'
				. '<td style="text-align:right;font:700 13.5px Arial,sans-serif;color:#1E2147;">' . esc_html( $value ) . '</td>'
				. '</tr></tbody></table></td></tr>';
		}
		$card = '<table role="presentation" width="100%" style="border-collapse:collapse;background:#F6F4FB;border-radius:14px;"><tbody>' . $rows . '</tbody></table>';

		$inner = self::row(
			self::icon_circle( '&#9881;', '#EFEBFF', '#7C5CFC' ) . self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. self::row( $card, '26px 48px 6px' )
			. self::row( self::note( $pt ? 'Podes atualizar estas preferências a qualquer momento na tua conta.' : 'You can update these preferences any time in your account.' ), '14px 48px 44px', true );

		return self::layout( $locale, $inner, $heading );
	}

	/* ---------- reactivation (template 12) ---------- */

	/**
	 * Render the re-engagement email for lapsed users.
	 *
	 * @param string                                                   $locale  'en'|'pt'.
	 * @param array<int,array{title:string,url:string,excerpt:string}> $items   What's new.
	 * @param string                                                   $cta_url "Back to the platform" URL.
	 */
	public static function reactivation( string $locale, array $items, string $cta_url ): string {
		$pt = 'pt' === $locale;

		$eyebrow = $pt ? 'Sentimos a tua falta' : 'We’ve missed you';
		$heading = $pt ? 'Há novidades à tua espera' : 'There’s something new for you';
		$intro   = $pt
			? 'Voltámos com mais conteúdo claro e sem jargão para continuares a aprender a investir. Vê o que tens de novo:'
			: 'We’re back with more clear, jargon-free content to keep building your investing confidence. Here’s what’s new:';
		$btn = $pt ? 'Voltar à plataforma' : 'Back to the platform';

		$cards = '';
		foreach ( $items as $item ) {
			$cards .= '<tr><td style="padding:0 0 14px;">'
				. '<a href="' . esc_url( (string) ( $item['url'] ?? '' ) ) . '" style="text-decoration:none;display:block;border:1px solid #EEEAF4;border-radius:12px;padding:14px 18px;">'
				. '<div style="font:700 16px Poppins,Arial,sans-serif;color:#1E2147;line-height:1.3;">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</div>'
				. '</a></td></tr>';
		}
		$list = '' !== $cards ? '<table role="presentation" width="100%" style="border-collapse:collapse;"><tbody>' . $cards . '</tbody></table>' : '';

		$inner = self::row(
			'<div style="background:linear-gradient(135deg,#FF6B5E,#FF8B7E);border-radius:16px;padding:28px 24px;text-align:center;">'
				. '<div style="font:700 12px Arial,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:#FFFFFF;opacity:.9;margin-bottom:8px;">' . esc_html( $eyebrow ) . '</div>'
				. '<div style="font:800 26px Poppins,Arial,sans-serif;color:#FFFFFF;line-height:1.15;">' . esc_html( $heading ) . '</div>'
				. '</div>',
			'40px 44px 0'
		)
			. self::row( self::lead( esc_html( $intro ) ), '22px 44px 0', true )
			. ( '' !== $list ? self::row( $list, '20px 44px 0' ) : '' )
			. self::row( self::button( $btn, $cta_url ), '16px 44px 44px', true );

		return self::layout( $locale, $inner, $intro );
	}

	/* ---------- account deletion scheduled (template 11) ---------- */

	/**
	 * Render the "your account is scheduled for deletion" message.
	 *
	 * @param string $locale       'en'|'pt'.
	 * @param string $date         Human deletion date.
	 * @param string $cancel_url   Cancel link.
	 * @param string $download_url Where to download data (account page).
	 */
	public static function deletion_scheduled( string $locale, string $date, string $cancel_url, string $download_url ): string {
		$pt = 'pt' === $locale;

		$heading = $pt ? 'A tua conta vai ser eliminada' : 'Your account is scheduled for deletion';
		$lead    = $pt
			? 'Recebemos o teu pedido para eliminar a conta. Todos os teus dados serão apagados definitivamente em:'
			: 'We received your request to delete your account. All your data will be permanently erased on:';
		$cancel  = $pt ? 'Cancelar eliminação' : 'Cancel deletion';
		$download = $pt ? 'Descarregar os meus dados' : 'Download my data';
		$note    = $pt
			? 'Se mudares de ideias, cancela a qualquer momento antes dessa data. Depois disso, a ação é irreversível.'
			: 'If you change your mind, cancel any time before that date. After that, this cannot be undone.';

		$datecard = '<div style="background:#FDECEA;border-radius:14px;padding:18px 22px;text-align:center;font:800 20px Poppins,Arial,sans-serif;color:#C0392B;">' . esc_html( $date ) . '</div>';

		$inner = self::row(
			self::icon_circle( '&#128465;', '#FDECEA', '#C0392B' ) . self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. self::row( $datecard, '22px 48px 0' )
			. self::row( self::button( $cancel, $cancel_url ), '24px 48px 6px', true )
			. self::row( '<a href="' . esc_url( $download_url ) . '" style="font:700 13.5px Arial,sans-serif;color:#7C5CFC;">' . esc_html( $download ) . '</a>', '6px 48px 6px', true )
			. self::row( self::note( $note ), '14px 48px 44px', true );

		return self::layout( $locale, $inner, $heading );
	}

	/* ---------- account email change (template 10) ---------- */

	/**
	 * Render the "confirm your new email" message (sent to the new address).
	 *
	 * @param string $locale 'en'|'pt'.
	 * @param string $old    Current email.
	 * @param string $new    Requested new email.
	 * @param string $url    Confirmation link.
	 */
	public static function email_change( string $locale, string $old, string $new, string $url ): string {
		$pt = 'pt' === $locale;

		$heading = $pt ? 'Confirma o teu novo email' : 'Confirm your new email';
		$lead    = $pt
			? 'Pediste para alterar o email da tua conta HowToInvest. Confirma a alteração no botão abaixo.'
			: 'You asked to change the email on your HowToInvest account. Confirm the change with the button below.';
		$btn  = $pt ? 'Confirmar novo email' : 'Confirm new email';
		$note = $pt
			? 'Este link expira em 24 horas. Se não foste tu a pedir isto, ignora este email — nada muda.'
			: 'This link expires in 24 hours. If you didn’t request this, ignore this email — nothing changes.';

		$rows = '<table role="presentation" width="100%" style="border-collapse:collapse;background:#F6F4FB;border-radius:14px;"><tbody>'
			. '<tr><td style="padding:16px 22px;border-bottom:1px solid #EBE6F4;"><table role="presentation" width="100%"><tbody><tr>'
			. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;">' . esc_html( $pt ? 'Email atual' : 'Current email' ) . '</td>'
			. '<td style="text-align:right;font:700 13.5px Arial,sans-serif;color:#1E2147;">' . esc_html( $old ) . '</td>'
			. '</tr></tbody></table></td></tr>'
			. '<tr><td style="padding:16px 22px;"><table role="presentation" width="100%"><tbody><tr>'
			. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;">' . esc_html( $pt ? 'Novo email' : 'New email' ) . '</td>'
			. '<td style="text-align:right;font:700 13.5px Arial,sans-serif;color:#147A57;">' . esc_html( $new ) . '</td>'
			. '</tr></tbody></table></td></tr>'
			. '</tbody></table>';

		$inner = self::row(
			self::icon_circle( '&#9993;', '#EFEBFF', '#7C5CFC' ) . self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. self::row( $rows, '26px 48px 0' )
			. self::row( self::button( $btn, $url ), '24px 48px 6px', true )
			. self::row( self::note( $note ), '16px 48px 44px', true );

		return self::layout( $locale, $inner, $heading );
	}

	/* ---------- security: password changed (template 09) ---------- */

	/**
	 * Render the "your password was changed" security alert.
	 *
	 * @param string                    $locale    'en'|'pt'.
	 * @param array{when:string,device:string,ip:string} $meta Event details.
	 * @param string                    $reset_url "wasn't you" → reset URL.
	 */
	public static function security_alert( string $locale, array $meta, string $reset_url ): string {
		$pt = 'pt' === $locale;

		$heading = $pt ? 'A tua password foi alterada' : 'Your password was changed';
		$lead    = $pt
			? 'A password da tua conta HowToInvest foi alterada. Se foste tu, está tudo bem — não precisas de fazer nada.'
			: 'The password for your HowToInvest account was changed. If this was you, all good — there’s nothing to do.';
		$rows = array(
			( $pt ? 'Data' : 'Date' )         => $meta['when'] ?? '',
			( $pt ? 'Dispositivo' : 'Device' ) => $meta['device'] ?? '',
			( $pt ? 'Endereço IP' : 'IP address' ) => $meta['ip'] ?? '',
		);
		$card  = '<table role="presentation" width="100%" style="border-collapse:collapse;background:#F6F4FB;border-radius:14px;"><tbody>';
		$i     = 0;
		$count = count( array_filter( $rows, static fn( $v ) => '' !== $v ) );
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			++$i;
			$border = $i < $count ? 'border-bottom:1px solid #EBE6F4;' : '';
			$card  .= '<tr><td style="padding:16px 22px;' . $border . '"><table role="presentation" width="100%" style="border-collapse:collapse;"><tbody><tr>'
				. '<td style="font:600 13.5px Arial,sans-serif;color:#7A7488;">' . esc_html( $label ) . '</td>'
				. '<td style="text-align:right;font:700 13.5px Arial,sans-serif;color:#1E2147;">' . esc_html( $value ) . '</td>'
				. '</tr></tbody></table></td></tr>';
		}
		$card .= '</tbody></table>';

		$wasnt = $pt ? 'Não foste tu?' : 'Wasn’t you?';
		$cta   = $pt ? 'Repor a password agora' : 'Reset your password now';
		$note  = $pt
			? 'Se não foste tu, repõe a password imediatamente e contacta-nos.'
			: 'If this wasn’t you, reset your password immediately and contact us.';

		$inner = self::row(
			self::icon_circle( '&#128274;', '#FDECEA', '#C0392B' ) . self::h1( $heading ) . self::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. self::row( $card, '28px 48px 0' )
			. self::row( '<div style="font:700 13px Arial,sans-serif;color:#C0392B;margin-bottom:10px;">' . esc_html( $wasnt ) . '</div>' . self::button( $cta, $reset_url ), '24px 48px 4px', true )
			. self::row( self::note( $note ), '16px 48px 44px', true );

		return self::layout( $locale, $inner, $heading );
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
