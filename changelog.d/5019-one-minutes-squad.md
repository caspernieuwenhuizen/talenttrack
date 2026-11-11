# One minutes squad and one median on every report (#5019)

The Minutes share, the team monthly report's minutes section, Team · Minutes
distribution and the player talking points now read the same squad: every
player on the register of one of the team's played matches in the window,
whatever team they are on now, plus the team's current roster. Archived players
and guests are left out. The median share is one function on every report.

Coaches will see numbers change. The monthly report used to count the players
with minutes plus the current roster, and the Minutes share only the players on
a register, so the same team and month could show very different medians. Both
now agree: on a team whose players have moved on, the monthly minutes table
can list more players and its median can drop to the Minutes share's; a roster
player who was never put on a register now appears on the Minutes share and
the Minutes distribution at 0 minutes. The Minutes distribution also counts
only played matches for its squad now. In a window in the past, the roster can
add a player who joined the team later, at 0 minutes, until team membership
over time is recorded. `GET /teams/{id}/minutes-share` keeps its shape; its
`players` list can be longer.
