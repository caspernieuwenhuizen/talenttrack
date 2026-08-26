# CI checks that every dashboard tile can actually be opened (#2885)

Bump: patch

A dashboard tile names a destination. Nothing checked that the destination
exists, so a tile could be registered pointing at a screen the product does not
route — and the board would show the feature as done.

A new check fails the build when a tile's screen has neither a route nor its own
link. Tiles that deliberately open something else, like the VCT session designer
opening its wizard, are recognised as such rather than reported.

Developer tooling; nothing in the product changes.
