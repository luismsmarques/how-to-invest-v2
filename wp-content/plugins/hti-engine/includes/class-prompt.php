<?php
/**
 * Builds the system + user prompts for the explainer LLM (Prompt_LLM_Schema §2–3).
 *
 * The LLM receives the already-decided archetype, the fixed allocation, the
 * fired traps, the user's answers (to personalise FORM only) and the curated
 * class notes (factual base). It must not change any number. Pure PHP.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles prompts from a deterministic result.
 */
class Prompt {

	/**
	 * Fixed system prompt (Prompt_LLM_Schema §2). Absolute rules for the model.
	 */
	public const SYSTEM = <<<'TXT'
És um assistente educativo de literacia financeira da plataforma HowToInvest.
A tua única função é EXPLICAR, em linguagem clara e acessível, um perfil de
investidor e uma estrutura de carteira por CLASSES DE ATIVOS que já foram
determinados por regras da plataforma. NÃO és um consultor financeiro.

REGRAS ABSOLUTAS:
1. NUNCA alteres, recalcules ou contradigas as percentagens de alocação que
   te são dadas. São factos fixos. Limita-te a explicá-las.
2. NUNCA menciones instrumentos, produtos, tickers, fundos ou empresas
   específicas (ex.: nunca "VWCE", "S&P 500", "Apple", "Bitcoin ETF").
   Fala apenas em CLASSES genéricas (ações globais, obrigações, etc.).
3. NUNCA uses linguagem imperativa ou de recomendação pessoal ("deves comprar",
   "a tua carteira ideal", "recomendo-te"). Usa linguagem condicional e
   ilustrativa ("um perfil como este costuma considerar...", "este exemplo
   ilustra...").
4. NUNCA cries urgência, nunca sugiras agir agora, nunca menciones corretoras
   ou execução de ordens.
5. Enquadra sempre como EDUCATIVO e ILUSTRATIVO, sobre um ARQUÉTIPO de perfil,
   não como conselho dirigido àquela pessoa em particular.
6. Se uma trava de segurança disparou, a mensagem educativa sobre essa trava
   é a PRIORIDADE e vem primeiro.
7. Responde SEMPRE e SÓ no idioma indicado, no formato JSON exato pedido,
   sem texto fora do JSON, sem markdown.

TOM: claro, calmo, encorajador, sem jargão não explicado. Como um professor
paciente, não um vendedor.
TXT;

	/**
	 * Build the user prompt from a result + answers (Prompt_LLM_Schema §3).
	 *
	 * @param array{archetype_id:int,allocation:list<array{class:string,pct:int}>,safety_flags:list<string>} $result          Engine result.
	 * @param array<string,mixed>                                                                            $answers         Questionnaire answers.
	 * @param string                                                                                         $locale          'en' or 'pt'.
	 * @param string                                                                                         $archetype_label Human label for the archetype.
	 * @return string
	 */
	public static function build_user( array $result, array $answers, string $locale, string $archetype_label ): string {
		$allocation_json = wp_json_encode( $result['allocation'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$flags           = empty( $result['safety_flags'] ) ? '[]' : implode( ', ', $result['safety_flags'] );

		$curated = array();
		foreach ( $result['allocation'] as $slice ) {
			$curated[] = '  ' . $slice['class'] . ': ' . Fallback::class_note( $slice['class'], $locale );
		}
		$curated_notes = implode( "\n", $curated );

		$answer = static fn( string $key ): string => (string) ( $answers[ $key ] ?? 'unknown' );

		return <<<TXT
Gera a explicação educativa para este caso. Responde apenas no idioma: {$locale}.

ARQUÉTIPO (decidido pela plataforma, facto fixo):
  id: {$result['archetype_id']}
  nome: {$archetype_label}

ALOCAÇÃO (decidida pela plataforma, factos fixos — NÃO alterar):
{$allocation_json}

TRAVAS DE SEGURANÇA DISPARADAS: {$flags}
  (se vazio, não há travas; se contém "no_emergency_fund", a mensagem sobre
   construir fundo de emergência é prioritária)

RESPOSTAS DO UTILIZADOR (para personalizar a forma da explicação, não o conteúdo):
  horizonte: {$answer( 'p1_horizon' )}
  objetivo: {$answer( 'p2_goal' )}
  reação a queda: {$answer( 'p3_drop_reaction' )}
  capacidade: {$answer( 'p4_capacity' )}
  experiência: {$answer( 'p5_experience' )}
  interesse ESG: {$answer( 'p7_esg' )}
  interesse crypto: {$answer( 'p8_crypto' )}

NOTAS CURADAS POR CLASSE (usa como base factual, reescreve em linguagem simples):
{$curated_notes}

Devolve um objeto JSON com exatamente esta forma:
{
  "why_archetype": "2-4 frases explicando por que este perfil corresponde às respostas dadas, em linguagem condicional",
  "class_notes": { "<class_key>": "1-2 frases educativas por cada classe na alocação" },
  "safety_message": "se houve trava, mensagem educativa prioritária; senão null"
}
TXT;
	}
}
