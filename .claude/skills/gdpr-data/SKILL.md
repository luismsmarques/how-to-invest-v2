---
name: gdpr-data
description: Use when handling personal data, consent, or privacy — storing profiles, linking them to accounts, the export and delete (data subject rights) endpoints, consent banner logic, data minimization, and keeping PII out of logs. Triggers on account features, the profile data model, consent, or anything touching user personal/financial data. These are legal requirements (P0).
---

# GDPR & Data Handling

We store users' **financial profiles** — sensitive data. The rights below are legal requirements, P0, in the launch gate (Criterios §5/§9).

## Minimization (the core principle)
- No account created → store only an **anonymous** session profile (no identity). `user_id = null`.
- A profile becomes identified **only** by the user's conscious action (creating an account → `claim-profile`).
- Don't collect anything not needed for the current purpose.

## Consent
- Consent banner refuses non-essential by default (privacy-first).
- Record consent (`consent` field with timestamp) **before** any non-essential analytics runs.
- Essential cookies (session) may run; analytics/marketing only after opt-in.

## Data subject rights (must work before launch)
- **Access/portability:** `GET /export` returns ALL of the user's data.
- **Erasure:** `DELETE /account` removes account + profiles + results in cascade, no identifiable residue. Irreversible, with confirmation.
- These are not "nice to have" — without them, do not launch.

## Accounts
- Built on native `wp_users`. Don't invent a parallel auth system.
- Profile links to user via `user_id`. Deleting the user cascades to their profiles/results.

## Logging
- Engine and error logs contain **no PII** — no emails, no raw answers tied to identity. Log archetype/version/anonymized metrics only.

## Sensitivity note
- Financial profile + identity is sensitive. The whole architecture (anonymous-by-default, conscious opt-in) exists to keep this defensible. Don't weaken it for convenience.

## Checklist
- [ ] Anonymous profiles carry no identity
- [ ] Identity only via claim-profile (conscious opt-in)
- [ ] Export returns all user data
- [ ] Delete cascades fully
- [ ] Consent recorded before non-essential processing
- [ ] No PII in logs
