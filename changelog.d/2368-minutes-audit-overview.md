# Minutes-audit overview: read-only games × players matrix (#2368)

Bump: minor

New **Minutes audit** report (Reports launcher → *Playing time*, or
`?tt_view=minutes-audit`): a read-only games × players auditability matrix that
makes recorded-minutes gaps obvious. Rows are the team's games in the window,
columns are the squad (resolved from attendance on those games, not the player's
team assignment), and each cell shows the minutes recorded for that player —
green for recorded, red for on-squad-but-zero, hatched for not-in-squad. Every
row carries a total and a completeness chip (Complete / Incomplete / Not
recorded), with a column-total footer. Four clickable gap KPIs (Games, Fully
recorded, Incomplete, Not recorded) summarise and filter the matrix. Each row
deep-links to the game's activity detail to record its minutes.

The audit reads the same recorded, actual, non-guest minutes as the minutes
report, so its numbers reconcile exactly; a team with games but no recorded
minutes shows an honest "not recorded" state rather than a misleading "0
players". Reachable via a REST read endpoint (`GET /reports/minutes-audit`) gated
on `tt_view_analytics` plus the `report_minutes_audit` toggle, with the caller's
team scope enforced.
