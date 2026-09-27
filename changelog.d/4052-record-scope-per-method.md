# Deleting a player through the API asks about that player (#4052)

`DELETE /players/{id}` asked only whether the caller could edit players at all, a right that holds across the whole academy, so someone allowed to edit one team's players could archive any player. It now asks the same question as the wp-admin delete: may this user edit this player. The record-scope CI gate now judges each method on a route separately, which is how this was found.
