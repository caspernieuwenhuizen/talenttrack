# Team · Minutes distribution and Squad evaluation summary showed an empty squad (#2849)

Both reports listed their players with a query that selected `tt_players.name`
— a column the table has never had; it carries `first_name` and `last_name`.
The database rejected the statement, the result came back as nothing, and each
report rendered an empty squad rather than an error anyone could act on. That
is what the pilot saw as "1 match recorded, 0 players in selection".

Both queries now build the name from the two real columns. A regression test
runs each statement and fails on a database error, because the KPI counts that
existing tests assert on are computed by different queries that never touch
`tt_players` — which is how this survived three rounds of fixes to the numbers
beside it.
