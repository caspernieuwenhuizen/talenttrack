# REST: `GET /me` tells a player or parent which player records are theirs (#3568)

A logged-in player had no way over the REST API to find their own player
record. The list routes are staff surfaces, and `GET /me` didn't exist. A
parent couldn't find their children either. `GET /me` now returns the
player the account is linked to and the account's active children, each
with an id, name, team and status. An account linked to nothing gets a 200
with `reason: "no_linked_player"` instead of an error, so an app can tell the
user their account isn't linked yet. It only ever returns the caller's own
links.
