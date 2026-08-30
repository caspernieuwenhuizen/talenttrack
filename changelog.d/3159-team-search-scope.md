# Team search returns the squads you can open, not the whole academy (#3159)

Bump: patch

The command palette's team search gated on `tt_view_teams` and then queried
every team in the club. That capability is club-wide on the coach role, so a
JO15 coach typing two letters enumerated every squad in the academy — names and
age groups. Team names encode age groups, so the result is a map of the
academy's cohorts: not player data itself, but the index to it.

Player search was already correct and is unchanged — it over-fetches and runs
each row through the same authorization the player's own profile uses. Team
search now applies the narrowing `GET /teams` has always applied: a global team
read searches everything, everyone else searches the teams they are assigned to,
and a caller with no teams gets an empty list rather than the club.

The narrowing runs in SQL rather than after the fact, so the ten-result cap is
filled with squads the caller can actually open.
