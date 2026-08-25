# Team · Minutes distribution: what counts as played, and one squad (#2833)

The report counted a fixture kicking off this evening as already played, so a
team with one played match read "1 of 2 played matches recorded" and carried an
amber warning that a match nobody had kicked off yet was missing its minutes. A
match now counts as played once its activity is completed — or, for academies
with the guided flow switched off, as soon as it carries recorded minutes,
since those are evidence it happened.

The *Matches recorded* tile and the squad beneath it also disagreed: minutes
recorded against a player who has since been archived were counted by the tile
and dropped from the squad, which is how the report could show one recorded
match above zero players and an empty state claiming no minutes existed at all.
Both now describe the same squad.
