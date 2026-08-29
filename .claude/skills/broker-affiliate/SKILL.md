---
name: broker-affiliate
description: Use when working on the broker editorial section — the broker comparison pages, per-broker reviews, "how to open an account" guides, the post-result "Putting it into practice" partner module, the /go/{slug} affiliate redirector, broker data (CPT broker + meta), or anything involving affiliate links, disclosures or CFD risk warnings. Triggers on class-brokers, class-broker-*, [hti_brokers], broker templates, or affiliate/monetization work. Encodes the CMVM/ESMA compliance rules that make this section legal.
---

# broker-affiliate — Broker section & affiliate compliance

The broker section is the **only** place on the site where brokers are named with
outbound links. It monetizes through affiliate deals while staying on the
editorial/informational side of CMVM rules. These rules are load-bearing — they
are what keeps the section legal. When in doubt, be more conservative.

## The regulatory line (why each rule exists)

- **CMVM (understanding of 2025-03-13, "finfluencers"):** advertising and client
  prospecting *on behalf of* brokers is reserved to financial intermediaries and
  tied agents. Generic disclaimers are not enough — the affiliate/commission
  relationship must be disclosed **on every page/channel** where it exists, and
  the broker (intermediary) is co-responsible. Consequence: this site publishes
  **comparative, factual, editorial content with on-page disclosure** — never
  "sign up now" prospecting, never personalized intermediary recommendations.
- **ESMA:** monetary incentives tied to retail CFD trading are banned in the EU;
  CFD marketing requires the risk warning. Consequence: every mention of a
  CFD-offering broker carries the "% of retail accounts lose money" warning.
- **ESMA/MAR:** investment recommendations must identify the author, separate
  fact from opinion, and disclose conflicts of interest. Consequence: public
  methodology page, verification dates, disclosed affiliation.

## Non-negotiable rules

1. **Containment.** Brokers are never named in: the engine/LLM output (the
   validator's `INSTRUMENT_BLOCKLIST` enforces this → fallback), the result's
   educational block, the PDF export, emails, Learn content, glossary, social
   posts. The partner module renders **after** `.hti-actions`, visually distinct.
2. **Label.** Every card/module with an active affiliate deal shows
   "Partner · Ad" / "Parceria · Publicidade".
3. **On-page disclosure.** Every page with broker links carries the canonical
   affiliate disclosure (`Disclaimer::affiliate( $locale )`, versioned via
   `Disclaimer::AFFILIATE_VERSION`), linking the "How we make money" page.
   Never write a bespoke disclosure.
4. **CFD risk warning.** Any surface that mentions a broker with
   `hti_broker_cfd = true` shows `Disclaimer::cfd_risk( $locale, $pct )` with
   that broker's real percentage (`hti_broker_cfd_risk_pct`).
5. **Links.** All outbound broker links go through `/go/{slug}?loc={location}`
   (302, `noindex`, robots-disallowed). `rel="sponsored nofollow noopener"` when
   `affiliate_active`; `rel="nofollow noopener"` for the official-site fallback.
   Raw affiliate URLs never appear in page HTML (cache-safe, deal-swap-safe).
6. **Language.** Factual/comparative, conditional where it evaluates ("who
   values X usually considers…"). Titles like "Best brokers in Portugal" are
   acceptable editorial framing; body copy never uses urgency or imperatives
   ("open an account now", "don't miss"). CTA button copy is factual: "Visit
   XTB", "Read the review".
7. **Deterministic matching only.** The post-result module is built server-side
   from curated meta (`profile_fit`, `asset_classes`) by `Broker_Match::pick()`.
   Never the LLM, never client-side logic, never persisted into the profile.
8. **Data honesty.** Every broker record carries a `verified` date. Numbers
   marked "não confirmado" in the reference study are not published until
   confirmed directly with the broker. Only brokers regulated by a top-tier EU
   authority (CMVM/BaFin/CySEC/KNF/FSA/AFM/Finantsinspektsioon…) are listed.
9. **No Review/AggregateRating schema.** `ItemList` on comparison pages,
   `Article` on reviews/guides. Editorial ratings stay visual-only.
10. **Tracking discipline.** Click counting is server-side in the `/go/`
    handler (`broker_click` + bounded slug/location breakdowns) — aggregated
    daily counters, zero PII, zero cookies, same policy as the metrics beacon.

## Where things live

- `includes/class-brokers.php` — records, strings (EN/PT), `go_link()`,
  `disclosure_html()`, `cfd_warning_html()`, `[hti_brokers]`, `[hti_broker_cta]`,
  `partner_module()`, ItemList schema.
- `includes/class-broker-go.php` — the `/go/{slug}` virtual route. Resolution
  order: a published `broker` post first, then the owner-managed links — a
  managed link can never shadow a broker (the admin refuses that slug), so the
  editorial section's disclosure/CFD guarantees stay unbypassable.
- `includes/class-go-links.php` — owner-managed `/go/` slugs (Tools → Outbound
  links) for links used **off** the site: Telegram, social bios, newsletters,
  campaigns. HTTPS-only destinations, parkable, bounded store, clicks counted
  per slug and per `loc` channel. These carry no on-site disclosure, so the
  disclosure duty lives in the post/bio that publishes the link — the admin
  screen says so. A broker that deserves editorial coverage still gets a
  review, not just a managed link.
- `includes/class-broker-match.php` — pure deterministic matching (tested in
  `tests/test-broker-match.php`).
- `includes/class-broker-admin.php` — the "Broker data" metabox (all meta).
- `includes/class-broker-seeder.php` — seeds AND syncs the broker CPT posts +
  section pages (pillar, categories, guides, "How we make money"), EN+PT via
  Polylang. Upsert by slug with a content hash-guard: repo changes update posts
  in place (status/slug preserved); `PROTECTED_META` (affiliate_url,
  affiliate_active, affiliate_network, cfd_risk_pct) is **never written on
  update** — those fields belong to the wp-admin metabox. Never delete + re-seed
  to update content.
- `includes/class-content-sync.php` — the sync central (Tools → Content sync,
  `wp hti sync-content`): auto-detects a deploy (file-manifest signature) and
  runs brokers + Learn + glossary in one background cron event. Auto mode never
  seeds the broker section on a site where it was never seeded (launch stays a
  manual owner decision).
- CPT `broker` + taxonomy `broker_use_case` — `class-cpt.php` / `class-taxonomy.php`.
- Theme: `templates/single-broker.html` + `render_broker_review()`.
- Canonical copy: `docs/Textos_Finais_HowToInvest_MVP.md` §Bloco 6.
- Writing the reviews/analyses themselves: `financial-analyst` (fact protocol);
  on-page optimization: `seo-content`.

## Checklist (before "done")

- [ ] Engine output, PDF, emails, Learn, glossary, social: zero broker names
- [ ] Label + on-page disclosure present on every broker surface
- [ ] CFD warning with the broker's real % wherever a CFD broker appears
- [ ] All outbound links via `/go/` with the correct `rel`
- [ ] Matching deterministic and server-side; module after `.hti-actions`
- [ ] `verified` dates current; no unconfirmed numbers published
- [ ] Content changes ship via deploy → Content_Sync (or Tools → Content sync);
      never delete + re-seed, and sync never touches the deal fields
- [ ] EN + PT parity; tests green (`tests/run.php`)
