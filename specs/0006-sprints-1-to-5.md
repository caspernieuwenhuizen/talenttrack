<!-- type: feat -->

# #0006 Sprint 1 — Principles schema + session plan-state

## Problem

Foundation sprint. Before a calendar UI can render anything meaningful, the underlying concepts must exist: the new **Principles** domain, plan-state on sessions, and capabilities to gate the surfaces in later sprints.

## Proposal

One migration adding `tt_principles` (new table, seeded with standard youth-football principles) and plan-state columns on `tt_sessions`. Three capabilities for planning surfaces. No user-visible features yet.

## Scope

### Schema

**`tt_principles`** — new domain:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
slug VARCHAR(64) NOT NULL UNIQUE,
name VARCHAR(128) NOT NULL,
description TEXT,
category VARCHAR(64),                 -- 'attacking', 'defending', 'transition', 'set-piece', 'individual'
is_seeded BOOLEAN DEFAULT FALSE,
archived_at DATETIME DEFAULT NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
```

**Extensions to `tt_sessions`**:
```sql
ALTER TABLE tt_sessions
  ADD COLUMN plan_state VARCHAR(16) DEFAULT 'completed',  -- 'draft', 'scheduled', 'in_progress', 'completed', 'cancelled'
  ADD COLUMN planned_at DATETIME DEFAULT NULL,            -- when was this scheduled in the planner
  ADD COLUMN planned_by BIGINT UNSIGNED DEFAULT NULL;
```

Default for existing rows: `plan_state = 'completed'` (they're already logged sessions, not planned activities). Backfill via migration. Future sessions created through the planner start at `draft` or `scheduled`.

**`tt_session_principles`** — many-to-many link between sessions and principles:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
session_id BIGINT UNSIGNED NOT NULL,
principle_id BIGINT UNSIGNED NOT NULL,
emphasis VARCHAR(16) DEFAULT 'primary',   -- 'primary', 'secondary'
UNIQUE KEY uk_session_principle (session_id, principle_id)
```

### Seed data

~20 standard youth-football principles shipped with the plugin, across 5 categories. Examples:
- Attacking: Build-up from the back; Creating overloads; Third-man runs; Width in final third.
- Defending: Compact shape out of possession; Pressing triggers; Delay and recover; Aerial duels.
- Transition: Counter-press 5 seconds rule; Quick transition to attack; Counter-attacking timing.
- Set-piece: Attacking corners; Defending corners; Free-kick rotations.
- Individual: First touch direction; 1v1 defending technique; Scanning before receiving.

Each with a short description. Clubs can edit, archive, or add their own in Sprint 5.

### Capabilities

- `tt_view_plan` — see the team planner. Granted to `tt_coach`, `tt_head_dev`, `administrator`.
- `tt_manage_plan` — create/edit planned activities. Granted to `tt_coach` (for their teams), `tt_head_dev`, `administrator`.
- `tt_manage_principles` — edit the principle library. Granted to `tt_head_dev`, `administrator`.

### REST stubs

Endpoint placeholders for later sprints:
- `GET/POST/PUT/DELETE /talenttrack/v1/principles`
- `GET /talenttrack/v1/teams/{id}/plan?from=&to=`
- `POST /talenttrack/v1/teams/{id}/plan/activities` (new planned activity — implemented in Sprint 3)

Stubs enforce capabilities; return placeholders.

## Out of scope

- Calendar UI (Sprint 2).
- Activity creation flow (Sprint 3).
- Session-plan unification (Sprint 4).
- Coach summary + reports (Sprint 5).
- Principle editor admin (Sprint 5).

## Acceptance criteria

