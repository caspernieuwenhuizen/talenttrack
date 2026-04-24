<!-- type: feat -->

# #0017 Sprint 2 — Execution view: aggregated case data

## Problem

A trial case has a date window (start → end). During that window, the player participates in sessions, gets evaluated, accumulates goals, and has attendance logged. All of that happens through the normal TalentTrack flows — sessions, evaluations, goals tables, all unchanged.

What's missing: a **single aggregated view** on the case page that pulls all that data together filtered to the trial date window. Without it, the HoD has to jump between Sessions, Evaluations, and Goals views and mentally filter to the trial period. Tedious and error-prone.

This sprint proves the "nothing duplicates" design — no new trial-specific evaluation table, no trial-specific session table. Just smart filtering of existing tables.

## Proposal

A new **Execution tab** on the case edit view that shows:

- Sessions the trialing player attended during the case window, with attendance status.
- Evaluations written during the case window (by anyone).
- Goals created or updated during the case window.
- A simple rolling-average of evaluation ratings during the window.

All data is read-only in this tab — the tab exists to synthesize, not to edit. Users edit sessions/evaluations/goals via the normal surfaces from earlier sprints.

## Scope

### Execution tab structure

Location: new tab on `FrontendTrialCaseView` (the edit surface from Sprint 1).

Four sections, stacked:

**1. Sessions section**
- List of sessions where the trial player was on the roster, with date in the trial window (`tt_sessions.date` between `case.start_date` and `case.end_date`).
- Columns: date, session type, team, attendance status for this player, notes.
- Read-only. Click a row → opens the session's edit view in a new tab (coach can then change attendance if needed; doesn't modify the case directly).
- Empty state: "No sessions yet during this trial period."

**2. Evaluations section**
- All evaluations of the trial player with `evaluation_date` in the trial window.
- Columns: date, evaluator, overall rating (circular badge like #0003), categories summary.
- Read-only view. Click → evaluation detail (frontend view).
- Empty state: "No evaluations yet. Assigned staff can provide input via the Staff Inputs tab (Sprint 3)."

**3. Goals section**
- Goals with created_at or status-transition timestamps in the trial window.
- Columns: title, status, priority, deadline, last updated.
- Shows goals created, transitioned, or completed during the window.
- Empty state: "No goals yet during this trial period."

**4. Synthesis section**
- Rolling average overall rating across all in-window evaluations.
- Trend indicator if there are 3+ evaluations (up/down/flat across the window).
- Attendance percentage: percent of in-window sessions where the player was present.
- "Compared to peers" note if comparative data exists: "Overall rating is in the top/middle/bottom third of players evaluated during similar windows." (Optional — skip if comparison data is noisy.)

### Data sources

All queries respect existing row-level security and capability gates:

- Sessions: existing session repository, filtered by team roster including this player and date-within-window.
- Evaluations: existing evaluation repository, filtered by `player_id = case.player_id` and `evaluation_date` in window.
- Goals: existing goal repository, filtered by `player_id` and `updated_at` or `created_at` in window.
- Synthesis: computed via `PlayerStatsService` with a date-range parameter (new overload — `PlayerStatsService::rollingForDateRange($player_id, $start, $end)`).

No new schema. No data duplication.

### Visibility

- Any user with `tt_view_trial_synthesis` can see this tab.
- Assigned staff see the full data.
- HoD sees everything.

### Performance

For a 4-week trial with ~8 sessions, ~4 evaluations, ~2 goals: queries are trivial (dozens of rows total). No pagination needed in v1. If clubs later run 3-month trials with heavier activity, add pagination then.

## Out of scope

- **Comparative benchmarking across trials** (e.g. "this player's rating vs. the average of all players who've been through Standard track this season"). Nice-to-have; not v1.
- **Exporting the execution view as a report.** The aggregated view is for HoD's in-app use. Exports go through #0014's report generator (which this epic's Sprint 4 hooks into).
- **Editing sessions/evaluations/goals from this tab.** Click-through to the normal surfaces to edit.
- **Filtering within the tab.** The case window is the filter. No sub-filtering yet.

## Acceptance criteria

### Functional

- [ ] Execution tab visible on the case edit view for users with `tt_view_trial_synthesis`.
- [ ] Sessions section lists sessions in the trial window with the player's attendance status.
- [ ] Evaluations section lists in-window evaluations with overall rating and summary.
- [ ] Goals section lists in-window goals with status.
- [ ] Synthesis section computes rolling average and attendance percentage correctly.
- [ ] Click-through from any row opens the corresponding edit view.

### Correctness

- [ ] An evaluation outside the trial window does NOT appear, even if it's on the same player.
- [ ] A session outside the window does NOT appear.
- [ ] Goals whose `created_at` OR `updated_at` OR `status_changed_at` falls in the window DO appear.
- [ ] Rolling average matches `PlayerStatsService`'s computation.
- [ ] Attendance percentage matches total-in-window-sessions-where-player-was-present / total-in-window-sessions.

### Permissions

- [ ] Users without `tt_view_trial_synthesis` do not see the tab.

### Empty states

- [ ] Trial just-started with no data shows appropriate empty messages in each section.

### No regression

- [ ] Normal Sessions, Evaluations, Goals views elsewhere are unaffected.
- [ ] `PlayerStatsService` callers elsewhere (profile view, rate card) work correctly.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Execution tab scaffolding + tab routing: ~1 hour
- Sessions section: ~1.5 hours
- Evaluations section: ~1.5 hours
- Goals section: ~1.5 hours
- Synthesis section + `PlayerStatsService` date-range overload: ~2 hours
- Mobile polish + empty states: ~1 hour
- Testing: ~1.5 hours

### Depends on

- Sprint 1 of this epic (case schema + edit view with tab framework)
- #0019 Sprint 1–2 (REST, components, list patterns)

### Blocks

None in this epic. But Sprint 4's decision panel pulls context from this view, so Sprint 2 should land before Sprint 4 is polished.

### Touches

- `src/Shared/Frontend/FrontendTrialCaseView.php` — add Execution tab
- `src/Modules/Players/PlayerStatsService.php` — add `rollingForDateRange()` method
- No new tables, no new endpoints (reuses existing session/eval/goal REST)
