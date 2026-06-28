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

The **Teams** picker is a **multi-select dropdown** listing every standard team you can access. Select one team and press **Apply** to plan that team in the full week grid. Hold Ctrl (Cmd on Mac) — or tap multiple options on touch — to select more than one. Only standard teams (no `team_kind`) appear — staff groups and other team-kinds are filtered out.

### Overview across teams (multiple selected)

Select **two or more teams** and the planner switches to a **condensed, read-only calendar** that keeps the week-grid metaphor instead of a flat table. Week blocks stack vertically (one block per week in the chosen range), and inside each block every selected team gets **one row** across the seven day columns — the same Mon–Sun ordering as the single-team grid, honouring the academy's first-day-of-week.

Each day-cell shows only the **activity-type pill** plus, for matches, the **opponent** and a **Home/Away** marker. There are no principle chips, copy chips, or schedule buttons — this is the Head-of-Department glance across several teams at once, and a HoD doesn't plan team-specific activities here. Each pill is **clickable** and opens the activity's read-only display view, the same destination as clicking a card in the single-team planner.

On phones the seven-column grid would not fit, so each team collapses to a card with a labelled list of its days that have activities — no horizontal scrolling. Drop back to a single team to return to the editable week-grid planner.

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

## Exporting the plan

Three export buttons sit above the grid:

- **Export PDF** — the landscape schedule table (one row per activity).
- **Export XLSX** — the week-by-week styled spreadsheet.
- **Weekly PDF** — a **branded, portrait** weekly layout: a vertical week with the weekday on the left and that day's activity card on the right (type tag, time/location/duration, match details, theme + principles, optional notes), in the academy's colours and crest. Ideal to hand to players/parents or pin to the noticeboard. A match card's heading is the **title** entered on the activity (e.g. "Candia 66 – Vv hedel 14-1"); if no title was set it falls back to `Team — Opponent`, or just the team name. A match card shows `Present HH:MM` (the arrival time set on the activity) ahead of `Kickoff HH:MM`; the kickoff line falls back to the activity's start time when no separate kickoff time was entered. When a location carries both a venue name and a street address (Spond imports both), they print on one line separated by a pipe — `Venue | Address`. The top-left badge shows the academy logo when one is configured, otherwise the ISO week number.

**Weekly PDF** opens a small **compose dialog** first, so you choose what goes in:

- **Show per day** — Time · Location · Duration · Match details · Theme/title · Principles · Notes · Show rest days (everything on except Notes by default).
- **Header** — Academy name · Generated date.

Clicking **Open PDF** opens the print-ready sheet in a new tab; use your browser's **Save as PDF** (or Print) to download it. Enable "Background graphics" in the print dialog so the green day cards and colour tags come through. The proposed filename mirrors the sheet title — `Week plan - {team} - Week {n} - {year}`.

The PDF covers the planner's **current date range** (set it with the window picker first). Branding comes from **Configuration → branding** (primary/secondary colour, academy name, logo) — no hardcoded colours.

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
