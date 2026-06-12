<!-- audience: user, admin -->

# Reports

The **Reports** tile is a launcher for different ways of looking at your data.

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
