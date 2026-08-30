---
name: security-audit
description: Use when auditing the security of the theme and plugins — "revisão de segurança", "o tema/plugins são seguros?", a pre-launch security pass, or after adding any endpoint, admin handler, form or external integration. php-standards teaches writing secure code; this skill teaches finding what slipped through. Triggers on security review requests and on project-review's security dimension.
---

# Security Audit — theme + plugins (hti-engine, hti-forex, hti-rss-ai, hti-social)

Audit what EXISTS, adversarially: for each surface ask "how would I abuse this?". Report
only findings with a plausible exploitation path in THIS project — no theatrical findings.

**Scope:** only code we wrote — `wp-content/themes/howtoinvest` and the four `hti-*`
plugins. Nothing from the WordPress.org repository (those are the vendor's to patch;
our job there is to keep them updated, not to read them).

## Surfaces to walk (real files)
- **REST** (`hti-engine/includes/class-rest.php`, `hti-forex/includes/class-bot.php`,
  `hti-social`'s class-rest): every route
  has an honest `permission_callback` (public routes justified, never accidental
  `__return_true`), nonce where state changes, rate limiting (`class-rate-limit.php`),
  and returns no more fields than the UI needs.
- **admin-post / forms** (`hti_run_seeder`, `hti_deposits_save`, contact, feedback, NPS):
  `current_user_can` + `check_admin_referer` on every handler; honeypots on public forms.
- **Rendering**: every `render_*` callback and shortcode escapes output (`esc_html`,
  `esc_attr`, `esc_url`, `wp_kses`); anything echoing request data is a finding.
- **RSS fetcher** (`hti-rss-ai/includes/class-fetcher.php`): SSRF guard intact (no
  internal IPs/ports), timeouts on all `wp_remote_*`, response size limits.
- **Telegram bot** (`hti-forex/includes/class-bot.php`, `class-telegram.php`): the
  webhook route is `__return_true` by necessity — Telegram cannot send a nonce — so the
  secret header must be compared with `hash_equals` INSIDE the handler, before anything
  reads the body. Also: the bot token never in an option, the `/forex/go/` redirector's
  slot and `cid` normalized before they reach an outbound URL.
- **Third-party code fetched at runtime** (`hti-social/includes/class-ffmpeg-cache.php`):
  anything downloaded and then served from our own origin is first-party script in the
  admin's browser. Demand a pinned hash, not just a pinned version — and check what the
  `.htaccess` written beside it actually denies.
- **Unbounded growth from public endpoints**: every map an anonymous request can add a
  key to needs a cardinality cap, not just a rate limit. `hti_metrics` is one option row
  read-modify-written on every beacon; a key space an attacker chooses is a slow DoS.
- **LLM/email keys**: `HTI_GEMINI_API_KEY`/`HTI_BREVO_API_KEY` only via
  `wp-config.php`/env — grep the repo AND the enqueued JS to confirm never client-side.
- **Auth flows**: double opt-in HMAC (`class-subscribe.php`), deletion-cancel token
  (`class-account.php`), Google OAuth state param (`class-google.php`).
- **Headers/cookies** (`hti-engine.php` `send_security_headers`): nosniff, frame,
  referrer, permissions; HSTS staged rollout state; cookies Secure+SameSite.
- **PDF/uploads** (`class-pdf.php`, Dompdf): no user-controlled paths; Dompdf version.

## Concrete greps (run all — cheap and high-yield)
```
\$_(GET|POST|REQUEST|COOKIE)\[          # then check each use is sanitized
echo .*\$|printf.*\$                    # unescaped output candidates
\$wpdb->(query|get_var|get_row|get_col|get_results)  # must be ->prepare'd
wp_remote_(get|post|request)            # URL from input? timeout set?
eval\(|base64_decode|system\(|exec\(|shell_exec
(api_key|secret|token|password)\s*=\s*['"]         # hardcoded secrets
permission_callback                     # audit every one
sslverify.*false|verify.*=>.*false     # TLS bypass
maybe_unserialize|unserialize\(         # object injection
```

## Dependencies & platform
- `composer audit` in `hti-engine/` (Dompdf CVEs); `Requires PHP`/`Requires at least`
  headers current; no `vendor/` committed. **A `composer audit` with no `composer.lock`
  is not an audit** — without the lock the deployed version is whatever the last
  `composer install` resolved, and the deploy wipes `vendor/` every time.
- Dompdf must be constructed with `isRemoteEnabled => false` (`class-pdf.php`).

## Information disclosure (cross-check gdpr-data)
- `error_log`/debug output with PII or keys; WP_DEBUG assumptions; REST responses
  leaking emails/IDs; versions/paths in HTML comments or headers.

## Automate the two checks that must not be eyeballed
`scripts/audit-handlers.py` (run from the repo root) does both and prints a table —
start there, then read the flagged lines yourself. A human skimming 37 handlers will
miss one:
1. **Every `admin_post*` registration → its handler → does the body contain both
   `current_user_can` and `check_admin_referer`/`wp_verify_nonce`?** Print a table; only
   `nopriv` routes may lack a capability, and each of those must show a token compared
   with `hash_equals`.
2. **Every `$wpdb->{query,get_var,get_row,get_col,get_results}` → the variables
   interpolated into its argument.** Only names matching `table|prefix` are acceptable
   unprepared; anything else is a finding until proven otherwise.

## Report format
Per finding: **Severidade** (crítico/alto/médio/baixo) · **Evidência** (file:line) ·
**Cenário de abuso** (concrete: attacker does X → gets Y) · **Correção proposta** (small
diff or config change). Order by severity. State explicitly which surfaces were checked
and came back CLEAN — a clean bill needs coverage proof, not silence.

## Checklist
- [ ] All surfaces walked, incl. all three plugins (not just hti-engine)
- [ ] Every grep from the list executed; hits triaged, not just counted
- [ ] Every `permission_callback` and admin handler individually verified
- [ ] Keys confirmed absent from repo and client JS
- [ ] `composer audit` run; PHP/WP minimums checked
- [ ] Findings have abuse scenario + fix; clean surfaces listed as covered
- [ ] No theatrical findings — each has a real exploitation path here
