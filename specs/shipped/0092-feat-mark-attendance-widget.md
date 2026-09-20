# 0092 — Mark Attendance widget: one-tap pitch-side attendance, optional rating fork

## Problem

The single most frequent head-coach action — recording attendance for a
session — costs ~6–8 distinct user actions today. The audit lives in
[docs/head-coach-actions.md § action #1](../docs/head-coach-actions.md).
Two specific blockers:

1. The `today_up_next_hero` widget's "Attendance" CTA looks like it
   deep-links to tonight's roster but actually drops the coach on the
   activities list ([TodayUpNextHeroWidget.php:42-60](../src/Modules/PersonaDashboard/Widgets/TodayUpNextHeroWidget.php#L42-L60)).
   The coach then has to scan, tap, scroll, flip the activity's status
   to `completed`, then find the roster.
2. Two surfaces touch attendance — the activity edit form
   ([FrontendActivitiesManageView.php:519-577](../src/Shared/Frontend/FrontendActivitiesManageView.php#L519-L577))
   and the evaluation wizard's `AttendanceStep`
   ([AttendanceStep.php](../src/Modules/Wizards/Evaluation/AttendanceStep.php)).
   Both write the same `tt_attendance` table so there's no data
   duplication, but a coach has two muscle-memory patterns for one job
   and the dashboard doesn't point clearly at either.

The mental model the coach actually has on the pitch is:
"who showed up · how did they do · save." Attendance is the primary
action, rating is the *optional* follow-up that happens in the same
motion when there's time. The current UI inverts that — rating is a
mandatory wizard, attendance is a setup step or a hidden roster gated
by a status flip.

## Proposal

Introduce a single canonical pitch-side motion called
**Mark Attendance**, surfaced as a dashboard hero widget and backed by
a thin wizard that chains existing pieces.

**Coach's mental loop:**

1. Land on dashboard → tap **Mark Attendance** on the hero.
2. Confirm / pick the activity (auto-selected when there's an upcoming
   activity today or tomorrow on a team the coach owns).
3. **Step 1 — Attendance roster.** Mark every player Present / Absent /
   Late (etc.). "Mark all present" shortcut + per-row controls. Same
   roster shape that ships in `AttendanceStep` today.
4. **Step 2 — Rate the present players?** A simple Yes / Skip fork.
   - **Skip** → wizard exits. Attendance is already persisted.
   - **Yes** → continue to step 3.
5. **Step 3 — Rate roster (only if step 2 = Yes).** Roster-style
   (one screen, one row per present/late player, the existing
   `RateActorsStep` quick-rate + expandable deep-rate UX). On submit,
   attendance + ratings are both persisted.

The Activity edit form survives unchanged as the **post-hoc edit
surface** — fixing a misclassified status after a parent texts "Sam
was just late." The dashboard hero is the at-the-pitch motion; the
form is the corrections desk.

### Roster-style rating

The rating step is roster-style, not player-by-player. One screen
shows all present + late players with the quick-rate categories
inline. Coach can quick-rate everyone in a single scroll, expand any
row's `<details>` to deep-rate with sub-categories + notes when they
have something to say. This is the existing `RateActorsStep` render
([RateActorsStep.php:32-80](../src/Modules/Wizards/Evaluation/RateActorsStep.php#L32-L80))
— the spec doesn't add a new rating UX, it just reaches it via an
attendance-led wizard.

## Wizard plan

This work IS a wizard. New slug `mark-attendance` registered in
`WizardRegistry`. Steps:

| Slug              | Existing? | Notes |
| ---               | ---       | ---   |
| `activity-picker` | yes — reuses `ActivityPickerStep` | Skipped when entry URL provides `activity_id`. Defaults to coach's next upcoming activity. |
| `attendance`      | yes — reuses `AttendanceStep` | Already idempotent (notApplicableFor() skips if `tt_attendance` rows exist for the activity). |
| `rate-confirm`    | **new** — `RateConfirmStep` | Yes / Skip fork. On Skip, sets `_skip_rating = 1` in state and submits the wizard. |
| `rate-actors`     | yes — reuses `RateActorsStep` | Runs only when `_skip_rating !== 1`. |

The existing `evaluation` wizard's activity-first path is unchanged —
operators who want a rating-mandatory motion still reach it via
"+ New evaluation". `AttendanceStep::nextStep()` needs a small change
to read its next-step from state (so the same step can chain to
`rate-confirm` in this wizard and `rate-actors` in the existing one).
Cleanest: a wizard registers a per-step routing hint in initial state
(e.g. `_attendance_next => 'rate-confirm'`); the step reads it,
defaults to `'rate-actors'` for back-compat.

## Scope

In:

- New widget `MarkAttendanceWidget` (XL hero, persona = coach):
  - Renders the soonest upcoming activity for the coach's teams (same
    query `TodayUpNextHeroWidget::nextActivity()` uses — extract it to
    a shared repository helper to avoid duplication).
  - Primary CTA: **Mark attendance** → `?tt_view=wizard&slug=mark-attendance&activity_id=<id>`.
  - Empty state: when no upcoming activity exists, primary CTA becomes
    **Pick a session** → opens the wizard at `activity-picker`.
  - Secondary link: **Edit activity** → `?tt_view=activities&id=<id>` for
    post-hoc roster fixes (keeps the form discoverable but secondary).
- New step `RateConfirmStep` in `src/Modules/Wizards/Evaluation/` (or
  a new `MarkAttendance/` namespace if we want the wizard's steps to
  cluster).
- New wizard registration entry in `WizardRegistry` keyed
  `mark-attendance`, label "Mark attendance", with the chain above.
- `AttendanceStep::nextStep()` reads `$state['_attendance_next']` —
  defaults to `'rate-actors'` if unset.
- `CoreTemplates::coach()` swaps the hero slot from `today_up_next_hero`
  to `mark_attendance_hero`. `TodayUpNextHeroWidget` stays registered
  so existing custom templates that explicitly pin it still work.
- Doc updates: `docs/head-coach-actions.md` (action #1 surface line +
  polish-notes resolution), `docs/coach-dashboard.md` + `docs/nl_NL/coach-dashboard.md`
  reflect the renamed hero.

Out:

- Any change to the activity edit form's inline roster (still the
  edit-after-the-fact surface).
- Any change to the rating UX itself (`RateActorsStep` stays as-is).
- The 3-segment per-row toggle (P/A/L) recommended in the action #1
  audit — high-value but tracked separately, not blocked by this work.
- Replacing the existing `evaluation` wizard's activity-first path —
  it stays as the rating-mandatory entry point.

## Acceptance criteria

- Coach lands on dashboard, sees the **Mark Attendance** hero showing
  the next activity. One tap on the primary CTA opens the wizard with
  that activity preselected. No "lands you on the list" detour.
- Empty state: coach with no upcoming activity sees a **Pick a session**
  CTA that opens the wizard at the activity-picker step.
- Wizard chain works end-to-end on a 360px viewport:
  - Roster step renders attendance for the picked activity, "Mark all
    present" works, status toggles work, ≥48px touch targets.
  - Confirm step shows two large buttons: **Rate the present players**
    + **Skip rating, save attendance**. Both ≥48px.
  - On Skip → wizard exits with success message; `tt_attendance` rows
    are written; no `tt_evaluations` rows are written.
  - On Yes → roster-style rating step runs; submission writes
    attendance + ratings atomically.
- Reopening the wizard for the same activity skips attendance (already
  recorded) and lands on the confirm step — so a coach who marked
  attendance during warm-up and wants to rate post-match doesn't
  re-enter attendance.
- Coach can still reach the activity edit form via the hero's
  **Edit activity** link to fix attendance after the fact.
- `TodayUpNextHeroWidget::nextActivity()` is extracted to a shared
  repository helper consumed by both widgets — no SQL duplicated.
- Player-centric: every screen in the wizard shows the player's name
  next to every control they own (no headerless row-of-statuses).
- §5 nav: wizard chrome already provides Previous / Next / Cancel
  (CLAUDE.md §6 exemption (c) covers Save+Cancel in wizard steps).
  Breadcrumbs render via the standard wizard chrome.
- §1 player-centric: `tt_attendance` rows continue to write
  `player_id` + `activity_id`; rating rows continue to write per
  player. No relationship is moved off the player spine.
- SaaS-readiness: writes still go via `AttendanceStep` and the
  evaluation REST controllers — no new write path that bypasses the
  domain layer.

## Notes

- The widget name "Mark Attendance" is the coach's verb, not the
  system's. The data model stays unchanged: attendance is a property
  of the activity; one row per player per activity. The widget just
  promotes the action that writes those rows to the most prominent
  slot on the coach dashboard.
- The roster-style rating decision (vs. player-by-player) was made on
  2026-05-11. Reasoning: at-the-pitch tempo benefits from one scroll
  through a roster, not N modal pushes. The existing `RateActorsStep`
  already supports both (quick-rate inline + `<details>` per row), so
  no rating-UX rework is needed.
- Future polish from action #1 audit that's *not* in this spec but
  becomes easier once it lands: 3-segment P/A/L toggle in
  `AttendanceStep`, "Mark all absent" inverse shortcut, server-side
  team-scoped roster query. Each is its own small PR.
- Replaces the "Attendance CTA → list view" bug in
  [TodayUpNextHeroWidget.php:42-60](../src/Modules/PersonaDashboard/Widgets/TodayUpNextHeroWidget.php#L42-L60)
  by deprecating the widget from the default coach template — no
  need to fix the CTA on a widget we're moving off the default
  layout.
