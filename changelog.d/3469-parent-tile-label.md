# A parent's dashboard tile no longer prints its label one letter per line (#3469)

The tile on the parent dashboard whose feature carries the "Under development"
badge rendered its label as a vertical column of single characters, and the
resulting card was tall enough to stretch every other tile in the rail with it.
A parent's landing screen was seven near-empty cards deep.

The tile is a single-line flex row. The badge does not wrap, and in the ~220px
grid column the rail uses on a laptop it left the label about one character
wide — at which point the label's own `overflow-wrap: anywhere` did exactly
what it was told and broke at every character.

The row now wraps, so the badge drops beneath the label instead of crushing it,
and the label keeps a minimum readable width. Long child names still wrap at
word boundaries. Tiles without the badge are unchanged, as is the player's own
dashboard.
