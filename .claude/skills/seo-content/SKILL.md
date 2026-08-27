---
name: seo-content
description: Use when writing or optimizing the on-page SEO of ANY indexable content — meta titles and descriptions, H1/H2 structure, keyword and search-intent mapping (EN and PT researched separately), strategic bolds, internal linking, FAQ blocks, freshness signals and GEO/LLM citability. Trigger whenever content is being published or revised to rank — broker reviews/comparisons, account-opening guides, Learn chapters, glossary, news, forex/tool pages, landing pages — or the user mentions rankear, keywords, meta title/description, snippets, AI Overviews or internal links. The technical layer (schema, sitemaps, redirects, CWV) lives in seo-wordpress; this skill is the content layer that sits on top of it.
---

# seo-content — On-page SEO that ranks in Google AND gets cited by LLMs

Every content page competes twice: in classic SERPs and inside AI answers
(Google AI Overviews, ChatGPT, Perplexity). Both reward the same thing — a page
that answers one intent completely, extractably and freshly. Write for the
reader; structure for the machines.

## One page, one intent

- Map the **primary keyword + intent** before writing: informational ("o que é
  um ETF"), comparison ("melhores corretoras para ETFs"), transactional/fundo de
  funil ("como abrir conta na XTB"). One primary keyword per page; secondaries
  become H2s, never competing pages (cannibalization splits authority).
- **PT keywords are researched, never translated.** "Best brokers" ≠ tradução;
  o termo PT real é "melhores corretoras" (e "análise/opiniões" para reviews).
  Confirm in Search Console / autocomplete before locking slugs and titles.

## Meta title & description (RankMath fields; seeder writes them too)

- **Title ≤ 60 chars**: primary keyword first, differentiator second, year via
  the `%currentyear%` RankMath variable on evergreen money pages (never a year
  hardcoded in the slug). Ex.: `Melhores corretoras em Portugal %currentyear% —
  comparação de plataformas reguladas`.
- **Description ~150 chars**: keyword + the concrete benefit + what makes this
  page trustworthy ("metodologia pública", "dados verificados") — factual, no
  urgency (the invariants apply to metas too).
- Never duplicate title/description across pages; EN and PT each get their own.

## Structure the machines can lift

- One H1 (primary keyword, natural). **Question-form H2s** matching real
  queries/People-Also-Ask; a "TL;DR / Em uma linha" answer at the top of long
  pieces. Definitions written self-contained (quotable without the page around
  them) — that's what LLMs cite.
- **Bolds are answers, not keywords**: bold the 2–5 sentence fragments that
  directly answer the page's question (snippet/AI extraction targets). Never
  mechanically bold keyword repetitions — it reads as spam to readers and
  raters alike.
- Comparisons in real `<table>`s; steps in ordered lists; FAQs as H3 question +
  short paragraph (they feed the FAQ intent even without FAQ schema).
- **Freshness is a claim**: state "dados verificados a {data}" / "as of {date}"
  near numbers and keep it true (see `financial-analyst`). Stale dates on YMYL
  pages cost rankings and citations.

## Internal linking (the site's compounding asset)

- Every money page joins the mesh: comparador ↔ categoria ↔ review ↔ guia, plus
  at least one contextual link from an educational page (Learn/glossary) into it
  and back. No orphans; 3+ contextual internal links per piece.
- Anchor text descriptive and varied, carrying the target's keyword ("análise
  completa à XTB", never "clica aqui"). In Learn/glossary content use the
  `[glossary:…]` / `[learn:…]` tokens; broker links from educational content go
  to the comparison page as further reading — never broker CTAs there.
- Affiliate/outbound rules are fixed: broker links only via `/go/{slug}` with
  the correct `rel` (see `broker-affiliate`); educational outbound links to
  authorities may pass authority (no nofollow needed).

## E-E-A-T signals inside the content

Named author with credentials (theme byline + `hti_schema_author`), visible
verification dates, the methodology/"Como ganhamos dinheiro" links, honest cons
in reviews, and disclosure where required — trust signals are ranking inputs on
YMYL pages, not decoration.

## What NOT to do

Keyword stuffing or bolding keywords; clickbait/urgency in titles (invariant);
years in slugs; two pages chasing one keyword; translating keywords literally;
publishing without meta title/description; links whose anchor is a bare URL.

## Checklist (before "done")
- [ ] Primary keyword + intent mapped; PT keyword researched, not translated
- [ ] Meta title ≤60 (keyword first, %currentyear% where evergreen) + unique ~150 description, both languages
- [ ] H1 + question H2s + TL;DR; 2–5 answer-bolds; tables/lists where they fit
- [ ] 3+ contextual internal links in and out (mesh complete, no orphans); anchors descriptive
- [ ] Freshness/E-E-A-T visible: dates, author, methodology/disclosure links
- [ ] Technical layer delegated: schema/sitemap/canonical per seo-wordpress
