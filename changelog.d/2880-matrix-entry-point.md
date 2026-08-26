# The access control matrix is reachable from Settings (#2880)

Bump: patch

The permission matrix has been editable from the front end since it shipped, but
nothing anywhere linked to it — the only way in was typing the address by hand.

It now appears in **Settings**, under System, for the academy admins who can
manage it. That was the point of building it: an academy admin should be able to
correct a permission that is too broad or too narrow — the grants that decide
who can open a player's evaluations, notes and medical fields — without needing
a WordPress account.
