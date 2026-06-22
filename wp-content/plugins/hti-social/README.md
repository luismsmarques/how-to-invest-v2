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
