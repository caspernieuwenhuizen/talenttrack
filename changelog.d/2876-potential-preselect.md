# Set potential opens on the band the player already has (#2876)

Bump: patch

The **Set potential** control on a player's profile opened blank every time,
whatever the academy had on record. It was reading the band from the player row,
where it has never been stored — potential is kept as dated history — and a
missing value there fails quietly, so the control simply showed nothing and
nobody was told why.

Blank is not a harmless starting point for this particular judgement. A coach
who cannot see what the academy currently thinks records a fresh opinion instead
of a revision, and because every save adds a dated entry, a player's potential
history filled up with restatements that read like changes of mind.

The control now opens on the current band and says when it was set and by whom,
so a coach can tell whether the standing judgement is recent enough to be worth
revisiting. Choosing the same band again and saving no longer adds anything to
the history — though re-affirming a band *with* a note still does, because
"still first team, but the last six weeks have been flat" is worth recording.
