---
name: seo-wordpress
description: Use when working on anything that affects search visibility — the JSON-LD schema graph (class-seo.php), E-E-A-T authorship, sitemaps incl. Google News, meta/canonicals, 301 redirects, noindex rules, internal linking, GEO/AI-engine optimization, and Core Web Vitals. Triggers on SEO plugin config, schema, redirects, sitemaps, Preferred Sources, or content-structure tasks. SEO is the project's primary business goal.
---

# SEO for WordPress

SEO is the **whole point** of the WordPress rebuild. Strategy doc: `docs/Estrategia_Conteudo_SEO_LLM.md` (hub-and-spoke, GEO, measurement). Growth playbook: `marketing-growth`.

## Structured data — the real implementation
- `hti-engine/includes/class-seo.php` emits a **JSON-LD @graph** on the front end: `WebSite` (+SearchAction), `Organization` (+`sameAs` via the `hti_organization_same_as` filter, logo), `DefinedTerm` (glossary), `NewsArticle` (news — always emitted, Google News-grade), `LearningResource`+`Quiz` (learn chapters), `BreadcrumbList`; the theme adds the `Course` node (`SEO::course_id()` ties chapters via `isPartOf`).
- **E-E-A-T author (YMYL):** the `hti_schema_author` filter (wired in the theme) replaces the Organization byline with a **Person** — name, jobTitle, `description` (investor 10+ years), `knowsAbout`, `sameAs` LinkedIn, photo from the About page featured image. Keep schema and the visible byline/author box in sync — they must never contradict.
- Dedupe: `Article` fallback only when no SEO plugin (RankMath/Yoast) is active; `hti_emit_entity_graph`/`hti_emit_breadcrumbs` filters avoid duplicates. Validate every type in the **Rich Results Test**.

## Sitemaps, redirects, indexing
- General sitemap: RankMath. **Google News sitemap:** `class-news-sitemap.php` (`/news-sitemap.xml`) — see `docs/Google_News_Checklist.md` for the manual Publisher Center side.
- **301s:** `class-redirects.php` maps legacy Base44 paths (filterable). Any new legacy URL gets a map entry; test each returns 301, not 302/404.
- Index: home, learn, glossary, news, landing pages. **noindex:** questionnaire, result, account, staging (`wp_robots` in `class-frontend.php` + theme). Canonicals via the SEO plugin.

## GEO / AI engines (AI Overviews, ChatGPT, Perplexity)
- Passage-level citability: TL;DR ("In one line") up top, **question-form H2s**, self-contained definitions, entity consistency via `[glossary:]`/`[learn:]` tokens.
- llms.txt (via RankMath) + AI crawlers allowed. **Preferred Sources:** the `howtoinvest/preferred-source` block (article foot, homepage, news hub, footer) deep-links `google.com/preferences/source` keyed on the bare domain — more presence in Top Stories/AI Overviews for opted-in readers.

## Content structure & internal linking
- One H1; logical H2/H3. Hub-and-spoke: every glossary term links its pillar Learn chapter and vice-versa (see `content-editorial`); CTA-to-questionnaire present but not spammy.
- Titles ≤60 chars with the search term; meta description ~150, benefit-led.

## CWV & measurement
- Self-hosted fonts, lazy images, minimal CSS; keep LCP/CLS/INP green (Search Console). Measure clicks/impressions per cluster in GSC; KPIs and tracking live in `analytics-measurement`.

## Checklist
- [ ] Schema valid per page type (Rich Results tested); Person author consistent with visible byline
- [ ] New content: TL;DR + question H2s + pillar/spoke links (no orphans)
- [ ] Sitemaps current; new legacy URLs 301-mapped and verified
- [ ] noindex on questionnaire/result/account/staging intact
- [ ] Titles/meta within limits; CWV green on key pages
