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
