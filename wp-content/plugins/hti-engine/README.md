# hti-engine (plugin custom)

O produto interativo do HowToInvest. Ver `docs/Stack_Concreta §4` para a estrutura alvo e a skill `hti-engine-spec`.

**Princípio:** as regras determinísticas decidem (arquétipo + alocação); o LLM só explica. Tudo prefixado `hti_` / namespace `HTI\Engine`.

## Estrutura alvo

```
hti-engine/
├── hti-engine.php          # bootstrap, hooks, ativação (flush rewrite)
├── includes/
│   ├── class-cpt.php        # ✅ CPTs: glossary + news (públicos) · htinvest_profile (privado)
│   ├── class-taxonomy.php   # ✅ taxonomias: glossary_topic + news_category (internal linking)
│   ├── class-seo.php        # ✅ JSON-LD: DefinedTerm (glossary) + Article/NewsArticle (fallback)
│   ├── class-redirects.php  # ✅ 301s dos URLs antigos do Base44 (mapa filtrável)
│   ├── class-seeder.php     # ✅ conteúdo seed: termos de glossário + páginas (1.5)
│   ├── class-config.php     # ✅ scoring + arquétipos curados (editáveis via options)
│   ├── class-engine.php     # ✅ regras determinísticas (pontuação→arquétipo→alocação→travas)
│   ├── class-fallback.php   # ✅ textos curados/fallback por arquétipo/classe/trava (EN+PT)
│   ├── class-validator.php  # ✅ schema + validações semânticas (rejeita → fallback)
│   ├── class-prompt.php     # ✅ system + user prompt (Prompt §2–3)
│   ├── class-gemini.php     # ✅ chamada server-side ao Gemini (chave em header, retry→fallback)
│   ├── class-explainer.php  # ✅ orquestra Gemini→validação→fallback
│   ├── class-disclaimer.php # ✅ disclaimer contextual versionado (Textos §1.1)
│   ├── class-questions.php  # ✅ definição do questionário (EN+PT) p/ o frontend
│   ├── class-frontend.php   # ✅ shortcode [hti_questionnaire] + enqueue + noindex
│   ├── class-rest.php       # ◑ /recommend ✅ · claim-profile/my-profiles/account/export ⬜
│   ├── class-pdf.php        # ⬜ geração do PDF do resultado
│   └── class-settings.php   # ⬜ página admin: chave API, modelo, arquétipos, scoring
├── assets/                  # ✅ js/questionnaire.js, js/result.js, css/app.css
├── tests/                   # ✅ matriz do motor (bootstrap.php + test-engine.php)
└── languages/               # ✅ hti-engine.pot + hti-engine-pt_PT.l10n.php
```

## Motor de recomendação (Fase 2 — `class-engine.php` + `class-config.php`)

**Regra de ouro:** as regras decidem, o LLM só explica. O `Engine` é **PHP puro** (sem WordPress, sem LLM) → totalmente determinístico e testável.

- **Scoring P1–P5** com pesos de `htinvest_scoring` (default em `Config`). Soma 0–27.
- **Arquétipo (1–5)** por thresholds: `0–5→1, 6–11→2, 12–17→3, 18–23→4, 24–27→5`.
- **Alocação** por classe (`global_equity, bonds, reits_alt, cash, crypto`), dentro dos intervalos curados de `htinvest_archetypes`, **soma sempre 100**.
- **Travas:** `no_emergency_fund` (P6=não), `horizon_override` (P1=3y + score alto → limita a arquétipo 2), `crypto_blocked` (crypto pedida mas arquétipo <3 ou sem fundo de emergência).
- **Crypto** só com P8=sim **e** arquétipo ≥3 **e** sem trava 1; fatia pequena fixa (2%) no extremo inferior.
- `engine_version` gravado em cada resultado (auditoria).

### Testes (matriz repetível — Criterios §1)
```
php wp-content/plugins/hti-engine/tests/test-engine.php
```
13 cenários (1 por arquétipo, 1 por trava, crypto concedida/bloqueada, ESG-é-lente) + fronteiras dos thresholds + determinismo + input inválido. Corre sem WordPress/PHPUnit; sai com código ≠ 0 em falha. **Estado: 85/85 ✓.**

## Camada de explicação (LLM só explica)

**Regra de ouro:** o LLM nunca decide. Recebe a decisão como facto e produz só texto; se falhar ou a validação rejeitar, entra o fallback curado. A alocação numérica sai sempre.

