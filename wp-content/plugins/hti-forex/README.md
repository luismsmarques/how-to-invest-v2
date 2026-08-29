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
| `includes/class-bot-math.php` | The bot's arithmetic, pure: the amount parser (₹, $, lakh, crore, Indian grouping), the PHP port of pip value and margin, Indian digit formatting, and the account picture. |
| `includes/class-telegram.php` | Bot API transport. Token from `HTI_TELEGRAM_BOT_TOKEN` in `wp-config.php`, never the database. Turns 403 into "drop them" and 429 into "wait this long". |
| `includes/class-bot-store.php` | Subscriber table (`dbDelta` from `init` — the deploy runs no activation hook) plus the aggregate balance counters, which are never linked to a chat id. |
| `includes/class-bot.php` | Webhook route `htinvest/v1/forex/telegram`, secret-header check, command router, and the reply text. |
| `includes/class-bot-broadcast.php` | The admin broadcast: cursor-walked batches on single cron events, dropping anyone who blocked the bot. |
| `includes/class-bot-admin.php` | The bot's panel in Settings → HTI Forex: webhook button, balance distribution, message composer. |
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
   `wp hti-forex seed`). Eight EN pages appear under `/forex/`: the hub,
   position size, pip value, profit/loss, market hours, plus the XAUUSD,
   "$100 account" and "with leverage" variants. Re-running only adds what
   is missing — if the hub was seeded before the variants existed, delete
   the hub page and re-seed to regenerate its tool list.
1b. **Brevo** — create a `SOURCE` **text attribute** in the Brevo dashboard
   (Contacts → Settings → Attributes). Confirmed opt-ins then carry the
   originating form (e.g. `FOREX-PIP_VALUE`, `EBOOK-PAGE`) for
   segmentation; without the attribute Brevo silently ignores it. Forex
   opt-ins receive the INR lot-size cheat sheet PDF after confirming.
2. **Rates** — activation schedules the twice-daily fetch and an immediate
   first fetch; check the "Exchange rates" panel shows `frankfurter` with a
   fresh date (use "Fetch now" if needed). Overrides are for emergencies.
3. **Conversion block** — the slot after the calculator carries either the
   Telegram channel or the email form, chosen in the settings
   (`conversion_block`: `telegram` | `email` | `both`, default `telegram`).
   It is a live experiment: this audience reads Telegram daily and may join a
   channel more readily than hand an email to a foreign site — or may not, and
   the email list is an asset the channel is not. The offer is the same either
   way: the **INR cheat sheet**, pinned in the channel instead of emailed.
   Set a **named invite link** as the URL (channel settings → invite links);
   Telegram counts joins per link, which is the only way to see how many of
   these clicks became followers. Clicks are counted our side as `cta_click`
   with `forex_telegram_hub` / `forex_telegram_{tool}`. Switching back to
   `email` is one click and loses nothing — the `hti_lead_magnet` filter and
   the PDF stay wired throughout.
4. **Campaign tracking** — first-party `page_view`/`cta_click` work out of the
   box (HTI Funnel screen). For ad-platform pixels, configure them in GTM;
   the dataLayer push is consent-gated as everywhere else on the site.
5. **Affiliate CTA** — paste the https partner URL, keep the conditional
   label, tick the tools it should show on, then enable the kill-switch
   checkbox. The `clickid`/`utm_campaign` landing parameter is appended to
   the CTA automatically for sub-id attribution.
6. **Before enabling the CTA**: re-read the regulatory note below — the
   tools are safe; the conversion layer is where the exposure lives.

## The Telegram bot

Send it an account balance and it answers with what the smallest position you
can open actually costs — margin locked, what a pip is worth, what a 20- and a
50-pip stop would cost, and what fraction of the account that is. One number
in, the whole risk picture out, in rupees.

It asks for a balance and nothing else on purpose. The people arriving from
`/forex/` mostly do not yet know their risk percentage or their stop in pips,
so a command syntax that demands both would be asking them for the answer. It
accepts `5000`, `₹1,00,000`, `50k`, `2 lakh` and `$100` — the dollar forms
matter, because the page that converts best on the site is literally *lot size
for a $100 account*.

Gold is deliberately out of scope: its margin needs a live metal price and we
have no source for one. It stays on the website, where the price is typed in.

**Setup.** Create a bot with @BotFather, put the token in `wp-config.php` as
`HTI_TELEGRAM_BOT_TOKEN`, then press *Register webhook* in Settings → HTI
Forex. Telegram allows one webhook per bot, so never point a live bot at
staging — it would silently take over the real one. Use a second test bot.

