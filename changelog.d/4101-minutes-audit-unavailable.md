# Minutes audit: players marked unavailable are not recording gaps (#4101)

A player marked absent, excused or injured in match prep, or on the attendance
register, was still counted as a squad player with missing minutes, so a fully
recorded match could never reach *Complete*. The audit now leaves a player out
of the squad for a game when they are marked anything other than Present, and
shows their cell hatched and labelled *Unavailable* instead of a red gap. The
reason is never shown. Recorded minutes always win: a player marked unavailable
who did play stays on the squad with their minutes. Tournament roll-ups follow
the same rule, and the report's REST payload gains an `unavailable` map per
game.
