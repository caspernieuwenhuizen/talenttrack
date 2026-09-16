# "My evaluations" opens on this season and loads fast (#3478)

A player's evaluations page sent every evaluation they had ever been given,
each with its full category-by-category breakdown hidden inside the page. For
one child with 208 evaluations that was 2.5 MB of HTML, with more than four
thousand rating rows nobody had asked to see — on the page players are most
likely to open on a phone after training.

The page now opens on the current season. A link above the list says how many
evaluations earlier seasons hold and shows them all in one tap; nothing has
been cut from a player's history. The breakdown under each evaluation is
fetched when the row is opened instead of being shipped in advance, and says so
if it cannot load.

The same list is available to other front ends at
`GET /players/{id}/evaluations`, returning what the player and their parents see
— never the coach's private notes — and the per-row breakdown at
`GET /players/{id}/evaluations/{evaluation_id}/detail`. A parent whose child has
chosen to keep evaluations private gets nothing from either.
