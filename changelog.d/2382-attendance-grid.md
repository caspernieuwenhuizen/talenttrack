# Attendance entry grid — a desktop, spreadsheet-style alternative to the wizard (#2382)

Bump: minor

A new desktop **Attendance grid** (`?tt_view=attendance-grid`, reachable from
the Activities screen) lets a coach record attendance for a whole period at
once, the way an Excel register works: players down the rows, activities
(training + matches) across the columns, one dropdown per cell, one Save for
the lot. It is the power-entry alternative to the step-by-step
mark-attendance wizard (epic #2381) — the wizard stays the mobile/pitch path.

Columns come from the team's active roster, so a brand-new activity still
shows every player; a per-column "all present" fills a whole session in one
click; the full five statuses (present / late / absent / excused / injured)
are all available, shown as an abbreviation in the cell and the full word in
the dropdown. Edits are tracked and written in one batch, and the grid
reads/writes the same recorded attendance the reports and the wizard use, so
everything reconciles.

Gated on the `tt_edit_activities` capability and a new **Attendance grid**
feature toggle (on by default; switch it off to hide the grid and block its
route). Also exposed over REST (`GET /activities/attendance-grid`,
`POST /attendance/bulk`) for a future non-WordPress front end.
