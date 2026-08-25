# The match analysis shows the goals that made the result (#2860)

Bump: patch

The analysis readback and the match-execution log describe the same game
and were not on speaking terms. The readback showed how the match ended and
how each team function was rated, but never when the goals came or who
scored them — so the one thing that explains a defending-phase rating lived
on a different screen.

Between the overall read and the phase tiles, the page now lists the goals
in the order they happened: minute, scorer, and the assist where one was
recorded. Minutes run straight through both halves, so a second-half goal
reads as 52' rather than restarting at 22' and the list shows the shape of
the game rather than two separate clocks. They sit above the phases
deliberately — three conceded inside ten minutes is context for the rating
underneath.

Our goals show the scorer; one logged without a scorer reads *Scorer not
recorded*, and an *Own goal* says so instead of borrowing somebody's name.
The opponent's are timed marks with no scorer, since their squad isn't in
the system.

The list is read-only — goals are logged and corrected on the
match-execution screen — and a match with no logged goals renders no goal
section at all rather than an empty list claiming nobody scored.

The same rows are on `GET /activities/{id}/analysis` under `goals`, so the
share page, the print sheet and any other consumer read one list.
