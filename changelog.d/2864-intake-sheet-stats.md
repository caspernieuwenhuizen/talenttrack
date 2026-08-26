# The printed goal-intake sheet counts matches and minutes correctly (#2864)

Bump: patch

The season-intake sheet a coach prints before a goals conversation was showing
numbers that could not both be true — one sheet read 36 matches and 140 minutes,
another 35 matches and 300 minutes, which is under nine minutes a match for a
regular starter.

It was counting every kind of activity as a match, including trainings and
meetings, and counting deleted, cancelled and not-yet-played fixtures along with
the real ones. The minutes were summing planned line-ups next to minutes
actually played. Where a figure looked plausible, it was a coincidence rather
than a sign that half of it was sound.

Both figures now come from the same place the minutes reports read, so the sheet
and **Player · Minutes played** describe the same season. The average rating on
the sheet also stops counting evaluations that were moved to the recycle bin,
which the evaluations list already hid.

This is the sheet that goes on the table at the start of a season-goals
conversation with a player and their parents. Numbers that contradict each other
undermine that conversation before it starts.
