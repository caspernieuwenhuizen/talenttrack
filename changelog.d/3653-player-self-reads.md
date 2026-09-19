# A player can read their own goals and journey over the API (#3653)

Bump: patch

The API refused data the player's own screens were already showing. `My goals`
and `My journey` rendered fine, but `GET players/{id}/timeline` and
`GET players/{id}/transitions` carried `tt_view_players` — a staff capability —
on the route, so a player asking for their own journey was turned away before
the per-player rules ever ran. Those handlers have always checked access per
player, the child's sharing preference and the per-entry visibility, so the
routes now ask only for a logged-in caller and let the handlers decide, exactly
as the rating-trend route already did.

Goals needed a route rather than a looser gate. `GET goals` is a staff
collection: a player holds no goals capability and got a 403, while a guardian
passed the check and got an empty list, because the collection narrows every
non-global reader to the teams they coach. That narrowing is what keeps the
collection safe, so it is untouched. Alongside it there is now
`GET players/{id}/goals`, the player-facing read — one player's board, the same
rows and the same order `My goals` renders — gated per player the way
`players/{id}/evaluations` is: own record, linked guardian, team or global
staff, and a `section_private` refusal when a player has kept their goals from
a guardian. Its links point at the player's own `my-goals` screen rather than
the staff list a player cannot open.

Nothing changes on a screen; this is the API catching up with what the screens
already do, so a front end outside WordPress can draw them.
