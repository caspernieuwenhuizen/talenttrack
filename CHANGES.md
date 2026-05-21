# TalentTrack v3.110.206 — Mobile readiness audit follow-up: table-wrap retrofit, inputmode retrofit, wizard step nits (closes #423)

## Why

Follow-up to v3.110.120 (AttendanceStep card UI). Three classes of mobile-readiness gaps at 360px:

1. Bare `<table class="tt-table">` elements crushed columns into unreadable cells (missing the `.tt-table-wrap` scroll container).
2. Numeric `<input type="number">` elements missing the matching `inputmode` attribute (mobile keyboards came up alphabetic-first).
3. Two wizard steps below the 48×48 tap floor / forcing horizontal overflow.

CLAUDE.md §2 codifies the standard. This release closes the remaining offenders found by the audit.

## Wave 1 — table-wrap retrofit (17 tables across 11 surfaces)

`FrontendAuditLogView`, `FrontendComparisonView`, `FrontendEvaluationsView`, `FrontendFunctionalRolesView`, `FrontendMigrationsView`, `FrontendPeopleManageView`, `FrontendTeamDetailView` (5 tables), `FrontendTeamsManageView` (2), `FrontendTrialCaseView` (5), `FrontendReportDetailView` (2).

`.tt-table-wrap` was already defined in `public.css`. Each table now scrolls horizontally inside its own region instead of pushing the surrounding page layout.

**Lookups admin (`FrontendConfigurationView::renderLookupCategoryEditor`) intentionally dropped on rebase**: the master-detail layout from v3.110.203 (#830) replaced the table with a `<ul>` rail — the table-wrap retrofit no longer applies to that view.

## Wave 2 — `inputmode` retrofit (6 numeric inputs)

| Surface | Field | inputmode |
|---|---|---|
| `FrontendPlayersManageView` | jersey | `numeric` |
| `FrontendPlayersManageView` | height_cm | `numeric` |
| `FrontendPlayersManageView` | weight_kg | `numeric` |
| `FrontendReportWizardView` | privacy.min_rating_threshold | `decimal` |
| `FrontendTrialCaseView` | overall_rating | `decimal` |
| `Invitations/AcceptanceView` | jersey | `numeric` |
| `Development/IdeasRefineView` | player_id / team_id | `numeric` |

Two of these conflicted with main on rebase — resolution kept HEAD's dynamic bounds (`tt_config rating_min/rating_max`, shipped v3.110.116) and added the PR's `inputmode="decimal"`.

## Wave 3 — wizard step nits

- `Activity/PrinciplesStep`: multi-select gains `width:100%; min-width:0; max-width:100%; box-sizing:border-box; font-size:16px` so it fits 360px viewports without iOS auto-zoom on focus.
- `Team/RosterStep`: checkbox-label `min-height: 48px` bumped from 32px to meet the §2 tap floor.

## What's unchanged

No PHP logic. No CSS file changes. No JS. No schema, no migration, no REST. Pure structural retrofit.

## How to test

- [ ] At 360px DevTools viewport, visit each Wave-1 view; data tables show their own horizontal scrollbar when wide, page layout stays put.
- [ ] Tap each Wave-2 input on a real phone; numeric/decimal keyboard pops up (not full alphabetic).
- [ ] Open new-activity wizard's Principles step at 360px — `<select multiple>` fits, focus doesn't trigger iOS auto-zoom.
- [ ] Open new-team wizard's Roster step on phone — checkbox rows feel comfortably tappable (48px min).
- [ ] Desktop ≥1024px smoke test: nothing visibly different.
