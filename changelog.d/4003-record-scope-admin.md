# Admin pages and bulk actions check each record they act on (#4003)

On several wp-admin pages one branch resolved the record and its twin did not:
the save path checked and the delete path did not, or the create path checked
and the status change did not. Every capability involved is club-wide, so it
answers what kind of record the caller manages and never which ones.

Deleting a player or a team now asks what saving one asks. The activities page
asks about the activity's team on render, save and delete, and about the team
an activity is being written for. Unassigning a staff member asks about the
team on the assignment row rather than the team named in the form. Permanently
deleting a PDP file asks whether the caller may see that player's file at all.
A Spond refresh asks whether the caller writes that squad's activities.
Pausing, resuming, archiving or permanently deleting a scheduled report asks
what creating it asked about the same team or player.

A bulk action filters the selection row by row before it dispatches, so a batch
mixing your own rows with someone else's acts on yours and leaves theirs alone,
and the notice counts what actually changed. A batch with nothing reachable in
it says so instead of reporting zero items archived.

Also deleted: the eight dead page classes under `includes/Admin/`. They
registered a second `admin_post_tt_save_player` / `tt_delete_player` and
similar with no capability check and no nonce, and were unreachable only
because Composer maps `TT\` to `src/`.
