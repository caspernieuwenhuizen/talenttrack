# Minutes audit / trace-back: report drill-down and raw rows (#2160)

Bump: minor

Every player's minutes total in the Team · Minutes reports (both the standard
report and the Analytics minutes report) now expands to the per-match rows that
sum to it — date, match, type, source (`actual` vs recomputed) and minutes —
reusing the same hardened query so the breakdown reconciles exactly with the
total. The trace is also exposed over REST at
`/teams/{id}/players/{pid}/minutes` for a non-WordPress front end.

The raw `tt_attendance` minutes rows — `minutes_played`, `record_type`,
`is_guest`, `activity_id` — are now documented and browsable in the Data
Browser for ad-hoc verification.
