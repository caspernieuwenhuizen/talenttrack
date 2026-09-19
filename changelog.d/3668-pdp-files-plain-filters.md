# REST: the PDP files list honours a plain team_id (#3668)

`GET pdp-files?team_id=…` ignored the filter without any warning and returned
every team's PDP files for the season, so a list meant to show one squad's
development plans mixed in every other squad. The list now takes `team_id`,
`player_id` and `status` as plain parameters as well as in the `filter[...]`
form, and `GET pdp-files/coverage` does the same for `team_id`. The nested
form wins if both are sent, and a coach still sees only their own files. Both
routes now list their parameters, including the allowed `per_page` values.
