# Minutes audit: a tournament day is a roll-up of its fixtures (#3857)

A tournament day appeared in the minutes audit as a game with an empty squad and
a red *Not recorded* chip — "go and record this" — over what is often the largest
block of minutes in a month. Opening its editor answered with no players, no
minutes field and no way to add anybody, so the row could never leave *Not
recorded*, and every per-player total covering a tournament weekend read as if
the weekend had not happened.

A tournament day carries no attendance of its own: the play happens in its
fixtures, each of which becomes its own match activity when a coach kicks it off.
The day is now a read-only roll-up. Its minutes are summed from those fixtures,
the row says *Tournament day — minutes recorded per fixture* in words rather than
in colour, it wears a *Roll-up* chip, and its action opens the tournament planner
— where the minutes actually live. Its status follows the fixtures, so a
tournament whose fixtures were recorded no longer reads as unrecorded.

Because the fixtures are rows in the same matrix, the roll-up is left out of the
column totals, the grand total and the gap KPIs; counting it as well would show
every player twice the game time they played.

`GET reports/minutes-audit/{id}/editor` now refuses a tournament day with
`409 minutes_recorded_per_fixture`, naming the tournament to open, instead of
answering `200` with an empty squad. The refusal stays team-scoped. The matrix
row also reports `tournament` as its type rather than the empty match subtype a
tournament has no value for.
