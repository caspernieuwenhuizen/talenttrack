# Minutes grid becomes Minutes + statistics (#3094)

Bump: minor

Goals and assists could only ever be recorded by running a match on the live
match sheet. A club that does its admin on a Sunday evening rather than with
a stopwatch on the touchline therefore had players whose minutes were
complete and whose output was permanently blank — on the player record, in
the reports, and in everything built on top of them.

The minutes grid now has a **G** and an **A** box beside every **Min** box,
and is called **Minutes + statistics**. Tab runs Min → G → A → next match,
the way the spreadsheet this grid imitates behaves. The Goals and Assists
columns switch off from a chip above the table when a coach only wants
minutes, and that choice is remembered per person.

Two things a manually recorded goal deliberately does not carry. It has **no
minute** — the coach does not know it, and a fabricated 34th minute would
flow into the match timeline as though somebody had watched it happen. And
it **never touches the scoreline**: the score is what happened, attribution
is what we know about it, and letting the second rewrite the first is how
the two came to disagree in the first place. A new footer row reconciles
them instead, reading `2/3` where a goal has no scorer against anyone's name
— information, not a validation gate.

An assist attaches to a goal that is already there rather than inserting one
of its own, which would inflate the team's score. Where no goal is free it
records a goal with no scorer, the honest version of "somebody finished his
pass and I can't remember who". Correcting a count downwards reverses rather
than deletes, and undoes typed entries before live-recorded ones, so a
correction can never destroy something that was actually observed.

Goals recorded this way count exactly like live ones everywhere — the same
store, read by activity rather than through a match execution.
