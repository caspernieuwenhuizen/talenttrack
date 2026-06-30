# Minutes reports: harden aggregation and stop fabricating estimates (#2158)

Bump: patch

The minutes-played reports now count only canonical recorded attendance —
`record_type = 'actual'`, non-guest — and sum each player's minutes per match
before joining, so a player with a duplicate attendance row for the same match
is counted once instead of being doubled by a JOIN fan-out. The "Player ·
Minutes played" and "Team · Minutes distribution" reports also now join
attendance on the correct `activity_id` column (the previous join used a column
renamed away years ago, which was one cause of reports showing zero minutes).

A match with no persisted minutes, no execution and no lineup now contributes
nothing — the old "credit each starter half a match" estimate is gone, so a
total never mixes recorded minutes with invented ones. Correctly-recorded past
matches show identical numbers.
