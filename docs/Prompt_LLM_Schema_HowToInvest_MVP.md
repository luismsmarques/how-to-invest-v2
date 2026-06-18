# Prompt do LLM Explicador + Schema de Validação — HowToInvest MVP

**Companion do:** PRD (v3) + Modelo de Dados e Contratos de API
**Data:** 18 de junho de 2026
**Estado:** especificação técnica para desenvolvimento

## Princípio central (não negociável)

O LLM **explica**, nunca **decide**. A decisão (arquétipo + alocação por classes) já foi tomada pelas regras determinísticas curadas *antes* de o LLM ser chamado. O LLM recebe essa decisão como facto e produz **apenas texto explicativo**. Se o LLM tentar alterar números, o schema de validação rejeita a resposta e dispara o fallback.

Isto é simultaneamente a garantia de qualidade e a barreira regulatória: o que sai é educação sobre um arquétipo, não aconselhamento gerado livremente.

---

## 1. O que entra e o que sai

**Entra no LLM** (montado server-side): o arquétipo já decidido, a alocação já calculada, que travas dispararam, as respostas do utilizador (para personalizar a *forma*), o idioma, e as notas curadas de cada classe de ativo.

**Sai do LLM:** um objeto JSON com texto explicativo nos campos definidos pelo schema (§4). Nada mais.

---

## 2. System prompt

```
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
```

---

## 3. User prompt (template, preenchido server-side)

```
Gera a explicação educativa para este caso. Responde apenas no idioma: {{locale}}.

ARQUÉTIPO (decidido pela plataforma, facto fixo):
  id: {{archetype_id}}
  nome: {{archetype_label}}

ALOCAÇÃO (decidida pela plataforma, factos fixos — NÃO alterar):
{{allocation_json}}

TRAVAS DE SEGURANÇA DISPARADAS: {{safety_flags}}
  (se vazio, não há travas; se contém "no_emergency_fund", a mensagem sobre
   construir fundo de emergência é prioritária)

RESPOSTAS DO UTILIZADOR (para personalizar a forma da explicação, não o conteúdo):
  horizonte: {{p1_horizon}}
  objetivo: {{p2_goal}}
  reação a queda: {{p3_drop_reaction}}
  capacidade: {{p4_capacity}}
  experiência: {{p5_experience}}
  interesse ESG: {{p7_esg}}
  interesse crypto: {{p8_crypto}}

NOTAS CURADAS POR CLASSE (usa como base factual, reescreve em linguagem simples):
{{class_notes_curated}}

Devolve um objeto JSON com exatamente esta forma:
{
  "why_archetype": "2-4 frases explicando por que este perfil corresponde às
                    respostas dadas, em linguagem condicional",
  "class_notes": { "<class_key>": "1-2 frases educativas por cada classe na
                    alocação" },
  "safety_message": "se houve trava, mensagem educativa prioritária; senão null"
}
```

---

## 4. Schema de validação da resposta (rejeita antes de gravar)

A resposta do LLM é validada contra este schema. Se falhar qualquer regra → fallback.

```json
{
  "type": "object",
  "required": ["why_archetype", "class_notes", "safety_message"],
  "additionalProperties": false,
  "properties": {
    "why_archetype": { "type": "string", "minLength": 20, "maxLength": 600 },
    "class_notes": {
      "type": "object",
      "additionalProperties": { "type": "string", "minLength": 10, "maxLength": 400 }
    },
    "safety_message": { "type": ["string", "null"], "maxLength": 600 }
  }
}
```

### Validações semânticas adicionais (além do schema)

Estas correm depois do schema e protegem as regras absolutas:

1. **Sem instrumentos nomeados:** rejeitar se o texto contém padrões de tickers/produtos (lista de bloqueio: nomes de ETFs comuns, "S&P", "Bitcoin", "Apple", regex de tickers maiúsculos de 3-5 letras, etc.). Conservador: na dúvida, rejeita → fallback.
2. **Chaves de `class_notes` coerentes:** as chaves devem ser exatamente as classes presentes na `allocation`. Sem classes a mais ou a menos.
3. **Sem dígitos de percentagem inventados:** o LLM não deve introduzir percentagens diferentes das dadas (verificar que quaisquer números no texto coincidem com a alocação fixa).
4. **Idioma correto:** detetar que a resposta está no `locale` pedido.
5. **Coerência da trava:** se `safety_flags` continha `no_emergency_fund`, `safety_message` não pode ser null.

---

## 5. Fallback (quando o LLM falha ou a validação rejeita)

A decisão determinística **já existe** independentemente do LLM. O fallback substitui só o texto, por conteúdo **pré-escrito e curado** por arquétipo e por idioma. O utilizador recebe sempre um resultado coerente; nunca vê um erro cru nem uma página partida.

```
Estrutura do fallback (EN e PT, um conjunto por arquétipo):
  why_archetype_fallback[archetype_id][locale]
  class_notes_fallback[class_key][locale]
  safety_message_fallback[flag][locale]
```

Estes textos pré-escritos são parte do artefacto "Textos finais" (próximo na via completa) e devem passar pela mesma validação jurídica que os textos gerados.

---

## 6. Parâmetros da chamada

| Parâmetro | Valor | Razão |
|---|---|---|
| Temperatura | Baixa (~0.3) | Consistência; minimizar criatividade indesejada |
| Max tokens | Limitado ao necessário para os campos | Controlo de custo |
| Modelo | A decidir (Open Question 1) | Trade-off custo/qualidade |
| Timeout | Definido; ao exceder → fallback | Cumprir alvo <8s p95 |
| Retries | 1 retry then fallback | Evitar latência acumulada |

---

## 7. Porque é que este desenho é defensável

- O LLM nunca vê liberdade para decidir o que a pessoa deve fazer — recebe a decisão pronta.
- As regras absolutas + validação semântica impedem, na prática, que o output deslize para instrumentos nomeados ou linguagem prescritiva.
- O fallback garante que mesmo uma falha do LLM não produz conteúdo não-validado.
- `engine_version` e `disclaimer_version` (do Modelo de Dados) registam exatamente o que foi aplicado.

Este documento é material direto para a validação jurídica (Open Question 3): mostra ao jurista, em concreto, as barreiras que impedem a ferramenta de cruzar para consultoria regulada.

---

## 8. O que falta a seguir (via completa)

Fechado: ✅ modelo de dados + contratos de API · ✅ prompt do LLM + schema.
Próximos:
1. **Textos finais** (disclaimers EN/PT, notas curadas por classe, mensagens por arquétipo, fallbacks pré-escritos, micro-explicações do questionário). É também o pacote para o jurista.
2. Stack concreta (tema, plugins, hosting, staging) — pode correr em paralelo.
3. Critérios de "pronto" + plano de QA.
```
