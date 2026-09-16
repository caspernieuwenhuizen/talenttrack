# A match can no longer close with no attendance and no minutes, quietly (#3445)

Bump: patch

Ending a match recomputed attendance and minutes from the match plan plus
the substitution log, and that step could give up without writing anything
and without saying so — the activity still flipped to completed, read
"Completed" everywhere, and nobody went back for it.

The recompute now reports why it stopped instead of answering a bare
`false` that all four of its callers discarded: no execution row, no match
plan, nobody on the availability list, or a failed write. Each one logs a
warning naming the activity and the execution.

The final whistle is still never refused — the match was played, and a
coach on a touchline cannot un-play it. Instead `POST …/finish` answers
`attendance_recorded`, `attendance_rows` and, where there is nothing to
show, an `attendance_gap` block naming the cause and where to fix it. The
post-match screen renders the same warning with a **Record attendance**
button pointing at the attendance grid for that team and date. The notice
is read back out of the match rather than carried in the response, so it
survives a reload and is there for whoever opens the match next, until the
register is recorded.

Two related repairs in the same write path: derived minutes are now written
to the `actual` attendance row, never into a planned (`expected`) one where
the minutes reports cannot see them, and the reconcile sweep no longer
deletes planned rows for players outside the availability list.
