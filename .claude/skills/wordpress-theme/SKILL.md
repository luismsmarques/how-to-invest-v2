---
name: wordpress-theme
description: Use when building or editing the WordPress block theme (Full Site Editing) — theme.json, block templates and template parts, child theme setup, global styles, fonts, colors, spacing tokens, and FSE patterns. Triggers on theme files, theme.json, templates/, parts/, or any front-end theme structure work.
---

# WordPress Block Theme (FSE)

We use a **child block theme** of a current official base theme (Twenty Twenty-X family). Customization lives in `theme.json` and block templates. **No page builders.**

## theme.json — single source of truth for design tokens
- Define colors, typography (font families, sizes), spacing scale, and layout (contentSize/wideSize) here.
- Mirror the design tokens agreed in the UX/UI work. Keep the palette small and intentional.
- Use fluid typography and spacing where it helps responsive behavior.
- Register custom font assets locally (performance + privacy — avoid loading Google Fonts from Google's CDN; self-host).

## Templates & parts
- Templates needed: `index`, `single` (post/article), `archive`, `page`, plus templates for the glossary term CPT and news CPT, and the homepage.
- Template parts: header, footer (footer must include the full disclaimer — see Textos Finais §1.3).
- Build with core blocks + block patterns. Register reusable patterns for: article CTA-to-questionnaire block, glossary term layout, news card.

## Child theme hygiene
- Keep customizations in the child theme so the base theme can update safely.
- `functions.php` of the child: enqueue child styles, register patterns, register nav menus, theme supports.

## Performance (SEO matters here)
- Minimal CSS; rely on theme.json-generated styles.
- Self-host fonts; preload critical font.
- No render-blocking junk. Lazy-load images (core does this).

## Interaction with the app
- The questionnaire/result are rendered by the `hti-engine` plugin (via shortcode/block), NOT the theme. The theme provides the page shell and styles; the plugin provides the interactive app.

## Checklist
- [ ] Tokens in theme.json, not hardcoded in CSS
- [ ] Footer disclaimer present in footer part
- [ ] Templates for all content types incl. glossary + news
- [ ] Fonts self-hosted
- [ ] Mobile layout verified
