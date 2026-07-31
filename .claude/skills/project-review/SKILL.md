---
name: project-review
description: Use when asked for a full project review as product owner — "revisão completa", "auditoria do projeto", "o que falta fazer", "pontas soltas", "estamos prontos para lançar?", roadmap or gap analysis. Orchestrates the specialist skills across every dimension (product, content, design, a11y, SEO, security, GDPR, metrics, tests, ops) and produces one prioritized report. Triggers on any request to assess the whole project rather than build a feature.
---

# Project Review — the product-owner audit

A repeatable, evidence-based audit of the WHOLE project. You are not building; you are
judging the gap between what the docs promise and what actually exists — then reporting
it so the owner can decide. **Never list a finding without concrete evidence** (file:line,
URL, or doc §).

## The three PO questions — always all three
1. **O que falta** — promised in `docs/` but absent or stubbed.
2. **O que está mal feito** — exists but violates `CLAUDE.md` invariants, specs or quality bars.
3. **O que está a mais** — dead code, unused features, stale docs, duplicated logic. An
   audit that only finds gaps is half an audit.

## Source of truth (compare REAL vs PROMISED — not vs opinion)
- `CLAUDE.md` — the 8 invariants (asset-class only, deterministic engine, disclaimer,
  EN+PT, GDPR P0, keys server-side, sum 100%).
- `docs/` — PRD (§7 KPIs), Modelo de Dados, Criterios_Pronto_QA…, `QA_Gate_Lancamento.md`,
  `QA_RGPD_Checklist.md`, `Estrategia_Conteudo_SEO_LLM.md`, `DEPLOY.md`.

## Dimensions — each delegates to its specialist skill
| Dimension | Skill | Watch for |
|---|---|---|
| Product/engine | hti-engine-spec | scoring vs spec, traps, fallback, schema reject |
| Content EN+PT | content-editorial, learn-guide, i18n-polylang | parity, orphan terms, thin pages, pending tokens |
| Design/UX | ux-ui-design | **missing states** (default/hover/focus/error/empty/loading), orphan pages without design, mobile |
| Accessibility | accessibility | keyboard, ARIA, contrast, skip link, WCAG 2.1 AA |
| SEO | seo-wordpress | schema, sitemaps, canonicals, internal links, CWV |
| Security | **security-audit** | run it as its own pass — never skim this one |
| GDPR | gdpr-data | export/delete P0, consent gating, no PII in logs |
| Metrics/KPIs | analytics-measurement | PRD §7 KPIs actually measured? events in allowlist? |
| Tests | testing-qa | suites green, engine matrix ≥12, exploratory flows |
| Deploy/ops | deployment-ops | VERSION bumps, staging-first, cache, env keys |

## Method
1. Read the docs → build the "promised" list per dimension.
2. Sweep code + content (Glob/Grep/read) → build the "exists" list. **Verify samples in
   depth** — a file existing does not mean it works or matches spec.
3. Diff in all three directions (missing / wrong / extra).
4. Run the suites (`php …/hti-engine/tests/run.php`, `…/hti-rss-ai/tests/run.php`) and
   `php -l` anything suspicious.
5. When the live site matters (content pasted live, deploy drift), ask the owner to run
   `curl` checks — repo state ≠ production state (the deploy is manual).

## Report format (the deliverable)
Table per finding: **Dimensão | Achado | Evidência | Prioridade | Esforço | Dono**.
- Prioridade: **P0** blocks launch/legal · **P1** important · **P2** improvement · **P3** idea.
- Esforço: S / M / L. Dono: código / config (wp-admin, cPanel) / conteúdo.
Close with **Top 5 próximas ações** in order. Write the report in PT (the owner's language).

## Checklist
- [ ] All three questions answered (falta / mal / a mais) — not just gaps
- [ ] Every finding has evidence (file:line, URL or doc §)
- [ ] Every dimension visited; security ran as its own `security-audit` pass
- [ ] Suites executed, results in the report
- [ ] Priorities are honest — P0 only for launch/legal blockers
- [ ] Repo-vs-production drift flagged (deploy is manual)
- [ ] Report ends with Top 5 next actions
