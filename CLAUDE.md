# CLAUDE.md — Contexto do projeto HowToInvest

Este ficheiro é lido automaticamente pelo Claude Code. Define como trabalhar neste repositório.

## O que é este projeto

Plataforma educativa de literacia financeira em WordPress. O utilizador responde a um questionário, é classificado num de cinco **arquétipos de investidor**, e recebe um **exemplo ilustrativo de carteira por classes de ativos** com explicação educativa. Objetivo de negócio: crescer por SEO/conteúdo.

Lê `/docs` para a especificação completa. Lê `README.md` para o mapa.

## Stack (não desviar sem aprovação)

- WordPress 7.0+, PHP 8.4 (mín. 8.3), MySQL 8.0+/MariaDB 10.6+.
- **Tema:** child block theme (FSE), customização via `theme.json`. Sem page builders.
- **App:** plugin custom `hti-engine`, frontend **JS ligeiro/vanilla** (sem React).
- **LLM:** Google Gemini, chamado **server-side**, com JSON mode.
- **Hosting:** cPanel (Apache + mod_rewrite). Cache/backups/segurança são responsabilidade nossa.
- **Idiomas:** EN default + PT. Toda string voltada ao utilizador em ambos.

## Invariantes — nunca quebrar

1. As **regras determinísticas decidem** (arquétipo + alocação). O **LLM só explica**. Se o output do LLM tentar mudar números → rejeitar via schema → fallback.
2. Output do motor/LLM **sempre por classes de ativos** (global_equity, bonds, cash, reits_alt, crypto). **Nunca** instrumentos, tickers, fundos, corretoras ou empresas nomeadas (o validator bloqueia e força fallback).
3. Linguagem **condicional e ilustrativa**, nunca imperativa ("um perfil como este costuma…", nunca "deves comprar").
4. **Disclaimer contextual** em todos os resultados. O resultado do motor, o PDF e os emails são 100% educativos — **nunca** contêm CTA de execução nem corretoras. Conteúdo sobre corretoras vive **apenas na secção editorial de corretoras** (comparador, reviews, guias de abertura de conta e o módulo "Passar à prática" a seguir ao resultado), sempre rotulado **"Parceria · Publicidade"**, com divulgação de afiliação **na própria página**, linguagem factual/condicional (nunca captação/imperativos), links de saída só via `/go/{slug}` com `rel="sponsored nofollow"` quando há afiliação ativa, e aviso de risco CFD quando a corretora oferece CFDs. Regras completas: `.claude/skills/broker-affiliate/SKILL.md`.
5. Sem criar conta → só sessão **anónima**, nenhum dado identificado retido.
6. **Export e delete** de conta (RGPD) são P0.
7. Chave do Gemini **nunca** no HTML/JS do cliente. Guardar via `wp-config.php`/env.
8. Alocação **soma 100%** e está dentro dos intervalos curados do arquétipo.

## Convenções de código

- Seguir **WordPress Coding Standards** (WPCS) para PHP.
- Prefixar tudo do plugin com `hti_` / `HTI_` para evitar colisões.
- Toda a saída **escapada** (`esc_html`, `esc_attr`, `wp_kses`); toda a entrada **sanitizada**.
- Endpoints REST com **nonce** e capacidade adequada.
- i18n: usar `__()`, `_e()`, text domain `hti-engine` / `howtoinvest`.
- Nada de queries SQL diretas onde a API do WP serve; quando inevitável, `$wpdb->prepare`.
- Commits pequenos e descritivos. Versiona só o nosso código (ver README).

## Estrutura do plugin (alvo)

Ver `docs/Stack_Concreta_HowToInvest_MVP.md §4`. Resumo:
`includes/` → class-cpt, class-rest, class-engine, class-gemini, class-fallback, class-pdf, class-settings.
`assets/js/` → questionnaire.js, result.js. `assets/css/`. `languages/`.

## Fluxo de trabalho

- **Branches — regra dura:** o trabalho vai **sempre primeiro para `develop`**, nunca
  direto para `main`. Cria a branch a partir de `develop` e abre o PR **com base em
  `develop`**. A `main` é **produção** e só recebe PRs de *release* (`develop` → `main`)
  depois de validado em staging. Ver `CONTRIBUTING.md` para o detalhe e para hotfixes.

  ```
  feature/… ──PR──▶ develop ──(staging, validar)──▶ PR ──▶ main ──(produção)
  ```

- Construir e testar em **staging** (subdomínio noindex + password), nunca em produção.
- Antes de marcar feito: critérios de aceitação do PRD + `docs/Criterios_Pronto_QA…` da área relevante.
- A matriz de teste do motor (mín. 12 cenários) deve correr como suite repetível.

## Como pedir trabalho ao Claude Code (exemplos)

- "Cria o CPT `htinvest_profile` privado conforme o Modelo de Dados §2."
- "Implementa o endpoint `/recommend` conforme os contratos §5, com a engine determinística e fallback."
- "Constrói o questionário multi-step em JS ligeiro conforme o wireframe E5, acessível por teclado."
- "Implementa o `theme.json` com os tokens do design."

## O que NÃO fazer

- Não introduzir React, page builders, ou dependências pesadas.
- Não pôr lógica de decisão de alocação no LLM ou no cliente.
- Não reter dados identificados sem conta criada.
- Não publicar texto financeiro sem disclaimer associado.
- Não nomear instrumentos financeiros em lado nenhum do output do motor/LLM.
- Não misturar corretoras no resultado do motor, no PDF ou nos emails; corretoras só na secção editorial rotulada "Parceria · Publicidade" com disclosure na página (skill `broker-affiliate`).
- Não publicar link de afiliado fora do redirector `/go/{slug}` nem sem `rel="sponsored nofollow"`.
