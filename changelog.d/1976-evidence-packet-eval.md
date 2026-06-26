# PDP evidence packet now includes the player's evaluations (#1976)

Bump: patch

The PDP evidence packet's evaluations query referenced two columns that
don't exist on `tt_evaluations` — `overall_rating` (the real column is
`rating`) and `status_finalized` (no such column anywhere) — so the query
always errored and `evaluations` came back empty for every player. The
query now reads the real `rating` column and treats any non-archived
evaluation in the window as evidence (`archived_at IS NULL`), matching how
the player journey selects evaluations. No schema change.