- **`class-prompt.php`** — system prompt (regras absolutas) + user prompt com a alocação fixa, arquétipo, travas, respostas e notas curadas.
- **`class-gemini.php`** — `generateContent` (JSON mode, temperatura 0.3, timeout 8s, 1 retry). Chave via `HTI_GEMINI_API_KEY` (wp-config) / env `GEMINI_API_KEY` / option, enviada em **header** `x-goog-api-key` — **nunca** no cliente nem nos logs.
- **`class-validator.php`** — schema (campos/limites) + semântica: sem instrumentos nomeados (blocklist + regex de tickers), sem percentagens fora da alocação, `class_notes` == classes da alocação, idioma correto, `safety_message` presente se trava disparou.
- **`class-fallback.php`** — textos pré-escritos EN+PT (Textos §2–§4), validados.
- **`class-explainer.php`** — orquestra: Gemini → validação → senão fallback; devolve `source` (`llm`/`fallback`).

### Testes
```
php wp-content/plugins/hti-engine/tests/test-explainer.php   # 17/17 ✓ (fallback válido + validador rejeita)
php wp-content/plugins/hti-engine/tests/test-prompt.php       # 11/11 ✓ (prompt carrega a decisão fixa)
```

## REST — `POST /wp-json/htinvest/v1/recommend`

Liga o motor ao mundo. Protegido por **nonce** (`X-WP-Nonce`, válido também para sessões anónimas).

1. Sanitiza respostas → `Engine::recommend` (inválido → **422**).
2. `Explainer::explain` (LLM→validação→fallback; nunca quebra).
3. Persiste um **perfil anónimo** (`htinvest_profile`, privado) com respostas, score, arquétipo, alocação, explicação (+`source`), `safety_flags`, consent, `engine_version`, `disclaimer_version`, `generated_at`.
4. Devolve o contrato (Modelo §5): `profile_id`, `session_token`, `archetype`, `allocation`, `explanation`, `safety_flags`, `disclaimer` contextual.

A decisão numérica **nunca** depende do LLM: erros do Gemini caem em fallback e devolvem 200. Chave do Gemini nunca no cliente. CPT `htinvest_profile` é privado, não indexável, fora do REST default.

## Frontend (E5–E7 — `class-frontend.php` + `assets/`)

Shortcode **`[hti_questionnaire]`** (a página `investor-profile-quiz` é criada pelo seeder). JS vanilla, sem build, carregado **só** nessa página; a página fica **noindex**.

- **`questionnaire.js`** — multi-step (1 pergunta/passo), barra de progresso, **estado parcial em `sessionStorage`**, acessível (fieldset/legend, foco gerido, teclado, `aria-live`/`role=alert`), mini-explicadores de ESG/crypto no "não sei". Valida no cliente só para deixar avançar; **scoring é sempre server-side**.
- **`result.js`** — render só a partir da resposta do servidor (nunca recalcula): arquétipo, disclaimer não-dispensável, **donut SVG próprio (sem libs)** + **lista de texto equivalente** (acessibilidade), "porquê", notas por classe, ações educativas (Aprender mais / Refazer). Travas educam primeiro (E7b).
- **`app.css`** — herda os tokens do `theme.json` (CSS vars) com fallback; mobile-first, foco visível, respeita `prefers-reduced-motion`.
- i18n: todas as strings vêm do servidor (`Questions::payload`), EN+PT.

Submissão: `fetch` POST a `/recommend` com nonce → processing → resultado; 422/500/erro de rede → estado de erro com "tentar novamente". Chave Gemini nunca no cliente.

> A seguir: rotas de conta/RGPD (claim-profile, my-profiles, export, account), login Google e PDF.

## Estado atual (Fase 1 — Fundação SEO)

**Tarefa 1.3 — CPTs de conteúdo (feito):**
- `glossary` — público, indexável, com arquivo em `/investing-glossary/`. Sementes de SEO/glossário (notas por classe de ativo → "O que é uma obrigação?").
- `news` — público, indexável, com arquivo em `/financial-news/`. Conteúdo editorial.
- Ambos `show_in_rest` (editor de blocos), labels **EN+PT**, e os slugs batem com os templates do tema (`single-glossary`, `archive-news`, etc.).
- Ativação faz `flush_rewrite_rules` para os permalinks resolverem de imediato.

