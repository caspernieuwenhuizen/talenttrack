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

## Frontend reports + Print/Save as PDF (v3.79.0)

Team rating averages and Coach activity now render natively on the public dashboard at `?tt_view=reports&type=team_ratings` and `?type=coach_activity` — no more wp-admin tab jump. Each report has a **Print / Save as PDF** button at the top: clicking it opens the browser's print dialog with a stylesheet that strips dashboard chrome, so picking "Save as PDF" produces a clean tabular PDF.

The legacy Player Progress + Radar report still opens in wp-admin since it leans on form-submit + Chart.js infrastructure significant to port. A future spec will migrate it.
