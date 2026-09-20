# Match sheet score follows a goal corrected away in the minutes grid (#3705)

The score on a match run from the live sheet is a reading of its goal log,
and every live route re-derives it after touching a goal. The minutes grid
did not: counting a player's goals down through the grid reversed the goal
but left the stored score where it was, so the match sheet could read 1–0
over an empty goal list. It now re-derives once per execution a correction
touched — a player counted down by three goals is one recount, and a
correction that only undoes typed entries still leaves the score alone,
because those belong to no match sheet.
