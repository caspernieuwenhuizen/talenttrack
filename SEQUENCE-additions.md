# SEQUENCE.md additions

These are the rows to insert into `SEQUENCE.md` for the four new specs from the May 2026 pilot retrospective. Each is shown with its target section and exact row format matching the existing convention.

---

## Insert into `## Ready (shaped, decisions locked)` table

Insert these three rows into the Ready table, after the existing `0081 | Onboarding pipeline` row. The numbering keeps with the existing convention (numerical order); insert before the final `|` close-of-table.

```markdown
| 0083 | Reporting framework — entity-driven KPIs with consistent dimension explorer. Six-child epic, three layers (data + KPIs + presentation) plus three integration surfaces (entity-page tab + central analytics + export+schedule). (1) `feat-fact-registry` — a `FactRegistry` cataloguing eight fact tables (evaluations, attendance, activities, goals, trial_decisions, prospects, journey_events, evaluations_per_session) with declarative dimensions + measures; new `Modules\Analytics\` module; reads existing tables, no schema changes. (2) `feat-kpi-platform` — augments existing `KpiDataSourceRegistry` (26 KPIs in `src/Modules/PersonaDashboard/Kpis/`) with fact-driven `Kpi` value objects; back-compat adapter so existing widgets keep working unchanged; ships 55 new KPIs across 5 entity scopes (player 15, team 15, activity 10, season 10, scout 5). (3) `feat-dimension-explorer` — new `?tt_view=explore&kpi={key}` view with filter chips, time-series chart, group-by selector, drilldown table; reuses Chart.js wiring from #0077 M6. (4) `feat-entity-analytics-tab` — Analytics tab on player / team / activity detail pages. (5) `feat-central-analytics-surface` — `?tt_view=analytics` for HoD + Academy Admin via new matrix entity `analytics`. (6) `feat-reporting-export-and-schedule` — CSV/XLSX/PDF export, scheduled email reports via new `tt_scheduled_reports` table; LicenseGate'd to Standard+. The fact-registry-as-spine + predefined-KPIs-with-explorer is the architectural choice over bespoke aggregations or open-ended report builder. ~4,700 LOC at conventional rates → ~1,800-2,000 LOC actual at the codebase's documented ~1/2.5 ratio across six PRs. | epic | [specs/0083-epic-reporting-framework.md](specs/0083-epic-reporting-framework.md) | ~4,700 LOC at conventional rates / ~1,800-2,000 LOC realistic |
| 0084 | Mobile experience — surface classification + native pattern vocabulary + deferred-wizard rollout. Three-child epic, deliberately small because most mobile groundwork already shipped. Builds on top of #0019 sprint 7 (PWA shell — manifest, service worker, offline form drafts, install prompt) and #0056 (mobile-first cleanup — 16px legacy form font, 48px tap-target floor, `inputmode`, `:focus-visible`, `touch-action`, safe-area-insets, `CLAUDE.md` mobile-first rule). What's new: (1) `feat-mobile-surface-classification` — extend `CoreSurfaceRegistration` with `mobile_class` field (`native` / `viewable` / `desktop_only`); new `Shared\MobileDetector` service; desktop-prompt page on `desktop_only` routes from mobile with "email me link" + "go to dashboard"; per-club override toggle. (2) `feat-mobile-pattern-library` — four CSS components missing on top of #0056's foundation (`tt-mobile-bottom-sheet`, `tt-mobile-cta-bar`, `tt-mobile-segmented-control`, `tt-mobile-list-item`); lint rule catches `<table>` in `native`-class templates. (3) `feat-mobile-classification-rollout` — classification declarations on every existing route; new-evaluation wizard's `RateActorsStep` mobile UX (deferred from #0072 v3.78.0 — "mobile-vs-desktop responsive layout split deferred") becomes the reference implementation by adopting the pattern library. ~5 routes are `native`, ~8 `viewable`, ~12 `desktop_only`. ~1,500 LOC at conventional rates → ~600 LOC actual at ~1/2.5 ratio across three PRs. | epic | [specs/0084-epic-mobile-experience.md](specs/0084-epic-mobile-experience.md) | ~1,500 LOC at conventional rates / ~600 LOC realistic |
| 0085 | Player notes and conversations — staff-only running log on player profiles for everyday observations between coaches, scouts, HoD, team managers (small-academy leadership-community use case from May 2026 pilot). Single-feat, single-PR. Architecturally lucky: existing `Modules\Threads` module already ships `tt_thread_messages` + `tt_thread_reads` + `ThreadTypeRegistry` infrastructure (currently only one type registered: `goal`). New `PlayerThreadAdapter` registered as `player` thread type. Two new caps `tt_view_player_notes` / `tt_edit_player_notes` bridged via `LegacyCapMapper`. New matrix entity `player_notes` seeded staff-only (asst/head coach + team manager `r/c[team]`, HoD/admin `rcd[global]`, scout `rc[global]`; player + parent: no grant). Top-up migration backfills existing installs (precedent: 0063_authorization_seed_topup_0079). New "Notes" tab on player profile (slot exists from #0082) with reverse-chrono list, in-place edit-own, plain-text body, @-mention autocomplete that fires `PlayerNoteMentionTemplate` workflow tasks. REST inherited from existing `ThreadsRestController` — no new routes. Visibility: `staff_only` default, `internal` (HoD-only) for sensitive notes; `public` deliberately not used. Notes auto-archived on player soft-delete; included in future GDPR erasure manifest. ~1,150 LOC at conventional rates → ~450 LOC actual at ~1/2.5 ratio in one PR. | feat | [specs/0085-feat-player-notes.md](specs/0085-feat-player-notes.md) | ~1,150 LOC at conventional rates / ~450 LOC realistic |
```

