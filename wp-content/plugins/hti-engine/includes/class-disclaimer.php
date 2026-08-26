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
	 * Affiliate-disclosure wording version (audit trail, Textos §6).
	 */
	public const AFFILIATE_VERSION = '1.0.0';

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

	/**
	 * Affiliate disclosure for the broker editorial section (Textos §6.1).
	 *
	 * CMVM (2025-03-13): the affiliate relationship must be disclosed on every
	 * page/channel where it exists — this text ships on each broker surface,
	 * never only in the footer. The "How we make money" link is appended by the
	 * renderer (Brokers::disclosure_html()), not here, so the text stays pure.
	 *
	 * @param string $locale 'en' or 'pt'.
	 */
	public static function affiliate( string $locale ): string {
		$pt = str_starts_with( strtolower( $locale ), 'pt' );

		if ( $pt ) {
			return 'Divulgação de parceria: alguns links nesta página são links de afiliado. Se abrires conta através deles, podemos receber uma comissão — sem custo extra para ti. Isso nunca altera as nossas comparações ou análises, que seguem uma metodologia pública. Isto é informação educativa, não é aconselhamento financeiro nem uma recomendação pessoal.';
		}

		return 'Partner disclosure: some links on this page are affiliate links. If you open an account through them, we may earn a commission — at no extra cost to you. This never changes our comparisons or reviews, which follow a public methodology. This is educational information, not financial advice or a personal recommendation.';
	}

	/**
	 * ESMA CFD risk warning with the provider's real loss percentage (Textos §6.3).
	 *
	 * @param string $locale 'en' or 'pt'.
	 * @param string $pct    Retail-accounts-losing percentage, digits only (e.g. '76').
	 */
	public static function cfd_risk( string $locale, string $pct ): string {
		$pt  = str_starts_with( strtolower( $locale ), 'pt' );
		$pct = trim( $pct );

		if ( $pt ) {
			return $pct . '% das contas de investidores de retalho perdem dinheiro ao negociar CFDs com este fornecedor. Os CFDs são produtos complexos e alavancados — considera se compreendes como funcionam e se podes suportar o risco elevado de perder o teu dinheiro.';
		}

		return $pct . '% of retail investor accounts lose money when trading CFDs with this provider. CFDs are complex, leveraged products — consider whether you understand how they work and whether you can afford the high risk of losing your money.';
	}
}
