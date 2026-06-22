# HowToInvest Social Generator (`hti-social`)

Brand-faithful social media card generator. Renders the design templates
(handoff 9 — "HowToInvest Social Templates") as real HTML/CSS, lets you edit the
text and drop in a photo, and exports a pixel-faithful **PNG** — with **no heavy
dependency**: the card is serialised into an SVG `<foreignObject>` (self-hosted
fonts embedded as base64) and drawn to a canvas.

## Where it lives
- **Standalone generator:** admin menu → **Social**. Pick any template, edit,
  export. (`data-mode="full"`.)
- **Per-post auto-fill:** a **Social cards** meta box on the **News** and
  **Glossary** editors, pre-filled from the post (title, dek/definition, featured
  image). (`data-mode="post"`.)
- **Reels:** admin menu → **Social → Reels**. Upload a video, type a title +
  caption, pick a branded overlay, and render a vertical **1080×1920** reel in
  the browser (Canvas + MediaRecorder → **WebM**, with the video's audio). No
  server, no FFmpeg. Instagram wants MP4, so a quick WebM→MP4 conversion may be
  needed. Use only footage you own/are licensed to use.
  - **AI assistant (optional):** toggle it on to generate a title + caption +
    post description (with hashtags) from a short brief, via server-side Gemini
    (`HTI_GEMINI_API_KEY`; key never reaches the browser). Guard-railed:
    educational, conditional, no advice, no named instruments. Off by default,
    and hidden if no key is configured.
  - **Show caption** toggle: turn the overlay caption off for clips that already
    have burned-in subtitles.
  - **Animated captions** (word by word): spreads the caption's words across the
    clip (prev dim · current coral · next dim), drawn on the canvas; replaces the
    fixed caption. Live-previewed via the playing video.
  - **End card (CTA):** a full-screen closing card (title + button + handle +
    disclaimer) faded in over the last ~3 seconds. Editable title/button.
  - **MP4 export (experimental):** an optional toggle converts the recorded WebM
    to Instagram-ready **MP4 (H.264/AAC)** in the browser via **ffmpeg.wasm**
    (single-thread core — no COOP/COEP headers needed). Lazy-loaded (~30 MB) only
    when used; falls back to WebM on failure. The CDN URLs are filterable via
    `hti_social_ffmpeg_urls` so the site can self-host them.

## REST
- `POST hti-social/v1/caption` — `{ brief, lang }` → `{ title, caption,
  description, hashtags[] }`. Capability `edit_posts` + `wp_rest` nonce.

## Invariants (educational platform)
- The legal **disclaimer** and **by-asset-class** framing are part of the brand,
  not optional content. The disclaimer is bilingual (PT/EN) and on by default.
- No named instruments/tickers in template copy.

## Architecture
- `includes/class-brand.php` — logo SVG, bilingual disclaimer, font URLs.
- `includes/class-templates.php` — category map + post-type → categories.
- `includes/class-assets.php` — enqueues + localized `HTI_SOCIAL` config.
- `includes/class-admin.php` — the generator page.
- `includes/class-metabox.php` — the News/Glossary meta box + prefill payload.
- `assets/js/templates.js` — template **data** (full-size HTML + `{{tokens}}`).
- `assets/js/social.js` — the editor + the SVG→canvas→PNG export engine.
- `assets/css/social.css` — editor chrome only (templates carry inline styles).

Tokens: `{{logo}}`, `{{disclaimer}}`, `{{#legal}}…{{/legal}}`, `{{img:slotId}}`,
and any `{{field}}` declared in a template's `fields`.

## Status — complete (19 templates)
All families from the handoff are in:
- **News** — Square 1080×1080, Story 1080×1920, X 1600×900.
- **Glossary** — Facebook 1080×1080, Feed 1080×1350, Story 1080×1920.
- **Fun fact** — green / purple square + green story.
- **Quiz CTA** — Square, Story, X.
- **og:image** — full-photo / split (1200×630).
- **Editorial 4:5** — Featured, Economy, Tool promo (square), Data infographic
  (SVG chart), Daily recap.

Both placements: the **Social** admin page (all templates) and the **Social
cards** meta box on News (news + og + editorial, auto-filled) and Glossary.

Fun fact uses the ported `{{illoShip}}` / `{{illoGold}}` brand illustrations
(raw SVG tokens, alongside `{{logo}}`).

Engine tokens also include `{{initial}}` (first letter of the term, used as the
big decorative glyph), `{{#has:KEY}}…{{/has:KEY}}` (keep a block only when a
field is filled) and `{{chips:KEY}}` (a comma list expanded into pills).

## Notes
- Export is client-side (admin's browser) — best in Chrome/Firefox. It does not
  auto-generate PNGs server-side; the post's content is **auto-filled** so you
  tweak and export in a couple of clicks.
- Self-hosted fonts in `assets/fonts/` mirror the theme (Poppins, Plus Jakarta
  Sans, latin subset).
