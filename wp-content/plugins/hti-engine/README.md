# hti-engine (plugin custom)

O produto interativo do HowToInvest. Ver `docs/Stack_Concreta §4` para a estrutura alvo e a skill `hti-engine-spec`.

**Princípio:** as regras determinísticas decidem (arquétipo + alocação); o LLM só explica. Tudo prefixado `hti_` / namespace `HTI\Engine`.

## Estrutura alvo

```
hti-engine/
├── hti-engine.php          # bootstrap, hooks, ativação (flush rewrite)
├── includes/
│   ├── class-cpt.php        # ✅ CPTs: glossary + news (públicos) · htinvest_profile (Fase 2)
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
- `glossary` — público, indexável, com arquivo em `/glossary/`. Sementes de SEO/glossário (notas por classe de ativo → "O que é uma obrigação?").
- `news` — público, indexável, com arquivo em `/news/`. Conteúdo editorial.
- Ambos `show_in_rest` (editor de blocos), labels **EN+PT**, e os slugs batem com os templates do tema (`single-glossary`, `archive-news`, etc.).
- Ativação faz `flush_rewrite_rules` para os permalinks resolverem de imediato.

> Os CPTs vivem no **plugin** (não no tema) para sobreviverem a trocas de tema. O CPT **privado** `htinvest_profile` (motor) entra na mesma `class-cpt` na Fase 2.

## Notas

- Text domain: `hti-engine`. Toda a string voltada ao utilizador em EN (default) + PT (`languages/`).
- A seguir na Fase 1: schema/sitemap por tipo de página (skill `seo-wordpress`) e o pattern de CTA (já existe no tema).
