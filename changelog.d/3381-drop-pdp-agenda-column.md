# PDP conversations: the retired agenda column is gone (#3381)

Bump: patch

The free-text *Agenda (pre-meeting)* box was replaced by the prep question
sets in v4.118.0, and its text moved into *"Anything else to prepare?"* at
the same time. The column stayed behind for one release as a rollback
courtesy; three releases later nothing has asked for it back, so it is
dropped. `Activator.php` loses it too, so a fresh install and an upgraded
one now have the same table rather than quietly differing.

The drop checks before it commits: any conversation still holding text that
does not appear verbatim in a prep answer on the same conversation stops it,
and the column is kept on that install instead. Losing a coach's write-up is
not recoverable; a column a fresh install does not have is a ticket.
