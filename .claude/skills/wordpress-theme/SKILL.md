---
name: wordpress-theme
description: Use when building or editing the WordPress block theme (FSE) — theme.json tokens, templates/parts, and especially the theme's real architecture of dynamic blocks with render callbacks, the strings()/t() bilingual table, the howtoinvest/t block, render_block filters, and the VERSION cache-bust. Triggers on theme files, theme.json, templates/, parts/, functions.php render_* callbacks, or any front-end theme structure work.
---

# WordPress Block Theme (FSE) — the `howtoinvest` theme

Block theme customized via `theme.json` + block templates. **No page builders.** The theme's dominant pattern is NOT static block markup — it is **dynamic blocks rendered in PHP**.

## The real architecture: dynamic blocks
- ~27 dynamic blocks registered in `functions.php` → `register_dynamic_blocks()` (e.g. `howtoinvest/news-article`, `learn-hub`, `drawer`, `preferred-source`), each with a `render_callback` `render_*()` returning escaped HTML.
- **Why dynamic:** patterns run at `init` before Polylang knows the language; dynamic blocks render at request time, so `t()` sees the current language. New language-aware UI = a dynamic block, not a static pattern.
- Cross-template tweaks go through **`render_block` filters** (e.g. `add_main_landmark_id()` injects `id="main"` on every `<main>` group) — one filter beats editing 16 templates.
- Sitewide utility elements (skip link) hook **`wp_body_open`**, not template markup.

## Bilingual UI strings
- The **`strings()`** table (`functions.php` ~346) holds every UI string as `key => ['en'=>…, 'pt'=>…]`; **`t( $key )`** (~515) resolves the current language. Always add EN+PT together (see `i18n-polylang`, `brand-voice`).
- In FSE templates use the **`howtoinvest/t` block** (`{"k":"key","tag":"a|p|span|…","href":"…","cls":"…"}`) — it renders via `t()` and localizes internal hrefs.

## theme.json + gotchas
- Tokens (colors, type, spacing, contentSize) live in `theme.json` — never hardcode values CSS can take from a token.
- **blockGap gotcha:** `theme.json` sets `blockGap`, so in a constrained group the *second* child gets an automatic `margin-block-start`. Inserting a new first child into a group (e.g. the header) shifts everything below — utility elements go via hooks instead.
- The header is `position: sticky` — it becomes the containing block for absolutely-positioned children; hide/show tricks must not rely on an offset parent (use the clip pattern, see `accessibility`).

## Assets & cache-busting
- `const VERSION` (`functions.php:19`) is the `?ver=` for every style/script. **Bump it on any CSS/JS change** or caches serve stale assets (see `deployment-ops`). It does not bust server-rendered HTML — that needs a page-cache purge.
- Fonts self-hosted; per-feature CSS/JS registered once and enqueued at render time by the block that needs it.

## Boundaries
- The questionnaire/result/account app is rendered by the **hti-engine plugin** (shortcodes `[hti_questionnaire]`, `[hti_account]`) — the theme provides shell + styles only.
- Footer part must keep the full disclaimer (`howtoinvest/t` k=footer_disclaimer).

## Checklist
- [ ] Tokens in theme.json; no hardcoded values CSS could token
- [ ] Language-aware UI = dynamic block (render callback), strings via `strings()`/`t()` in EN+PT
- [ ] Escaped output in every `render_*` callback
- [ ] No new first-child inserted into constrained groups (blockGap); utilities via hooks
- [ ] `VERSION` bumped when CSS/JS changed
- [ ] Footer disclaimer intact; plugin/theme boundary respected
