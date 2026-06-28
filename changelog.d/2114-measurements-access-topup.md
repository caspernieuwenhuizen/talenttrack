# Measurements: restore admin & coach access on upgraded installs (#2114)

Bump: patch

On sites upgraded from before the Measurements module shipped, academy
admins, heads of development and coaches were silently denied access to
**Record measurements** and **Testing coverage** — the dashboard tile
appeared but the screen reported "no permission". The authorization rows
for the module were added to the seed but never back-filled into existing
installs (the matrix reseed is manual and destructive). A new idempotent
migration adds the missing `measurements` / `measurement_sessions` /
`measurement_definitions` matrix rows, leaving any operator edits intact.
The two staff tiles now gate on the same matrix entity the views enforce,
so a tile can no longer appear for someone the screen will refuse.
