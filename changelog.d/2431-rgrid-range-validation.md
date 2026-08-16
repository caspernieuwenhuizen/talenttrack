# Ratings grid: out-of-range scores are flagged as you type, and can no longer fail silently (#2431)

A score outside the academy's rating scale used to be accepted by the grid,
dropped by the server, and then reported back as saved. The grid cleared its
unsaved markers and announced that all changes were stored, so a coach who
typed 12 on a 5–10 scale had no way to know the score never landed — and
because the rejected value became the new baseline, pressing Save again
wouldn't retry it either.

Scores are now checked against the configured scale as you type. An offending
cell is marked, the line under the grid says what the allowed range is, and
Save stays disabled until it's corrected. Nothing you typed is rewritten
behind your back: an out-of-range score stays on screen for you to fix rather
than being clamped, and a score that misses the scale's step is refused
instead of being quietly rounded to the nearest one.

The bulk ratings endpoint now reports refused cells separately from blank
ones, so a partial save is honest about what it did and didn't write. Valid
cells in the same batch still save, so one bad score can't cost a whole
squad's worth of typing.
