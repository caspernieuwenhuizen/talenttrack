# A team's record, form and leaderboards as one reader (#3520)

Bump: minor

Groundwork for the team statistics tab, with nothing on screen yet.

Every number a coach wants about their team's season was recorded and none of
them could be asked for together. Goals had a reader, minutes had a reader,
the last few results had a reader — a team's *record* had none, so the first
screen that wanted all four would have worked it out for itself, and the second
would have worked it out again, differently.

`GET /teams/{id}/stats` now answers it once: played, won, drawn, lost, goals for
and against, clean sheets, recent form, top scorers, top assists, appearances
and minutes, over a window that defaults to the current season.

Two counting rules are the point of it. A tournament is a multi-game day, so it
stays out of the record — one scoreline cannot describe four games — while the
goals scored there still count for the player who scored them; the report says
how many tournament days it left out rather than letting the total quietly
disagree with what the coach remembers. And a match nobody typed a result into
is listed as exactly that, not counted as a nil-nil draw.

Reading a team's statistics needs the same access as opening that team. A coach
who cannot see a squad cannot read its record either.
