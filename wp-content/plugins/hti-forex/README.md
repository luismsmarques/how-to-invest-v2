# HTI Forex

Free forex calculators for Indian traders, served as an isolated, English-only
section under `/forex/`. Built as landing pages for paid campaigns (Propeller
Ads, Facebook via GTM) **and** as indexable SEO pages for the INR gap the
market research identified (no major calculator supports INR as the account
currency, none renders market hours in IST).

## Why a separate plugin

The main site (hti-engine + theme) has hard invariants: no broker CTAs, no
named instruments, conditional language, bilingual EN+PT. This section is the
**single documented exemption**: it names currency pairs (that *is* the tool)
and may render an affiliate CTA. Keeping it in its own plugin keeps the
exemption physically contained — nothing here touches hti-engine, and removing
the section is "deactivate one plugin".

## Map

| File | Purpose |
| --- | --- |
| `hti-forex.php` | Bootstrap: constants, requires, `Xxx::init()` calls, (de)activation. |
| `includes/class-config.php` | Pure config: pairs (pip size, contract size), sessions (IANA tz), per-page FAQs, tool field/output specs. Single source for page content **and** FAQ schema. |
| `includes/class-settings.php` | Settings → HTI Forex: affiliate CTA (URL, label, global kill-switch + per-tool toggles), subid passthrough config, manual rate overrides. |
| `includes/class-rates.php` | USD→INR/JPY reference rates: twice-daily WP-Cron fetch (Frankfurter/ECB, keyless), stored in an option, admin override > fetched > shipped fallback. |
| `includes/class-tools.php` | `[hti_forex_tool name="position_size\|pip_value\|sessions"]`: form render, risk block, CTA block, email capture, IST baseline table, conditional enqueue. |
| `includes/class-schema.php` | JSON-LD on `wp_head` for pages with the shortcode: WebApplication (INR) + FAQPage + BreadcrumbList. |
| `includes/class-seeder.php` | Idempotent create-only seeder: `/forex/` hub + 3 child pages, Polylang EN, admin button + `wp hti-forex seed`. |
| `assets/js/forex-core.js` | Pure math (UMD, DOM-free, Node-testable): pip value, position size, session windows. |
| `assets/js/forex.js` | DOM layer: inputs → outputs, `en-IN` INR formatting, editable rate, live IST clock, affiliate subid passthrough, email form. |
| `assets/css/forex.css` | Mobile-first styles (`hti-fx-` prefix). |
| `tests/` | Pure-PHP harness (`php tests/run.php`) + Node math test — no WordPress needed. |

## Monetization & compliance notes

- The affiliate CTA is **off by default** and renders nothing until Settings →
  HTI Forex is configured. The global kill-switch removes every CTA instantly,
  without a deploy.
- Affiliate links always carry `rel="sponsored nofollow noopener"` and sit next
  to a risk warning. Copy stays conditional — the pages sell the tool, not the
  broker.
- Regulatory context (India): retail forex is regulated under FEMA; the RBI
  publishes an Alert List of unauthorised platforms. The tools are education;
  the conversion layer is where the risk lives — hence the kill-switch and the
  factual "is forex trading legal in India?" block maintained in one place
  (the `/forex/` hub).
- Email capture reuses hti-engine's double-opt-in endpoint
  (`htinvest/v1/subscribe`) with `source: forex-<tool>`; no new PII is stored
  by this plugin.

## FAQ schema drift caveat

Page FAQ copy is seeded from `Config::faqs()` — the same array the FAQPage
JSON-LD is built from, so visible content and schema agree at seed time. If an
editor rewrites the page copy in wp-admin, the schema keeps emitting the config
version; keep the two in sync by editing `Config::faqs()` and re-seeding a
fresh page (the seeder never updates existing pages).

## Settings walkthrough (go-live)

1. **Seed the pages** — Settings → HTI Forex → "Seed forex pages" (or
   `wp hti-forex seed`). Four EN pages appear under `/forex/`.
2. **Rates** — activation schedules the twice-daily fetch and an immediate
   first fetch; check the "Exchange rates" panel shows `frankfurter` with a
   fresh date (use "Fetch now" if needed). Overrides are for emergencies.
3. **Campaign tracking** — first-party `page_view`/`cta_click` work out of the
   box (HTI Funnel screen). For ad-platform pixels, configure them in GTM;
   the dataLayer push is consent-gated as everywhere else on the site.
4. **Affiliate CTA** — paste the https partner URL, keep the conditional
   label, tick the tools it should show on, then enable the kill-switch
   checkbox. The `clickid`/`utm_campaign` landing parameter is appended to
   the CTA automatically for sub-id attribution.
5. **Before enabling the CTA**: re-read the regulatory note below — the
   tools are safe; the conversion layer is where the exposure lives.

## Phase 2 backlog

- Brevo source attribution: generalize hti-engine's `hti_ebook_pending` into
  a source-pending store and pass a `SOURCE` attribute on upsert, so forex
  leads can be segmented (and a "lot size cheat sheet" PDF can ride the same
  gate as the ebook).
- Dedicated XAUUSD page (`/forex/xauusd-lot-size-calculator/`) — gold is the
  most persistent modifier in the research; the anchor on the pip-value page
  covers it for now.
- Profit/loss calculator; "with leverage" and "for $100 account" variant
  pages with unique content.
- Noindex campaign variants (stripped nav, harder CTA) using the existing
  robots-filter pattern, if ad QS/policy calls for separate landers.
- NSE ↔ global lot-convention converter and the XAUUSD ↔ MCX gold bridge
  (research gaps #2 and #5) once the MVP proves traffic.

## Tests

```
php wp-content/plugins/hti-forex/tests/run.php
```

Runs every `tests/test-*.php` plus `tests/test-forex-core.mjs` under Node when
available. Wired into `.github/workflows/ci.yml`.
