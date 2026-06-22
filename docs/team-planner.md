<!-- audience: user -->

# Team planner

The **Team planner** is the "what is my team doing this week" calendar. Open it from the dashboard tile (Performance group) or directly via `?tt_view=team-planner`.

## What it shows

A grid of days, organised by week. Each day is a card listing the activities scheduled for that day, with:

- The activity title.
- A coloured **status pill** — Planned (yellow), Completed (green), Cancelled (red). The same pill the activities list shows. Cancelled activities are excluded from the planner — once you cancel an activity it drops out of the grid.
- The location (when set).
- Up to three **principle chips** if the activity is tagged with coaching principles.

Today's column is highlighted with a coloured outline. Days with no activities show a `+ Add` button (when you have edit permission) that opens the activities form pre-filled with the date and team.

## Picking a window

Use the **Show** dropdown in the toolbar to switch between:

- **One week** — seven days, the default. The Mon–Sun grid that ships everywhere.
- **Two weeks**, **Four weeks**, **Eight weeks** — stacks two / four / eight consecutive weeks vertically. Each week gets a *"Week of …"* header.
- **Full season** — every week from the start to the end of the **current season** (the row marked `is_current` in the seasons table). The week boundaries are snapped to whole weeks so the grid lines up.

The dropdown round-trips through the URL — bookmark or share `?tt_view=team-planner&team_id=12&range=4weeks&week_start=2026-05-04` and the same window opens for the next person.

For week / 2 / 4 / 8-week ranges the toolbar has **prev / today / next** buttons that step by the chosen window size — *Next 4 weeks* actually advances four weeks, not seven days. For Full season the prev/next buttons are replaced with the season name, since the season picker is implicit (it's always the current season).

## Picking teams

The **Teams** picker lists every standard team you can access as checkboxes. Tick one team and press **Apply** to plan that team in the full week grid. Only standard teams (no `team_kind`) appear — staff groups and other team-kinds are filtered out.

### Overview across teams (multiple selected)

Tick **two or more teams** and the planner switches to a **condensed, read-only overview** — one row per activity with **Date · Team · Type · Match (opponent + home/away) · Status**. This is the Head-of-Department glance across several teams at once; because a HoD doesn't plan team-specific activities, the overview has no copy / duplicate / schedule chrome. Drop back to a single team to return to the editable week-grid planner.

## Scheduling an activity

Two paths:

- **Toolbar `+ Schedule activity` button** — opens the activities form with the team pre-filled and the activity created at status `scheduled`.
- **Day card `+ Add` link** (visible on empty days) — opens the activities form with the team and the date pre-filled, also created at status `scheduled`.

Either way, you land in the activities form and complete it as normal — title, type, location, attendance, etc.

## Status and the planner

The planner reads the **Status** field from the activities form (Planned / Completed / Cancelled). Whatever you set there is what shows on the planner card. There is no separate "planner status" to keep in sync — the activities form is the single source of truth.

A scheduled activity stays at *Planned* (yellow pill) until you flip it to *Completed* (green) on the form, typically once attendance has been recorded. *Cancelled* activities drop out of the planner entirely.

## Principles trained — last 8 weeks

Below the grid is a small panel showing the top ten coaching principles trained in the last eight weeks for the selected team. Counts are based on completed activities — a planned activity won't bump the count until you mark it Completed.

This panel uses the existing `tt_principles` framework — there's no parallel store. Tag your activities with principles on the activities form and they'll show up here automatically.

## Permissions

- **`tt_view_plan`** — required to open the planner. Granted by default to anyone who can see activities.
- **`tt_manage_plan`** — required to schedule new activities from the planner. Granted by default to anyone who can edit activities.

If you can see activities you can see the planner. If you can edit activities you can schedule them from it.

## What's not here yet

- **Drag-drop reschedule** — to move an activity, edit it on the activities form and change the date.
- **Inline create modal** — clicking `+ Add` takes you to the activities form rather than opening a quick-create dialog. The form is the canonical create surface.
- **Multiple-season picker** — the Full season range always picks `is_current`. To plan a future season, set that season's `is_current` flag first under PDP → Seasons.

## Repeating sessions (v4.20.125)

Two shortcuts remove the re-typing from weekly planning:

- **Duplicate** under each activity card opens the create form pre-filled with the source's title, time, location, type, and connected principles — dated one week after the original by default. Attendance and evaluations never travel with the copy. Adjust anything, then save.
- **Copy last {weekday}** appears in empty day cells (today or later) when the team has a previous session on that weekday — an empty Tuesday offers "Copy last Tuesday". It opens the same pre-filled form targeted at that cell's date.

Both land in the activities form for confirmation, so nothing is cloned blind; the copy is created as a scheduled plan item.
