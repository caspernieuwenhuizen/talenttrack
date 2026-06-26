# My activities: full-width on desktop, all info inline (#1986)

Bump: patch

The player's **My activities** list now uses the full dashboard width on
desktop instead of a narrow 860px column. Rows are no longer clickable — the
old row link pointed at the staff activity-detail view, which a player isn't
authorised for (it returned "niet geautoriseerd"). Everything a player may
see is now shown inline in the table, including a new **Location** column
alongside date, title, type, team and their own attendance status.
