<?php
/**
 * Pre-written explanatory text (the fallback, and the curated notes the LLM
 * rewrites). Used whenever the LLM fails or its output is rejected — the
 * deterministic decision always ships with coherent text.
 *
 * Source: docs/Textos_Finais §2 (class notes), §3 (archetype "why"),
 * §4 (safety-trap messages). EN default + PT. Pure data — no WordPress, no LLM.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Curated, validated fallback copy by archetype / class / trap and locale.
 */
class Fallback {

	/**
	 * Safety-trap message priority (highest first).
	 */
	private const TRAP_PRIORITY = array( 'no_emergency_fund', 'horizon_override', 'crypto_blocked' );

	/**
	 * Build a full explanation object for a deterministic result.
	 *
	 * @param array{archetype_id:int,allocation:list<array{class:string,pct:int}>,safety_flags:list<string>} $result Engine result.
	 * @param string                                                                                          $locale 'en' or 'pt'.
	 * @return array{why_archetype:string,class_notes:array<string,string>,safety_message:?string}
	 */
	public static function build( array $result, string $locale ): array {
		$locale = self::normalize( $locale );

		$class_notes = array();
		foreach ( $result['allocation'] as $slice ) {
			$note = self::class_note( $slice['class'], $locale );
			if ( '' !== $note ) {
				$class_notes[ $slice['class'] ] = $note;
			}
		}

		return array(
			'why_archetype'  => self::why_archetype( (int) $result['archetype_id'], $locale ),
			'class_notes'    => $class_notes,
			'safety_message' => self::safety_message( $result['safety_flags'], $locale ),
		);
	}

	/**
	 * "Why this archetype" text.
	 *
	 * @param int    $archetype_id Archetype 1–5.
	 * @param string $locale       Locale.
	 */
	public static function why_archetype( int $archetype_id, string $locale ): string {
		$locale = self::normalize( $locale );
		return self::ARCHETYPES[ $archetype_id ][ $locale ] ?? self::ARCHETYPES[ $archetype_id ]['en'] ?? '';
	}

	/**
	 * Educational note for an asset class.
	 *
	 * @param string $class  Asset-class key.
	 * @param string $locale Locale.
	 */
	public static function class_note( string $class, string $locale ): string {
		$locale = self::normalize( $locale );
		return self::CLASS_NOTES[ $class ][ $locale ] ?? self::CLASS_NOTES[ $class ]['en'] ?? '';
	}

	/**
	 * Highest-priority safety message for the fired flags, or null.
	 *
	 * @param list<string> $flags  Safety flags.
	 * @param string       $locale Locale.
	 */
	public static function safety_message( array $flags, string $locale ): ?string {
		$locale = self::normalize( $locale );
		foreach ( self::TRAP_PRIORITY as $flag ) {
			if ( in_array( $flag, $flags, true ) ) {
				return self::TRAPS[ $flag ][ $locale ] ?? self::TRAPS[ $flag ]['en'] ?? null;
			}
		}
		return null;
	}

