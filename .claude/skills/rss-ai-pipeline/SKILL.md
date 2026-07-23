---
name: rss-ai-pipeline
description: Use when engineering the hti-rss-ai plugin — feed fetching and reliability, item grouping (lexical + embeddings), the article generators (group, per-item, YouTube), the safety validator, retention/cleanup, and the drafts/groups admin. Triggers on any wp-content/plugins/hti-rss-ai code, grouping/embedding logic, feed health, or news-generation changes. Editorial review of the output lives in content-editorial; this skill is the code.
---

# rss-ai-pipeline — News generation engineering

`wp-content/plugins/hti-rss-ai/` turns RSS/YouTube items into pending-review news articles. Plan doc: `docs/RSS_AI_Feed_Plan.md`; the plugin `README.md` maps milestones. **The editorial gate is human review — nothing here may auto-publish.**

## The pipeline (order matters)
1. **Fetch** — `class-fetcher.php`: per-feed pull with dedupe (per-feed GUID + cross-feed title **fingerprint** near-dup suppression), exponential backoff capped at 1 day, auto-pause after repeated errors, health/error reset on a clean fetch. Auto-runs grouping after fetch.
2. **Group** — `class-grouping.php`: clusters same-story items per language. **Cross-cycle:** new items first try recent open groups, so a running story grows one group. Matching is **hybrid** — lexical similarity + Gemini embeddings per language (`class-embeddings.php`), degrading gracefully with no key/quota. A **recency guard** (max date-span setting) keeps old news out of fresh stories. `class-cleanup.php::reconcile_groups` handles zombie/empty/orphan groups; retention prunes stale items/groups/logs but **never published posts**.
3. **Generate** — `class-generator.php`: per-**group** (Gemini grounded search) or per-**item** (`generate_from_item`, formats News/Quote/Tutorial/Summary; video items delegate to `class-youtube-generator.php`, transcript via `class-supadata.php`). The original item/video is **always attributed in sources**. Daily generation limit (`over_daily_limit`/`bump_daily`); featured image best-effort (`class-featured-image.php`, never blocks).
4. **Validate** — `class-validator.php`: required fields, non-empty sources, advice/ticker blocklist, wrong-language rejection, near-verbatim-copy rejection, meta-description clamp (≤155). Conservative by design — generation has no fallback, so false positives are worse than misses. **The validator is a safety net, not the editorial gate.**
5. **Publish** — posts are created `pending` (`Settings::post_status()`); NewsArticle schema + the news sitemap come from **hti-engine** (`class-seo.php`, `class-news-sitemap.php`), not this plugin.

## Engineering rules
- **Gemini key server-side only** (`class-gemini-client.php`); every external call has a timeout; validate URLs before fetching remote media (SSRF guard, `wp_http_validate_url`).
- `$wpdb->prepare` for all custom-table SQL; schema changes bump `DB_VERSION` with an idempotent migration.
- Admin actions (`class-drafts.php`, list tables, group editing) need nonce + `manage_options`.
- Watch cron weight: grouping runs inside the fetch cron — keep embedding backfills bounded (`embed_max_per_run`).
- Log meaningful steps via `class-logger.php` (fetch/group/generate/cleanup) — it's the observability surface.

## Tests
- `tests/run.php` harness (pure PHP, shims — see `testing-qa`). Covered: grouping (incl. recency), validator, fetcher backoff, extract-json, image-client. Add a `test-*.php` when touching grouping/validator/fetcher logic; cleanup/retention SQL is the under-tested risky area.

## Checklist
- [ ] Output stays `pending` — never auto-publish
- [ ] Source item attributed; validator passes (language, no advice/tickers/copy)
- [ ] External calls: timeout + URL validation; key server-side
- [ ] SQL prepared; migrations bump DB_VERSION idempotently
- [ ] Admin actions nonce + capability gated
- [ ] Suite green (`php tests/run.php`); new logic gets a test
