# The minutes API says which window it used, and can answer for one match (#3748)

`GET /activities/minutes-grid` quietly applied the season default window
whenever a caller passed no dates, and said nothing about it — so a match
outside that window simply wasn't in the response, with no way to tell a
missing column from a missing register. The response now carries a `window`
object with the `from` and `to` actually applied, whether they were supplied,
defaulted, or fell back because a malformed date was sent. It comes from
`MinutesGridQuery` rather than the controller, so the grid screen and every
other caller of that query report the same thing.

There is also a new route, `GET /activities/{id}/minutes`, for the coach who
sees "minutes 16/16" on the activities list and wants to read them back: the
per-player minutes for one match, with its goals, assists and squad flags, and
no need to know the date or guess a window around it. It is derived from the
same query the grid uses, so the two can't report different numbers, and it is
scope-checked per activity like the grid is. The grid itself is unchanged in
every other respect.
