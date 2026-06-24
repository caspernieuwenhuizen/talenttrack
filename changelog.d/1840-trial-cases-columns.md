# Fix Unknown-column errors on the trials list and reports (#1840)

Bump: patch

Adds a forward migration that restores the `opened_by` and `overall_rating`
columns on `tt_trial_cases`. Installs that ran the original trial-module
migration before these columns existed were missing them, causing
"Unknown column" database errors on the trials list and the trial reports
(and a blank, unstyled trials page when the failed query halted rendering).
The migration is idempotent and backfills `opened_by` from `created_by`.
