# Minutes reports: honest match count, and a filter bar on both (#2433, #2434)

Bump: patch

The Team · Minutes distribution report could show a match count that
contradicted the squad beside it — "19 wedstrijden" next to an empty player
list. The match count was the only query on the page that carried none of the
exclusions its sibling queries carry, so archived, binned, cancelled and
not-yet-played fixtures all counted towards it.

The tile now reports what the report can actually account for. *Matches
recorded* counts the matches that produced recorded minutes — the same matches
the player bars are built from, so the two can never disagree — and carries the
honest denominator underneath: how many matches were played in the window. When
they differ the tile is flagged ("3 gespeelde wedstrijden hebben geen minuten"),
which names the gap as a recording gap rather than leaving a coach to guess.
Fixtures dated in the future no longer count as played. The counting rule moved
out of the view into `MinutesQuery::matchCountsForTeam()`, beside the
predicates it has to agree with, and is covered by tests.

Both minutes reports also gained the shared filter bar the other standard
reports have had since v4.80: period pills plus a manual From/To range. Every
figure follows the chosen window — KPI tiles, per-match rows, each player's
drill-down and the Explorer link. The default is unchanged at a rolling 12
months, so no existing number moves; the empty state's "widen the window"
advice is now something a user can actually act on. As a side-effect the
Explorer drill-through is bounded for the first time: it previously passed the
literal string `-12 months` as a date, which matched every row.
