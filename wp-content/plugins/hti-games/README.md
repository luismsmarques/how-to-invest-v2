# HTI Games

Two educational games under `/games/` (`/pt/jogos/`), in one isolated plugin.

- **Survive the Charts** — a chart a day. Buy, sell or pass, then choose what
  fraction of a $10,000 virtual account to put behind the call. The lesson is
  position size: the game is built so that being right about direction is not
  what keeps you alive.
- **The Reveal** — an anonymised dossier of a real company at a real year:
  sector, six fundamentals against their sector average, three headlines from
  the period. Invest a share of the account or pass; then the name, the year
  and the real five-year return, next to what the index did.

## Why a separate plugin

The same reasoning as `hti-forex`: keeping the section in its own plugin keeps
its rules physically contained, and removing it is "deactivate one plugin".
It also keeps the theme untouched — the games ship as shortcodes on ordinary
pages and carry their own CSS, so `functions.php`, `theme.json` and
`style.css` have no game code in them at all.

## The rules that are not negotiable

- **No brokers.** No affiliate link, banner, partner module or broker mention
  on any game surface. The `/forex/` exemption does not extend here by
  analogy: ESMA prohibits incentives tied to retail CFD trading, and a
  leaderboard that rewards risk next to a broker CTA would be one. There is a
  test that greps the rendered pages for `/go/` and the broker slugs.
- **No prizes, no real money.** Virtual capital only, and the page says so.
- **The Reveal never serves an unverified case.** A case without a source URL
  and a recorded verification is forced back to draft and is excluded from the
  pool by the query itself. Editing any of the numbers clears the verification.
- **The server decides.** The client is never sent the outcome candles, nor the
  company name, year or return, before its decision is recorded.

## Layout

```
includes/   pure engines first (no WordPress), then the WordPress-bound classes
assets/js/  *-core.js are UMD mirrors of the PHP engines; *.js are the UIs
assets/css/ games.css (shell) + stc.css (dark) + reveal.css (light)
tests/      pure PHP, no WordPress, no PHPUnit — run.php globs test-*.php
```

## Tests

```
php wp-content/plugins/hti-games/tests/run.php
```

The two engines exist twice — in PHP (which decides) and in JS (which
animates). `tests/gen-parity.mjs` writes `tests/fixtures/parity.json` from the
JS; the PHP tests and the JS test both assert against it. Changing the maths on
one side without regenerating turns one of the two suites red, which is the
entire point.
