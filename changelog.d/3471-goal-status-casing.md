# Goal statuses read in Dutch again, whichever casing the row was saved with (#3471)

A goal's status chip showed the English "In Progress" on a Dutch install — on a
player's own goals, on a parent's view of their child's, and on the coach
surfaces, all of which read the same field. The priority chip beside it was
translated, which is what made it look arbitrary.

The `goal_status` lookup is seeded in Title Case, so depending on which path
wrote the goal the column holds either `in_progress` or `In Progress`. The
label resolver matched only the first shape and handed the second straight
back. The goal still landed in the right board column with the right chip
colour, because those two already folded the casing — only the words did not.

Both shapes now resolve to the same label, leading and trailing whitespace from
an import included, and `Pending approval` has a translation instead of
rendering as an English fall-through. Player status got the same treatment, for
the same reason. Nothing is stored differently and no goal changes state.
