# 8-a-side and 6-a-side teams line up on their own shape (#3574)

An 8v8 team's match line-up was drawn on an eleven-a-side 4-3-3 pitch: eight
players in the first eight slots and three attacking slots left empty. The
prep screen, the printed prep, the team sheet and the live match sheet each
resolved the formation their own way, and none of them knew the small-sided
shapes. They now resolve it one way:

1. The formation you picked, drawn on its own slots.
2. Otherwise, the team's football form: 3-3-1 for 8v8, 3-2-1 for 6v6, 4-3-3 for 11v11.

The four small-sided formations (3-3-1 and 3-2-2 with eight slots and a keeper; 3-2-1 and 2-3-1 with six, no keeper) now draw correctly everywhere.

The demo generator also binds each match to a formation for the team's form,
puts a goalkeeper in goal where the squad has one, and uses the age group's
configured half length instead of a fixed 35 minutes.
