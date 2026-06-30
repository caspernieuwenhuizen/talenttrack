# Attendance reports: period quick-pills + activity-type filter (#2136)

Bump: minor

Both the team and player attendance reports now carry the same filtering
vocabulary as the activities list: retrospective period quick-pills (Last
week, This month, This season) that set the From/To window for you — with
the manual date range still overriding — and an activity-type filter
(training / game / tournament). The type filter narrows every figure
consistently: the KPI tiles, the table, the leaderboard and the at-risk
panel. Filters render through the shared FilterBar (a Filters bottom sheet
on mobile, inline on desktop) and the filter flows into the shared
`AttendanceRankingQuery` plus a new `activity_type_key` parameter on the
attendance REST endpoints, so a SaaS consumer gets the same answers.
