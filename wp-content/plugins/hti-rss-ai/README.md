# HTI RSS AI Feed

Feeds the HowToInvest **news** area from RSS sources. Pipeline:

**RSS → drafts → group similar items → (on demand) Gemini-grounded fact research
→ original SEO/Google-News article → `news` post in _pending review_.** The plugin
never publishes — a human finalizes (featured image, category, sitelinking) and publishes.

See the full plan: [`docs/RSS_AI_Feed_Plan.md`](../../../docs/RSS_AI_Feed_Plan.md).

## Status
- **M0 — scaffold (done):** bootstrap, activation with the three custom tables, Settings page, dependency notice, i18n.
- **M1 — feeds management (done):** add/edit/delete feeds (WP_List_Table) + "Test feed" preview.
- **M2 — fetcher + drafts (done):** WP-Cron fetch + parse + dedupe + image extraction → drafts; Drafts list with filters, "Fetch now" and bulk "ignore".
- M3 — grouping · M4 — research + generation · M5 — review + SEO · M6 — hardening.

## Requirements
- WordPress 6.7+, PHP 8.3+.
- **HTI Engine** active (provides the `news` content type and the Gemini key
  `HTI_GEMINI_API_KEY` / Connectors, reused here).

## Conventions
- Prefix `rssai_` / `RSSAI_`, namespace `HTI\RssAI`. WPCS, escaped output,
  sanitized input, nonces + capabilities. EN default + PT.
