# A scout on a trialist's panel can now read that trialist (#3928)

A scout's link to a player — a seat on their trial case's panel, or an
entry in the scout's assignment list — was narrowed to players on status
`active`. A player being assessed on trial is on status `trial`, so the
panel-seat route resolved the right player and then filtered them away
again: the scout held no access to the one player they had been appointed
to assess.

A link now survives on `active` and `trial`. `inactive`, `released` and
`graduated` still end it, as does archiving a player or moving them to the
recycle bin — which the status filter did not previously cover at all.
