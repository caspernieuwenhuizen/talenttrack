# TalentTrack v3.98.1 — Security and privacy documentation (#0086 Workstream A)

Closes #0086 Workstream A — the documentation half of the May 2026 security-and-privacy retrospective. Five customer- and operator-facing artifacts now live in the repo: two operator guides under `docs/` (EN + NL twins) and three public-facing trust documents under `marketing/security/` (the public security page sourced for `talenttrack.app/security`, the public privacy policy sourced for `talenttrack.app/privacy`, and a draft Data Processing Agreement template pending legal review).

**No code change.** Pure docs PR. Engineering work for #0086 (TalentTrack-native MFA, session management UI, login-fail tracking, optional admin IP allowlist + the annual external audit) is Workstream B and C; ships in subsequent releases.

Renumbered v3.97.2 → v3.98.1 mid-rebase after parallel-agent ships of #0088 (PDE collision/reflow) and #0068 Phase 1 (Team Blueprint drag-drop) took the v3.97.2 / v3.98.0 slots.

## Locked decisions baked in

These six product decisions were locked in v3.95.1 (#0086 spec lock) and inform every artifact in this PR:

1. **MFA — build TalentTrack-native** (not a WP-plugin recommendation). SaaS-port-blocking decision. Documented as roadmap commitment on the public security page; until it ships, the operator security guide recommends a vetted WP plugin (`Two Factor` or `Wordfence Login Security`).
2. **GDPR splits out** — subject-access export ships in #0063 (Export module) use case 10; right-to-be-forgotten erasure becomes its own future spec gating on #0083 (Reporting framework's fact registry). The operator privacy guide forward-references both with current manual procedures until they ship.
3. **No column-level encryption** in v1. The public security page documents the host's at-rest encryption commitment instead.
4. **DPA — standard template** with no per-customer negotiation. Documented as such in the privacy policy and the DPA template's preamble.
5. **Audit transparency — middle level**. Annual external audit by Securify or Computest, summary findings published on `talenttrack.app/security` within one month of report receipt, full report under NDA on request. Both the public page and the DPA Annex 3 commit to this.
6. **Brand — TalentTrack-branded** trust pages on `talenttrack.app/security` and `talenttrack.app/privacy`. The legal entity in the DPA is MediaManiacs.

## What landed

### `docs/security-operator-guide.md` + Dutch twin

Day-one + annual-review checklist for the Academy Admin. Five day-one configuration steps. MFA recommendations (vetted WP plugin until TalentTrack-native MFA ships). Audit-log review patterns. Impersonation as the operator's lens. Suspected-breach response (lock account, take backup, check audit log, contact MediaManiacs, reset adjacent accounts; GDPR 72-hour breach-notification clock). Backups as a security layer. Annual checklist (7 items).

### `docs/privacy-operator-guide.md` + Dutch twin

GDPR-facing how-to. Controller / processor split. Personal data inventory per category. Three day-one privacy steps. Subject-access requests — 5-step manual procedure today; pointer to #0063 use case 10 for the future single-click flow. Erasure requests — 5-step manual procedure today; pointer to the future erasure-pipeline spec. Retention defaults table. The player-notes (#0085) GDPR subtlety. Privacy lifecycle of a player joining and leaving. Annual privacy checklist (6 items).

### `marketing/security/security-page.md`

Source for `talenttrack.app/security`. Where data lives, encryption commitments, who can access customer data, audit trail, roadmap commitments (#0086 Workstream B items 1-3 within 6 months), annual external audit cadence + summary-findings publication commitment, breach-notification flow, sub-processor list, DPA pointer + bug-bounty stance.

### `marketing/security/privacy-policy.md`

Source for `talenttrack.app/privacy`. Two-role structure (MediaManiacs as website controller; academies as install controllers with MediaManiacs as their processor under DPA). Lawful basis. Data subject rights with response routing. Retention. Sub-processors. International transfers. Cookies. Children. Change-management commitment.

### `marketing/security/dpa-template.md`

**Draft pending legal review.** Standard EU DPA structure, 14 Articles + 3 Annexes. Annotated as draft pending legal review throughout.

### Cross-references

- `docs/access-control.md` + Dutch twin gain a new section pointing at the two operator guides and the `marketing/security/` directory.
- `marketing/security/README.md` indexes the directory and documents update cadence.

## Affected files

- `docs/security-operator-guide.md` — new.
- `docs/nl_NL/security-operator-guide.md` — new (NL twin).
- `docs/privacy-operator-guide.md` — new.
- `docs/nl_NL/privacy-operator-guide.md` — new (NL twin).
- `marketing/security/security-page.md` — new.
- `marketing/security/privacy-policy.md` — new.
- `marketing/security/dpa-template.md` — new (draft pending legal review).
- `marketing/security/README.md` — new (directory index + update cadence).
- `docs/access-control.md` + `docs/nl_NL/access-control.md` — cross-reference paragraph added.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

The two operator guides ship in EN + NL. The three public-facing artifacts ship in EN only for now.

## What's NOT in this PR

- Engineering work for #0086 Workstream B (MFA, session UI, login-fail, IP allowlist) — separate PRs.
- Engineering work for #0086 Workstream C (external audit) — kicks off after Workstream B Children 1-3 ship.
- Translation of the three public-facing artifacts to Dutch — out of scope.
- Legal review of the DPA — out-of-band activity; the draft is annotated as such throughout.
- Actual publication of the public files to `talenttrack.app` — operator-side activity once the source files are in the repo.
