---
name: social-media
description: Use when creating or editing social media assets — the hti-social plugin's cards, stories, reels and carousels (News/Glossary/Quiz-CTA/Fun-fact/Editorial/Myth families), the brand primitives, the {{token}} grammar, and AI captions/TTS. Triggers on hti-social plugin work, social template or reel authoring, the "Social cards" meta box, or generating social copy. Enforces the project invariants (asset-class only, disclaimer, bilingual EN+PT). SEO/content is the primary business goal; social is distribution.
---

# social-media — Branded social cards, stories & reels

The `wp-content/plugins/hti-social/` plugin generates brand-faithful social assets **in the browser** (SVG→canvas→PNG; reels via canvas + MediaRecorder). No server render for the base path. Read `wp-content/plugins/hti-social/README.md` first — it is authoritative.

## Non-negotiable invariants (same as the whole project)
- **By asset class only** — never name instruments, tickers, funds, brokers or companies (watch editable "Broker A/B/C" placeholders in the myth templates).
- **Educational, conditional tone; disclaimer always present** — `{{disclaimer}}` / `{{#legal}}…{{/legal}}` come from `class-brand.php`, never a bespoke one.
- **No execution/broker CTAs.** The only CTA is the questionnaire or newsletter/ebook.
- **Bilingual EN + PT** for any caption/copy.
- **Gemini key stays server-side** — captions/TTS go through `class-gemini.php`, never the browser.

## Where things live
- `includes/class-brand.php` — brand primitives: logo SVG, bilingual legal disclaimer, self-hosted font URLs. Non-optional.
- `includes/class-templates.php` — template category map + which families a post type gets.
- `assets/js/templates.js` — the template DATA (full HTML + `{{tokens}}`). **25 ids** across families: News (`news-square/story/x`), Glossary (`glossary-fb/feed/story`), Fun-fact (`fact-green/purple/story`), Quiz-CTA (`cta-square/story/x`), og:image (`og-photo/split`), Editorial (`ed-news/econ/promo/infographic/recap/dca`), Myth-buster carousel (`myth-hook/reality/how/proof/cta`).
- `assets/js/social.js` — editor + the SVG→canvas→PNG export engine.
- `assets/js/reels.js`, `reel-templates.js`, `script-reel.js` + `includes/class-reels.php`, `class-script-reel.php`, `class-ffmpeg-cache.php` — reels, timed script→scene reels, mirrored ffmpeg.wasm assets (`uploads/hti-social/ffmpeg/`).
- `includes/class-rest.php` — REST namespace `hti-social/v1`: `/caption`, `/tts`, `/ffmpeg-assets`, `/log`. **All gated by `edit_posts` + `wp_rest` nonce.**
- `includes/class-metabox.php` — "Social cards" meta box on the News + Glossary editors (auto-prefills from the post).

## Token grammar (for any card/copy you generate)
`{{logo}}`, `{{disclaimer}}`, `{{#legal}}…{{/legal}}`, `{{img:slotId}}`, `{{initial}}`, `{{#has:KEY}}…{{/has:KEY}}`, `{{chips:KEY}}`, `{{illoShip}}`/`{{illoGold}}`. Keep new templates token-driven so brand + disclaimer inject consistently.

## Conventions
- Follow `php-standards` (PHP) and `frontend-vanilla` (JS: no build, no framework). New REST routes need `edit_posts` + nonce (see `wordpress-backend`).
- MP4 export (ffmpeg.wasm) is opt-in with a WebM fallback — set expectations, don't assume MP4.
- Copy voice: mirror `docs/Textos_Finais_HowToInvest_MVP.md`. Distribution strategy: `marketing-growth`.

## Checklist (before "done")
- [ ] Asset-class only; **no named instruments/brokers/companies** (check myth placeholders)
- [ ] `{{disclaimer}}`/`{{#legal}}` present; conditional tone; no execution CTA
- [ ] Bilingual EN + PT copy
- [ ] Token-driven template; brand primitives from `class-brand.php`
- [ ] Any REST route gated by `edit_posts` + nonce; Gemini server-side only
- [ ] Exports tested (PNG; reel WebM/MP4 fallback noted)
