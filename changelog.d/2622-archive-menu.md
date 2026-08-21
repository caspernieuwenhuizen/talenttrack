# The archive filter folds into one button, and stops claiming to show everything (#2622)

Bump: patch

Every list spent a row of buttons on a filter almost nobody touches: Alle,
Actief, Gearchiveerd. It has collapsed into a single **⋯** button at the end of
the filter row, on phone and desktop alike.

Two things were wrong beyond the wasted space. **Alle did nothing** — it
returned exactly the same rows as Actief, because a list with no filter has
always shown active records only. And it was the option highlighted when you
arrived, so the screen said you were looking at everything while showing you
active records. Alle is gone; the lists open on Actief and say so.

Switching to archived records still announces itself clearly: the ⋯ button turns
yellow and a label appears beside it naming the state, with a ✕ to go back. An
archived list can't be mistaken for an empty one.

Applies to players, teams, people, evaluations, goals, tournaments, holidays,
activities, exercises, training plans and PDP coverage. The Goals list's
Actief / Behaald / Gemist buttons are a different filter and are unchanged.
