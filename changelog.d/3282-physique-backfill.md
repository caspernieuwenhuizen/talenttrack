# Existing height and weight readings are caught up on the profile (#3282)

The profile height and weight only started following the dated readings
partway through, and by design they only updated when a reading was saved —
so on any existing academy a player measured before that shipped still showed
whatever was typed at signup, and there was nothing on screen to explain why.

Upgrading runs a one-off pass that brings every profile in line with the
readings behind it.

Nothing is blanked or guessed: a player with no usable reading keeps the value
that was already there, an archived reading does not count, and a clearly
mistyped number is refused exactly as it would be on a fresh save.
