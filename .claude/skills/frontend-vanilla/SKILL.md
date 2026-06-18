---
name: frontend-vanilla
description: Use when building the interactive front-end of the app in lightweight vanilla JavaScript (no React) — the multi-step questionnaire, session-state persistence, the result page rendering, the allocation chart, fetch calls to our REST endpoints, and progressive enhancement. Triggers on assets/js work or any client-side interactivity.
---

# Frontend — Lightweight Vanilla JS

No React, no build-heavy framework. Plain modern JS (ES modules), progressive enhancement, accessibility-first.

## Questionnaire (questionnaire.js)
- Multi-step, one question per step, progress bar.
- Persist partial answers in `sessionStorage` so a reload keeps progress (NOT localStorage for the result — keep it session-scoped; respects privacy).
- Keyboard-navigable: arrow/tab/enter; visible focus; radios as a proper fieldset/legend with labels.
- Client validation blocks "next" until answered, but the **real validation and all scoring is server-side**. Client never computes the archetype or allocation.
- On submit: `fetch` POST to `/wp-json/htinvest/v1/recommend` with nonce header.

## Result (result.js)
- Render allocation from the server response only. Never recompute.
- Allocation chart with a **lightweight** chart lib, loaded only on the result page. Provide a text-equivalent list of the same numbers (accessibility).
- Show disclaimer prominently; it is not dismissible.
- Export PDF triggers server-side generation (the PDF is built in PHP).

## Network & security
- Send WP nonce on every request. Handle 422/500/502 gracefully → show the fallback content the server returns, never a raw error.
- Never put the Gemini key client-side (it isn't — calls go through our endpoint).
- No third-party trackers loaded before consent.

## Performance
- Defer/async scripts. Load per-page, not globally.
- Keep total JS small; no heavy dependencies.

## i18n
- Strings come from the server (localized via `wp_localize_script`), so both EN and PT are covered server-side.

## Checklist
- [ ] Keyboard + screen-reader friendly
- [ ] Session-persisted partial state
- [ ] No client-side scoring/decision
- [ ] Graceful handling of all error codes
- [ ] Chart has text alternative
