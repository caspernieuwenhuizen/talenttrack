# Activities are completable again when the guided wizard is off (#2401, #2407)

Switching the guided attendance/evaluation wizard off left an activity with no
way forward: the **Complete activity** button vanished from the activity page,
its card in the list and the edit form, and nothing on the remaining path ever
marked an activity completed.

Both halves are fixed. With the wizard off, the completion button stays and now
reads **Mark attendance**, opening the desktop attendance grid on that
activity's own column; the dashboard's **Mark attendance** hero goes there too
instead of dropping the coach on an unfiltered activities list. A new **Mark
completed** action on a planned activity flips its status, so a wizard-off
academy no longer accumulates activities stuck at "planned" — which had been
quietly distorting the attention and up-next groupings. Recording attendance in
the grid deliberately does not auto-complete anything, because one grid save can
span weeks of sessions.

With the wizard switched on, nothing changes.
