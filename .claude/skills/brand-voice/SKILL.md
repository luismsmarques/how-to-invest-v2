---
name: brand-voice
description: Use when writing or reviewing any user-facing copy — headlines, microcopy, disclaimers, emails, result/archetype text, error/empty/loading states, CTAs, social captions — to keep the informal, calm, second-person voice and the conditional/illustrative framing. Triggers on copy/microcopy authoring, UI strings, disclaimers, or any tone review. Bilingual EN+PT; the source of truth is docs/Textos_Finais_HowToInvest_MVP.md.
---

# brand-voice — Copywriting & tone

The canonical copy lives in `docs/Textos_Finais_HowToInvest_MVP.md` (EN+PT): disclaimers (Bloco 1), curated asset-class notes (Bloco 2), the "why this archetype" text (Bloco 3), safety-trap messages (Bloco 4), questionnaire micro-explainers (Bloco 5). **Reuse and adapt those, don't reinvent them.** Note: the curated notes/archetype/trap texts do double duty — they feed the LLM (which personalises the wording) **and** are the pre-written fallbacks — so keep any you write self-sufficient and already-safe.

## The voice (in one line)
Warm, calm, plain-spoken, second person ("you" / PT informal "tu"). A knowledgeable friend who explains, never a salesperson who pushes. Reassuring, never hyped.

## Non-negotiables (the invariants, expressed as copy)
- **Educational, never advice.** Conditional & illustrative, never imperative. Write "a profile like this usually…", "an example for this profile tends to…", "you might explore…" — **never** "you should buy", "do this", "the best…".
- **By asset class only** — never name instruments, tickers, funds, brokers or companies; say "steadier classes", "global shares", not products.
- **A disclaimer is always present** on anything with a portfolio example — use the canonical variants (contextual / short / footer), never a bespoke one.
- **No execution/broker CTAs.** The only CTAs are the questionnaire and the newsletter/ebook.
- **No promises, urgency or fear.** No "guaranteed", "you'll make", "don't miss", "act now", "beat the market". Investing "carries risk, including loss of capital."
- **Define jargon on first use;** prefer everyday analogies.

## Signature moves (the phrase bank)
- Conditional framing: *"A profile like this usually…"*, *"An example here tends to…"*, *"…might explore / could study."*
- Calm hedges: *"organised by asset class, not specific products"*, *"illustrative example"*, *"it doesn't account for your full situation."*
- Close, human: contractions in EN; PT "tu" ("o teu perfil", "vês", "considera falar com…").
- Prefer: *steadier, growth, cushion, ballast, over the long run, comfort with ups and downs.*
- Avoid: *hot, opportunity, secret, smart money, returns you can expect, must, best, top picks.*

## Bilingual EN + PT
- Always write **both**. PT is **European Portuguese (pt-PT), informal "tu"** — same meaning, not a literal translation; match register and rhythm to the EN.
- UI strings go through the theme `strings()` table / `t()` (see the theme) or the engine text domain, always as an EN+PT pair.

## Where copy surfaces
- Result/archetype + trap messages → the engine (curated notes = LLM input + fallback; see `hti-engine-spec`).
- Landing/email/social copy → `marketing-growth` / `social-media`. Learn/glossary prose → `learn-guide` / `content-editorial`. SEO titles/meta → `seo-wordpress`.

## Checklist (before "done")
- [ ] Conditional/illustrative; **no imperative money advice**; no promises/urgency/fear
- [ ] Asset-class only; **no named instruments/brokers/companies**
- [ ] Disclaimer present where a portfolio example appears (canonical variant)
- [ ] Only questionnaire / newsletter-ebook CTAs
- [ ] Second person, warm, jargon defined on first use
- [ ] EN + PT written (pt-PT, "tu"); consistent with `docs/Textos_Finais…`
