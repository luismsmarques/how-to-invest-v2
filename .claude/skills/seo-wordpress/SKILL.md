---
name: seo-wordpress
description: Use when working on anything that affects search visibility — structured data (schema.org), XML sitemaps, meta titles/descriptions, canonical URLs, 301 redirects, noindex rules, internal linking, content structure for indexing, and Core Web Vitals. Triggers on SEO plugin config, schema, redirects, or content-structure tasks. SEO is the project's primary business goal.
---

# SEO for WordPress

SEO is the **whole point** of the WordPress rebuild. Treat it as a first-class requirement.

## Structured data (schema.org)
- Articles → `Article`. Glossary terms → `DefinedTerm` / `FAQPage` where it fits. News → `Article`/`NewsArticle`.
- Validate every type in Google's Rich Results Test before considering done.
- Let the SEO plugin (RankMath or Yoast — one only) generate base schema; extend for the glossary CPT.

## Indexing rules
- Index: home, articles, glossary terms, news, key landing pages.
- **noindex**: questionnaire, result pages, staging site, any thin/utility pages.
- Canonical URLs correct; avoid duplicate content across CPTs.

## Redirects (protect existing equity)
- 301 every old Base44 URL (e.g. `/Questionnaire`, `/EducationalResources`, `/FinancialNews`) to its new home. Map them explicitly. Test each returns 301, not 302/404.

## Content structure
- One clear H1 per page; logical heading hierarchy.
- Internal linking: glossary terms link to related articles and vice-versa; the per-class notes seed glossary entries.
- Article CTA-to-questionnaire block is the conversion bridge — present but not spammy.

## Core Web Vitals (ranking factor)
- Cache + CDN active. Self-hosted fonts. Optimized images (WebP, lazy-load).
- Monitor LCP/CLS/INP in Search Console; keep them green on key pages.

## Operational
- Submit XML sitemap to Search Console.
- Track indexed pages, keyword positions, organic sessions (the success metrics in PRD §7).

## Checklist
- [ ] Valid schema per page type (Rich Results tested)
- [ ] Sitemap submitted
- [ ] All old URLs 301'd and verified
- [ ] noindex on questionnaire/result/staging
- [ ] CWV green on key pages
