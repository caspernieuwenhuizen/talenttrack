# A new alert when nobody has revisited a player's potential (#3225)

Bump: minor

Potential is meant to be a quarterly judgement, and nothing in the product ever
said so. A band set at intake and never revised stayed on the record looking
current, while still counting toward the player's traffic-light status, their
team-chemistry score and their development plan — an out-of-date number quietly
shaping decisions nobody was re-examining.

**Potential not revisited** now appears when a player's potential has gone two
quarters without being set or confirmed, or has never been set at all. The clock
starts at whichever is later, the last entry or the day the player joined, so a
player who signed three weeks ago is not overdue and a player nobody has ever
assessed is covered rather than invisible.

It goes to the people who can act on it — the head of development and the club
admin by default, plus any head coach your academy has granted that right. A
head coach who can only read potential is not told, because there would be
nothing they could do. Trial players are left out.

Like every alert, it clears itself: set the potential and it is gone on the next
pass, with nothing to dismiss. The window is
`alerts_potential_stale_days`, 180 days by default.
