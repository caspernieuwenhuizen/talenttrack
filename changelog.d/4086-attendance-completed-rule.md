# Attendance counts the activities the coach marked completed, on every surface (#4086)

Bump: patch

After #4041 every attendance percentage used the same formula, but not the
same activities. The rolling attendance KPI, the coach's team attendance KPI,
the player's own attendance KPI, the team overview, the team roster widget,
the player report PDF, the KPI snapshot export and the roster-stats export
still decided "this activity happened" from the planner's `plan_state`
instead of the status the coach set. A training marked completed whose
planner state was left at "scheduled" dropped out, and a planned training
still carrying the column's default counted. They now all read the activity
status, like the attendance reports already did. The PDP evidence packet had
no finished-activity filter at all, so a planned activity with a pre-filled
register counted toward the rate discussed with a family; it now counts only
completed activities too. The two "my attendance" KPIs no longer count an
activity the planner has marked in progress until the coach completes it.
