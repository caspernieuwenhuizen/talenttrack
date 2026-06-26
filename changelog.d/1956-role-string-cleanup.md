# Coaches who are also parents regain player-notes thread access (#1956)

Fixed a case where a coach whose own child is in the academy — so they
hold both the coach and the parent role — was wrongly blocked from
reading and posting on the player-notes threads of players they coach.
The player-notes adapter used to deny anyone carrying the player or
parent WP role outright, which false-denied these dual-role coaches. The
denial now rests solely on the player-notes capability plus the existing
team-ownership scope check, so a coach keeps access on their own
players while pure players and parents — who hold no player-notes
capability — stay denied exactly as before.

Also removed an unused duplicate role-lookup helper from the
authorization service. No behaviour change from that part; the canonical
role-lookup chokepoint is untouched.
