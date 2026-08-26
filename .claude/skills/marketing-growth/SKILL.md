---
name: marketing-growth
description: Use when working on growth, email marketing or conversion — the newsletter (Brevo), weekly/digest campaigns, the ebook lead-magnet gate, double opt-in, funnel CTAs, positioning, and the SEO/content growth strategy (the road to steady organic traffic). Triggers on class-subscribe/class-campaigns/class-brevo, the ebook flow, newsletter/CRM, or growth/marketing planning. SEO/content is the project's primary business goal.
---

# marketing-growth — Newsletter, lead-gen & growth

The growth engine is **SEO/content → funnel → email**. This site converts through education, never a hard sell. Read `docs/Estrategia_Conteudo_SEO_LLM.md` (the content/SEO strategy) and `docs/PRD_HowToInvest_WordPress_MVP.md §7` (success metrics) first.

## Non-negotiable invariants
- **Only two conversion CTAs exist:** the **questionnaire** and the **newsletter/ebook**. **Never** an execution/broker CTA.
- **Educational, conditional tone; disclaimer always present; asset-class only** (no named instruments) — applies to every email, landing page and campaign.
- **Bilingual EN + PT** for all subscriber-facing copy; voice mirrors `docs/Textos_Finais_HowToInvest_MVP.md`.
- **No subscriber PII stored on-site** — Brevo is the source of truth; keys server-side (`HTI_BREVO_API_KEY` in `wp-config.php`).

## Where things live
- `includes/class-subscribe.php` — `[hti_subscribe]` shortcode + **double opt-in** (stateless HMAC `optin`/`unsub` tokens), posts to `POST htinvest/v1/subscribe`. Holds the **ebook gate**: sources starting `ebook*` set `hti_ebook_pending` so the PDF is delivered only after opt-in confirmation.
- `includes/class-brevo.php` — Brevo Contacts/Lists client; per-language list ids (`brevo_list_id_en`/`brevo_list_id_pt`).
- `includes/class-mailer.php` — Brevo transactional send + `is_brevo_configured()`.
- `includes/class-emails.php` — shared branded HTML email shell (navy header, accent CTA, disclaimer footer), bilingual.
- `includes/class-campaigns.php` — weekly newsletter (`hti_weekly_newsletter`, Mon 09:00) + daily digest (`hti_daily_digest`, 07:00) from the `news` CPT + manual broadcast; admin under **Settings → HTI Newsletter**.
- `includes/class-nps.php` — NPS 0–10 survey + reporting (option-backed, no Brevo).
- Ebook lead magnet (in the **theme**): `functions.php` `render_ebook_landing()` (`/ebook/`), `assets/js/ebook.js` (depends on `hti-track`), deliverables in `assets/ebook/` (cover + EN/PT PDFs).

## The growth playbook (organic-first)
- **Long-tail PT first** (lower competition), then EN parity — publish where you can win, then scale.
- **E-E-A-T for finance (YMYL):** a real, named author with credentials + `sameAs`; substantive About page.
- **Off-page/authority** is the biggest lever: relevant PT-finance links, the quiz as a shareable asset, light digital PR from aggregate quiz data.
- **Loop:** Search Console weekly — fix high-impression/low-click titles, lift page-5–15 pages, fill cluster gaps. Measure with `analytics-measurement`.
- Use the AI **news** sparingly and human-reviewed (see `content-editorial`) — mass thin AI content risks Helpful-Content penalties.

## Checklist (before "done")
- [ ] Only questionnaire / newsletter-ebook CTAs; no execution CTA
- [ ] Bilingual EN + PT; disclaimer present; asset-class only
- [ ] Subscriber data via Brevo only (no on-site PII); keys server-side
- [ ] Double opt-in respected; ebook delivered only after confirmation
- [ ] Emails use `class-emails.php` shell; campaign scheduled/queued correctly
- [ ] Growth work ties back to a measurable KPI (see `analytics-measurement`)
