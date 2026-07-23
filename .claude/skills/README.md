# Skills — HowToInvest

Project skills for Claude Code. Each skill is a `<name>/SKILL.md` that packages the
rules, file map and checklist for one area, so the right context loads automatically
when a matching task comes up.

**How they activate:** Claude reads each skill's `description` (the *"Use when… Triggers
on…"* line) and applies the skill when your task matches — e.g. touching
`class-metrics.php` pulls in `analytics-measurement`. You can also invoke one by name.

**Read first, always:** `CLAUDE.md` (the project invariants). Every skill enforces them:
asset-class only (no named instruments/brokers), educational & conditional tone,
disclaimer present, bilingual EN+PT, no execution CTAs, LLM keys server-side.

---

## Web / front-end (4)
| Skill | Use when |
|---|---|
| **frontend-vanilla** | Building interactive front-end in lightweight vanilla JS (no React) — questionnaire, result, chart, REST fetches, progressive enhancement. |
| **wordpress-theme** | Editing the FSE block theme — `theme.json`, templates/parts, global styles, fonts, spacing tokens, patterns. |
| **ux-ui-design** | Visual/interaction decisions — design tokens, hierarchy, component states, mobile-first, micro-copy placement. |
| **accessibility** | Any user-facing UI for a11y — keyboard nav, focus, ARIA, labels, contrast, screen-reader (WCAG 2.1 AA). |

## Backend / engine (6)
| Skill | Use when |
|---|---|
| **wordpress-backend** | Server-side WP — CPTs/taxonomies, hooks, REST routes, options/settings, cron, i18n, capabilities. |
| **php-standards** | Writing/reviewing any PHP — PHP 8.4 + WPCS, sanitize/escape/prepare, WP_Error, clean classes. |
| **hti-engine-spec** | The recommendation engine — deterministic scoring, archetypes, safety traps, the Gemini call, schema validation, fallback. |
| **rss-ai-pipeline** | Engineering the `hti-rss-ai` plugin — fetching/backoff, grouping + embeddings, generators, validator, retention. |
| **i18n-polylang** | The bilingual layer in code — Polylang linking, language resolution, PT slugs, hreflang, localized URLs. |
| **gdpr-data** | Personal data, consent or privacy — profiles, export/delete, consent banner, no PII in logs (P0). |

## Content / brand (4)
| Skill | Use when |
|---|---|
| **learn-guide** | Writing/revising Learn chapters — the "zero to first portfolio" path, house style, EN+PT, quiz format. |
| **content-editorial** | Content beyond Learn — glossary authoring, AI-news review, the Markdown import pipeline, topic clusters. |
| **brand-voice** | Any user-facing copy — microcopy, disclaimers, emails, result/CTA text — informal, calm, conditional tone. |
| **seo-wordpress** | Search visibility — schema.org, sitemaps, meta, canonicals, 301s, internal linking, Core Web Vitals. |

## Marketing / growth (2)
| Skill | Use when |
|---|---|
| **marketing-growth** | Growth & email — newsletter/Brevo, campaigns, the ebook lead magnet, funnel CTAs, the organic-growth playbook. |
| **social-media** | Social assets — the `hti-social` cards/reels/templates, brand primitives, `{{token}}` grammar, AI captions. |

## Ops / quality (3)
| Skill | Use when |
|---|---|
| **analytics-measurement** | Measuring/tracking — the first-party funnel, HTITrack, consent-gated GA, KPIs + event allowlist, Search Console. |
| **deployment-ops** | Deploy & environments — cPanel deploy, staging-first, CI, `VERSION` cache-busting, composer, env keys. |
| **testing-qa** | Writing tests & validating — the pure-PHP harness, adding `test-*.php`, the QA gates + RGPD checklist. |

---

## Adding a skill
Keep the repo convention: one `SKILL.md` under `.claude/skills/<name>/`, frontmatter with
only `name` (= folder) + `description` (*"Use when [context] — [specifics]. Triggers on
[file/feature signals]."*), a body of terse `##` rule sections (**bold** the
non-negotiables), and a closing `## Checklist` of `- [ ]` items. Cite real files and
cross-reference sibling skills by name. No `references/` subfolders or scripts — inline it.
Aim ~40–60 lines. Cross-check `CLAUDE.md` invariants and the relevant `docs/` spec.
