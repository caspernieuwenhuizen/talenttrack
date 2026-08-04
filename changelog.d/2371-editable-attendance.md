# Edit attendance on a completed activity, and see the roster without opening Edit (#2371)

Bump: patch

The activity detail page now has a collapsible **Show roster** list under the
attendance breakdown — every registered player with their status (guests
tagged), so you can see who had which attendance without opening the edit
form.

A completed **training** now also carries an **editable attendance table** on
its edit form: correct a missed or wrong status (and note) per player and hit
Update activity. This restores the flat-form path as the fallback for when the
guided wizards are switched off — previously, with wizards disabled, recorded
attendance could not be corrected at all. Reuses the existing recorded-
attendance write path (no new write logic); match-type activities keep their
minutes-aware completion flow. The completed-activity wording no longer implies
attendance is still to be captured.
