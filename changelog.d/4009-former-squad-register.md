# Last season's register survives the age-group move (#4009)

Looking back at a window from before a squad moved up, the attendance grid
listed the team's players as they are *today* and the recorded marks of the
players who were actually there — two different sets of players once the
summer conveyor has run, so every row rendered empty and the whole season's
register was unreachable. The team's activity list had the mirror image: one
card could read "recorded 20 / 20, complete" next to 0% present.

The grid now lists both: the current roster, plus everybody who carries a
recorded mark in the window, greyed and under the roster with the team they
moved to beside their name. Those rows are read-only — attendance can only be
recorded for a player in the squad, and the write would have been refused
silently, so the grid says so instead. A note under the grid explains it.

The activity list's counts (recorded, present, present %) and the
complete / partial / none filter now count the register as it was recorded
rather than the players who happen to be on the team today.
