# TalentTrack v3.101.0 — Team planner: week-view calendar with plan-state on activities (#0006)

Closes #0006 (Team planning module) in one bundled ship at user direction. The spec was a five-sprint epic estimating ~48-60h driver time with a custom JS calendar component as Sprint 2's centerpiece. This PR ships a pragmatic single-PR landing that delivers the spec's user-facing acceptance criteria without the heavy JS investment.

## What landed and why this shape

The spec was authored before the codebase's vocabulary sweep (sessions → activities, migration 0027) and before the methodology framework shipped its `tt_principles` table (migration 0015). The planning spec calls for a parallel `tt_principles` and a `tt_session_principles` link — both of which already exist in the codebase under different shapes. Reusing them sidesteps a parallel-domain split that would split clubs' coaching-philosophy data across two tables.

Three architectural calls for the trim-down:

1. **Reuse existing `tt_principles` (methodology framework) as the principle lookup.** The methodology principles ARE the coaching-philosophy items the spec calls for — already coded, multilingual, and tied to formations / lines of play. Any planning-driven coach who tags an activity with a principle is reaching for the same concept the methodology layer already models.
2. **Reuse the existing `?tt_view=activities&action=new` form.** The spec calls for a bespoke activity-creation modal with principle multi-select. The activities form already supports principle linkage (per #0077 M2's frontend↔admin parity); the planner just deep-links to it with `?session_date=&plan_state=scheduled` pre-filled.
3. **Server-rendered week view, no JS calendar.** The spec budgets ~18-22h for a custom calendar component (Sprint 2). The week view's value-add is "what's happening this week, navigate by week" — that's expressible as a 7-column server-rendered grid with prev/next links.

## Migration 0073 — `tt_activities` plan-state columns

Three new columns on `tt_activities` (renamed from `tt_sessions` in migration 0027):

```sql
ALTER TABLE tt_activities ADD COLUMN plan_state VARCHAR(16) NOT NULL DEFAULT 'completed' AFTER notes;
ALTER TABLE tt_activities ADD KEY idx_plan_state (plan_state);
ALTER TABLE tt_activities ADD COLUMN planned_at DATETIME DEFAULT NULL AFTER plan_state;
ALTER TABLE tt_activities ADD COLUMN planned_by BIGINT UNSIGNED DEFAULT NULL AFTER planned_at;
```

The default `'completed'` on existing rows is the deliberate back-compat hinge: every historical row keeps its log-only meaning, and the existing logging flow (which doesn't pass `plan_state`) keeps writing `'completed'` rows by default. New rows from the planner pass `plan_state='scheduled'` and the controller stamps `planned_at` + `planned_by` for them.

Allowed states: `draft` / `scheduled` / `in_progress` / `completed` / `cancelled`. The lifecycle:
- `scheduled` — created by the planner.
- `in_progress` — reserved (the spec calls for a nightly cron transition; that's deliberately deferred — manual on-attendance-save transition is sufficient for v1).
- `completed` — auto-set when attendance is logged on a `scheduled` row.
- `cancelled` — manually-set via the existing activity status flow; the planner shows them with a strike-through but the canvas excludes them by default.

Idempotent via `SHOW COLUMNS` guards.

## Capabilities

Two new caps in `LegacyCapMapper`, both bridging to the existing `activities` matrix entity:

```php
'tt_view_plan'   => [ 'activities', 'read'   ],
'tt_manage_plan' => [ 'activities', 'change' ],
```

A coach who can read team activities can use the planner; a coach who can edit activities can schedule new ones. No new matrix top-up migration needed.

## REST changes

`ActivitiesRestController::list_sessions` gains `filter[plan_state]` (single value or comma-separated list, validated against the allowlist).

`ActivitiesRestController::extract()` gains `plan_state` handling — when set to `scheduled`, also stamps `planned_at = NOW()` + `planned_by = $user_id`. When absent, the column default (`'completed'`) wins; the legacy logging flow is unchanged.

`ActivitiesRestController::update_session()` gains an auto-transition: when attendance is written on an activity whose current `plan_state` is `scheduled` or `in_progress`, the state flips to `completed`. The planner depends on this so completed activities show up correctly in the "what happened this week" view.

## Frontend — `?tt_view=team-planner`

New `FrontendTeamPlannerView` at `src/Modules/Planning/Frontend/`. Server-rendered, ~330 lines. Three sections:

### Toolbar

- Team picker (auto-submits on change). Excludes `team_kind = 'trial_group'` rows (the trial-group pseudo-teams from #0081 are not the planner's audience).
- Prev / Today / Next week navigation. URL-state-driven (`?week_start=YYYY-MM-DD`).
- "+ Schedule activity" button (visible only with `tt_manage_plan`) → existing activities create form pre-filled.

### Week grid

7 columns (Mon-Sun). Each column renders the day's activities as cards with title + plan-state pill + location + top-3 principle chips. Empty days have a "+ Add" placeholder linking to the activities create form with `?session_date=YYYY-MM-DD&plan_state=scheduled` pre-filled. Mobile (<720px): stacks to single column. ≥720px: 7-column grid. ≥48px tap targets throughout.

### Principle-coverage panel

Top 10 principles trained over the last 8 weeks with activity-count chips. Pulls from existing `tt_activity_principles` join — no new pivot.

## Activities form — URL-driven pre-fill

`FrontendActivitiesManageView::renderForm()` honours two URL params on create (edit ignores them):

- `?session_date=YYYY-MM-DD` pre-fills the date input.
- `?plan_state=scheduled` (or `draft`) injects a hidden form field that flows through the AJAX form to the REST POST.

## Module + tile registration

- New `PlanningModule` (thin shell so the module-disabled toggle can hide the planner).
- Tile registered in the Performance group at `order=25` (right after Activities at 20). `entity` reuses `activities_panel`. `cap='tt_view_plan'` gates visibility.
- Dispatch case in `DashboardShortcode::render()` routes `team-planner` to the new view.
- Slug ownership registered: `team-planner` → `M_PLANNING`.

## What's NOT in this PR (deferred from the spec)

- **Drag-drop reschedule** (Sprint 2). Coach edits the date via the activities form's date input.
- **Month view** (Sprint 2). The week view is bookmarkable via `?week_start=`; clicking back/forward is functionally equivalent.
- **Inline activity creation modal** (Sprint 3). The existing activities form is the canonical create surface.
- **Bespoke principle editor admin** (Sprint 5). The existing methodology principle library at `?tt_view=methodology` covers this.
- **Nightly cron for `scheduled → in_progress` transitions** (Sprint 4). Manual on-attendance-save transition is sufficient for v1.
- **Heat map + top-5 bar chart** (Sprint 5). Principle-coverage list panel serves the same question.
- **Cancellation flow with reason capture** (Sprint 4). The existing activity-status `cancelled` value is honoured; reason capture uses the existing notes field.

The trim-down is deliberate: each deferred item is a 2-6h polish-pass that can ship once the planner is in real use. Shipping the canvas + plan-state + principle-coverage gets coaches into the workflow today.

16 new translatable strings covering the Account-page tab UI and the roadmap bullet list.

8 new NL msgids covering the planner's labels. State labels (`Draft` / `Scheduled` / `In progress` / `Completed` / `Cancelled`) reuse existing entries.

## Affected files

- `database/migrations/0073_activities_plan_state.php` — new.
- `src/Infrastructure/REST/ActivitiesRestController.php` — `filter[plan_state]` + `plan_state` field in `extract()` + auto-transition on attendance save.
- `src/Modules/Authorization/LegacyCapMapper.php` — two new cap mappings.
- `src/Modules/Planning/PlanningModule.php` — new.
- `src/Modules/Planning/Frontend/FrontendTeamPlannerView.php` — new.
- `src/Shared/Frontend/FrontendActivitiesManageView.php` — URL-driven date / plan_state pre-fill on create.
- `src/Shared/CoreSurfaceRegistration.php` — module-class constant + slug ownership + tile registration.
- `src/Shared/Frontend/DashboardShortcode.php` — dispatch case.
- `assets/css/components/team-planner.css` — new (~190 lines, mobile-first, plan-state colours).
- `config/modules.php` — registers `PlanningModule`.
- `languages/talenttrack-nl_NL.po` — 8 new NL strings.
- `readme.txt`, `talenttrack.php`, `SEQUENCE.md` — version bump + ship metadata.
