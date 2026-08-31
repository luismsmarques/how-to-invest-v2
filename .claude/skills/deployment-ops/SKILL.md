---
name: deployment-ops
description: Use when deploying, releasing or configuring environments — the cPanel Git deploy (.cpanel.yml), the staging-first workflow and branch strategy, CI (php -l + test suites), cache-busting via the theme VERSION bump, composer/vendor (Dompdf), and server-side env keys (Gemini/Brevo). Triggers on deploy, .cpanel.yml/DEPLOY.md, CI config, releases, or environment/cache issues. Build and test on staging (noindex + password), never in production.
---

# deployment-ops — Deploy, environments & releases

Hosting is cPanel (Apache + LiteSpeed). We own cache, backups and security. Read `DEPLOY.md` (the runbook) and `CLAUDE.md` (invariants + staging-first workflow) first.

## Branch strategy
- `main` = **production**, `develop` = **staging**, `claude/*` = work branches.
- **Always build/verify on staging** (a noindex + password subdomain) before production. Never edit production directly.

## What deploys (`.cpanel.yml`)
- rsync/cp of the **five** plugins (`hti-engine`, `hti-rss-ai`, `hti-social`, `hti-forex`, `hti-games`) + the `howtoinvest` theme into `$DEPLOYPATH/wp-content`. **WordPress core, `wp-config.php` and `uploads/` are never touched.** A plugin without its `rm -rf` + `cp -R` pair in `.cpanel.yml` is not deployed **and the deploy still goes green** — add the pair in the same commit that adds the plugin.
- Runs `composer install --no-dev --no-interaction --optimize-autoloader` (timeout 180) in `hti-engine`, with `|| true` — so `vendor/` (Dompdf) may legitimately be absent; the PDF path degrades to printable HTML.
- After deploy: **clear the page cache** (LiteSpeed + WP) — HTML/PHP changes are not busted by asset versions.
- **Content converges on its own**: `Content_Sync` (hti-engine) detects the deploy via a file-manifest signature and schedules one background sync (brokers upsert + Learn/glossary imports) — needs a site visit for WP-Cron to fire. Merging content to `main` = publishing it. Status/override: Tools → Content sync. Broker deal fields (affiliate/CFD %) are never touched by sync.

## Cache-busting (assets)
- The theme const `VERSION` (`wp-content/themes/howtoinvest/functions.php:19`) is the `?ver=` on every `wp_register_style`/`wp_register_script`. **Bump it whenever you change CSS/JS**, or caches serve stale assets. (It does NOT bust server-rendered HTML — that needs a page-cache purge.)

## Secrets & config
- `HTI_GEMINI_API_KEY`, `HTI_BREVO_API_KEY` (and GA id) live in `wp-config.php`/env **only** — never in the repo or client JS. Resolved server-side (`class-gemini`, `class-mailer`).

## CI (`.github/workflows/ci.yml`)
- PHP 8.3 + Node 22. `php -l` lint across **all** of `wp-content/plugins` + the theme (excludes `vendor/` and `node_modules/`), `node --check` across every `.js`, then the **four** suites (`hti-engine`, `hti-rss-ai`, `hti-forex`, `hti-games`), three explicit Node parity runs, and a `composer audit --locked` on the pinned Dompdf. Runs on push/PR to `main`/`develop`/`claude/**`. **`.claude/skills` and docs are markdown → no CI impact.** A new plugin needs its own `run.php` step here, or it ships unverified.

## Checklist (before "done")
- [ ] Built/verified on **staging**, not production
- [ ] `composer test` / both `tests/run.php` green; `php -l` clean
- [ ] **`VERSION` bumped** if any CSS/JS changed
- [ ] No secrets committed; env keys in `wp-config.php` only
- [ ] Post-deploy page cache cleared (LiteSpeed/WP)
- [ ] DB migrations self-run on load; content converges via the Content_Sync auto-run after deploy — verify in Tools → Content sync (see `content-editorial`)
