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
│   ├── class-settings.php   # ✅ admin: chave Gemini + scoring/arquétipos (req. 6.7)
│   ├── class-consent.php    # ✅ banner de consentimento (E8, RGPD) + gate analytics
│   ├── class-analytics.php  # ✅ Google Analytics (GA4) carregado só após consentimento
│   ├── class-pdf.php        # ✅ export PDF do resultado (Dompdf, fallback HTML)
│   ├── class-rest.php       # ✅ /recommend · result · register · login · claim · my-profiles · export · account
│   ├── class-rate-limit.php # ✅ throttle por-IP nos endpoints públicos (M1)
│   ├── class-mailer.php     # ✅ email transacional via Brevo (fallback wp_mail)
│   ├── class-verification.php # ✅ double opt-in (verificação por email) (M2)
│   ├── class-google.php     # ✅ login com Google (OAuth 2.0)
│   ├── class-cron.php       # ✅ limpeza diária: perfis anónimos + contas não-verificadas (L1)
│   ├── class-pdf.php        # ⬜ geração do PDF do resultado
│   └── class-settings.php   # ⬜ página admin: chave API, modelo, arquétipos, scoring
├── assets/                  # ✅ js/{questionnaire,result,account,consent}.js, css/{app,consent}.css
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
- **`class-llm.php`** — **transporte provider-agnostic**: prefere o **WP AI Client (WordPress 7.0 — Connectors API, `wp_ai_client_prompt`)** quando disponível (provider/modelo — Gemini/Claude/OpenAI — definidos em *Settings → Connectors*), e cai no `class-gemini.php` se não estiver configurado. É **só** transporte; o `Prompt`, `Validator` e `Fallback` não mudam. Desativável pelo filtro `hti_use_wp_ai_client`.
- **`class-gemini.php`** — fallback de transporte: `generateContent` (JSON mode, temperatura 0.3, timeout 8s, 1 retry). Chave via `HTI_GEMINI_API_KEY` (wp-config) / env `GEMINI_API_KEY` / option, enviada em **header** `x-goog-api-key` — **nunca** no cliente nem nos logs.
- **`class-validator.php`** — schema (campos/limites) + semântica: sem instrumentos nomeados (blocklist + regex de tickers), sem percentagens fora da alocação, `class_notes` == classes da alocação, idioma correto, `safety_message` presente se trava disparou.
- **`class-fallback.php`** — textos pré-escritos EN+PT (Textos §2–§4), validados.
- **`class-explainer.php`** — orquestra: Gemini → validação → senão fallback; devolve `source` (`llm`/`fallback`).

### Testes
```
php wp-content/plugins/hti-engine/tests/test-explainer.php   # 17/17 ✓ (fallback válido + validador rejeita)
php wp-content/plugins/hti-engine/tests/test-prompt.php       # 11/11 ✓ (prompt carrega a decisão fixa)
php wp-content/plugins/hti-engine/tests/test-settings.php     # 16/16 ✓ (normalização rejeita config inválida)
php wp-content/plugins/hti-engine/tests/test-ratelimit.php    # 7/7  ✓ (throttle por-IP por ação)
php wp-content/plugins/hti-engine/tests/test-cron.php         # 3/3  ✓ (cutoff de retenção)
php wp-content/plugins/hti-engine/tests/test-mailer.php       # 6/6  ✓ (payload Brevo)
```

## Hardening de segurança
- **Rate limiting (M1)** — `class-rate-limit.php`: throttle por-IP via transients no `/recommend` (15/10min), `/register` (5/h) e `/login` (10/15min) → **429** ao exceder. Filtros `hti_rate_limits` / `hti_client_ip` (ex.: Cloudflare).
- **Anti-enumeração (M2) — double opt-in** — `/register` cria sempre uma conta **não-verificada** (ou reenvia) e responde **sempre o mesmo** ("verifica o teu email"), eliminando o oráculo de enumeração. O link emailado ativa a conta, autentica e faz **claim** do perfil pendente. Login bloqueado até verificar. Email via **Brevo** (`class-mailer.php`; chave `HTI_BREVO_API_KEY`/env/settings, em header, nunca ecoada) com **fallback `wp_mail`**.
- **Limpeza RGPD (L1)** — `class-cron.php`: cron diário poda perfis **anónimos não-reclamados** com >90 dias (filtro `hti_profile_retention_days`); contas/claimed nunca tocados.

