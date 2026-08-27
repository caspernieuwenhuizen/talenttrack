# Edit and Archive are icons in record headers (#2871)

Bump: patch

On a player's profile, **Edit** was a full-width button at the far left of
its own row, with the `⋯` menu at the far right of the same row. It is now
a pencil icon sitting next to the `⋯`, which gives the header back the
space that button was using — space that on a phone was pushing the
player's own details below the fold.

**Archive** is icon-only in record headers too. It keeps its bin icon, its
red styling and its confirmation step; only the word goes.

Both keep their names for screen readers and show them on hover, so
nothing is lost by dropping the visible label.
