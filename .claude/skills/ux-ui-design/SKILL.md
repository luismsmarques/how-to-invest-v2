---
name: ux-ui-design
description: Use when making visual or interaction design decisions — design tokens (color, type, spacing), visual hierarchy, component states (default/hover/focus/error/empty/loading), mobile-first layout, micro-copy placement, and turning wireframes into a polished, trustworthy interface. Triggers on design work, theme.json styling intent, or UI of the questionnaire/result.
---

# UX/UI Design

The product handles people's money concerns — the design must feel **calm, clear, and trustworthy**, never hypey or salesy. Educational, not a trading app.

## Design tokens (feed into theme.json)
- Small, intentional palette. A calm primary (the brand green from the original works), neutral grays, clear semantic colors for the safety messages.
- Type scale with strong hierarchy; generous line-height for readability of educational text.
- Consistent spacing scale; let content breathe.

## Hierarchy & trust
- The disclaimer is visible but not alarming — present, legible, not a scary wall.
- The archetype name and the chart are the focal point of the result; explanations support them.
- Safety-trap screens (no emergency fund, etc.) lead with the educational message, calmly — not as an error.

## States (design all of them)
- Questionnaire: default, selected, error (unanswered), progress.
- Result: normal, each safety trap, loading (the processing screen).
- Account: logged-out, logged-in, empty (no saved profiles yet).
- Always design empty and loading states, not just the happy path.

## Mobile-first
- The questionnaire must be comfortable on a phone (large tap targets, one question per screen).
- Chart and allocation list stack cleanly on narrow screens.

## Micro-copy
- The per-question micro-explanations (Textos Finais §5) are part of the design — give them a distinct, supportive visual treatment (the "ℹ why we ask" block).
- Tone: second person, warm, plain language. Never imperative about investing.

## Trust signals (without overpromising)
- Clear "educational tool" framing up front.
- No fake urgency, no countdowns, no "act now."

## Checklist
- [ ] Tokens defined and consistent
- [ ] All states designed (incl. empty/loading/error)
- [ ] Mobile layout verified
- [ ] Disclaimer legible but calm
- [ ] Tone matches educational positioning
