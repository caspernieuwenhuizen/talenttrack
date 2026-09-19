# Players and parents can see their own playing time (#3666)

Bump: minor

A player asking how much they had actually played was refused their own
record: both minutes routes are staff surfaces, and no screen showed a
player their minutes at all. The development home now carries a **Playing
time** block — total minutes over the last twelve months, how many matches
they came from, and the three most recent of those with the minutes in
each — and `GET /players/{id}/minutes` answers the same figures over REST
for the player, their linked guardians and the staff who can already see
them.

Absolute minutes only: no share of the team's available minutes and never a
team-mate's figure. The numbers come from the same query the coach's
minutes report reads, so a player's total and their coach's always agree.
**Playing time** joins the sections a player can switch off for a parent in
*My settings → what your parent can see*; the team-wide minutes routes are
unchanged and stay staff-only.
