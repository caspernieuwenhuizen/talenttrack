# Attendance: a late player was at the session (#4013)

A player who arrives late is now counted as attended. Present % is
`(present + late) / total` everywhere the shared attendance query answers —
the player attendance report, the leaderboard, the at-risk panel, the monthly
team report's attendance band and the three REST routes. Late used to sit in
neither the numerator nor the missed count, which made the player who was at
every session but late four times the worst-looking attender on the team while
never being flagged, and put the report's colour band and its flag on
different numerators.

Lateness is not lost by that. It flags on its own threshold
(`attendance_late_flag_threshold`, defaulting to the at-risk threshold), and
every flag now says which count raised it: the ⚠ badge and the At-risk players
panel read *3 missed*, *4 late*, or both, and the REST rows carry
`flag_reasons`. `AttendanceFlagService` owns the attended numerator, the missed
set and both thresholds, so the report, the leaderboard, the monthly report and
the daily attendance-flag notification cannot drift.

Two definitions that had been copied out of the service are now read from it:
the declining-trend heuristic's missed set, and the Comms cron's SQL literal.
The cron also stops gating on `plan_state`, which defaults to `completed` on
every activity the planner did not create — it now uses the same "has this
happened" gate as every report, so the daily nudge no longer counts sessions
that have not taken place.
