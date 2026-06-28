# Fix critical error when editing a match activity (#2097)

Bump: patch

Opening any match in the activity edit form raised a WordPress critical
error. The match-length / participation block referenced an `$id` variable
that was never defined in that render method, so a null id was passed to
methods expecting a non-nullable integer and PHP aborted with a TypeError.
The id is now resolved from the loaded activity, and create-mode matches
(which have no id yet) skip the lookups. Editing matches works again.