	/**
	 * Normalize a locale to a supported key.
	 *
	 * @param string $locale Raw locale (e.g. 'pt_PT').
	 */
	private static function normalize( string $locale ): string {
		return str_starts_with( strtolower( $locale ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Archetype "why" text by locale (Textos §3).
	 */
	private const ARCHETYPES = array(
		1 => array(
			'en' => "A profile like this usually puts protecting the money first, because it may be needed soon or because big swings feel uncomfortable. That's why an example for this profile leans heavily on steadier asset classes and keeps growth assets small.",
			'pt' => 'Um perfil como este costuma pôr a proteção do dinheiro em primeiro lugar, porque pode vir a ser preciso em breve ou porque grandes oscilações causam desconforto. Por isso um exemplo para este perfil apoia-se sobretudo em classes mais estáveis e mantém pequena a fatia de crescimento.',
		),
		2 => array(
			'en' => 'This profile leans toward caution but is open to some growth over a medium horizon. An example here tends to balance steadier classes with a meaningful, but not dominant, slice of shares.',
			'pt' => 'Este perfil inclina-se para a prudência, mas está aberto a algum crescimento num horizonte médio. Um exemplo aqui costuma equilibrar classes mais estáveis com uma fatia de ações relevante, mas não dominante.',
		),
		3 => array(
			'en' => 'This is the middle ground: enough time and comfort to hold a solid share of growth assets, balanced by steadier ones. An example for this profile tends to split fairly evenly between growth and stability.',
			'pt' => 'Este é o meio-termo: tempo e conforto suficientes para manter uma boa fatia de ativos de crescimento, equilibrada por outros mais estáveis. Um exemplo para este perfil costuma dividir-se de forma relativamente equilibrada entre crescimento e estabilidade.',
		),
		4 => array(
			'en' => 'With a long horizon and comfort with ups and downs, this profile can let growth assets do the heavy lifting. An example here tends to weight global shares strongly, with a smaller cushion of steadier classes.',
			'pt' => 'Com um horizonte longo e conforto com os altos e baixos, este perfil pode deixar os ativos de crescimento fazer o trabalho pesado. Um exemplo aqui costuma dar bastante peso às ações globais, com uma almofada menor de classes mais estáveis.',
		),
		5 => array(
			'en' => 'A very long horizon and a high tolerance for volatility let this profile lean almost entirely on growth assets, accepting bigger swings in exchange for more long-term growth potential. An example here keeps steadier classes minimal.',
			'pt' => 'Um horizonte muito longo e uma elevada tolerância à volatilidade permitem que este perfil se apoie quase totalmente em ativos de crescimento, aceitando oscilações maiores em troca de mais potencial de crescimento a longo prazo. Um exemplo aqui mantém as classes mais estáveis ao mínimo.',
		),
	);

	/**
	 * Per-asset-class notes by locale (Textos §2).
	 */
	private const CLASS_NOTES = array(
		'global_equity' => array(
			'en' => "Global equities are the growth engine of a portfolio — shares in companies around the world. Over long periods they've tended to grow the most, but they also swing the most along the way.",
			'pt' => 'As ações globais são o motor de crescimento de uma carteira — participações em empresas de todo o mundo. Em períodos longos, tendem a crescer mais, mas também oscilam mais pelo caminho.',
		),
		'bonds'         => array(
			'en' => "Bonds are loans to governments or companies that pay you interest. They usually move more gently than shares, which is why they're often used to add stability and soften the ups and downs of a portfolio.",
			'pt' => 'As obrigações são empréstimos a governos ou empresas que te pagam juros. Costumam mexer-se de forma mais suave do que as ações, por isso são muitas vezes usadas para dar estabilidade e atenuar os altos e baixos de uma carteira.',
		),
		'cash'          => array(
			'en' => "Cash and equivalents are money you can reach quickly without much risk to its value. It grows little, but it's there when you need it — useful for short-term needs and peace of mind.",
			'pt' => 'A liquidez e equivalentes são dinheiro a que consegues chegar depressa sem grande risco para o seu valor. Cresce pouco, mas está lá quando precisas — útil para necessidades de curto prazo e tranquilidade.',
		),
		'reits_alt'     => array(
			'en' => "This bucket covers things like real estate or other assets that don't always move in step with shares and bonds. A small slice can add variety, which sometimes helps smooth the overall ride.",
			'pt' => 'Este grupo cobre coisas como imobiliário ou outros ativos que nem sempre se movem ao mesmo ritmo das ações e obrigações. Uma pequena fatia pode acrescentar variedade, o que por vezes ajuda a suavizar o percurso global.',
		),
		'crypto'        => array(
			'en' => "Crypto is a young, highly volatile category — it can rise and fall sharply in short periods. If it appears here at all, it's only as a very small, optional slice, and only for profiles with a long horizon and a solid financial base.",
			'pt' => 'A cripto é uma categoria jovem e muito volátil — pode subir e descer bruscamente em curtos períodos. Se aparecer aqui, é apenas como uma fatia muito pequena e opcional, e só para perfis com horizonte longo e uma base financeira sólida.',
		),
	);

	/**
	 * Safety-trap messages by locale (Textos §4).
	 */
	private const TRAPS = array(
		'no_emergency_fund' => array(
			'en' => "Before talking about portfolios, the most important step is building an emergency fund — usually 3 to 6 months of expenses kept somewhere safe and easy to reach. It's what stops a surprise from forcing you to sell investments at a bad time. The example below is something to keep in mind for *after* you've built that base.",
			'pt' => 'Antes de falarmos de carteiras, o passo mais importante é construir um fundo de emergência — normalmente 3 a 6 meses de despesas guardados num sítio seguro e de fácil acesso. É o que evita que um imprevisto te obrigue a vender investimentos num mau momento. O exemplo abaixo é algo a ter em mente para *depois* de teres essa base montada.',
		),
		'horizon_override'  => array(
			'en' => 'Even though you\'re comfortable with risk, you told us you may need this money within about 3 years. That\'s a short window to recover if markets fall, so the example below stays more cautious than your appetite alone would suggest — time matters as much as comfort.',
			'pt' => 'Apesar de te sentires confortável com o risco, indicaste que podes precisar deste dinheiro dentro de cerca de 3 anos. É uma janela curta para recuperar se os mercados caírem, por isso o exemplo abaixo mantém-se mais prudente do que o teu apetite sozinho sugeriria — o tempo conta tanto como o conforto.',
		),
		'crypto_blocked'    => array(
			'en' => "You showed interest in crypto, but for this profile it's left out for now. Crypto's sharp swings sit better with a long horizon and a solid financial base first. As those build up, it's something you could learn more about later.",
			'pt' => 'Mostraste interesse em cripto, mas para este perfil fica de fora por agora. As oscilações bruscas da cripto encaixam melhor com um horizonte longo e uma base financeira sólida primeiro. À medida que isso se consolida, é algo sobre o qual podes aprender mais mais tarde.',
		),
	);
}
