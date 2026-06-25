# Goal/season intake print no longer leaks archived evaluations (#1860)

The goal/season intake printout pulled a player's evaluation data — the
average rating and the strong/weak category breakdown — without excluding
archived evaluations, so the print could show ratings the player's own
evaluation page hides. All three intake-print evaluation reads now apply the
same `archived_at IS NULL` filter the evaluation page uses, so the printout
matches what's on screen.
