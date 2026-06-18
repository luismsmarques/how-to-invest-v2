<?php
/**
 * Contextual result disclaimer (Textos_Finais §1.1), versioned for audit.
 *
 * Every recommendation ships with this non-dismissible disclaimer. Bump
 * VERSION whenever the wording changes so each stored result records exactly
 * which disclaimer applied.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the contextual disclaimer text and its version.
 */
class Disclaimer {

	/**
	 * Disclaimer wording version (audit trail).
	 */
	public const VERSION = '1.0.0';

	/**
	 * Contextual disclaimer for the result, by locale.
	 *
	 * @param string $locale 'en' or 'pt'.
	 */
	public static function contextual( string $locale ): string {
		$pt = str_starts_with( strtolower( $locale ), 'pt' );

		if ( $pt ) {
			return 'Isto é uma ferramenta educativa, não é aconselhamento financeiro. O que vês abaixo é um exemplo ilustrativo do tipo de estrutura de carteira que um perfil como o teu poderia estudar — organizado por classe de ativos, não por produtos específicos. Não tem em conta a tua situação financeira completa, nem é uma recomendação pessoal. Antes de tomares qualquer decisão, considera falar com um profissional financeiro registado.';
		}

		return 'This is an educational tool, not financial advice. What you see below is an illustrative example of the kind of portfolio structure that a profile like yours might explore — organised by asset class, not specific products. It doesn\'t account for your full financial situation, and it isn\'t a personal recommendation. Before making any decision, consider speaking with a registered financial professional.';
	}
}
