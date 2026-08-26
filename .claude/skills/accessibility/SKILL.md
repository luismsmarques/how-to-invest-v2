---
name: accessibility
description: Use when building or reviewing any user-facing interface for accessibility — keyboard navigation, focus management, ARIA, form labels, color contrast, screen-reader support, and text alternatives for visual content like the allocation chart. Target WCAG 2.1 AA. Triggers on questionnaire, result, account UI, or theme template work.
---

# Accessibility (WCAG 2.1 AA)

Target AA across the user-facing app. Accessibility is in the launch gate (Criterios §7).

## Keyboard
- Full questionnaire completable with keyboard only.
- Logical tab order; no keyboard traps.
- Visible focus indicator on every interactive element (don't remove outlines without replacement).

## Forms (the questionnaire)
- Radio groups in `<fieldset>` with `<legend>` = the question.
- Every input has an associated `<label>`.
- Error messages programmatically associated (`aria-describedby`), announced.
- The "why we ask" micro-explanation linked to the question, not just visually adjacent.

## ARIA (only when needed)
- Prefer native semantics; add ARIA only to fill gaps.
- Progress bar: `role="progressbar"` with `aria-valuenow/min/max`.
- Live region for the processing→result transition so screen readers learn the result loaded.

## Color & contrast
- All text meets AA contrast.
- Never use color alone to convey meaning (the chart needs labels/patterns + the text-equivalent list).

## The chart
- The allocation chart must have a **text alternative**: the same percentages as an accessible list/table. This is also good for SEO and for the PDF.

## House patterns (implemented — follow these precedents)
- **Skip link:** emitted on `wp_body_open` (`render_skip_link()` in the theme), NOT inside the header group — the header is `position: sticky` (breaks absolute-offset hiding) and theme.json `blockGap` adds margin to a group's second child. Hidden via the clip pattern; on focus `position: fixed` at the viewport corner (`.hti-skip` in `style.css`). Target `#main` is injected by a `render_block` filter (`add_main_landmark_id`) with `tabindex="-1"`.
- **Toggle buttons/tabs:** expose state with `aria-pressed` (news-hub tabs in `news-hub.js`; glossary filters in `glossary.js`).
- **Custom radiogroups (quiz):** WAI-ARIA pattern — roving tabindex (one tab stop: the chosen option or the first), Arrow/Home/End move focus, Space/Enter selects (`learn.js`); initial tabindex also set server-side.

## Screen reader pass
- Manually walk questionnaire → result with a screen reader before launch.

## Checklist
- [ ] Keyboard-only completion works
- [ ] Visible focus everywhere
- [ ] Fieldset/legend/labels correct
- [ ] Contrast AA
- [ ] Chart has text equivalent
- [ ] Screen-reader walkthrough done
