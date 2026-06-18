# Modelo de Dados e Contratos de API — HowToInvest MVP

**Companion do:** PRD HowToInvest WordPress MVP (v2) + Wireframes
**Data:** 18 de junho de 2026
**Estado:** especificação técnica para desenvolvimento

## Decisões fechadas (registo)

| Decisão | Escolha |
|---|---|
| Perfis submetidos | Guardados, com conta de utilizador no MVP (scope alargado) |
| Conta de utilizador | **Dentro do MVP** (P0) — alarga âmbito e data |
| Base de utilizadores | Sistema nativo do WordPress (`wp_users`) |
| Intervalos de alocação | Configuração editável no admin (honra req. 6.7) |
| Idioma | Bilingue, default **EN** + PT (modelo language-aware) |
| Resultado | Guardado (não recalculado ao recarregar/exportar) |
| Direitos RGPD | Ver/exportar/apagar conta = **P0** (requisito legal) |

> **Nota de scope:** a conta de utilizador entrou no MVP por decisão explícita. Isto traz registo, login (incl. Google), recuperação de password e direitos do titular (ver/exportar/apagar) para dentro do P0. O PRD deve ser atualizado para refletir esta mudança.

---

## 1. Visão geral das entidades

```
wp_users (nativo WP)
   │ 1
   │
   │ N
htinvest_profile (CPT privado)
   │ 1
   │
   │ 1
htinvest_result (guardado com o perfil)

CONFIGURAÇÃO (wp_options):
  htinvest_archetypes   ← intervalos de alocação editáveis
  htinvest_scoring      ← pesos de pontuação editáveis
  htinvest_settings     ← chave API, modelo, temperatura, idioma default
```

**Princípios:**
- O perfil liga-se a um utilizador WP nativo (`user_id`). Campo **opcional**: um perfil pode existir anónimo (sessão) e ser "adotado" por uma conta quando o utilizador a cria.
- Decisão (arquétipo + alocação) é determinística e vem das regras curadas; o LLM só preenche os campos de *texto explicativo*.
- Todos os campos de texto voltados ao utilizador têm variante por idioma (`en`, `pt`).

---

## 2. Entidade: Perfil (`htinvest_profile`)

Custom Post Type privado (`exclude_from_search`, não indexável).

| Campo | Tipo | Notas |
|---|---|---|
| `id` | int | ID do post WP |
| `user_id` | int \| null | FK para `wp_users`. **Null** se anónimo. Preenchido ao criar conta. |
| `session_token` | string | Liga perfil anónimo à sessão antes de haver conta |
| `created_at` | datetime | |
| `locale` | enum(`en`,`pt`) | Idioma em que foi respondido |
| `answers` | object | Respostas P1–P8 (ver abaixo) |
| `score` | int | Soma P1–P5 (0–27) |
| `archetype_id` | enum(1..5) | Resultante da pontuação |
| `safety_flags` | array | Travas disparadas: `["no_emergency_fund"]`, `["horizon_override"]`, `["crypto_blocked"]` |
| `consent` | object | `{ analytics: bool, timestamp }` (RGPD) |

### Estrutura `answers`

```json
{
  "p1_horizon": "7_15y",          // 3y | 3_7y | 7_15y | over_15y
  "p2_goal": "accumulate",        // protect | grow | accumulate | maximize
  "p3_drop_reaction": "hold",     // sell_all | sell_part | hold | buy_more
  "p4_capacity": "comfortable",   // almost_none | small | comfortable | significant
  "p5_experience": "some",        // never | little | some | confident
  "p6_emergency_fund": true,      // bool — trava 1
  "p7_esg": "yes",                // yes | no | unknown  (lente, não pontua)
  "p8_crypto": "yes"              // yes | no | unknown  (lente, não pontua)
}
```

---

## 3. Entidade: Resultado (`htinvest_result`)

Guardado junto ao perfil (post meta ou tabela ligada). Não recalculado ao recarregar/exportar.

| Campo | Tipo | Origem | Notas |
|---|---|---|---|
| `profile_id` | int | — | FK para o perfil |
| `archetype_id` | enum(1..5) | regras | |
| `allocation` | array | regras | Por **classe de ativo**, soma 100% |
| `explanation` | object | **LLM** | Texto por idioma — só explicação |
| `disclaimer_version` | string | config | Versão do disclaimer aplicada (auditoria) |
| `generated_at` | datetime | — | |
| `engine_version` | string | — | Versão das regras curadas (auditoria) |

### Estrutura `allocation` (por classes, nunca instrumentos)

```json
[
  { "class": "global_equity", "pct": 60 },
  { "class": "bonds",         "pct": 30 },
  { "class": "reits_alt",     "pct": 7  },
  { "class": "cash",          "pct": 3  }
]
```
*Regra de validação: soma === 100. Cada `pct` dentro do intervalo curado do arquétipo. Crypto sempre no extremo inferior.*

### Estrutura `explanation` (gerada pelo LLM, language-aware)

```json
{
  "en": {
    "why_archetype": "You indicated a 7–15 year horizon and that you stay calm during drops...",
    "class_notes": {
      "global_equity": "Global equities are the growth engine of a portfolio...",
      "bonds": "Bonds add stability..."
    },
    "safety_message": null
  },
  "pt": { "...": "..." }
}
```
*O LLM **não** produz `allocation` nem `archetype_id`. Só texto. Validado contra schema antes de gravar.*

---

## 4. Configuração editável (admin, req. 6.7)

