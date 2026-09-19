# The activities list honours plain team and date filters (#3584)

`GET activities` only read filters nested as `filter[team_id]`,
`filter[date_from]` and `filter[date_to]`. A request with plain `team_id`,
`from` / `to` or `date_from` / `date_to` (the names the attendance and minutes
grids use) got a 200 with every team and every date. The list now accepts
both forms, and the nested form wins when both are sent. A date that isn't
written as YYYY-MM-DD is refused with a 400 instead of being ignored. The route
now declares its parameters, so the REST route index lists them. A coach still
sees only their own teams, whichever form they use.
