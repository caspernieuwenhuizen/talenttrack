# Minutes share: the report says which matches the window left out (#3796)

Team · Minutes share could read identically for a team that played nothing and
for a team whose whole season sat on the other side of the chosen period, and
the empty state's advice — "widen the window" — gave no clue whether widening
would help or by how much. A board member following one player's minutes plan
hit exactly that: the match they were looking for was completed, with minutes
for all sixteen players, and the report simply did not mention it.

The report now counts the played matches outside the resolved window and says
so, with the dates to widen onto: "3 played matches fall outside this window,
between 14 September 2024 and 7 June 2025." The empty state tells the two cases
apart — a team with no played matches on record at all is a different problem
from a period aimed at the wrong months, and only the second is one click from
being right. The same count comes back on `GET /teams/{id}/minutes-share` and
its per-player sibling as `outside_window`, so a non-WordPress client gets the
same answer.

Both minutes-share routes now declare their `from` and `to` parameters. They
have always accepted them — the rolling twelve months is only the fallback, and
the report's From/To range and **This season** pill have set them since the
report shipped — but undeclared parameters do not appear in the route index,
which is how they came to be reported as missing. The default window is
unchanged.
