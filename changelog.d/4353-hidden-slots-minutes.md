# Players left behind by a formation switch are no longer counted as starters (#4353)

When a coach switched a match prep from a full 4-3-3 to a 3-2-1, the five
players on the positions that disappeared stayed in the saved line-up so
switching back restores them, and everything outside the prep screen still
treated them as starters: they were credited a half of starting minutes,
put on the pitch in the live match, listed on the team sheet and the
printout, projected as starting in the activity's line-up, and offered for
rating in the evaluation wizard. All of these now read only the positions
the prep's current formation draws; the prep screen still keeps the other
players so a switch back brings them back. The match prep picker's
"on pitch" count follows the same rule. Recorded minutes of completed
matches are not changed; an open prep's line-up projection corrects itself
on its next save.
