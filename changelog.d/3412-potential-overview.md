# Potential overview: a squad's potential bands, in one place and editable (#3412)

Bump: minor

A head of development could not see every player in an age group with their
current potential band, sorted. Not approximately either: the band appeared
nowhere that showed more than one player, and the one cross-player surface
that reads potential — the traffic-light dot — folds it into a composite that
can be neither sorted nor filtered by. Answering "who are our first-team
potential players in U15" meant opening players one at a time.

**Reports → Potential overview** is the answer. Pick a team, or an age group
spanning several squads, and you get one row per player with their current
band, the movement that produced it (raised or lowered, and from what), when
it was recorded and by whom. Sortable by band, name, team or date; filterable
to the bands you care about — including **Not recorded**.

Players with no band recorded are rows, marked as such and counted in the
coverage figure. On a typical academy they are the majority, and a list that
quietly left them out would tell a head of development the opposite of the
truth. Players below the age the band is asked at are counted separately
rather than as gaps.

The band cell is editable in place for anyone who may record potential, which
is what #3386 — a bulk potential capture grid — resolves into: the screen that
shows a squad's potential is where it gets edited, rather than a second surface
with its own idea of what a band means. It is a grid, so it saves the way the
other grids do: change as many as you like, press **Save bands** once, and
**Cancel** means cancel. Writes go through the same path and the same rules as
the per-player capture popover, so restating a standing band records nothing
and a player below the age floor is skipped with a count.

Players and parents reach nothing here, whatever your status-dot setting says:
a ranked list of staff judgements across a squad is more exposing than one
colour about one child. Integrations can read the same filtered set from
`GET /wp-json/talenttrack/v1/reports/potential-overview`.

The report can be switched off for an academy under Features.
