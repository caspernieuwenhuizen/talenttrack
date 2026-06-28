# PDP list: one player-centric list with Active/Archived + team gate (#2040)

Bump: patch

The PDP tile now opens on a single player-centric list — the old Coverage /
Files tab split is gone. Archived PDP files moved into the same list behind
**Active / Archived** state pills (for operators who can unarchive or delete),
each archived row keeping its Restore / permanent-delete actions. Users who
span more than one team (or have global scope) now pick a team first ("Select
a team to see its players.") instead of facing an unscoped all-players list; a
single-team coach goes straight to their roster. The redundant per-row Open
button stays gone (#2039). The `pdp-files/coverage` REST endpoint gained an
`archived` parameter for the new view.