> Os CPTs vivem no **plugin** (não no tema) para sobreviverem a trocas de tema. O CPT **privado** `htinvest_profile` (motor) entra na mesma `class-cpt` na Fase 2.

**Taxonomias (feito — `class-taxonomy.php`):**
- `glossary_topic` (tópicos, hierárquica) em `glossary` → arquivo `/glossary-topic/{termo}/`.
- `news_category` (categorias, hierárquica) em `news` → arquivo `/news-category/{termo}/`.
- Públicas, `show_in_rest`, `show_admin_column` — base de **internal linking** (objetivo SEO da Fase 1).
- O seeder cria o tópico **"Asset classes"** (PT em term meta `hti_name_pt`) e atribui-lhe os 5 termos de glossário → hub de links pronto a usar.
- Os arquivos de termo usam o template genérico `archive.html` do tema (hierarquia de templates do WP); pode criar-se `taxonomy-glossary_topic.html` depois se quiseres um layout dedicado.

**Schema / sitemap (feito — `class-seo.php`):**
- Glossário emite sempre **`DefinedTerm`** (JSON-LD), ligado ao `DefinedTermSet` do arquivo `/investing-glossary/`.
- `Article` / `NewsArticle` saem como **fallback** apenas quando **não** há plugin SEO ativo (deteta RankMath/Yoast), para não duplicar com o RankMath.
- Sitemaps/metas são do **RankMath**; os CPTs são `public`/`has_archive`, logo entram automaticamente.

### Config no wp-admin (RankMath — decisão fechada)
1. Instalar/ativar **RankMath** (não versionado).
2. **Sitemap Settings** → confirmar `glossary` e `news` incluídos.
3. **Titles & Meta → Post Types** → schema default: `news` = *NewsArticle*, `glossary` = *Article* (o `DefinedTerm` é adicionado pelo plugin).
4. Mais tarde (Fase 2): **noindex** em questionário/resultado + staging (invariante).

**Migração SEO — 301s (feito — `class-redirects.php`):**

| Antigo (Base44) | Novo | Nota |
|---|---|---|
| `/About` | `/about/` | página |
| `/Contact` | `/contact/` | página |
| `/FinancialNews` | `/financial-news/` | arquivo CPT `news` (slug SEO) |
| `/FinancialNewsArticle` | `/financial-news/` | sem id → arquivo |
| `/HowToStart` | `/how-to-start-investing/` | keyword SEO |
| `/PrivacyPolicy` | `/privacy-policy/` | página legal |
| `/Questionnaire` | `/investor-profile-quiz/` | Fase 2 (slug SEO) |
| `/TermsAndConditions` | `/terms-and-conditions/` | página legal |

- Redirect 301 via `template_redirect`, case-insensitive, ignora query string.
- Mapa editável sem deploy via filtro **`hti_legacy_redirects`** — ajusta os slugs para baterem com as páginas que criares (1.5).

**Conteúdo seed (feito — `class-seeder.php`, tarefa 1.5):**
- **5 termos de glossário** (as notas curadas por classe de ativo, Textos §2): `global-equities`, `bonds`, `cash`, `reits-and-alternatives`, `crypto`.
- **6 páginas**: `investor-profile-quiz` (com o shortcode `[hti_questionnaire]`), `about`, `contact`, `how-to-start-investing` (guia real + CTA), `privacy-policy` e `terms-and-conditions` (placeholders com aviso, **carecem de revisão jurídica**).
- **Bilingue:** EN no post; PT em meta (`hti_title_pt`, `hti_content_pt`, `hti_excerpt_pt`) — *language-aware* até a abordagem multilíngue ser fechada.
- **Idempotente:** salta entradas já existentes (por slug); nunca sobrescreve edições.
- Define `wp_page_for_privacy_policy` (alinhamento RGPD).
- **Executar:** `wp hti seed` (WP-CLI) **ou** wp-admin → **Ferramentas → Semear conteúdo** (botão, nonce + `manage_options`).

## Notas

- Text domain: `hti-engine`. Toda a string voltada ao utilizador em EN (default) + PT (`languages/`).
- Slugs canónicos otimizados para SEO (keyword-rich): `/investing-glossary/`, `/financial-news/`, `/how-to-start-investing/`, `/investor-profile-quiz/`. Utilitárias/legais ficam limpas.
- A seguir na Fase 1: conteúdo seed (1.5) — criar as páginas-alvo (`/about/`, `/how-to-start-investing/`, legais) e os artigos/termos.
