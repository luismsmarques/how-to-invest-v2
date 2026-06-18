---
name: wordpress-backend
description: Use when working on WordPress server-side code — registering custom post types or taxonomies, hooks (actions/filters), the REST API, options/settings, transients, user/capability management, cron, or internationalization. Triggers on tasks involving wp-admin, plugin PHP logic, data persistence, or the hti-engine plugin's includes/ classes.
---

# WordPress Backend

Server-side WordPress development for the `hti-engine` plugin. Always prefer WordPress core APIs over reinventing.

## Custom Post Types
- `htinvest_profile` is **private**: `public => false`, `exclude_from_search => true`, `show_in_rest => false` (we expose via our own controlled endpoints, not the default REST).
- Register on `init`. Use `hti_` prefix. Capabilities mapped so only the owner/admin can read a profile.

## REST API (our endpoints)
- Namespace `htinvest/v1`. Routes: `/recommend`, `/claim-profile`, `/my-profiles`, `/account` (DELETE), `/export` (GET). See `docs/Modelo_Dados_API… §5`.
- Every route: `permission_callback` (never `__return_true` for state-changing or personal-data routes), nonce verification, capability checks.
- Validate + sanitize all input via `args` schema in `register_rest_route`.
- Return `WP_REST_Response` with proper status codes (200/422/500/502 per the contract).

## Hooks
- Use actions/filters, never edit core. Document each `add_action`/`add_filter` with why.
- Enqueue assets with `wp_enqueue_script`/`style`, versioned, only on the pages that need them (questionnaire/result), not site-wide.

## Options & settings
- Settings page via Settings API. Store `htinvest_archetypes`, `htinvest_scoring`, `htinvest_settings` as options.
- Gemini API key: read from `wp-config.php` constant / env var if defined; settings field only as fallback. **Never** echo the key.

## Security
- Sanitize input (`sanitize_text_field`, `absint`, etc.); escape output (`esc_html`, `esc_attr`, `wp_kses`).
- `$wpdb->prepare` for any direct SQL. Prefer WP_Query / get_posts / metadata API.
- Nonces on all forms and AJAX/REST.

## i18n
- Text domain `hti-engine`. Wrap all user-facing strings in `__()`/`esc_html__()`. Load `languages/`.
- Remember: every string also needs the PT variant (handled via translation files or the language-aware data model).

## Checklist before done
- [ ] Capabilities + nonce on every endpoint
- [ ] Input sanitized, output escaped
- [ ] No fatal on missing/invalid data — graceful handling
- [ ] Strings translatable
