<!-- audience: admin -->

# Evaluation coverage

The **Evaluation coverage** report is a Head-of-Development surface that
answers one question: *which players have not been evaluated this window,
and which coach owns the gap?* It lives under **Analytics → Evaluation
coverage** (`?tt_view=eval-coverage`) and needs the analytics-viewing
permission (`tt_view_analytics`).

## Evaluation windows

A **window** is a named period of the season — for example "Autumn
review" running 1 September to 15 October — in which every player is
expected to receive at least one evaluation. You define the windows in
the settings-style editor at the top of the report:

1. Give the window a **name**.
2. Pick its **start** and **end** dates.
3. Add as many rows as you need (one blank row is always offered for the
   next window). Clear a row's fields to remove that window.
4. Click **Save windows**.

Windows are stored per academy in configuration — there is no separate
"windows" record to manage, and the report never sends reminders. A
player counts as **covered** for a window when they have at least one
evaluation whose date falls inside that window.

## The coverage matrix

Below the editor, the matrix lists every active player grouped by team,
with one column per window. Each cell shows:

- **✓ Evaluated** — at least one evaluation landed in that window. Hover
  the tick to see which coach recorded the most recent one.
- **• Not evaluated** — a gap. The cell is marked with a dot and a
  "Not evaluated" label (state is conveyed by icon and text, never colour
  alone).

A KPI strip across the top totals players, windows, overall coverage
percentage, and the number of gaps.

## Gaps by coach

The **Gaps by coach** strip tallies how many uncovered cells fall under
each team's head coach, sorted worst-first. Players whose team has no
head coach assigned roll up under **Unassigned**. This is the "who owns
the gap" answer at a glance.

## Open evaluations by coach

Each coach who has authored evaluations appears as a chip under **Open
evaluations by coach**. Clicking a chip opens the Evaluations list
filtered to that coach (`?filter[coach_id]=…`), so you can drill from a
gap straight into what that coach has — and has not — recorded.

## Attendance-recording compliance

The last strip reports, per team and per window, the share of
**completed** activities that have **any** attendance recorded. This
separates two very different situations:

- A team with a low percentage has completed activities but the coach is
  not recording who attended.
- A team showing **No activity** simply had no completed activities in
  that window — nothing to record.

## Where this fits

This report is read-only analytics built on existing evaluation and
attendance data. It does not change any player, evaluation, or activity
record — it only surfaces where evaluation coverage is missing so a Head
of Development can act. The same data is available through the REST API
at `/wp-json/talenttrack/v1/eval-coverage`.
