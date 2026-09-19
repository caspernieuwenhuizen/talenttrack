# REST: the goals list honours a plain player_id (#3607)

`GET goals?player_id=…` ignored the filter without any warning and returned
every goal the caller could see. A client would show that as one player's
goals. The goals list now takes its filters as plain parameters (`player_id`,
`team_id`, `status`, …) as well as in the `filter[...]` form, and the nested
form wins if both are sent. A child's choice to hide their goals from a
parent applies either way. The route also lists its parameters.
