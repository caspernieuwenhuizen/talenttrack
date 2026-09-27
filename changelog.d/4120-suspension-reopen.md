# Suspensions: reopening the match that served one runs it again (#4120)

A suspension is served when its last covered match is completed. Reopening
that match left it served, and the player's journey kept saying the ban had
ended. Now reopening it clears the served date, removes *Suspension served*
from the journey, and the player shows as unavailable for that match again.
Completing it once more serves the suspension again, with one journey entry.
Reopening an earlier match of the ban changes nothing while the last one
stays completed. Extension point: `tt_player_suspension_unserved`.
