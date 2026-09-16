# Saving an activity from the frontend no longer deletes the planned squad (#3451)

Bump: patch

The second door onto the same data loss #3456 closed. Recording attendance
through the activity form's Save — the frontend and REST path, rather than
wp-admin — rewrote the roster rows, and that rewrite began by deleting
every one of them, the coach's planned squad included. The register came
back labelled as recorded attendance and the plan was gone, so Saturday's
selection disappeared behind an ordinary save exactly as it did in
wp-admin.

The rewrite now touches only the recorded half of the table. The planned
squad, its statuses, notes and line-up survive untouched, and the Line-up
card lists each starter once whether the line-up sits on the plan or, on
an install that already lost its plan to the old behaviour, on the
recorded row that replaced it.

Behind that, every write to the attendance table now goes through one
writer that has to be told which kind of row it is writing — a plan or a
register — and a build check makes sure the queries that read the table
say which kind they mean. Both are internal; the reason they are here is
that this was the tenth time the two kinds were confused, and the two that
confused them on the way in destroyed real data.

Rows already deleted on an install cannot be recovered, and inventing a
plan that never existed would be worse than the gap.
