<?php
/**
 * Questionnaire definition (E5) — questions, options, micro-explanations and
 * UI strings, by locale. Authored from the Wireframes (E5), the data model
 * (answer keys/values, Modelo §2) and the micro-explanations (Textos §5).
 *
 * This is the single source the front-end renders from; it is localized to the
 * browser via wp_localize_script, so EN + PT are both covered server-side.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the localized questionnaire + result strings for the front-end.
 */
class Questions {

	/**
	 * Full payload for a locale: questions, asset-class labels and UI strings.
	 *
	 * @param string $locale 'en' or 'pt'.
	 * @return array<string,mixed>
	 */
	public static function payload( string $locale ): array {
		$l = str_starts_with( strtolower( $locale ), 'pt' ) ? 'pt' : 'en';

		return array(
			'locale'    => $l,
			'questions' => self::questions( $l ),
			'classes'   => self::classes( $l ),
			'ui'        => self::ui( $l ),
		);
	}

	/**
	 * Pick a localized string from an [en,pt] pair.
	 *
	 * @param array{en:string,pt:string} $pair  Pair.
	 * @param string                     $l     Locale key.
	 */
	private static function t( array $pair, string $l ): string {
		return $pair[ $l ] ?? $pair['en'];
	}

	/**
	 * The eight questions in order.
	 *
	 * @param string $l Locale key.
	 * @return list<array<string,mixed>>
	 */
	private static function questions( string $l ): array {
		$defs = array(
			array(
				'id'      => 'p1_horizon',
				'type'    => 'single',
				'label'   => array( 'en' => 'When might you need this money?', 'pt' => 'Quando poderás precisar deste dinheiro?' ),
				'info'    => array( 'en' => "Time is an investor's biggest ally — the further away your goal, the more ups and downs you can ride out along the way.", 'pt' => 'O tempo é o maior aliado de quem investe — quanto mais longe está o teu objetivo, mais altos e baixos consegues atravessar pelo caminho.' ),
				'options' => array(
					array( 'value' => '3y', 'en' => 'Within 3 years', 'pt' => 'Dentro de 3 anos' ),
					array( 'value' => '3_7y', 'en' => '3 to 7 years', 'pt' => '3 a 7 anos' ),
					array( 'value' => '7_15y', 'en' => '7 to 15 years', 'pt' => '7 a 15 anos' ),
					array( 'value' => 'over_15y', 'en' => 'More than 15 years', 'pt' => 'Mais de 15 anos' ),
				),
			),
			array(
				'id'      => 'p2_goal',
				'type'    => 'single',
				'label'   => array( 'en' => "What's your main goal for this money?", 'pt' => 'Qual é o teu principal objetivo para este dinheiro?' ),
				'info'    => array( 'en' => 'An emergency fund and a retirement pot call for very different strategies.', 'pt' => 'Um fundo de emergência e um pé-de-meia para a reforma pedem estratégias muito diferentes.' ),
				'options' => array(
					array( 'value' => 'protect', 'en' => 'Protect what I have', 'pt' => 'Proteger o que tenho' ),
					array( 'value' => 'grow', 'en' => 'Grow it steadily', 'pt' => 'Fazer crescer de forma estável' ),
					array( 'value' => 'accumulate', 'en' => 'Build wealth over time', 'pt' => 'Acumular património ao longo do tempo' ),
					array( 'value' => 'maximize', 'en' => 'Maximise long-term growth', 'pt' => 'Maximizar o crescimento a longo prazo' ),
				),
			),
			array(
				'id'      => 'p3_drop_reaction',
				'type'    => 'single',
				'label'   => array( 'en' => 'If your portfolio dropped 20% in a few weeks, what would you do?', 'pt' => 'Se a tua carteira caísse 20% em poucas semanas, o que farias?' ),
				'info'    => array( 'en' => 'The best plan is the one you can stick to when markets get scary — selling at the bottom is the costliest mistake.', 'pt' => 'O melhor plano é o que consegues manter quando o mercado assusta — vender no fundo é o erro mais caro.' ),
				'options' => array(
					array( 'value' => 'sell_all', 'en' => 'Sell everything to avoid more loss', 'pt' => 'Vendo tudo para não perder mais' ),
					array( 'value' => 'sell_part', 'en' => "Sell some — I'd feel nervous", 'pt' => 'Vendo uma parte, fico nervoso' ),
					array( 'value' => 'hold', 'en' => 'Do nothing and wait to recover', 'pt' => 'Não faço nada, espero recuperar' ),
					array( 'value' => 'buy_more', 'en' => 'Buy more while prices are low', 'pt' => 'Aproveito para investir mais' ),
				),
			),
			array(
				'id'      => 'p4_capacity',
				'type'    => 'single',
				'label'   => array( 'en' => 'How stable is your financial situation for taking risk?', 'pt' => 'Quão estável é a tua situação financeira para assumir risco?' ),
				'info'    => array( 'en' => 'How much risk you can take depends on having a stable financial base behind you.', 'pt' => 'A capacidade de assumires risco depende de teres uma base financeira estável por trás.' ),
				'options' => array(
					array( 'value' => 'almost_none', 'en' => 'Very tight — little room', 'pt' => 'Muito apertada — pouca margem' ),
					array( 'value' => 'small', 'en' => 'Some room, but limited', 'pt' => 'Alguma margem, mas limitada' ),
					array( 'value' => 'comfortable', 'en' => 'Comfortable and stable', 'pt' => 'Confortável e estável' ),
					array( 'value' => 'significant', 'en' => 'Very secure, with a strong buffer', 'pt' => 'Muito segura, com uma boa almofada' ),
				),
			),
			array(
				'id'      => 'p5_experience',
				'type'    => 'single',
				'label'   => array( 'en' => 'How familiar are you with investing?', 'pt' => 'Quão familiarizado estás com investir?' ),
				'info'    => array( 'en' => 'Familiarity helps you stay calm, but it never replaces having a plan that fits your time frame.', 'pt' => 'A familiaridade ajuda-te a manter a calma, mas nunca substitui ter um plano adequado ao teu prazo.' ),
				'options' => array(
					array( 'value' => 'never', 'en' => "I've never invested", 'pt' => 'Nunca investi' ),
					array( 'value' => 'little', 'en' => 'A little', 'pt' => 'Um pouco' ),
					array( 'value' => 'some', 'en' => 'Some experience', 'pt' => 'Alguma experiência' ),
					array( 'value' => 'confident', 'en' => 'Confident and experienced', 'pt' => 'Confiante e experiente' ),
				),
			),
			array(
				'id'      => 'p6_emergency_fund',
				'type'    => 'boolean',
				'label'   => array( 'en' => 'Do you already have an emergency fund (3–6 months of expenses)?', 'pt' => 'Já tens um fundo de emergência (3 a 6 meses de despesas)?' ),
				'info'    => array( 'en' => 'Investing before you have a safety cushion is risky — this question is here to protect you.', 'pt' => 'Investir antes de teres um colchão de segurança é arriscado — esta pergunta está aqui para te proteger.' ),
				'options' => array(
					array( 'value' => 'true', 'en' => 'Yes', 'pt' => 'Sim' ),
					array( 'value' => 'false', 'en' => 'Not yet', 'pt' => 'Ainda não' ),
				),
			),
			array(
				'id'      => 'p7_esg',
				'type'    => 'single',
				'label'   => array( 'en' => 'Do you care about ESG (environmental, social, governance) factors?', 'pt' => 'Dás importância a fatores ESG (ambientais, sociais e de governo)?' ),
				'info'    => array( 'en' => "This is a lens on where money goes — it doesn't change your risk profile.", 'pt' => 'É uma lente sobre onde o dinheiro vai — não altera o teu perfil de risco.' ),
				'unknown_info' => array( 'en' => 'ESG means looking at how companies handle environmental, social and governance issues — a lens some people like to apply to where their money goes.', 'pt' => 'ESG significa olhar para a forma como as empresas lidam com questões ambientais, sociais e de governo — uma lente que algumas pessoas gostam de aplicar a onde colocam o seu dinheiro.' ),
				'options' => array(
					array( 'value' => 'yes', 'en' => 'Yes', 'pt' => 'Sim' ),
					array( 'value' => 'no', 'en' => 'No', 'pt' => 'Não' ),
					array( 'value' => 'unknown', 'en' => "I'm not sure", 'pt' => 'Não sei' ),
				),
			),
			array(
				'id'      => 'p8_crypto',
				'type'    => 'single',
				'label'   => array( 'en' => 'Would you be open to a small, optional crypto slice?', 'pt' => 'Estarias aberto a uma pequena fatia opcional de cripto?' ),
				'info'    => array( 'en' => 'If it appears at all, it is only ever a very small slice, and only for the right profile.', 'pt' => 'Se aparecer, é sempre apenas uma fatia muito pequena, e só para o perfil adequado.' ),
				'unknown_info' => array( 'en' => 'Crypto is a young, very volatile type of digital asset. It can grow fast but also fall hard — which is why it only ever shows up here as a tiny, optional slice.', 'pt' => 'A cripto é um tipo de ativo digital jovem e muito volátil. Pode crescer depressa mas também cair com força — por isso só aparece aqui como uma fatia minúscula e opcional.' ),
				'options' => array(
					array( 'value' => 'yes', 'en' => 'Yes', 'pt' => 'Sim' ),
					array( 'value' => 'no', 'en' => 'No', 'pt' => 'Não' ),
					array( 'value' => 'unknown', 'en' => "I'm not sure", 'pt' => 'Não sei' ),
				),
			),
		);

		// Flatten to the chosen locale.
		$out = array();
		foreach ( $defs as $q ) {
			$item = array(
				'id'    => $q['id'],
				'type'  => $q['type'],
				'label' => self::t( $q['label'], $l ),
				'info'  => self::t( $q['info'], $l ),
			);
			if ( isset( $q['unknown_info'] ) ) {
				$item['unknown_info'] = self::t( $q['unknown_info'], $l );
			}
			$item['options'] = array_map(
				static fn( array $o ): array => array(
					'value' => $o['value'],
					'label' => $o[ $l ] ?? $o['en'],
				),
				$q['options']
			);
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Asset-class display labels.
	 *
	 * @param string $l Locale key.
	 * @return array<string,string>
	 */
	private static function classes( string $l ): array {
		$labels = array(
			'global_equity' => array( 'en' => 'Global equities', 'pt' => 'Ações globais' ),
			'bonds'         => array( 'en' => 'Bonds', 'pt' => 'Obrigações' ),
			'reits_alt'     => array( 'en' => 'REITs & alternatives', 'pt' => 'Imobiliário e alternativos' ),
			'cash'          => array( 'en' => 'Cash', 'pt' => 'Liquidez' ),
			'crypto'        => array( 'en' => 'Crypto', 'pt' => 'Cripto' ),
		);
		return array_map( static fn( array $pair ): string => $pair[ $l ] ?? $pair['en'], $labels );
	}

	/**
	 * UI strings for the questionnaire and result.
	 *
	 * @param string $l Locale key.
	 * @return array<string,string>
	 */
	private static function ui( string $l ): array {
		$s = array(
			'step'              => array( 'en' => 'Step %1$s of %2$s', 'pt' => 'Passo %1$s de %2$s' ),
			'why_we_ask'        => array( 'en' => 'Why we ask', 'pt' => 'Porque perguntamos' ),
			'previous'          => array( 'en' => '← Previous', 'pt' => '← Anterior' ),
			'next'              => array( 'en' => 'Continue →', 'pt' => 'Continuar →' ),
			'see_result'        => array( 'en' => 'See my profile', 'pt' => 'Ver o meu perfil' ),
			'choose_one'        => array( 'en' => 'Please choose an option to continue.', 'pt' => 'Escolhe uma opção para continuar.' ),
			'short_disclaimer'  => array( 'en' => 'Educational tool · not financial advice · examples by asset class only', 'pt' => 'Ferramenta educativa · não é aconselhamento · exemplos só por classe de ativos' ),
			'processing'        => array( 'en' => 'Preparing your educational profile…', 'pt' => 'A preparar o teu perfil educativo…' ),
			'processing_1'      => array( 'en' => 'Reading your time horizon…', 'pt' => 'A analisar o teu horizonte…' ),
			'processing_2'      => array( 'en' => 'Building the example portfolio…', 'pt' => 'A montar o exemplo de carteira…' ),
			'error'             => array( 'en' => 'Something went wrong preparing your result. Please try again.', 'pt' => 'Algo correu mal a preparar o teu resultado. Tenta novamente.' ),
			'retry'             => array( 'en' => 'Try again', 'pt' => 'Tentar novamente' ),
			'result_heading'    => array( 'en' => 'Your profile', 'pt' => 'O teu perfil' ),
			'example_structure' => array( 'en' => 'Example structure by asset class', 'pt' => 'Exemplo de estrutura por classe de ativos' ),
			'chart_label'       => array( 'en' => 'Allocation by asset class', 'pt' => 'Alocação por classe de ativos' ),
			'why_archetype'     => array( 'en' => 'Why you landed in this profile', 'pt' => 'Porque caíste neste perfil' ),
			'what_classes_mean' => array( 'en' => 'What each class means', 'pt' => 'O que significa cada classe' ),
			'before_portfolios' => array( 'en' => 'Before we talk about portfolios…', 'pt' => 'Antes de falarmos de carteiras…' ),
			'export_pdf'        => array( 'en' => 'Export PDF', 'pt' => 'Exportar PDF' ),
			'read_more'         => array( 'en' => 'Learn more', 'pt' => 'Aprender mais' ),
			'retake'            => array( 'en' => 'Retake', 'pt' => 'Refazer' ),
			'start_over_note'   => array( 'en' => 'Retaking starts fresh and keeps nothing.', 'pt' => 'Refazer recomeça do zero e não guarda nada.' ),
		);
		return array_map( static fn( array $pair ): string => $pair[ $l ] ?? $pair['en'], $s );
	}
}
