# Saving an activity in wp-admin no longer deletes the planned squad (#3456)

Bump: patch

The wp-admin activity form rewrites the attendance it records, and until
now that rewrite started by deleting every roster row on the activity —
including the squad a coach had planned. An administrator fixing a title
or a kick-off time destroyed the plan, and the rows that came back were
labelled as a register, so the attendance reports and the completeness
counts then agreed a register had been taken for an activity nobody had
registered.

The form now touches only the recorded half of the table: the planned
squad, its statuses, notes and line-up survive a save untouched, and the
rows the form writes say for themselves that they are recorded
attendance. Correcting a register from wp-admin works exactly as before.

Rows already deleted on an install cannot be recovered — there is nothing
to restore them from, and inventing a plan that never existed would be
worse than the gap.
