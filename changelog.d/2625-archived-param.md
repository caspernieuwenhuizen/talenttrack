# One name for the archive filter across every list (#2625)

Bump: patch

The holidays, tournaments, exercises and training-plan lists called their
archive filter `status`, while every other list called it `archived`. Same
control, two names — and `status` already meant something else on the players
and goals lists, where it selects a player's own status (trial, released) or a
goal's bucket (achieved, missed).

All four now use `archived`, and the filter is labelled "Archive" on those
screens, so "Status" consistently means a record's own status. Links you
bookmarked and views you saved before this change keep working; saved views are
migrated automatically the first time the plugin loads.
