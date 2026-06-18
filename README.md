# HowToInvest — Projeto WordPress MVP

**AI-Powered Investing for Everyone** — reconstrução em WordPress (full-WP) da plataforma educativa de perfis de investimento.

> 👉 **A começar agora?** Lê o **`START_HERE.md`** primeiro — tem o plano de arranque e a ordem de leitura. Se estás no Claude Code, lê também o `CLAUDE.md`.

Plataforma onde, através de um questionário educativo, o utilizador descobre o seu **arquétipo de investidor** e recebe um **exemplo ilustrativo de carteira por classes de ativos** — sempre num enquadramento educativo, nunca como aconselhamento.

---

## 🎯 O essencial em 30 segundos

- **Objetivo:** SEO e conteúdo (crescer por tráfego orgânico).
- **Stack:** WordPress 7.0+ · block theme nativo · plugin custom `hti-engine` · JS ligeiro · Google Gemini (LLM) · hosting cPanel.
- **Princípio do motor:** as regras determinísticas **decidem** (arquétipo + alocação); o LLM apenas **explica**. Nunca o contrário.
- **Enquadramento:** ferramenta **educativa** — classes de ativos (nunca instrumentos), linguagem ilustrativa, disclaimers contextuais.
- **Idiomas:** EN (default) + PT.

---

## 📚 Documentos de especificação (`/docs`)

Ler por esta ordem na primeira vez:

| # | Documento | O que define |
|---|-----------|--------------|
| 1 | `PRD_HowToInvest_WordPress_MVP.md` | Visão, scope, requisitos P0/P1/P2, motor educativo (§11) |
| 2 | `Wireframes_Fluxo_HowToInvest_MVP.md` | Ecrãs, fluxo de navegação, estados/edge cases |
| 3 | `Modelo_Dados_API_HowToInvest_MVP.md` | Entidades, CPTs, contratos REST, RGPD |
| 4 | `Prompt_LLM_Schema_HowToInvest_MVP.md` | System/user prompt, schema, validação semântica, fallback |
| 5 | `Textos_Finais_HowToInvest_MVP.md` | Disclaimers, notas por classe, arquétipos, travas, micro-explicações (EN+PT) |
| 6 | `Stack_Concreta_HowToInvest_MVP.md` | Servidor, tema, plugins, estrutura do `hti-engine`, ambientes |
| 7 | `Criterios_Pronto_QA_HowToInvest_MVP.md` | Definition of Done, checklists, gate de lançamento |

---

## 🗂️ Estrutura do repositório

```
howtoinvest/
├── README.md                    # este ficheiro
├── CLAUDE.md                    # contexto que o Claude Code lê sempre
├── docs/                        # os 7 documentos de especificação
├── .claude/
│   └── skills/                  # skills de desenvolvimento (ver abaixo)
└── wp-content/
    ├── themes/howtoinvest/      # child block theme (FSE)
    └── plugins/hti-engine/      # o plugin custom = o produto
```

> **Nota:** versiona em Git apenas `wp-content/themes/howtoinvest`, `wp-content/plugins/hti-engine`, `docs/`, `.claude/`, `CLAUDE.md` e `README.md`. O core do WordPress e plugins de terceiros **não** vão para o repo.

---

## 🧩 Skills de desenvolvimento (`.claude/skills/`)

Base de conhecimento para o Claude Code construir tema e plugin com qualidade e consistência:

| Skill | Quando dispara |
|-------|----------------|
| `wordpress-backend` | CPTs, hooks, REST API, opções, segurança WP, i18n |
| `wordpress-theme` | Block theme, theme.json, templates FSE, child theme |
| `php-standards` | PHP 8.4 moderno, WPCS, segurança, sanitização |
| `frontend-vanilla` | JS ligeiro, questionário multi-step, charts, acessibilidade |
| `ux-ui-design` | Design tokens, hierarquia, estados, mobile-first |
| `hti-engine-spec` | Regras do motor, arquétipos, travas, integração Gemini |
| `seo-wordpress` | Schema, sitemaps, metas, Core Web Vitals, 301s |
| `accessibility` | WCAG 2.1 AA, teclado, ARIA, contraste |
| `gdpr-data` | Consentimento, direitos do titular, minimização |

---

## 🔌 MCPs recomendados

Para o desenvolvimento, considerar ligar no Claude Code:
- **Base de dados (MySQL):** acesso à BD do WordPress para inspeção/queries durante o desenvolvimento. *(Decidir: o WP no cPanel usa MySQL; o Supabase já ligado é Postgres — clarificar se há papel para ele ou se é só MySQL do cPanel.)*
- **Gemini API:** não é um MCP — é uma chave de API consumida server-side pelo `hti-engine` (guardada via env/wp-config, nunca no cliente).

Ver `CLAUDE.md` para detalhes operacionais.

---

## 🚦 Por onde começar

**Fase 1 — Fundação SEO** (não depende do motor):
1. WordPress 7.0+ no cPanel, PHP 8.4, HTTPS.
2. Child block theme a partir de tema base oficial.
3. Plugin SEO + estrutura de conteúdo (posts, CPT glossário, CPT notícias).
4. 301s dos URLs antigos do Base44.
5. 5–10 artigos seed + termos de glossário (reutilizar notas por classe dos Textos Finais).

Depois: Fase 2 (motor), Fase 3 (conta+RGPD+PDF), Fase 4 (lançamento). Ver PRD §9.

---

## ⚠️ Invariantes que nunca se quebram

1. O LLM **nunca** decide alocações — só explica.
2. Output **sempre** por classes de ativos, nunca instrumentos nomeados.
3. Disclaimer contextual em **todos** os resultados.
4. Sem criar conta → **nenhum** dado identificado é retido.
5. Export e delete de conta (RGPD) são **P0**, não opcionais.
6. Chave Gemini **nunca** chega ao cliente.
7. Toda a string voltada ao utilizador existe em **EN e PT**.
