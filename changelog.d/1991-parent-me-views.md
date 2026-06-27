# Parents can open their child's development me-views (#1991)

Bump: patch

A parent linked to a player but with no own player record was denied every
"Mijn …" me-view ("This section is only available for users linked to a
player record"). The dispatch gate checked "is the current user a player"
instead of "can the current user view this player".

The gate now authorizes the resolved target via
`AuthorizationService::canViewPlayer`, and the subject resolution falls back
to the parent's linked child (from `tt_player_parents`) when no explicit
`?player_id` is present: a single-child parent auto-resolves to that child;
a multi-child parent gets a child picker first and chooses. A user with no
own player and no linked child is still denied, and there is no cross-family
or cross-academy leakage (every read still passes `canViewPlayer`). The same
authority backs `GET /players/{id}`, so a non-WordPress front end gets the
same answer.
