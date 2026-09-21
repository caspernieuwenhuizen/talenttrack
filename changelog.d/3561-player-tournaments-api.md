# A player's tournament record, on the API (#3561)

Bump: minor

Tournament minutes could be read one squad-day at a time and never one
player at a time: the planner's minutes ticker showed how a Saturday had
been divided between sixteen children, and nowhere showed how a season of
Saturdays had gone for one of them.

`GET /players/{id}/tournaments` is that answer — every tournament a player
was in the squad for, every fixture of those they were down for, the
minutes the rotation plan gave them, how they compare with their own
playing-time target, and what is still ahead. It is what the Tournaments
tab on the player file will render.

The minutes arithmetic moved out of the tournament planner's controller
into one domain service that both now call, so the player's file and the
coach's ticker cannot drift apart. A test compares the two endpoints
player by player for the same tournament.

Two things the answer is careful about. Minutes come from the rotation
plan of completed fixtures and never from the attendance register — a
tournament day's attendance is one total for the day, so the two are never
added together. And a fixture with no result recorded says so, rather than
reporting 0-0: a goalless draw and a game nobody typed in are different
facts about a child's season.

Who can read it follows the player-tournaments permission: a coach for
their own squads, a parent for their own child unless the child has closed
the section, the player for themselves, and the Head of Development or
academy administrator for anyone.
