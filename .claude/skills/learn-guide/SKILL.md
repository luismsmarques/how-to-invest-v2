---
name: learn-guide
description: Use when writing or revising educational guides for the Learn hub (the `learn` CPT) — beginner-friendly, step-by-step articles that make up the "From zero to your first portfolio" learning path and feed the ebook. Triggers on requests to create/structure a learn guide, a chapter of the path, or Learn-hub content. Enforces the project invariants, bilingual EN+PT, house style and SEO.
---

# learn-guide — Authoring beginner guides

Audience: **young beginners** with little or no finance background. Goal: make them feel investing is understandable and calm, never salesy. Output is for the `learn` CPT (archive `/learn/`), bilingual EN + PT (Polylang), and doubles as **ebook chapters**.

Read `CLAUDE.md` (invariants) and `docs/` before writing substantive content.

## Non-negotiable invariants (same as the whole project)
1. **Educational, never advice.** Conditional/illustrative language ("a profile like this often…", never "you should buy"). No imperative calls to act.
2. **By asset class only** — global equities, bonds, cash, REITs/alternatives, crypto. **Never** name instruments, tickers, funds, brokers or companies.
3. **Rules decide, content explains.** Any allocation shown must match the engine's curated archetype ranges; don't invent numbers. Crypto only ever a tiny, optional, conditional slice.
4. **Disclaimer** present (use the theme/engine disclaimer block, never a bespoke one).
5. **No execution CTAs** (no "open an account with X"). The only conversion CTA is the questionnaire and the newsletter/ebook.

## The learning path (the backbone)
Every guide is one chapter; the sequence is also the ebook's table of contents. Category = `learn_topic`.

- **Module 0 — Mindset & money** (`mindset`/`planning`): why invest · compound interest · emergency fund first · budgeting basics
- **Module 1 — Foundations** (`concepts`): what investing is · risk & reward · time horizon · inflation
- **Module 2 — Asset classes** (`concepts`): global equities · bonds · cash · REITs/alternatives · crypto (minimal slice)
- **Module 3 — Diversification & portfolios** (`concepts`): diversification · asset allocation · example portfolios by profile · rebalancing
- **Module 4 — In practice** (`getting-started`): account types · costs & fees · how a portfolio is built (generic) · periodic investing (DCA)
- **Module 5 — Behaviour** (`mindset`): staying calm in drops · common mistakes · scam/red-flag awareness
- **Module 6 — Your plan** (`getting-started`): set goals · take the questionnaire → archetype · next steps

Keep `learn_topic` to the existing four slugs: `getting-started`, `concepts`, `mindset`, `planning`.

## Structure of a guide
1. **H1** = the title (one only).
2. **Hook** (2–3 sentences): the beginner's question this answers, in plain words.
3. **"In one line"** summary box (TL;DR) up top.
4. **Body in short H2/H3 steps** — numbered when it's a process. One idea per section, short paragraphs, concrete everyday analogies.
5. **Key takeaways** bullet list near the end.
6. **Glossary + internal links**: link the first mention of any term to its glossary entry; link forward/back to the previous/next chapter; one contextual link to the questionnaire where natural.
7. **Disclaimer block** (standard).
8. **Excerpt** set (used by the hub list + meta description fallback).

Tone: warm, encouraging, jargon-free; define every term on first use; prefer "you" sparingly and never imperative about money decisions. Aim ~700–1200 words per chapter.

## Bilingual EN + PT
- Write **both** EN (default) and PT. PT is European Portuguese (pt-PT), informal "tu", same meaning not literal translation.
- Link the pair as Polylang translations; file each under the same `learn_topic` (PT term has the `-pt` slug — see `class-seeder.php`).

## SEO (primary business goal — see seo-wordpress skill)
- **Title** ≤ 60 chars, includes the beginner search term ("what is…", "how to…").
- **Meta description** ~150 chars, benefit-led.
- One H1, logical H2/H3 hierarchy; descriptive slug.
- Internal links to glossary + adjacent chapters (cluster around the path pillar).
- Schema is emitted automatically (`Article` via class-seo); ensure title/excerpt/thumbnail are set.

## How to create it — the content pipeline (NOT the seeder)
The seeder is install-only. Ongoing Learn content lives in the editorial pipeline:

1. **Plan** in the sheet `wp-content/plugins/hti-engine/content/learn-plan.csv` (module, order, slug, EN/PT titles, topic, status, glossary, prev, next). Add/triage the chapter row there first.
2. **Author** one Markdown file per chapter at `wp-content/plugins/hti-engine/content/learn/<slug>.md`:
   - Frontmatter: `slug`, `slug_pt`, `module`, `order`, `topic`, `status`, `title_en`, `title_pt`, `excerpt_en`, `excerpt_pt`, `glossary` (comma list), `prev`, `next`.
   - Body split by `<!-- EN -->` / `<!-- PT -->`. Dialect: a leading `> ` line = the TL;DR callout; `## ` / `### ` headings; `- ` bullet lists; a `## Key takeaways` / `## Pontos-chave` heading whose list becomes the takeaways box; `**bold**`. The glossary "Learn more" line and the prev/next chapter nav are **auto-appended from frontmatter** — don't write them in the body. The questionnaire CTA is appended automatically too.
   - **Optional end-of-chapter quiz**: an `## Quiz` (EN) / `## Questionário` (PT) heading at the very end of each language body, then numbered questions with `- [ ]` / `- [x]` options (exactly one `[x]` correct per question, ≥2 options). It is stripped from the article and stored as post meta; the single-guide renders it and passing all questions marks the chapter "completed". Derive 2–3 questions from the chapter's key takeaways; keep them educational and by asset class (never "which product to buy"). Example:
     ```
     ## Quiz
     1. What does inflation slowly do to idle cash?
     - [x] Reduces what it can buy over time
     - [ ] Increases the number in your account
     - [ ] Makes it grow faster than investing
     ```
3. **Import** with `wp hti import-learn` (or Tools → Learn content). It upserts both languages, idempotent by slug, links them via Polylang, files them under `learn_topic`, and sets the SEO description from the excerpt. New chapters are **published straight away** in both languages; an existing post keeps its current status (a re-sync never reverts an editor change).
4. Update the `status` column in the sheet as chapters ship.

Inline glossary tokens `[glossary:slug|Text]` / `[learn:slug|Text]` exist but resolve to the given slug regardless of language — for correct PT term URLs prefer the auto-built "Learn more" line (frontmatter `glossary`), which resolves the localized term. Glossary authoring, news review and the shared import pipeline live in `content-editorial`.

A chapter must be ebook-ready: self-contained, with TL;DR + takeaways — the ebook lead magnet (see `marketing-growth`) reuses the title + body + takeaways.

## Checklist (before "done")
- [ ] Beginner-readable; every term defined on first use
- [ ] By asset class only; **no named instruments/brokers/companies**
- [ ] Conditional/illustrative language; no execution CTA
- [ ] Any numbers match the engine's archetype ranges
- [ ] TL;DR + Key takeaways present
- [ ] Glossary links + prev/next chapter links + one questionnaire link
- [ ] Disclaimer block present
- [ ] EN + PT written and linked as translations, same `learn_topic`
- [ ] Title ≤60, meta description, slug, excerpt, featured image set
