# howtoinvest (child block theme)

Child block theme (FSE) for HowToInvest. Parent: **Twenty Twenty-Five** (swappable via the `Template:` header in `style.css`). Customization lives in `theme.json`; user-facing copy lives in translatable PHP patterns. No page builders. See the `wordpress-theme` and `ux-ui-design` skills.

## Structure

```
howtoinvest/
├── style.css                 # theme header (Template: twentytwentyfive)
├── theme.json                # design tokens — single source of truth
├── functions.php             # enqueue, i18n, pattern category (namespaced, hti_ intent)
├── parts/
│   ├── header.html           # site title + navigation
│   └── footer.html           # nav + full disclaimer (via pattern) 
├── templates/
│   ├── index.html            # blog fallback
│   ├── home.html             # front page (hero + latest posts)
│   ├── single.html           # article + CTA-to-questionnaire
│   ├── page.html             # default page
│   ├── page-no-sidebar.html  # full-width custom template
│   ├── archive.html          # generic archive
│   ├── single-glossary.html  # glossary CPT (term)        ← needs CPT from task 1.3
│   ├── archive-glossary.html # glossary index             ← needs CPT from task 1.3
│   ├── single-news.html      # news CPT (article)         ← needs CPT from task 1.3
│   └── archive-news.html     # news index                 ← needs CPT from task 1.3
├── patterns/
│   ├── footer-disclaimer.php # full disclaimer (Textos §1.3), EN+PT
│   ├── disclaimer-short.php  # one-line disclaimer (Textos §1.2)
│   ├── cta-questionnaire.php # insertable CTA → /investor-profile-quiz/ (never brokerage)
│   ├── glossary-term.php     # glossary term layout
│   └── news-card.php         # news card for query loops
└── languages/
    ├── howtoinvest.pot           # translation template
    └── howtoinvest-pt_PT.l10n.php # PT translations (loaded natively by WP 6.5+)
```

## Notes

- **Design tokens** (calm green primary, neutral grays, info/caution semantics, fluid type & spacing) are in `theme.json`. Don't hardcode colors/sizes in CSS.
- **Disclaimer** is rendered in the footer on every page, and is fully bilingual (EN default + PT) through the pattern + `languages/`.
- **404 / search** intentionally inherit the parent theme (already translatable and well-designed).
- **Fonts** use a system stack (no external requests — privacy + performance). Self-host a brand font later if desired (register in `theme.json` → `settings.typography.fontFamilies` with a local `src`).
- The **glossary / news templates** are ready but only take effect once the `glossary` and `news` CPTs are registered (Phase 1, task 1.3, in the `hti-engine` plugin).
- The interactive **questionnaire / result** are NOT in the theme — they come from the `hti-engine` plugin via shortcode/block. The CTA links to `/investor-profile-quiz/`.
