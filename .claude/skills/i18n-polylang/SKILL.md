---
name: i18n-polylang
description: Use when handling the bilingual EN+PT layer in code — Polylang translation linking, language resolution (pll_* / current_lang), PT slugs and -pt term conventions, per-post language in schema/hreflang, the theme strings() table vs __() text domains, and localizing internal URLs. Triggers on pll_ calls, language switching/resolution, translation linking in importers, hreflang, or any "works in EN but not /pt/" bug. Bilingual parity is a project invariant.
---

# i18n-polylang — Bilingual engineering (EN default + PT)

Every user-facing surface exists in **EN (default) + PT (pt-PT, informal "tu")**. Polylang links the pairs. This skill is the *engineering* layer; tone lives in `brand-voice`, content authoring in `learn-guide`/`content-editorial`.

## Registration & linking
- CPTs/taxonomies are made translatable **in code** via the `pll_get_post_types`/`pll_get_taxonomies` filters (`hti-engine.php`) — never rely on someone ticking Polylang settings.
- Importers/seeder create the EN and PT posts and link them (`pll_set_post_language` + `pll_save_post_translations` — see `class-content-import.php::link_languages`). A PT post without linking is invisible to hreflang and the language switcher.
- **Every `pll_*` call must be guarded** with `function_exists()` — the code degrades gracefully without Polylang.

## Resolving the language (pick the right tool)
- Theme UI: `current_lang()` (`functions.php`) → `'en'|'pt'` from Polylang, falling back to locale.
- Given a post id, get the translated id: `pll_get_post( $id, $lang_slug )` — e.g. schema author URL, curriculum PT resolution. Never assume the EN id.
- Per-post language (not site locale) for metadata: `SEO::post_lang()` pattern → `pll_get_post_language( $id )` → BCP-47 (`en-US`/`pt-PT`) for `inLanguage`; hreflang pairs are emitted by Polylang once translations are linked.

## Slug & string conventions
- Content frontmatter carries `slug` (EN, canonical — curriculum/progress key) + `slug_pt`; PT taxonomy terms use the `-pt` suffix (see the seeder).
- Theme UI strings: the `strings()` table + `t()` (always add EN+PT together); FSE templates use the `howtoinvest/t` block. Plugin PHP strings: `__()/_e()` with text domains `hti-engine` / `howtoinvest`.
- Markdown content: one file, `<!-- EN -->` / `<!-- PT -->` sections; `[glossary:]`/`[learn:]` tokens **only in the EN section** (they resolve to the given slug regardless of language).

## URLs & pitfalls
- **Never hardcode `/pt/`.** Internal links must be localized: the theme's `localize_internal_href()` (used by `howtoinvest/t`), `page_url()`/`archive_url()` helpers, or `pll_get_post` + `get_permalink`.
- Emails/deep links resolve the *user's* stored locale (e.g. `Account::user_locale`), not the request locale.
- "Works in EN, broken in /pt/" usually means: unlinked translation, an unguarded `pll_*` call, a hardcoded EN slug, or a static pattern rendering before the language is known (use a dynamic block — see `wordpress-theme`).

## Checklist
- [ ] Both languages written; posts/terms linked as Polylang translations
- [ ] `pll_*` calls guarded with `function_exists()`
- [ ] Language resolved per-post (`pll_get_post_language`) where metadata needs it; ids translated via `pll_get_post`
- [ ] No hardcoded `/pt/` or EN slugs in links; internal hrefs localized
- [ ] UI strings added to `strings()` (theme) or `__()` with the right text domain (plugins), EN+PT
- [ ] Verified on both `/` and `/pt/` before done