**It never speaks first.** There is no daily alert and no schedule. The only
unprompted message anyone receives is one an admin writes in wp-admin and
confirms. Frequency is what makes people block a bot, and a blocked user is
gone permanently — there is no re-subscribing.

**What it stores.** A row per chat id with two display preferences and two
timestamps: no names, no message text, no balances. `/stop` deletes the row,
and so does a 403 from Telegram. Separately, every balance is counted into a
band — counts only, never against a chat id — which is what turns the bot into
the audience research the project is missing: after a fortnight the panel says
whether these are ₹5,000 accounts or ₹5,00,000 ones.

**The partner line** sits at the foot of an answer, after the arithmetic, never
inside it, and only when both `cta_enabled` and `bot_ad_enabled` are on. It
goes through `/forex/go/tg-bot/`, so its clicks are already counted as
`forex_go_tg-bot` and the partner's sub-id tells the placement apart from the
website's and the cheat sheet's.

> Recorded risk, since this was a deliberate decision: XM appears on the RBI's
> Alert List, and trading offshore OTC forex breaches FEMA for Indian
> residents. A private message is a more forward posture than a labelled slot
> on a page, and the recipients are Indian residents. The label, the CFD risk
> warning, the Alert List line and answering before advertising are as far as
> the code can go; the rest is a business call.

## Phase 2 backlog

Shipped since the MVP: profit/loss calculator, XAUUSD / "$100 account" /
"with leverage" variant pages, Brevo `SOURCE` attribution and the cheat-sheet
lead magnet (all in this plugin + a small generalization in hti-engine's
subscribe flow: `hti_pending_source` store + `hti_lead_magnet` filter).

Still open:

- Noindex campaign variants (stripped nav, harder CTA) using the existing
  robots-filter pattern, if ad QS/policy calls for separate landers.
- NSE ↔ global lot-convention converter and the XAUUSD ↔ MCX gold bridge
  (research gaps #2 and #5) once the MVP proves traffic.
- Romanized Hindi/Tamil educational articles with English slugs (research:
  tools in English, education in Indian languages).
- Total funding-cost calculator in ₹ (UPI/IMPS fees, conversion markup,
  withdrawal costs) — research gap #4, defensible because it needs upkeep.

The cheat sheet's reference rates are baked into the PDF (dated August
2026). To refresh: edit `assets/pdf/src/cheat-sheet.html`, run
`assets/pdf/src/build.sh` (headless Chromium; `CHROMIUM=/path/to/chrome` if it
is not on the PATH), and commit both files. Chromium keeps `<a href>` as real
PDF link annotations, which is what makes the sheet's links clickable — check
the page count after editing, the layout is built to fill exactly two A4 pages.

To put the partner's banner on page 1, drop the creative from the affiliate
panel at `assets/pdf/src/xm-600x90.png` (600×90) and rebuild: `build.sh`
injects it at the `<!--XM_BANNER-->` marker, and injects nothing when the file
is absent, so the sheet is never published with a broken image. The banner
links through `/forex/go/cheatsheet-banner/` — a different placement from the
text block's `/forex/go/cheatsheet/`, so the two are told apart both in the
affiliate panel and in our click counts. Page 1 has room for it; page 2 does
not, which is why the slot lives there.

The sheet's partner link points at `/forex/go/cheatsheet/`, never at the
affiliate URL. `includes/class-go.php` resolves that route at click time from
the CTA settings, appends the configured sub-id (`clickid` by default) so the
placement is attributable, counts a `cta_click` under `forex_go_{slot}`, and
falls back to the `/forex/` hub whenever the CTA kill-switch is off — a
printed link must never dead-end. The route is `noindex` and robots-disallowed.
Adding a placement is just a new slot in the URL: `/forex/go/{slot}/`.

Rewrite rules are flushed once per plugin VERSION (the cPanel deploy never
reactivates plugins), so bump `VERSION` when touching the route.

## Tests

```
php wp-content/plugins/hti-forex/tests/run.php
```

Runs every `tests/test-*.php` plus `tests/test-forex-core.mjs` under Node when
available. Wired into `.github/workflows/ci.yml`.

**The parity fixture.** `tests/fixtures/parity.json` is the contract between
the two implementations of the same arithmetic — the JavaScript the website
runs and the PHP the bot runs. Both suites assert against it, so changing the
maths on either side without regenerating turns one of them red. Regenerate
deliberately, and read the diff:

```
node wp-content/plugins/hti-forex/tests/gen-parity.mjs
```

The website and the bot disagreeing about what a pip costs would cost us the
one thing that makes this project worth trusting.
