# The goals on My PDP open now (#3397)

**What you are working on now** on My PDP listed a player's active goals as plain
text. It sits directly under the conversation those goals came out of, and it was
the one place in the product where a goal could not be tapped to read its
description, its target date in context, or the coach's comments on it.

Each card now opens the goal, with a link back to the PDP. The target date also
renders in the site's date format — it was printing the raw stored value, so the
same goal read `2026-05-14` here and `14 mei 2026` one tap away — and goal titles
now go through the same translation layer My goals uses.
