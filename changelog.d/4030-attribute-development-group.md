# A family no longer reads the academy's forecast for its child (#4030)

A player's chemistry attributes include a **development** group —
Potential, Development forecast, Ceiling estimate — which is the academy's
judgement of how far the child will go. The plugin had already decided that
judgement is staff-only: the status verdict and the potential band are
withheld from the player and the guardian on purpose. The attribute read
never asked. It was gated on "may this person view the player", and a
parent may view their own child, so `GET /players/{id}/attributes` handed
them a ceiling estimate.

The development group is now withheld from any reader who does not hold
player potential on that player. Staff who set it still see it: the head
coach for their own squad, the Head of Development and the academy admin
for any team. The other five groups — physical, technical, tactical,
mental, behaviour — are recorded observations rather than a forecast and
are unchanged.

The group is dropped rather than blanked, in the API response and on the
attributes screen alike. A `null` score would read as "not recorded yet",
and an empty group would still tell the reader that a forecast exists.
The rule lives in one place, so the screen and the payload cannot drift.
