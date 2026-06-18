---
name: php-standards
description: Use when writing or reviewing any PHP code in this project — to apply modern PHP 8.4 features, WordPress Coding Standards, secure coding (sanitization, escaping, prepared statements), error handling, and clean class structure. Triggers on any .php file authoring or review.
---

# PHP Standards (PHP 8.4 + WPCS)

Target PHP 8.4 (works on 8.3). Follow WordPress Coding Standards, with modern PHP where WPCS allows.

## Modern PHP to use
- Typed properties, typed params, return types.
- Constructor property promotion, readonly where it fits.
- Enums for fixed sets (archetype ids, asset classes, answer keys) — improves the engine's safety.
- Null-safe operator, match expressions over long switch where clearer.

## WPCS essentials
- Yoda conditions where WPCS expects them; spacing and naming per WPCS.
- snake_case for functions, `HTI_` prefixed PascalCase for classes.
- One class per file, `class-foo.php` naming.

## Security (non-negotiable)
- **Sanitize input** at the boundary: `sanitize_text_field`, `absint`, `sanitize_email`, allowlist for enums.
- **Escape output** at the point of output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`.
- **Prepared statements**: `$wpdb->prepare()` always when raw SQL is unavoidable.
- **Nonces + capabilities** for any action with side effects.
- Never trust client data; the allocation decision is server-side only.

## Error handling
- Fail gracefully — the engine must return a valid numeric result even if the LLM call throws (catch, log without PII, use fallback).
- Use `WP_Error` for recoverable errors in WP context.
- No PII in logs (see gdpr-data skill).

## Structure
- Small, single-responsibility classes (engine, gemini, fallback, pdf, rest, cpt, settings).
- Dependency passed in, not fetched globally, where reasonable (testability).

## Checklist
- [ ] Types on all signatures
- [ ] Input sanitized / output escaped
- [ ] No PII in logs
- [ ] Graceful fallback paths covered
- [ ] Enums for fixed sets
