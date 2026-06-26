# Cohort decision board (read-only) (#1383)

Bump: minor

A new **Cohort decision board** under Analytics gives the Head of Development
one read-only screen for end-of-season decisions. Pick a team or age group and
see one row per active player with their status, rolling rating and trend arrow,
season attendance %, conducted-PDP-talk count, and current PDP verdict (or
"Pending"), each linking straight into the player's PDP file. Columns are
sortable (server-side, works without JavaScript) and the board exports to CSV.
Verdicts stay set in the PDP file — this board never edits them. Cap-gated on
the analytics capability; coaches see only their own teams. Backed by a new
`GET /cohort-board` REST endpoint sharing the same domain service.
