# Custom player fields show "Hidden" outside the staff (#4716)

The club's own player fields now follow one registry entry everywhere. The player record over REST leaves them out for a reader who does not work with the player, as before, and now asks that entry directly. The player card and the wp-admin player record show the label of every active field with "Hidden" in place of its value for such a reader, such as the read-only observer, whether the field is filled or not. Staff see the values as before.
