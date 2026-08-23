# Exports read in Dutch, values as well as headers (#2012)

Bump: patch

The column titles were
already translated; the cells under them were not, so a squad list opened in
Excel showed *Status* over `active`, *Rol* over `coach`, and
`["CB","LB"]` where the player's profile says *Centrale verdediger /
Linksback*. Since the export is usually the file that leaves the academy — to a
parent, a federation desk or the board — that was the most visible place for a
raw database code to surface.

Players list, team roster and season stats, attendance register, staff directory
and the goals export all now carry the same labels as the screen. A position
code an academy added itself, with no label yet, still shows as the code rather
than disappearing. The demo-data round-trip, the full backup and the
subject-access export keep their raw values on purpose — something reads those
back.
