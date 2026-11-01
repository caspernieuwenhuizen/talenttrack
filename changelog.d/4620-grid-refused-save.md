# Attendance, minutes and ratings grids: a refused save is reported, and read-only teams render read-only (#4620)

The attendance and minutes grids could say "All changes saved" when nothing
had been saved, for instance for a team whose assignment had ended. They now
say how many changes were not saved and why, and your edits stay marked so
nothing is lost. The ratings grid does the same when a player or category is
no longer part of the activity, and asks you to reload the grid.

The grids also stop offering what can't be saved. The team list holds the
teams you can record for, so an assignment that has ended, or hasn't started
yet, no longer puts a team on it. If your role lets you see every team, you
still see them all, but a team you can't change opens read-only: the recorded
values are shown, with no dropdowns, boxes or Save, and a note says why.
