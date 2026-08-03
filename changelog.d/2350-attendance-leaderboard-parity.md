# Attendance leaderboard: filter + chrome parity (#2350)

The attendance leaderboard now shares the same filter bar and chrome as the player attendance report: a team picker, retrospective period pills, an activity-type filter and a manual date range, plus the leaderboard's "How many" cap. Opening it with no filters defaults to the current season. A KPI strip above the tables summarises the ranked players (total, average attendance, at-risk count), computed from the data already fetched — no extra query. Flagged players in the "Needs attention" table keep their missed-count badge, and the empty-state messages now say what to try next.

Bump: patch