---

## Insert into `## Needs refinement / shaping` section

Replace the current `_None._` placeholder with a proper table. The section currently reads:

> ## Needs refinement / shaping
>
> _None._ Every open idea has been shaped into a spec; the `ideas/` folder holds only its README.

Replace with:

```markdown
## Needs refinement / shaping

| # | Topic | Type | Spec | Decisions needed |
| - | - | - | - | - |
| 0086 | Security and privacy workstream — three workstreams (documentation + development gaps + external audit) for the May 2026 pilot's security/privacy concern. Workstream A (~1-2 weeks): public security page, privacy policy, DPA template, in-product operator guides for security and privacy. Workstream B (~4-6 weeks for items 1-3): 2FA enforcement OR recommendation for HoD/admin personas, session management UI at `?tt_view=my-sessions`, login-fail tracking to `tt_audit_log`, optional IP-whitelisting; GDPR subject-access export + erasure pipeline (probably routed via #0063 Export + future #0073-equivalent). Workstream C (~3 months elapsed): annual external audit by Securify or Computest, ~€5-15k, after Workstream B items 1-3 ship. Codebase audit confirms most foundations are solid (cap-and-matrix auth, audit logging, impersonation with audit trail, tenancy, encrypted credentials at rest, phone-home diagnostics) — the gaps are 2FA, session UI, login-fail, and the GDPR-mandatories. | epic | [specs/0086-epic-security-and-privacy.md](specs/0086-epic-security-and-privacy.md) | (1) 2FA: build or recommend a WordPress plugin? (2) Where do GDPR export + erasure live — here, in #0063, or new spec? (3) Column-level encryption on sensitive LONGTEXTs — yes/no? (4) DPA: standard template or per-customer? (5) Audit transparency: minimum / middle / maximum? (6) Brand: security pages on talenttrack.app or mediamaniacs.nl? |
```

---

## What this changes about the broader sequencing

Three items move into Ready (#0083, #0084, #0085); one becomes the first entry in the previously-empty Needs Refinement section (#0086). This is the first time since the post-v3.x sweep that the Needs Refinement section has held a real spec — every backlog item had been shaped into Ready before. #0086 is shaped enough to know it's six product decisions short, but not enough to be locked.

Total backlog effort estimate (in `## Total backlog effort estimate` section near the bottom of SEQUENCE.md) does not need updating in the same edit — that section walks the existing items by ID and the new specs slot in naturally on the next refresh.

---

## Order of insertion into the file

If you're applying these manually:

1. Open `SEQUENCE.md`.
2. In the `## Ready (shaped, decisions locked)` table, insert the three new rows (#0083, #0084, #0085) in numerical order after the existing #0081 row but before the closing of the section (the blank line before `## Parked`).
3. Replace the `_None._ Every open idea has been shaped into a spec; the ideas/ folder holds only its README.` line with the new table containing the #0086 row.
4. Save.

That's the whole edit. No other section needs to change.
