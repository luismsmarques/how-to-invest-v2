# hti-engine (plugin custom)

O produto interativo do HowToInvest. Ver `docs/Stack_Concreta §4` para a estrutura alvo e a skill `hti-engine-spec`.

**Princípio:** as regras determinísticas decidem (arquétipo + alocação); o LLM só explica. Tudo prefixado `hti_` / namespace `HTI\Engine`.

## Estrutura alvo

```
hti-engine/
├── hti-engine.php          # bootstrap, hooks, ativação (flush rewrite)
├── includes/
│   ├── class-cpt.php        # ✅ CPTs: glossary + news (públicos) · htinvest_profile (Fase 2)
│   ├── class-seo.php        # ✅ JSON-LD: DefinedTerm (glossary) + Article/NewsArticle (fallback)
│   ├── class-redirects.php  # ✅ 301s dos URLs antigos do Base44 (mapa filtrável)
│   ├── class-seeder.php     # ✅ conteúdo seed: termos de glossário + páginas (1.5)
│   ├── class-rest.php       # ⬜ endpoints /recommend, /claim-profile, /my-profiles, /account, /export
│   ├── class-engine.php     # ⬜ regras determinísticas (pontuação→arquétipo→alocação)
│   ├── class-gemini.php     # ⬜ chamada server-side ao Gemini + validação schema
│   ├── class-fallback.php   # ⬜ textos pré-escritos por arquétipo/idioma
│   ├── class-pdf.php        # ⬜ geração do PDF do resultado
│   └── class-settings.php   # ⬜ página admin: chave API, modelo, arquétipos, scoring
├── assets/                  # ⬜ js/questionnaire.js, js/result.js, css/
└── languages/               # ✅ hti-engine.pot + hti-engine-pt_PT.l10n.php
```

## Estado atual (Fase 1 — Fundação SEO)

**Tarefa 1.3 — CPTs de conteúdo (feito):**
- `glossary` — público, indexável, com arquivo em `/investing-glossary/`. Sementes de SEO/glossário (notas por classe de ativo → "O que é uma obrigação?").
- `news` — público, indexável, com arquivo em `/financial-news/`. Conteúdo editorial.
- Ambos `show_in_rest` (editor de blocos), labels **EN+PT**, e os slugs batem com os templates do tema (`single-glossary`, `archive-news`, etc.).
- Ativação faz `flush_rewrite_rules` para os permalinks resolverem de imediato.

> Os CPTs vivem no **plugin** (não no tema) para sobreviverem a trocas de tema. O CPT **privado** `htinvest_profile` (motor) entra na mesma `class-cpt` na Fase 2.

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
- **5 páginas** alvo dos 301s: `about`, `contact`, `how-to-start-investing` (guia real + CTA), `privacy-policy` e `terms-and-conditions` (placeholders com aviso, **carecem de revisão jurídica**).
- **Bilingue:** EN no post; PT em meta (`hti_title_pt`, `hti_content_pt`, `hti_excerpt_pt`) — *language-aware* até a abordagem multilíngue ser fechada.
- **Idempotente:** salta entradas já existentes (por slug); nunca sobrescreve edições.
- Define `wp_page_for_privacy_policy` (alinhamento RGPD).
- **Executar:** `wp hti seed` (WP-CLI) **ou** wp-admin → **Ferramentas → Semear conteúdo** (botão, nonce + `manage_options`).

## Notas

- Text domain: `hti-engine`. Toda a string voltada ao utilizador em EN (default) + PT (`languages/`).
- Slugs canónicos otimizados para SEO (keyword-rich): `/investing-glossary/`, `/financial-news/`, `/how-to-start-investing/`, `/investor-profile-quiz/`. Utilitárias/legais ficam limpas.
- A seguir na Fase 1: conteúdo seed (1.5) — criar as páginas-alvo (`/about/`, `/how-to-start-investing/`, legais) e os artigos/termos.
