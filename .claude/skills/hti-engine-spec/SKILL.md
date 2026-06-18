---
name: hti-engine-spec
description: Use when implementing or modifying the recommendation engine itself — the deterministic scoring (P1–P5), archetype mapping, allocation ranges, the three safety traps, the Gemini call, response schema validation, and the pre-written fallback. This is the defensible core. Triggers on class-engine, class-gemini, class-fallback, or any logic that decides or explains a recommendation.
---

# hti-engine — The Engine Spec

This is the **most important** and most sensitive part. Read `docs/PRD… §11`, `docs/Modelo_Dados…`, and `docs/Prompt_LLM_Schema…` before touching it.

## The golden rule
**Rules decide. LLM explains.** The archetype and allocation come from deterministic curated rules. The LLM only writes explanatory text. If the LLM output tries to change numbers or name instruments → schema/semantic validation rejects it → fallback.

## Scoring (deterministic)
- Sum P1–P5 using weights from the `htinvest_scoring` option (editable in admin).
- Map sum to archetype via thresholds: 0–5→1, 6–11→2, 12–17→3, 18–23→4, 24–27→5.
- P6 (emergency fund), P7 (ESG), P8 (crypto) are NOT scored: P6 is a trap, P7/P8 are lenses.

## Allocation (deterministic)
- Pull ranges from `htinvest_archetypes` option for the resolved archetype.
- Produce concrete percentages within ranges; **must sum to 100**.
- By **asset class only**: global_equity, bonds, cash, reits_alt, crypto. Never instruments.
- Crypto only if P8=yes AND archetype ≥3 AND trap 1 not fired; always at the low end.

## Safety traps (override scoring)
1. `no_emergency_fund` (P6=no): educational message first; portfolio framed as "for later."
2. `horizon_override` (P1=≤3y but high score): cap to archetype 1–2, explain why.
3. `crypto_blocked` (crypto requested but conditions unmet): educate instead of including.

## Gemini call (class-gemini)
- Server-side only. JSON mode. Low temperature (~0.3). Timeout → fallback. 1 retry then fallback.
- Build prompt from: resolved archetype + fixed allocation + fired traps + user answers (to personalize FORM) + curated class notes. See Prompt doc §2–3.
- The LLM receives the decision as fact; it must not alter it.

## Validation (reject → fallback)
- JSON schema (Prompt doc §4): required fields, length bounds.
- Semantic checks: no named instruments (blocklist + ticker regex), no invented percentages, correct language, class_notes keys == allocation classes, safety_message present if trap fired.
- On any failure: use pre-written fallback text; the numeric result still ships.

## Audit
- Store `engine_version` and `disclaimer_version` on every result.
- Logs contain no PII.

## Test matrix (must pass — see Criterios §1)
One profile per archetype (5) + one per trap (3) + crypto granted + crypto blocked + ESG requested. ≥12 documented input→output scenarios, run as a repeatable suite.

## Checklist
- [ ] Allocation sums to 100, within ranges
- [ ] Class-only output, no instruments
- [ ] Traps override correctly
- [ ] LLM never changes numbers
- [ ] All validation paths fall back gracefully
- [ ] Key never client-side
