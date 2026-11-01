# Attendance, minutes and ratings grids: a refused save is reported, and read-only teams render read-only (#4620)

The three grids could say "All changes saved" when the server had skipped the
changes: the bulk endpoints answer `skipped: N` for a team the user may not
write, and the grids only read `rejected`. A skipped change now fails the save:
the save bar says how many changes were not saved, and every edit stays dirty
so a second Save is possible. A blank ratings cell, which is skipped by design,
still counts as saved.

The grids also stop offering what the save would refuse. The team picker lists
the teams the save writes for (`ActivityAccess::registerTeamIds()` for
attendance, `writableTeamIds()` for minutes), so an ended or future assignment
no longer lists its team. A club-wide reader still sees every team, but a team
they cannot write renders read-only: no dropdowns or boxes, no Save, and a note
saying why. The ratings grid does the same for an activity whose team the
reader cannot write.
