# Team blueprint: the player picker shows the position, not raw JSON (#4574)

The team blueprint's roster and its player picker showed a player's position as raw JSON, for example `["RW"] · 17 jaar`. They now show the first preferred position's name in the site's language, for players stored with a list of positions and for older records stored as a comma-separated list alike. A player with no position still shows `-`. The button that clears a filled slot is now at least 24 by 24 pixels with a mouse; it was 16 by 21.
