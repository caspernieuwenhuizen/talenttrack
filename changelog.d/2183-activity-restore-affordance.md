# Archived activity detail page offers Restore, not Archive (#2183)

Bump: patch

Opening an archived activity's detail page now shows a **Restore** action in
the header instead of a second **Archive** button. Restoring returns the
activity to the active list in one click. An archived activity is read-only
until restored — its Edit and match actions stay hidden until it is active
again. The read-only detail now resolves archived rows too, so an archived
activity no longer reads as "not found".