- [ ] Migration creates `tt_principles`, `tt_session_principles`; alters `tt_sessions`.
- [ ] ~20 seeded principles exist after activation, across 5 categories.
- [ ] Existing `tt_sessions` rows get `plan_state = 'completed'` on upgrade.
- [ ] New session creation (via existing flows from #0019) defaults to `plan_state = 'completed'` — no behavior change.
- [ ] Capabilities registered and granted.
- [ ] REST stubs exist.

## Notes

### Sizing

~6–8 hours. Breakdown:
- Migration (2 new tables + 1 alter + backfill): ~2 hours
- Seed data authoring (20 principles with descriptions): ~2 hours
- Capability registration: ~0.5 hour
- REST stubs: ~0.5 hour
- Testing: ~1 hour

### Depends on

Nothing. First sprint.

### Blocks

All other sprints in this epic.

### Touches

- New migration: `NNNN_add_principles_and_planning.php`
- `includes/Activator.php` — caps + seed
- `src/Modules/Planning/PlanningModule.php` (new)
- `includes/REST/Planning_Controller.php` (new stubs)

---

# #0006 Sprint 2 — Custom calendar UI

## Problem

The planner needs a calendar component. Per shaping decision: **build custom, no external library.** This sprint is almost entirely the calendar component itself — it's the biggest sprint in the epic.

## Proposal

A new `FrontendCalendarComponent` (under `src/Shared/Frontend/Components/`) reusable anywhere. Week view (primary), month view (overview), drag-drop to move activities. Pure HTML/CSS/JS, no external dependencies.

## Scope

### FrontendCalendarComponent

Location: `src/Shared/Frontend/Components/FrontendCalendarComponent.php`.

### Week view (primary)

- Seven columns, one per weekday.
- Each column: vertical strip showing the day's activities as colored blocks positioned by time.
- Header row: weekday name + date.
- Time axis on left (e.g. 8am–10pm, half-hour ticks).
- Activity block rendering: color-coded by principle category (attacking = red, defending = blue, transition = purple, etc.).
- Hover/tap: tooltip with activity title, time, location, assigned principles.
- Click: open activity detail modal (implemented Sprint 3).
- Drag-drop: drag an activity block to a new day/time slot. Saves via REST on drop.
- Create by click-drag on empty space: draws a new-activity placeholder in the time range, opens creation modal (Sprint 3).

### Month view (overview)

- Grid of weeks, each week a row.
- Each day cell shows activity count (`3 activities`) with small principle-category colored dots.
- Click a day to switch to week view starting on that day.

### Navigation

- Previous/next arrows.
- "Today" button.
- Date picker (custom, not a library — native `<input type="date">` with styling).
- View toggle: Week / Month.

### Responsive

- Desktop (≥960px): week view shows all 7 days.
- Tablet (640–960px): week view shows 3 days with scroll for more.
- Mobile (<640px): week view shows 1 day; swipe to navigate. Month view shrinks to a compact grid.

### State handling

- Uses URL querystring for current view + date (so bookmarkable).
- Loads activities for the visible range via REST.
- Optimistic UI for drag-drop (move visually, save in background, revert on failure).

### No external libraries

No FullCalendar, no Toast UI, no Flatpickr. Use native HTML controls where possible; build the rest with plain JS + CSS. Leverage #0019 Sprint 1's CSS scaffold for styling consistency.

## Out of scope

- Day view (single-day detailed zoom). Use week view at 1-day width on mobile; no separate day view in v1.
- Recurring activities. Each activity is its own instance; no "repeat weekly" in v1.
- Timezone handling beyond the site's configured timezone.
- Attendance entry inline on the calendar — Sprint 4 handles session transitions.

## Acceptance criteria

- [ ] Week view renders activities correctly positioned by date + time.
- [ ] Month view shows activity counts per day.
- [ ] Navigation (prev/next, today, date picker, view toggle) works.
- [ ] Drag-drop rescheduling works with optimistic UI + failure revert.
- [ ] Click an activity opens a detail modal (stub in this sprint; detail filled in Sprint 3).
- [ ] Responsive: desktop, tablet, mobile all usable.
- [ ] No external JS libraries pulled in.
- [ ] Bundle size increase to the plugin under 20KB (plain JS + CSS only).

## Notes

### Sizing

~18–22 hours. Breakdown:
- Calendar HTML structure + CSS layout (week + month): ~6 hours
- Time-axis positioning logic: ~2 hours
- Activity block rendering + principle colors: ~2 hours
- Navigation controls: ~2 hours
- Drag-drop + optimistic UI: ~4 hours
- Responsive (3 breakpoints): ~3 hours
- URL state + REST integration: ~2 hours
- Testing: ~2 hours

### Depends on

Sprint 1 of this epic. #0019 Sprint 1 (CSS scaffold, REST conventions).

### Blocks

Sprints 3, 4, 5.

### Touches

- `src/Shared/Frontend/Components/FrontendCalendarComponent.php` (new)
- `assets/js/components/calendar.js` (new — ~1000 lines of JS)
- `assets/css/components/calendar.css` (new)

---

# #0006 Sprint 3 — Activity creation with principle linkage

## Problem

Sprint 2 delivered the calendar as a display + drag-drop component. Sprint 3 adds the creation flow: a coach clicks-drags on an empty slot, fills in a form, links principles, saves → activity appears on the calendar.

## Proposal

Activity creation modal with principle multi-select, time/location/notes fields. Saves as a new row in `tt_sessions` with `plan_state = 'scheduled'`.

## Scope

### Activity creation modal

Triggered by:
- Click-drag on empty calendar space (time range pre-filled).
- "+ New activity" button.

Form fields:
- Title (short name).
- Team (pre-filled if coach has one active team; dropdown if multiple).
- Date + start time + end time (inputs; pre-filled from click-drag).
- Location (free text).
- Principles: multi-select chips. Category grouping in dropdown ("Attacking > Build-up from the back").
- Primary principle: radio selection among chosen principles (the main focus).
- Notes (free text, coach's own).
- Expected attendance: defaults to full team roster; coach can uncheck individual players if they know some will be absent.

### Persistence

- New row in `tt_sessions` with `plan_state = 'scheduled'`, `planned_at = NOW()`, `planned_by = current_user`.
- Rows in `tt_session_principles` linking the session to its principles, one with `emphasis = 'primary'`.

### Activity detail modal (view/edit)

Click an existing activity on the calendar → detail modal:
- Shows all the above fields, editable.
- "Save changes" / "Cancel" / "Delete activity" / "Log attendance now" (the last one jumps to session-edit flow in the existing Sessions module).

### Principle picker UX

The multi-select is the most important UX detail:
- Principles grouped by category in the dropdown.
- Search field filters across all principles.
- Selected principles render as removable chips under the input.
- Radio toggle among chips to mark the primary one.

## Out of scope

- Custom principle creation from the activity form (handled in Sprint 5's principle editor).
- Bulk activity creation (clone last week, import from template). Future.
- Recurring activities (weekly training pattern). Future.

## Acceptance criteria

- [ ] Coach can create a new activity from click-drag.
- [ ] Coach can create a new activity from the + button.
- [ ] Form validation catches missing required fields.
- [ ] Creation writes to `tt_sessions` with `plan_state = 'scheduled'`.
- [ ] Principle multi-select works smoothly (search, select, primary toggle).
- [ ] Editing an existing activity updates the row.
- [ ] Deleting an activity removes it from calendar and session tables.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Creation modal form: ~2 hours
- Principle picker with search + category groups + primary toggle: ~3 hours
- Persistence wiring: ~1.5 hours
- Edit/delete flows: ~1.5 hours
- Testing + polish: ~1 hour

### Depends on

Sprints 1–2 of this epic.

### Blocks

Sprint 4.

### Touches

- `src/Shared/Frontend/Components/ActivityCreationModal.php` (new)
- `src/Modules/Planning/ActivityService.php` (new — writes to sessions + principles)
- REST endpoint implementation for `POST /teams/{id}/plan/activities`

---

# #0006 Sprint 4 — Session unification + plan-state transitions

## Problem

Sprints 1–3 created "planned activities" as sessions with `plan_state = 'scheduled'`. The existing Sessions module (from #0019 Sprint 2) handles sessions with `plan_state = 'completed'`. They share the same table but have no connection in the UI.

This sprint wires them together: a scheduled activity, when its date arrives, shows in the Sessions module as an upcoming session; logging attendance transitions it to `completed`; the coach sees a consistent narrative from "planned" to "logged."

## Proposal

Three integration points:
1. Sessions module list view shows scheduled + completed (filter by plan-state).
2. "Log attendance" from calendar activity triggers the existing session-edit flow (with the scheduled session loaded).
3. On attendance save, plan-state transitions from `scheduled` to `completed` automatically.

## Scope

### Sessions module list enhancement

In `FrontendSessionsManageView` (from #0019 Sprint 2), add:
- Plan-state filter: All / Scheduled / Completed / Cancelled.
- Default filter: "Scheduled + Completed" (exclude drafts and cancellations from default view).
- Column: Plan state (with visual cue — icon or colored pill).

### Log-attendance flow from calendar

Clicking "Log attendance now" on a calendar activity:
- Opens the Session edit view (from #0019 Sprint 2) with the specific session pre-loaded.
- UI highlights the attendance section (auto-scroll + visual pulse).
- On save of attendance, plan-state transitions `scheduled` → `completed`.

### Plan-state transitions (automatic)

Logic:
- `scheduled` → `completed` when attendance is logged (any player marked present/absent/late).
- `scheduled` → `cancelled` when coach explicitly cancels (new "Cancel activity" action, adds a cancellation reason).
- `scheduled` → `in_progress` when the activity's start time arrives (nightly cron or on-demand check).
- `in_progress` → `completed` when attendance is logged.

### Cancellation flow

- "Cancel activity" button on activity detail modal.
- Confirmation dialog: "Cancel this activity? Reason (optional)."
- On confirm: plan_state → `cancelled`, cancellation reason saved to `notes` field (or a new `cancellation_reason` column — add via migration if cleaner).
- Cancelled activities show in calendar with a visual strikethrough, and in session lists with the "Cancelled" filter.

### Upcoming-sessions in player profile

The #0014 Part A "Upcoming" section on player profile shows:
- Sessions where the player's team is assigned AND `plan_state IN ('scheduled', 'in_progress')` AND date >= today.
- Already filters correctly if #0014 Part A was built against the post-Sprint-1 schema.
- This sprint verifies the wiring.

## Out of scope

- Attendance pre-filling based on roster changes between scheduling and execution. Attendance always starts from the current roster at logging time.
- Notifications to players about scheduled/cancelled sessions. Future idea.
- "What did we cover?" post-session retrospective prompts. Future.

## Acceptance criteria

- [ ] Session list view has plan-state filter with correct defaults.
- [ ] Calendar activity "Log attendance" opens the correct session edit view.
- [ ] Saving attendance transitions plan-state from scheduled to completed.
- [ ] Cancellation flow works, sets state to cancelled, captures reason.
- [ ] Nightly cron transitions activities to `in_progress` when their start time passes.
- [ ] Player profile's "Upcoming" section shows scheduled sessions correctly.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Session list plan-state filter + UX: ~2 hours
- Log-attendance deep-link from calendar: ~1.5 hours
- Plan-state transition logic: ~2 hours
- Cancellation flow: ~2 hours
- Nightly cron + testing: ~1 hour
- Player profile upcoming verification: ~0.5 hour

### Depends on

Sprints 1–3 of this epic. #0019 Sprint 2 (Sessions module frontend).

### Blocks

Sprint 5.

### Touches

- `src/Shared/Frontend/FrontendSessionsManageView.php` — filter addition
- `src/Modules/Planning/PlanStateService.php` (new — transitions logic)
- Cron registration for in_progress transitions
- Migration (optional): `cancellation_reason` column on `tt_sessions`

---

# #0006 Sprint 5 — Coach summary + principle reporting + principle editor

## Problem

The planner is functional after Sprint 4. What's missing is the *retrospective* view: which principles has the team actually covered? Are some principles neglected? The coach needs a summary view that aggregates activity patterns.

Plus, clubs need to edit the principle library itself — rename seeded ones, add club-specific ones, archive ones they don't use.

## Proposal

Two deliverables:
1. **Coach summary view** — weekly summary of activities done + coming up, with principle coverage heat map.
2. **Principle editor admin surface** — CRUD for `tt_principles`, gated by `tt_manage_principles`.

## Scope

### Coach summary view

Location: new frontend tile or "Summary" tab on the team planner.

Contents:

**This week**:
- Activities done so far (with plan_state completed).
- Principles covered this week (with count of activities touching each).
- Attendance summary: team average attendance %.

**Next 7 days**:
- Activities scheduled.
- Principles to be covered.
- Quick "replan" link to the calendar.

**Principle coverage heat map (last 8 weeks)**:
- Grid: rows = principles, columns = weeks.
- Cell color: green if covered ≥1 time, deeper green for more, white if never covered.
- Surfaces neglected principles ("haven't touched Counter-press triggers in 6 weeks").

**Top principles by frequency**:
- Bar chart: top 5 most-covered principles over last 8 weeks.
- Flags the club philosophy concentration.

### Principle editor

Location: frontend Administration tile group, gated by `tt_manage_principles`.

Surfaces:
- List of all principles (seeded + custom) with filter by category + archived.
- Row actions: Edit, Archive, Duplicate.
- Edit form: name, description, category.
- Create form: same fields, new principle starts with `is_seeded = false`.
- Reset-to-default: for seeded principles that were edited, reverts to shipped default.

Seeded principles cannot be deleted — only archived.

### REST

- `GET /talenttrack/v1/teams/{id}/plan/summary?weeks=8` — returns the summary data.
- `GET/POST/PUT/DELETE /talenttrack/v1/principles` — full CRUD.

## Out of scope

- Cross-team principle coverage (academy-wide view). v1 is per-team.
- Automatic principle-recommendations ("haven't covered this, consider scheduling it"). Surfacing it is enough; let the human decide.
- Importing/exporting principle libraries between clubs. Future.

## Acceptance criteria

- [ ] Coach summary view renders this-week, next-7-days, heat map, and top-5 bar chart.
- [ ] Heat map correctly shades cells based on principle-activity counts.
- [ ] Clicking a principle in the heat map filters the calendar to activities involving that principle.
- [ ] Principle editor surfaces work (list, edit, archive, create, duplicate, reset seeded).
- [ ] Seeded principles cannot be deleted (only archived).
- [ ] Permissions gate correctly.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Coach summary view: ~4 hours
- Heat map implementation (pure CSS grid): ~2 hours
- Top-5 bar chart: ~1 hour
- Principle editor: ~2 hours
- Testing: ~1 hour

### Depends on

Sprints 1–4 of this epic.

### Blocks

Nothing. End of epic #0006.

### Touches

- `src/Shared/Frontend/FrontendTeamPlannerSummaryView.php` (new)
- `src/Shared/Frontend/FrontendPrinciplesEditorView.php` (new)
- `src/Modules/Planning/SummaryService.php` (new)
- REST endpoints implemented
- Administration tile group — add principle editor tile
