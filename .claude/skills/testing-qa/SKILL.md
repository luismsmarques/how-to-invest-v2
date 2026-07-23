---
name: testing-qa
description: Use when writing tests or validating changes — the pure-PHP test harness (tests/run.php + bootstrap shims, no WordPress), how to add a test-*.php, the engine test matrix, and the launch QA gates including the RGPD export/delete checklist. Triggers on tests/ work, adding coverage, or pre-commit/pre-launch validation. Run the suites before every commit.
---

# testing-qa — Test harness & QA gates

Tests run in **pure PHP, without WordPress or a database** — WP functions are shimmed in memory, so they test *logic*, not the live WP integration. Deep integration (real REST/DB cascade) needs a wp-env harness we don't have yet; note that gap honestly.

## The harness
- Each plugin has `tests/run.php` — globs `test-*.php`, runs each in its own process, aggregates, exits non-zero on failure (gates CI). Also runs an optional Node `.mjs` test.
- `tests/bootstrap.php` — defines `ABSPATH`, `DAY_IN_SECONDS`, and in-memory shims (`get_transient`, `add_filter`, `sanitize_text_field`, …), then `require`s the deterministic classes. It does **not** shim `get_option`/user meta — add those in your test file when needed.
- Run: `composer test` (from the plugin dir) or `php wp-content/plugins/<plugin>/tests/run.php`. Suites live in `hti-engine/tests/` and `hti-rss-ai/tests/`.

## Writing a test (`tests/test-<thing>.php`)
- `require __DIR__ . '/bootstrap.php';` then `require` the class(es) under test.
- Add any missing WP shims guarded by `function_exists()` (e.g. an in-memory `get_option`/`update_option`, or `wp_strip_all_tags`). See `test-metrics.php` and `test-account-gdpr.php` for the pattern.
- Reach private static methods with `ReflectionMethod::setAccessible(true)` (see `test-account-gdpr.php`).
- Use the colored `check(bool, label)` helper; print a summary and `exit(failures ? 1 : 0)`.
- Keep it deterministic — no network, no real time-dependence where avoidable.

## What to cover
- **Engine matrix** (`test-engine.php`): ≥12 scenarios — the 5 archetypes, the 3 safety traps, crypto cases, ESG invariance, determinism, thresholds, invalid input. Any engine change adds/updates a scenario (see `hti-engine-spec`).
- Validators, prompt, fallback, settings, cron, rate-limit, metrics/KPIs, GDPR grace flow — each has a `test-*.php`.
- **Untestable here → manual:** real REST auth + the GDPR delete/export DB cascade run through `docs/QA_RGPD_Checklist.md` on staging.

## QA gates (before launch)
- `docs/Criterios_Pronto_QA_HowToInvest_MVP.md` (definition of done), `docs/QA_Gate_Lancamento.md` (launch gate), `docs/QA_RGPD_Checklist.md` (RGPD P0). Verify schema in the Rich Results Test; Core Web Vitals green.

## Checklist (before "done")
- [ ] New/changed logic has a `test-*.php`; both suites green (`run.php`)
- [ ] `php -l` clean on every touched PHP file
- [ ] Shims added guarded by `function_exists`; privates via reflection
- [ ] Engine changes update the ≥12-scenario matrix
- [ ] DB/REST-dependent behaviour flagged for the manual QA checklist
