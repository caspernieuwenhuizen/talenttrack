# Team attendance report: expandable rows to drill into players (#2137)

Bump: minor

Each team row on the team attendance report is now tap-to-expand: tapping
the team name opens an inline sub-table of that team's players (player ·
present %, with at-risk players marked), loaded on demand for the active
window and filters from the shared `AttendanceRankingQuery`. One team is
open at a time. The disclosure is a semantic `<button aria-expanded>` and
is keyboard-operable; without JavaScript a "View players" link beside each
team opens the player report pre-filtered to that team instead, so the
drill-down is always reachable. The per-player slice is exposed at a new
`GET /reports/attendance` REST endpoint for non-WordPress consumers.
