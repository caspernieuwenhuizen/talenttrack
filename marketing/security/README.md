# marketing/security/

Source files for TalentTrack's public-facing trust artifacts. Kept under `marketing/` (not `docs/`) because these are customer-facing web/legal documents, not operator-facing in-product docs. The operator-facing how-to lives at `docs/security-operator-guide.md` and `docs/privacy-operator-guide.md`.

## Files

| File | Destination | Status |
|---|---|---|
| `security-page.md` | `talenttrack.app/security` | Draft — copy to website on next site update; refresh annual-review date |
| `privacy-policy.md` | `talenttrack.app/privacy` | Draft — fill in [bracketed] fields (controller address, Last reviewed date), then publish |
| `dpa-template.md` | PDF download from `talenttrack.app/security/dpa.pdf` | **Draft pending legal review.** Do not execute against customers until reviewed. Convert to PDF after review, preserving the Annex tables and signature block |

## Update cadence

- **Annual review.** Update the *Last reviewed* date on `security-page.md` and `privacy-policy.md`. Walk through the bracketed placeholders to confirm none are stale. Re-publish.
- **Sub-processor changes.** When the sub-processor list in Annex 2 of the DPA or the table on `privacy-policy.md` changes, update both, email all customers, increment the DPA version number.
- **Audit cycle.** After each annual external audit, update the *Most recent audit summary* section on `security-page.md` within one month of report receipt.
- **Material commitment changes.** Anything that changes a customer commitment (encryption claims, breach-notification clauses, audit cadence) requires customer email + DPA addendum.

## Locked decisions (#0086)

These six product decisions were locked during the May 2026 retrospective and inform every artifact in this directory:

1. **MFA — build TalentTrack-native** (not a WP-plugin recommendation; SaaS-port-blocking decision).
2. **GDPR work splits out** — subject-access export ships in #0063 (Export module) use case 10; right-to-be-forgotten erasure becomes its own future spec gating on #0083 (Reporting framework's fact registry).
3. **No column-level encryption** in v1 — host's at-rest commitment documented instead.
4. **DPA — standard template** with no per-customer negotiation.
5. **Audit transparency — middle level** — annual audit, summary findings published on `talenttrack.app/security`, full report under NDA on request.
6. **Brand — TalentTrack-branded** trust pages on `talenttrack.app/security` and `talenttrack.app/privacy`. The legal entity in the DPA is MediaManiacs.

## Cross-references

- Spec: `specs/0086-epic-security-and-privacy.md`
- Operator docs: `docs/security-operator-guide.md`, `docs/privacy-operator-guide.md` (+ `nl_NL/` twins)
- Phone-home telemetry doc: `docs/phone-home.md` (referenced from `security-page.md`)
- Access-control reference: `docs/access-control.md`
- Backups reference: `docs/backups.md`
