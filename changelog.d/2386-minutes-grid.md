# Minutes entry grid — a desktop, spreadsheet-style companion to the attendance grid (#2386)

Bump: minor

A new desktop **Minutes grid** (`?tt_view=minutes-grid`, reachable from the
Attendance/Minutes toggle on the grid surface) records match minutes for a
whole period at once: players down the rows, matches across the columns, a
minutes box per squad cell, one Save for the lot. It's the sibling of the
attendance grid (epic #2381), restricted to match activities.

Only players in a match's squad are editable; non-squad cells are hatched and
informational, mirroring the Minutes-audit matrix. Each edit is routed through
the same minutes-ownership arbiter the per-match editor uses — a match run
through match-execution keeps your figure as an override that survives a
recompute, while a paper match writes the minutes directly — so the grid, the
Minutes-audit tool, and the Minutes-played report always reconcile.

Gated on the `tt_edit_activities` capability and a new **Minutes grid** feature
toggle (on by default; switch it off to hide the grid and block its route; the
per-match minutes editor stays available). Also exposed over REST
(`GET /activities/minutes-grid`, `POST /minutes/bulk`).
