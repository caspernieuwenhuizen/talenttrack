# All-teams lens now resolves from the authorization matrix (#1942)

Replaced the phantom `tt_view_all_teams` / `tt_edit_settings` capability
idiom — which gated the academy-wide ("all teams") lens across reports,
analytics, attendance, the cohort board, the team planner, match-execution
surfaces and the matches-needing-review widget — with a single
`AllTeamsScope` helper that asks the authorization matrix for global-scope
read on each surface's own entity (reports surfaces check `reports`,
analytics / attendance check `activities`, the evaluations audit override
checks `evaluations`). Frontend renders and REST permission callbacks now
resolve the all-teams question from one place, so they can no longer drift.
Head of Development and Academy Admin keep the club-wide view; scouts gain
the club-wide reports and analytics lens where the matrix already grants
them global read.
