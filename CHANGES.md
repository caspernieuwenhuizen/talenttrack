# TalentTrack v3.110.206 — Mobile readiness audit follow-up: table-wrap retrofit, inputmode retrofit, wizard step nits (closes #423)

## Why

Follow-up to v3.110.120 (AttendanceStep card UI), originally opened in late 2026-05 and held until rebase appetite returned. The audit identified three classes of mobile-readiness gaps still present on the dashboard at 360px:

1. Bare `<table class="tt-table">` elements were crushed into unreadable cells on narrow viewports because they were missing the `.tt-table-wrap` scroll container.
2. Numeric `<input type="number">` elements were missing the matching `inputmode` attribute, so mobile keyboards popped up alphabetic-first instead of numeric/decimal.
3. Two wizard steps had unfriendly mobile defaults — multi-select forced 320px min-width and a checkbox row sat below the 48×48 tap floor.

CLAUDE.md §2 codifies the standard. This release closes the remaining offenders found by the audit.

## Wave 1 — table-wrap retrofit (17 tables across 12 surfaces)

Wrapped every offender:

- `FrontendAuditLogView`
- `FrontendComparisonView`
- `FrontendEvaluationsView`
- `FrontendFunctionalRolesView`
- `FrontendMigrationsView`
- `FrontendPeopleManageView`
- `FrontendTeamDetailView` (5 tables)
- `FrontendTeamsManageView` (2 tables)
- `FrontendTrialCaseView` (5 tables)
- `FrontendReportDetailView` (2 tables)

`.tt-table-wrap` was already defined in `public.css` (the AttendanceStep card retrofit shipped it). Each table now scrolls horizontally inside its own region instead of pushing the surrounding page layout.

**Lookups admin (`FrontendConfigurationView::renderLookupCategoryEditor`) intentionally dropped from the retrofit on rebase**: the master-detail layout that landed in v3.110.203 (#830) replaced the table with a `<ul class="tt-lookup-md-list">` rail — the table-wrap retrofit is no longer applicable to that view.

## Wave 2 — `inputmode` retrofit (6 numeric inputs)

| Surface | Field | inputmode |
|---|---|---|
| `FrontendPlayersManageView` | jersey | `numeric` |
| `FrontendPlayersManageView` | height_cm | `numeric` |
| `FrontendPlayersManageView` | weight_kg | `numeric` |
| `FrontendReportWizardView` | privacy.min_rating_threshold | `decimal` |
| `FrontendTrialCaseView` | overall_rating | `decimal` |
| `Invitations/Frontend/AcceptanceView` | jersey | `numeric` |
| `Development/Frontend/IdeasRefineView` | player_id / team_id | `numeric` |

Two of these conflicted with main on rebase. Resolution kept HEAD's dynamic bounds (read from `tt_config rating_min/rating_max` so they track the academy's configured scale) and added the PR's `inputmode="decimal"`. The hardcoded `min="1" max="5"` from the original PR is gone — it would have regressed v3.110.116's rating-scale work.

## Wave 3 — wizard step nits

- `Activity/PrinciplesStep`: multi-select gains `width:100%; min-width:0; max-width:100%; box-sizing:border-box; font-size:16px` so it fits 360px viewports and doesn't trigger iOS auto-zoom on focus.
- `Team/RosterStep`: checkbox-label `min-height: 48px` bumped from 32px so the row meets the §2 tap floor.

## What's unchanged

- No PHP logic. No CSS file changes (`.tt-table-wrap` was already defined). No JS. No schema, no migration, no REST. Pure structural retrofit.
- The wp-admin admin surfaces are out of scope (they're not part of the mobile-first standard).
- The CSV/JSON exporters render machine-readable tables and are intentionally not wrapped.

## How to test

- [ ] At 360px DevTools viewport, visit each Wave-1 view; data tables show their own horizontal scrollbar when wide, page layout stays put.
- [ ] Tap into each Wave-2 input on a real phone; numeric/decimal keyboard pops up (not full alphabetic).
- [ ] Open new-activity wizard's Principles step on 360px phone — `<select multiple>` fits inside viewport, no horizontal scroll, focusing doesn't trigger iOS auto-zoom.
- [ ] Open new-team wizard's Roster step on phone — checkbox rows feel comfortably tappable (48px min).
- [ ] Desktop ≥1024px smoke test: nothing should look different.

## Rebase notes

This PR sat open for ~5 days while v3.110.200 → v3.110.203 shipped. The rebase had eight conflicts; six were trivial (version bump, changelog header, side-by-side `inputmode` additions). Two were structural: the lookups admin's `<table>` is gone (master-detail rewrite) and the report-wizard input merged correctly with the dynamic-bounds work from v3.110.116.
