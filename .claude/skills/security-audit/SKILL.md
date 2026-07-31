---
name: security-audit
description: Use when auditing the security of the theme and plugins — "revisão de segurança", "o tema/plugins são seguros?", a pre-launch security pass, or after adding any endpoint, admin handler, form or external integration. php-standards teaches writing secure code; this skill teaches finding what slipped through. Triggers on security review requests and on project-review's security dimension.
---

# Security Audit — theme + plugins (hti-engine, hti-rss-ai, hti-social)

Audit what EXISTS, adversarially: for each surface ask "how would I abuse this?". Report
only findings with a plausible exploitation path in THIS project — no theatrical findings.

## Surfaces to walk (real files)
- **REST** (`hti-engine/includes/class-rest.php`, `hti-social`'s class-rest): every route
  has an honest `permission_callback` (public routes justified, never accidental
  `__return_true`), nonce where state changes, rate limiting (`class-rate-limit.php`),
  and returns no more fields than the UI needs.
- **admin-post / forms** (`hti_run_seeder`, `hti_deposits_save`, contact, feedback, NPS):
  `current_user_can` + `check_admin_referer` on every handler; honeypots on public forms.
- **Rendering**: every `render_*` callback and shortcode escapes output (`esc_html`,
  `esc_attr`, `esc_url`, `wp_kses`); anything echoing request data is a finding.
- **RSS fetcher** (`hti-rss-ai/includes/class-fetcher.php`): SSRF guard intact (no
  internal IPs/ports), timeouts on all `wp_remote_*`, response size limits.
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
  headers current; no `vendor/` committed.

## Information disclosure (cross-check gdpr-data)
- `error_log`/debug output with PII or keys; WP_DEBUG assumptions; REST responses
  leaking emails/IDs; versions/paths in HTML comments or headers.

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