## Settings admin (req. 6.7 — `class-settings.php`)

Página **Definições → HowToInvest** (cap. `manage_options`), via Settings API:
- **Gemini:** modelo + chave. A chave dá **prioridade a `HTI_GEMINI_API_KEY` (wp-config) / env**; o campo do admin é só fallback, **nunca é ecoado** de volta (em branco mantém a existente).
- **Scoring:** pesos P1–P5 e thresholds editáveis.
- **Arquétipos:** labels (EN+PT) e intervalos de alocação por classe.

A **normalização é pura e testada**: rejeita config que partiria o motor (thresholds não-contíguos, intervalos que não somam 100, `min>max`) revertendo para defaults + `settings_error`; força `crypto.min=0` (para a exclusão ser válida). Assim o motor **garante sempre** uma alocação de 100% dentro dos intervalos.

## REST — `POST /wp-json/htinvest/v1/recommend`

Liga o motor ao mundo. Protegido por **nonce** (`X-WP-Nonce`, válido também para sessões anónimas).

1. Sanitiza respostas → `Engine::recommend` (inválido → **422**).
2. `Explainer::explain` (LLM→validação→fallback; nunca quebra).
3. Persiste um **perfil anónimo** (`htinvest_profile`, privado) com respostas, score, arquétipo, alocação, explicação (+`source`), `safety_flags`, consent, `engine_version`, `disclaimer_version`, `generated_at`.
4. Devolve o contrato (Modelo §5): `profile_id`, `session_token`, `archetype`, `allocation`, `explanation`, `safety_flags`, `disclaimer` contextual.

A decisão numérica **nunca** depende do LLM: erros do Gemini caem em fallback e devolvem 200. Chave do Gemini nunca no cliente. CPT `htinvest_profile` é privado, não indexável, fora do REST default.

### Conta + RGPD (Fase 3) — todas autenticadas (login + nonce)
- **`POST /claim-profile`** — liga um perfil anónimo (por `session_token`) à conta atual: define `user_id`/autor e **limpa o `session_token`**. Identidade só por esta ação consciente (minimização). 404 se não existir, 409 se já for de outra conta.
- **`GET /my-profiles`** — resumos dos perfis do utilizador (arquétipo, alocação, travas, data).
- **`GET /export`** — **(RGPD, P0)** devolve **todos** os dados do utilizador (conta + perfis completos), com `Content-Disposition` para download.
- **`DELETE /account`** — **(RGPD, P0)** exige `confirm: true`; apaga **em cascata** todos os perfis (e meta) e depois a conta (`wp_delete_user`). Irreversível.

Minimização: perfis anónimos não têm identidade (`user_id` nulo); logs sem PII; contas nativas (`wp_users`).

### Login com Google (OAuth — `class-google.php`)
Botão **"Continuar com o Google"** no formulário de conta (só aparece se configurado). Fluxo Authorization Code **server-side**: `state` em transient (CSRF) que carrega o `session_token` a fazer claim; troca do código → `id_token` (vindo do Google por TLS, claims fiáveis) → encontra/cria `wp_user` pelo email **verificado** → autentica → claim. Client ID/secret via `HTI_GOOGLE_CLIENT_ID`/`HTI_GOOGLE_CLIENT_SECRET` (wp-config/env) ou Settings (secret nunca ecoado). O **Redirect URI** a registar no Google Console aparece nas Settings.

