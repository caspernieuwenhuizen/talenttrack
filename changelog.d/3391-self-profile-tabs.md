# A player can open the tabs on their own profile again (#3391)

A player opening their own profile and clicking any tab — Profile, Player
card, Measurements, Media, PDP, Injuries, Strava — landed on *"Not
authorized"*. So did all five links on the At-a-glance rail. Every route out
of a player's own profile was a dead end.

The profile itself was fine. Since the player, parent and coach views were
unified onto one permission-aware screen, the tab strip had gone on building
its links against the staff Players surface, which a player holds no access
to and never should. A parent got through only because their role happens to
carry that access for their own child, which is why this looked like a
problem with one tab rather than with every link on the page.

Links are now built for whoever is reading. Staff keep the Players route;
a player goes to their own profile, and a parent to their child's. Nobody
gained access to anything — the destinations were always permission-checked,
and the player was simply being sent to the wrong one.

The **player card** had the same dead end, on three screens: your own card on
your profile, on My profile and on My team all clicked through to the staff
surface. A card that already shows the record you are looking at now carries
no click-through at all — which also removes a link that, for staff, only
ever pointed back at the page they were on. Cards in a podium or a list,
where clicking through actually goes somewhere, are unchanged.
