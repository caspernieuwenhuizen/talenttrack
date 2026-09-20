# A player's tournament record reaches their coaches and their family (#3560)

Bump: minor

Tournaments were admin-and-staff-only, so a player's own tournament history —
the days they played and the minutes they got — had nobody to read it but the
people running the planner.

It now has an access entity of its own, `player_tournaments`: the coaches and
heads of development of the player's teams, the player, their parents, and the
academy admin. Read only, and one player's figures rather than a squad's.

The planner is deliberately untouched. Widening `tournaments` so a family could
see their own child's afternoon would have shown them every other child's too,
which is the whole reason this is a second entity rather than a wider grant.

Players gain a **Tournaments** toggle in "What your parent can see", beside
Playing time — a tournament day is where a young player's share of the pitch is
most visible and most compared. It defaults to shared, so no family loses
anything.

A top-up migration adds the rows to installs that already carry a matrix, and
reports what it wrote.
