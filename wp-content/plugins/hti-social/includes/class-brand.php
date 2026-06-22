<?php
/**
 * Brand constants shared by every template: the logo mark (SVG), the bilingual
 * legal disclaimer, default handles. Keeping these here means the disclaimer is
 * always available and the brand never drifts between templates.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Static brand assets + copy.
 */
class Brand {

	/**
	 * The HowToInvest shield logo as an inline SVG string (scales to its box).
	 * Ported from the design's brandLogo().
	 */
	public static function logo_svg(): string {
		$bars = array(
			array( 20.5, 22, 1, 11, 0 ),
			array( 19.2, 25.5, 3.6, 5, 1 ),
			array( 25.6, 20, 1, 12.5, 0 ),
			array( 24.3, 23.5, 3.6, 5.5, 1 ),
			array( 30.2, 21.5, 1, 11, 0 ),
			array( 28.9, 24.5, 3.6, 4.5, 1 ),
			array( 35.6, 18, 1, 12.5, 0 ),
			array( 34.3, 21, 3.6, 5.8, 1 ),
			array( 20.4, 40, 3.6, 6, 0.8 ),
			array( 25.9, 37.5, 3.6, 8.5, 0.8 ),
			array( 31.4, 35, 3.6, 11, 0.8 ),
			array( 36.9, 32.5, 3.6, 13.5, 0.8 ),
		);
		$rects = '';
		foreach ( $bars as $b ) {
			$rects .= sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" rx="%s"/>',
				$b[0],
				$b[1],
				$b[2],
				$b[3],
				$b[4]
			);
		}
		return '<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<circle cx="32" cy="32" r="32" fill="#1E2147"/>'
			. '<circle cx="32" cy="32" r="29.3" stroke="#fff" stroke-opacity=".28" stroke-width=".8"/>'
			. '<path d="M32 12L50 17.5V32c0 10-7.5 16.6-18 20-10.5-3.4-18-10-18-20V17.5z" fill="#fff"/>'
			. '<g fill="#7C5CFC">' . $rects . '</g>'
			. '</svg>';
	}

	/**
	 * "Fun fact · green" illustration (a ship), ported from the design.
	 */
	public static function ship_svg(): string {
		return '<svg viewBox="0 0 120 120" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M60 18v54" stroke="#fff" stroke-width="3" stroke-linecap="round"/>'
			. '<path d="M62 24c18 6 26 20 28 38H62z" fill="#7FE0B0"/>'
			. '<path d="M58 30c-12 5-18 16-19 30h19z" fill="#fff" fill-opacity=".85"/>'
			. '<path d="M28 74h64l-11 18H39z" fill="#FFD79E"/>'
			. '<path d="M22 98c7 6 14 6 21 0s14-6 21 0 14 6 21 0" stroke="#7FE0B0" stroke-width="3.5" fill="none" stroke-linecap="round"/>'
			. '</svg>';
	}

	/**
	 * "Fun fact · purple" illustration (gold coins), ported from the design.
	 */
	public static function gold_svg(): string {
		return '<svg viewBox="0 0 120 120" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<rect x="30" y="80" width="60" height="16" rx="3" fill="#E8B84B"/>'
			. '<rect x="38" y="62" width="44" height="16" rx="3" fill="#F2CD6B"/>'
			. '<path d="M50 44h20l8 16H42z" fill="#FFE08A"/>'
			. '<path d="M54 47l-5 11" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-opacity=".6"/>'
			. '<circle cx="34" cy="36" r="9" fill="#F2CD6B"/>'
			. '<circle cx="88" cy="30" r="7" fill="#E8B84B"/>'
			. '</svg>';
	}

	/**
	 * Bilingual legal disclaimer (matches the design's renderVals()).
	 *
	 * @return array{en:string,pt:string}
	 */
	public static function disclaimers(): array {
		return array(
			'pt' => 'Conteúdo educativo sobre literacia financeira. Não é aconselhamento financeiro, de investimento, fiscal ou jurídico. Investir envolve risco, incluindo a perda de capital. Exemplos ilustrativos e apenas por classe de ativos.',
			'en' => 'HowToInvest is an educational platform about investing literacy. Nothing here is financial, investment, tax or legal advice. Investing carries risk, including loss of capital. Examples are illustrative and by asset class only.',
		);
	}

	/**
	 * Default brand fields shared across templates.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'handle'   => 'howtoinvest.pro',
			'handleTw' => 'howtoinvestpro',
			'domain'   => 'howtoinvest.pro',
		);
	}

	/**
	 * URLs of the self-hosted woff2 fonts the export embeds for fidelity.
	 *
	 * @return array<int,array{family:string,weight:int,url:string}>
	 */
	public static function font_faces(): array {
		$base = HTI_SOCIAL_URL . 'assets/fonts/';
		return array(
			array( 'family' => 'Poppins', 'weight' => 400, 'url' => $base . 'poppins-400.woff2' ),
			array( 'family' => 'Poppins', 'weight' => 500, 'url' => $base . 'poppins-500.woff2' ),
			array( 'family' => 'Poppins', 'weight' => 600, 'url' => $base . 'poppins-600.woff2' ),
			array( 'family' => 'Poppins', 'weight' => 700, 'url' => $base . 'poppins-700.woff2' ),
			array( 'family' => 'Poppins', 'weight' => 800, 'url' => $base . 'poppins-800.woff2' ),
			array( 'family' => 'Plus Jakarta Sans', 'weight' => 400, 'url' => $base . 'plus-jakarta-sans-400.woff2' ),
			array( 'family' => 'Plus Jakarta Sans', 'weight' => 500, 'url' => $base . 'plus-jakarta-sans-500.woff2' ),
			array( 'family' => 'Plus Jakarta Sans', 'weight' => 600, 'url' => $base . 'plus-jakarta-sans-600.woff2' ),
			array( 'family' => 'Plus Jakarta Sans', 'weight' => 700, 'url' => $base . 'plus-jakarta-sans-700.woff2' ),
		);
	}
}
