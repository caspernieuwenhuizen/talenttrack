# Reports: exclude cancelled activities from minutes and attendance (#2259)

Bump: patch

Cancelled matches and trainings no longer contribute to the minutes or
attendance reports. An activity counts as cancelled when either its
`plan_state` is `cancelled` or its `activity_status_key` is `cancelled`, and
both markers are now honoured across the team and player minutes reports, the
standard-report minutes queries, and the attendance-ranking and team
attendance reports. Previously the minutes reports counted cancelled
activities entirely, and the attendance reports only caught the `plan_state`
marker — a completed-then-cancelled activity still skewed the numbers.
Non-cancelled activities, including manual "paper match" minutes, are
unaffected. Query-only change.
