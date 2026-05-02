---
id: 0077
type: epic
status: in-progress
title: Polish wave 1 — foundations + 11-module consistency pass
---

# 0077 — Polish wave 1

A bundled polish + parity pass across 11 modules, riding on 3 cross-cutting
foundations. Single PR, single squash-merge, to avoid sequenced merge conflict
chains across modules that touch overlapping shared components.

## Why one PR

Several items below depend on the same foundation files (`FrontendViewBase`,
`FrontendWizardView`, `WidgetCatalogue`, `RecordLink`, `LookupPill`). Sequenced
PRs would cascade rebases and force repeated `.po`/`.mo`/`SEQUENCE.md` resets.
A single long-lived `polish-wave-1` branch, rebased onto main daily, keeps the
diff coherent.

## Scope — three foundations + eleven module changes

### F1 — Reusable HelpDrawer component
Promote the `tt-docs-drawer` pattern from `DashboardShortcode` into
`Shared/Frontend/Components/HelpDrawer.php` + `assets/js/components/help-drawer.js`.
Single API: `HelpDrawer::button( string $topic_slug, string $label = '' )`.
`FrontendWizardView::renderHelpSidebar` becomes a thin wrapper.

### F2 — Breadcrumbs + parent contract
Add `parentView(): ?string` to `FrontendViewBase`. New `FrontendBreadcrumbs`
component renders at top of `renderShell` based on the chain (detail + edit
views only — list views stay clean). Removes per-view back-button boilerplate
where the breadcrumb chain already conveys "back".

### F4 — Module completeness self-report
Each module reports `{ list, detail, edit, widget, kpi, docs, help }` presence
via `Module::completeness()`. Surfaced on a `WP_DEBUG`-only dev tile.

(F3 — backend≡frontend parity standard — is implemented through M2/M3/M6 below
rather than as a foundation file.)

### M-items
| # | Module | Change |
|---|---|---|
| M1 | Persona dashboard | Catalogue dropdown for all widget data sources (was free-text for non-KPI) |
| M2 | Activities frontend | Add Principles practiced multiselect; backport Guests to admin |
| M3 | Goals frontend | Add `linked_principle_id` + `linked_action_id` UI + per-principle widget/KPI |
| M4 | Trial cases | 6-tab → linear page with anchor nav; trial players on team page; relocate trajecten + letter templates to a Trials config tab |
| M5 | Teams | Edit cap audit on `tt_head_dev` + `tt_team_manager` |
| M6 | Comparison frontend | Chart.js radar + trend lines parity with admin; aligned 4-up grid |
| M7 | Functional roles | `assignment_count` audit (must include `tt_team_people` + `tt_club_people`) |
| M8 | Player profile | Header + tabs (Profile, Goals, Evaluations, Activities, PDP, Trials); linkify evaluations list |
| M9 | PDP cycle | Per-conversation status enum (`scheduled`/`held`/`signed_off`); "1/4 done" indicator; 2-week pre-meeting reminder; parent/player ack viz; drawer help |
| M10 | Player-created goals | Wizard entry; lands `pending_approval`; head_coach approves to `active` |
| M11 | Reports | PDF generation via Dompdf; frontend report views (drop wp-admin jumps); tile harmonisation |

## Decisions (locked)

1. Breadcrumbs scope — detail + edit views only.
2. Parity direction — frontend canonical; admin mirrors. Guests backported to admin in M2.
3. Trials UX — linear page with anchor nav; tabs dropped.
4. PDP per-conversation status — enum: `scheduled` / `held` / `signed_off`.
5. Player-goal approval — head_coach only (matches PDP signoff pattern).
6. Reports — PDF first; new report types deferred to a future spec.
7. Single PR / single merge.

## Translations + docs

- Every user-facing string lands in `languages/talenttrack-nl_NL.po`.
- Each affected module gets `docs/<slug>.md` + `docs/nl_NL/<slug>.md` updates.
- `.mo` regen runs through CI on merge.

## Versioning

Single version bump on the squash-merge. Target: `v3.79.0` (next minor after
v3.78.0). SEQUENCE.md updated to mark #0077 shipped.

## Out of scope

- New report types (separate spec).
- Generic email/notification rework (separate spec).
- Module-completeness UI on production (only WP_DEBUG in this wave).
