<!-- audience: user, admin -->

# Reports

The **Reports** tile is a launcher for different ways of looking at your data. The reports are grouped by purpose so the right one is easy to find: **Development & performance** (ratings, progress, rate cards), **Playing time** (minutes played and squad load), **Attendance** (team and player attendance statistics and the leaderboard), **Recruitment** (scouting, prospects, trial funnel), **Staff & quality** (coach activity and evaluation quality), and **Season overview** (the annual review). Sections you don't have access to — recruitment and season-wide reports are academy-admin only — simply don't appear. All standard reports — including team attendance, player attendance, the leaderboard and minutes-per-team — live here and breadcrumb under **Reports**; they are no longer duplicated on the Analytics dashboard.

## Player progress

Quick visual reports for coaches:

- **Player progress** — radar charts of your top players.
- **Player comparison** — pick two or more players and see their latest evaluations as overlapping radars.
- **Team averages** — per-team averages across the main categories.

For deeper per-player views, see [Rate cards](?page=tt-docs&topic=rate-cards) and [Player comparison](?page=tt-docs&topic=player-comparison).

## Team rating averages

A simple table — one row per team, one column per main category, plus an evaluation count. Shows the lifetime average across active evaluations on each team. Archived players and archived evaluations are left out.

A quick way to see which team is strongest this season.

## Coach activity

How many evaluations each coach has saved in the chosen window (last 7, 30, 90, 180 or 365 days). Useful for spotting a coach who has fallen behind, or for confirming that a planned assessment period actually happened.

## Coach · Evaluation quality (v4.20.123)

The head-of-development's evaluation spot-check as a report: one row per coach with their evaluation count, rating count, mean rating, standard deviation, the most-given rating (and what share of all their ratings sits at it), and the date of their last evaluation. Filterable by team and date range.

Rows where the standard deviation is below **0.5** across **10 or more ratings** are flagged *low variance* — the statistical signature of a coach rating everyone the same number. A coach with only a handful of ratings is never flagged; there's no meaningful variance to measure yet.

Restricted to academy-wide roles (head of development / admin): coaches cannot see each other's statistics. The **Export (CSV)** button downloads the same rows; integrations can read them from `GET /wp-json/talenttrack/v1/reports/coach-evaluation-quality` with the same permission gate.

## Frontend reports + Print/Save as PDF (v3.79.0)

Team rating averages and Coach activity now render natively on the public dashboard at `?tt_view=reports&type=team_ratings` and `?type=coach_activity` — no more wp-admin tab jump. Each report has a **Print / Save as PDF** button at the top: clicking it opens the browser's print dialog with a stylesheet that strips dashboard chrome, so picking "Save as PDF" produces a clean tabular PDF.

## Player · Progress & radar (v4.20.124)

The legacy wp-admin "Player Progress & Radar" report now renders natively on the dashboard as a standard report (Reports → *Player · Progress & radar*). Same three modes with the same data: **Player Progress** (each selected player's last five evaluations as stacked radar series — leave the selection empty for the top-10 active players), **Player Comparison** (each player's most recent evaluation overlaid on one radar; pick at least two), and **Team Averages** (one radar series per team, averaged per category).

Coaches see only their own teams' players and teams; academy-wide roles see everything. The old wp-admin route redirects here, so bookmarks keep working. Integrations can read the same datasets from `GET /wp-json/talenttrack/v1/reports/player-radar?mode=…&player_ids=…`.

## Only past, actually-held activities count

Both attendance reports — and the leaderboard and at-risk panel that share their query — only count activities that have **actually been held**: completed, in the past (session date today or earlier). An activity dated in the future never contributes to an attendance statistic, even if attendance was pre-filled on it. An activity dated **today** does count. This keeps each player's attendance figure truthful — a coach reviewing a profile sees only sessions the player could really have attended.

## Filtering the attendance reports — period pills + activity type

Both the team report and the player report carry the same filtering vocabulary as the activities list:

- **Period quick-pills** — *Last week*, *This month* (month-to-date), *This season*. These are retrospective (the reports look back). Picking a pill sets the From/To window for you. The explicit **From / To** date range is always the manual override — type a date there and it wins over the pill.
- **Activity type** — narrow to one type (training / game / tournament, whatever your academy has configured). The type filter narrows every figure consistently: the KPI tiles, the table, the leaderboard and the at-risk panel.

On a phone the filters collapse into a **Filters** button that opens a bottom sheet; from desktop width up they sit inline. Every control is keyboard-operable.

