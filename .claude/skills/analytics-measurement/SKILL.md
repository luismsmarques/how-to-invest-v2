---
name: analytics-measurement
description: Use when measuring behaviour or adding tracking — the first-party cookieless funnel (class-metrics + htinvest/v1/event), HTITrack/track.js and declarative data-hti-track tagging, consent-gated GA4, the KPIs (engine success-rate, time-to-result p95) and the event allowlist, plus Search Console. Triggers on class-metrics/class-analytics/track.js, adding a tracked event, the HTI Funnel screen, or KPI/measurement work. Analytics is consent-gated (RGPD) — see gdpr-data.
---

# analytics-measurement — Funnel, KPIs & tracking

Two parallel systems: a **first-party cookieless funnel** (always on) and **consent-gated GA4**. Never mix them. KPIs come from `docs/PRD_HowToInvest_WordPress_MVP.md §7`.

## Non-negotiable invariants
- **No PII, no cookies, no IP/user-id** in the first-party funnel.
- **GA/GTM only after analytics consent** (`class-consent.php` → `analytics_allowed()`); the banner rejects non-essential by default. See `gdpr-data`.

## First-party funnel (the default)
- `includes/class-metrics.php` — registers `POST htinvest/v1/event` (public, anonymous). Stores **aggregate daily counts** in the `hti_metrics` option (autoload off, 120-day retention), plus per-recommend outcome counts (`rec`) and a latency histogram (`lat`, buckets `0-1…16+`) for the **time-to-result p95**. Powers the **Settings → HTI Funnel** admin screen (`totals()` + `render_page()`).
- **Event allowlist:** `Metrics::events()`. **An event name not in this list is silently dropped** — always add the name there when you introduce a new event, or it won't count first-party.
- Server-side KPI: `Metrics::record_recommend($outcome, $ms)` from `REST::recommend()` — `ok_llm`/`ok_fallback`/`error` → engine-success-rate; the histogram → p95.

## Client tagging (HTITrack)
- `assets/js/track.js` defines `window.HTITrack` (handle `hti-track`, enqueued site-wide). Triple path: always-on cookieless beacon → `htinvest/v1/event`; consent-gated GTM `dataLayer`; consent-gated gtag (buffered pre-consent).
- **Any script that calls `HTITrack.event()` must declare `array('hti-track')` as a dependency**, or load order makes it a no-op.
- Declarative tagging: `data-hti-track="event_name"` (+ `data-htip-*`) on an element auto-fires it.
- `assets/js/analytics.js` + `class-analytics.php` — GA4 gtag loader (measurement id `ga_id`, filter `hti_ga_id`; empty = defer to GTM).

## SEO / search measurement
- `class-google.php` (Search Console), `class-seo.php` (JSON-LD), `class-news-sitemap.php` (Google News sitemap), `class-redirects.php` (301s). Submit the sitemap in GSC; validate each schema type in the Rich Results Test. Targets/strategy: `seo-wordpress` + `marketing-growth`.

## Tests
- `wp-content/plugins/hti-engine/tests/test-metrics.php` (funnel/p95), `test-google.php`. Add coverage when you change the metrics shape.

## Checklist (before "done")
- [ ] New event name added to `Metrics::events()` (else dropped)
- [ ] Scripts using HTITrack declare the `hti-track` dependency
- [ ] First-party path is cookieless/no-PII; GA only after consent
- [ ] KPI change reflected in `totals()`/`render_page()` + a test
- [ ] SEO measurement (GSC/Rich Results) verified for new page types