### Conta — registo/login + UI
- **`POST /register`** — cria conta nativa (subscriber) e autentica; devolve **novo nonce**. 422 (email/pw inválidos), 409 (já existe).
- **`POST /login`** — `wp_signon`; devolve novo nonce; 401 em credenciais erradas.
- **Frontend (`account.js`):** no resultado, **"Guardar o meu perfil"** → se não autenticado, registo/login inline → `claim-profile` (liga o perfil anónimo). Dashboard **`[hti_account]`** (página `my-account`, noindex): lista `/my-profiles`, **Exportar** (download do `/export`) e **Apagar conta** (`/account`, com confirmação). EN+PT, acessível.

## Consentimento (E8 — `class-consent.php` + `assets/.../consent.*`)

Banner próprio, sem dependências, **privacy-first**:
- Analítica/não-essenciais **OFF por omissão**; só corre após opt-in explícito.
- Escolha registada no cookie `hti_consent` (`{analytics, ts}`, 180 dias, `SameSite=Lax`/`Secure`).
- Botões: **Aceitar** · **Recusar não-essenciais** · **Personalizar** (toggle de analítica) + link à política de privacidade. EN+PT, acessível.
- Gate **server-side**: `Consent::analytics_allowed()` (lê o cookie) + filtro `hti_analytics_allowed` — usa-o para condicionar qualquer script de analítica.
- Gate **client-side**: `window.HTIConsent.get()/open()` + evento `hti-consent-changed`. O questionário já envia o `consent.analytics` real (do cookie) ao `/recommend`.

### Google Analytics (GA4 — `class-analytics.php` + `analytics.js`)
O `gtag.js` **não** carrega até o utilizador aceitar a analítica no banner (invariante RGPD: nada de trackers antes do consentimento). O `analytics.js` injeta o GA só quando o consentimento é dado e reage ao evento `hti-consent-changed` (carrega de imediato ao aceitar, sem reload). ID configurável em **Settings → Analytics** ou pelo filtro `hti_ga_id` (default `G-QWST7PZNBT`); vazio desativa. `anonymize_ip` ativo.

## Export PDF (`class-pdf.php`)

Botão **Exportar PDF** no resultado → POST a `admin-post.php` (action `hti_pdf`, nonce; token fora do URL) → autoriza por **dono da conta** ou **`session_token`** do perfil → gera o documento (arquétipo, disclaimer, gráfico de barras + tabela, "porquê", notas por classe, rodapé com data/disclaimer curto).

- Render via **Dompdf** (`composer require dompdf/dompdf`, instalado no deploy; `vendor/` não versionado, autoload condicional no bootstrap).
- **Fallback** sem a lib: serve HTML imprimível (Print → Guardar como PDF), por isso funciona mesmo antes do `composer install`.

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
- **7 páginas**: `investor-profile-quiz` (`[hti_questionnaire]`), `my-account` (`[hti_account]`), `about`, `contact`, `how-to-start-investing` (guia + CTA), `privacy-policy` e `terms-and-conditions` (placeholders, **carecem de revisão jurídica**).
- **8 artigos educativos** (posts, EN+PT, com CTA e links ao glossário): perfil de investidor, classes de ativos, horizonte temporal, manter a calma nas quedas, fundo de emergência, diversificação, risco vs. retorno, ESG.
- **Bilingue:** EN no post; PT em meta (`hti_title_pt`, `hti_content_pt`, `hti_excerpt_pt`) — *language-aware* até a abordagem multilíngue ser fechada.
- **Idempotente:** salta entradas já existentes (por slug); nunca sobrescreve edições.
- Define `wp_page_for_privacy_policy` (alinhamento RGPD).
- **Executar:** `wp hti seed` (WP-CLI) **ou** wp-admin → **Ferramentas → Semear conteúdo** (botão, nonce + `manage_options`).

## Notas

- Text domain: `hti-engine`. Toda a string voltada ao utilizador em EN (default) + PT (`languages/`).
- Slugs canónicos otimizados para SEO (keyword-rich): `/investing-glossary/`, `/financial-news/`, `/how-to-start-investing/`, `/investor-profile-quiz/`. Utilitárias/legais ficam limpas.
- A seguir na Fase 1: conteúdo seed (1.5) — criar as páginas-alvo (`/about/`, `/how-to-start-investing/`, legais) e os artigos/termos.
