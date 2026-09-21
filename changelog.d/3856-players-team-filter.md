# The players list reads a plain team_id (#3856)

`GET /players?team_id=73` now returns that squad. The plain spelling was
neither applied nor refused — WP REST drops a query parameter no route
declared, without a word — so asking for one team answered with every
player the caller may read, each row carrying a real team name that made
the answer look deliberate. The plain names of every list filter
(`team_id`, `position`, `preferred_foot`, `age_group`, `archived`,
`status`, `assignment`, `media_consent`) now fold into the nested
`filter[...]` form, with the nested spelling winning when both are sent,
and a filter that is sent but cannot be read is refused with
`400 bad_filter` rather than dropped.
