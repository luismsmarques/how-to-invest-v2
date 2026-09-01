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
- **M3 — grouping (done):** Jaccard clustering of similar drafts per language + Groups page (view items, "Group now", dismiss).
- **M4 — research + generation (done):** Gemini (Google Search grounding) fact research → validated JSON → original SEO/Google-News article saved as a pending `news` post (with sources + disclaimer). "Generate article" on a group; daily limit.
- **M5 — review + SEO (done):** news edit-screen meta box (AI provenance + sources + sitelinking suggestions) and meta-description bridge to RankMath/Yoast.
- **M6 — hardening (done):** capped activity Logger + Logs page; logging across fetch/group/generate; `uninstall.php` (drops tables + options); pure-PHP test suite (extract-json, validator, grouping).
- **M7 — featured image (done):** on generation, an **AI illustration** about the article topic is set as the post's featured image. "Regenerate AI image" button in a news-editor meta box. Settings: enable toggle + image model.
- **M9 — image brief + model discovery (done, v1.13.0):** the illustration is
  drawn from a small JSON **image brief** rather than straight from the feed
  photo; model names are **discovered from the API** instead of typed from
  memory; image/embedding failures are **counted and shown** in Settings; and
  the last resort is a **brand card drawn locally**, never the agency photo.
- **M8 — social media kit (removed in v1.6.0):** the old GD-rendered cards
  (square 1080×1080 + story 1080×1920) were **removed** — superseded by the
  **`hti-social`** plugin (Social Generator), which renders the full design
  template set as HTML/CSS and exports faithful PNGs, auto-filled from the news
  post. The featured image is still reused there (no extra AI calls).

The full pipeline (feeds → drafts → groups → generate → AI featured image →
review) is complete. Social cards are now produced by **`hti-social`**.

## Featured image

Every article gets one, by one of these routes, in order:

1. **AI illustration drawn from the image brief** (`ai-from-brief`) — the normal
   path. A short JSON brief describes the scene: read out of the feed photo by a
   vision call when there is one, invented from the headline when there is not.
   Both roads end in the same shape, so "no photo in the feed" is an ordinary
   path rather than a special case. The brief is kept in `rssai_image_brief`
   meta, so an editor can see what the model understood and a regeneration can
   reuse it.
2. **AI illustration restyled from the feed photo** (`ai-from-feed`) — the
   rescue, when 1 fails and the item has a photo and an image-to-image model is
   configured.
3. **Brand card** (`brand-card`) — drawn locally by `class-fallback-card.php`
   with GD: a 1200×675 geometric card in the site palette, deterministic from
   the headline. No network, no API, so it cannot fail for the same reasons.

**The feed photograph is read and never published.** It is the input to the
brief and the base of the rescue route; it is never itself the featured image.
That reads like a regression and is the opposite — there is now always an image,
and it is always ours. Route recorded in `rssai_card_photo_source` meta.

The image is reused by the `hti-social` card generator (auto-fill), no extra
AI calls.

## Models

Google retires model names on its own schedule, and a name that worked at
install time stops working later with no warning anywhere but the log — which is
how this plugin lost its featured images for two weeks. So:

- **Settings → List available models** asks the API (`ListModels`) what the key
  actually has, grouped by capability.
- A **test button** per model makes one real call and reports the result.
- A **retired-name warning** appears next to any field holding a name known to
  be switched off, and a one-off migration rewrites the two that shipped
  (`imagen-*` → `gemini-2.5-flash-image`, `text-embedding-*` →
  `gemini-embedding-001`). The migration is needed because the settings screen
  writes every key on any save, so changing a code default changes nothing on an
  installation that has ever been configured.

## Pipeline health

Image generation and embeddings are best-effort: when they fail the article is
still written. That is deliberate, and it is also how a dead model goes
unnoticed. **Settings → Pipeline health** counts the last 24 hours of successes
and failures per subsystem and shows the last error.

## Requirements
- WordPress 6.7+, PHP 8.3+.
- **HTI Engine** active (provides the `news` content type and the Gemini key
  `HTI_GEMINI_API_KEY` / Connectors, reused here).

## Conventions
- Prefix `rssai_` / `RSSAI_`, namespace `HTI\RssAI`. WPCS, escaped output,
  sanitized input, nonces + capabilities. EN default + PT.