## Drilling into a team's players (team report)

On the team report each team row is **tap-to-expand**: tapping the team name opens an inline sub-table of that team's players (player · present %, with at-risk players marked), loaded on demand for the active window and filters. Tapping again collapses it; one team is open at a time. Without JavaScript, a **View players** link beside each team opens the player report pre-filtered to that team instead — the drill-down is always reachable.

## Minutes played — totals and per-match trace

The minutes reports count only **recorded** match minutes: actual, non-guest
attendance. Planned (expected) roster rows and guest appearances never count,
and a match with no recorded minutes contributes nothing — the reports never
estimate or invent minutes, so a zero is an honest "no data recorded" rather
than a guess.

Every player's minutes total is a **drill-down**: open it to see the per-match
rows that sum to it — date, match, type, source (`actual` recorded minutes vs a
recompute from the match-execution log) and minutes. The breakdown reconciles
exactly with the total, so you can always trace a reported number back to its
source rows. On the Team · Minutes distribution report each player bar expands;
on the Analytics minutes report each Total opens the per-match table beneath the
row. Both work on a phone and by keyboard; without JavaScript the per-match rows
stay visible inline.

Integrations can read the same trace — gated on `tt_view_reports` with the same
team-scope narrowing as the report:

- `GET /wp-json/talenttrack/v1/teams/{team_id}/players/{player_id}/minutes?from=…&to=…` — the per-match minutes rows for one player and the reconciling `total_minutes`.

To verify a total against the raw stored rows, the `tt_attendance` minutes rows
(`minutes_played`, `record_type`, `is_guest`, `activity_id`) are browsable in
the **Data Browser**.

## Player attendance — ranking + at-risk flags (v4.21.36)

The player attendance report defaults to **worst attendance first** (lowest present %), so the players who need attention surface at the top. It lists **every player** with recorded attendance in the window — no top-N cap — and every column stays sortable (click a header to re-sort).

Players who have **missed** a configurable number of activities in the window (absent / excused / injured) are **flagged**: an inline ⚠ badge with the missed count, a tinted row, and an **At-risk players** panel above the table listing them worst-first. The threshold (default **3**) is the *single source of truth* shared with the daily attendance-flag notification, so the report and the nudge email always agree.

### Tracing the activity count (drill-down)

Each player's **Activities** count is a link. Open it to see the actual sessions behind the number: the activities list opens filtered to that player, the report's team, and the report's date window, showing only activities the player has a recorded attendance row for. From there each activity opens its detail with the recorded attendance status, so a coach can reconcile the count with the source rows — the same tracing the minutes report offers. A **← Back** link returns to the report.

### Setting the at-risk threshold

The threshold lives in **Configuration → General → Attendance at-risk threshold** (an academy-admin setting). One number, between 1 and 50, drives every at-risk flag: the player attendance report, the attendance leaderboard, and the daily attendance-flag notification all read it. Lower it to catch slips earlier; raise it if your academy only wants to act on persistent absence.

## Attendance leaderboard (v4.27.0)

A dedicated league table reachable from the Reports launcher (*Attendance leaderboard*). It ranks players over the chosen window into two side-by-side tables: **Needs attention** (the lowest attendance %, where at-risk players keep their ⚠ badge) and **Most reliable** (the highest attendance %). By default it shows **all** players in the window; type a number in *How many* to narrow each table to that many rows. Optionally narrow to a single team. Coaches see only their own teams; academy-wide roles see the club.

On a phone the two tables stack into one column with no horizontal scroll; from tablet width up they sit side-by-side. Every column is sortable on top of the default ranking.

Integrations can read the same data — with the same `tt_view_analytics` gate and team-scope narrowing — from:

- `GET /wp-json/talenttrack/v1/reports/attendance-leaderboard?from=…&to=…&n=…&team_id=…&activity_type_key=…` — `{ top, bottom, total }`.
- `GET /wp-json/talenttrack/v1/reports/attendance-at-risk?from=…&to=…&team_id=…&activity_type_key=…` — flagged players worst-first, each with a `declining` trend marker, plus the active `threshold`.
- `GET /wp-json/talenttrack/v1/reports/attendance?from=…&to=…&team_id=…&activity_type_key=…` — the per-player attendance rows for one window (powers the team report's inline drill-down): `{ players, threshold }`.

The optional `activity_type_key` on every attendance endpoint narrows to one activity type, matching the report UI's Type filter.
