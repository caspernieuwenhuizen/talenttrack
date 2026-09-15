# The KPI snapshot now carries the potential band (#3414)

An audit of all twenty exporters found that none of them mentioned potential
— the product's only recorded answer to *where is this player going* — and
the KPI snapshot is the one artefact an academy reviews about every player at
once.

The snapshot gains a second sheet listing every active player with their
current band and the date it was recorded, and two headline metrics saying how
many active players have a band and how many do not. The band is read through
the same accessor the status dot uses, so the sheet and the player's profile
cannot disagree. A player nobody has assessed gets an empty cell rather than a
default; on an academy that has not run a potential round the column is
visibly sparse, which is the honest picture.

The Players list export is deliberately untouched. A potential band is a staff
judgement about a minor, and that export is a roster and contact sheet meant to
be mailed around.
