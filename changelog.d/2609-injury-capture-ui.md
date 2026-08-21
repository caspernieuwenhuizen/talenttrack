# Record an injury, and record the return (#2609)

Bump: minor

An injury is one of the transitions a player's journey is meant to carry — trial,
signing, promotion, injury, return to play. TalentTrack has modelled it since the
journey shipped, but there was never a screen to enter one, so in practice an
injury ended up in a free-text note or nowhere at all.

Players now have an **Injuries** tab. A head coach records an injury for their own
squad through a short guided flow — who, what, when — and closes it with **Record
return** when the player is back. Both ends land on the player's journey
automatically, so a coach reading the file next season can see what the player came
back from and how long it took.

A new **Injuries** tile answers the squad-level question: who is out right now,
since when, and who was expected back before today. An expected return that has
passed with nobody recording an actual one is flagged, because that row needs a
decision rather than a nudge.

Injuries stay medical data about minors: every read is audit-logged, entries on the
journey keep their medical visibility level, assistant coaches have no access at
all, and deleting one remains with the head of development and the academy admin.
