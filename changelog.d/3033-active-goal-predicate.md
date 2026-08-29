# One definition of an active goal on the player file (#3033)

Bump: patch

The Goals tab of a player's file used to list goals whose own pill read
"Voltooid" under the heading "Actieve doelen", and the number on the tab, the
number in the heading and the goals figure in the at-a-glance panel could all
disagree for the same player — three surfaces had each written their own idea of
what "active" means, and one of them had no status filter at all.

There is now one definition, held in the goals repository and read by all three,
so the numbers cannot drift apart again. The list holds only goals the player is
still working on; achieved and abandoned goals move into a **Completed goals**
section directly beneath it, collapsed by default, so a finished goal stays part
of the player's file instead of vanishing from it.

The tab badge also missed its club filter, which is fixed in the same pass.