### `htinvest_archetypes` — intervalos de alocação

```json
{
  "3": {
    "label": { "en": "Balanced", "pt": "Equilibrado" },
    "ranges": {
      "global_equity": [50, 65],
      "bonds":         [25, 35],
      "cash":          [0, 5],
      "reits_alt":     [5, 10],
      "crypto":        [0, 3]
    }
  }
}
```

### `htinvest_scoring` — pesos de pontuação e mapeamento

```json
{
  "weights": {
    "p1_horizon":   { "3y":0, "3_7y":2, "7_15y":4, "over_15y":6 },
    "p2_goal":      { "protect":0, "grow":2, "accumulate":4, "maximize":6 },
    "p3_drop_reaction": { "sell_all":0, "sell_part":2, "hold":4, "buy_more":6 },
    "p4_capacity":  { "almost_none":0, "small":2, "comfortable":4, "significant":6 },
    "p5_experience":{ "never":0, "little":1, "some":2, "confident":3 }
  },
  "thresholds": { "1":[0,5], "2":[6,11], "3":[12,17], "4":[18,23], "5":[24,27] }
}
```
*Mudar números aqui = sem deploy. Cada alteração incrementa `engine_version` para auditoria.*

---

## 5. Contratos de API (endpoints do plugin)

Todos server-side, com nonce WP. A chave da API LLM nunca chega ao cliente.

### POST `/wp-json/htinvest/v1/recommend`

Recebe respostas, classifica (determinístico), chama LLM para explicação, valida, devolve.

**Request**
```json
{
  "locale": "en",
  "answers": { "p1_horizon": "7_15y", "...": "..." },
  "consent": { "analytics": false }
}
```

**Response 200**
```json
{
  "profile_id": 1234,
  "session_token": "abc...",
  "archetype": { "id": 3, "label": "Balanced" },
  "allocation": [ { "class": "global_equity", "pct": 60 }, "..." ],
  "explanation": { "why_archetype": "...", "class_notes": {}, "safety_message": null },
  "safety_flags": [],
  "disclaimer": "Educational tool. This is an illustrative example..."
}
```

**Erros** (nunca quebram a página — frontend mostra fallback)
| Código | Significado | Comportamento |
|---|---|---|
| 422 | Respostas inválidas/incompletas | Pedir correção |
| 502 | LLM timeout/quota | Devolver alocação + **explicação fallback pré-escrita** por arquétipo |
| 500 | Erro interno | Fallback + log |

*Decisão determinística (arquétipo + alocação) **nunca** depende do LLM. Se o LLM falhar, o resultado numérico sai na mesma; só o texto é substituído por fallback.*

### POST `/wp-json/htinvest/v1/claim-profile`

Liga um perfil anónimo (via `session_token`) à conta recém-criada (`user_id` do WP). Requer utilizador autenticado.

### GET `/wp-json/htinvest/v1/my-profiles`
Lista perfis do utilizador autenticado (base do dashboard).

### DELETE `/wp-json/htinvest/v1/account` — **RGPD P0**
Apaga a conta e todos os perfis/resultados associados. Irreversível, com confirmação.

### GET `/wp-json/htinvest/v1/export` — **RGPD P0**
Exporta todos os dados do utilizador (portabilidade).

---

## 6. Fluxo de dados (anónimo → conta)

```
1. Visitante responde questionário  → perfil criado com user_id=null + session_token
2. POST /recommend                  → arquétipo + alocação (regras) + explicação (LLM)
3. Resultado guardado e mostrado     → disclaimer contextual
4. Utilizador escolhe "criar conta"  → registo WP nativo (email/pass ou Google)
5. POST /claim-profile               → perfil.user_id = novo user; session_token limpo
6. Dashboard                         → GET /my-profiles
   (a qualquer momento: export / delete — RGPD)
```

---

## 7. Direitos RGPD embebidos no modelo (P0)

- **Acesso/portabilidade:** `GET /export` devolve todos os dados do utilizador.
- **Apagamento:** `DELETE /account` remove conta + perfis + resultados em cascata.
- **Minimização:** perfis anónimos não guardam identidade; só viram identificados por ação consciente do utilizador (`claim-profile`).
- **Consentimento:** campo `consent` no perfil, registado antes de qualquer analítica não-essencial.
- **Sensibilidade:** o perfil financeiro é tratado como dado sensível — confirmar tratamento concreto com jurista (liga à Open Question 3 do PRD).

---

## 8. Notas de auditoria (defensabilidade do motor)

- `engine_version` e `disclaimer_version` gravados em cada resultado: permite provar, no futuro, exatamente que regras e que disclaimer foram aplicados a cada recomendação.
- A `allocation` é sempre rastreável às regras curadas (`htinvest_archetypes`), nunca à imaginação do LLM.
- Logs do motor não devem conter dados pessoais identificáveis.

---

## 9. O que falta a seguir (via completa)

Fechado: ✅ modelo de dados + contratos de API.
Próximos:
1. Prompt do LLM explicador + schema de validação da `explanation` (encaixa diretamente nos contratos acima).
2. Textos finais (disclaimers EN/PT, mensagens por arquétipo, fallbacks pré-escritos, micro-explicações).
3. Stack concreta (tema, plugins, hosting, staging) — pode correr em paralelo.
4. Critérios de "pronto" + plano de QA.
5. **Atualizar o PRD**: mover conta de utilizador e direitos RGPD de P2 → P0.
```
