# Attendance reports no longer count not-yet-held activities (#2135)

Bump: patch

The team and player attendance reports — and the leaderboard and at-risk
panel that share their query — now exclude activities dated in the future.
An activity created via the normal "+ New activity" form defaults to
`plan_state = 'completed'`, so a future activity with pre-filled attendance
used to slip past the existing guards and inflate the statistics. The
reports now also require `session_date <= CURDATE()` (an activity dated
today still counts), matching the established predicate in
`ActivitiesRepository`. Query-only; past windows show identical numbers.
