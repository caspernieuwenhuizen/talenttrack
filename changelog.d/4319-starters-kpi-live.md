# Match prep's starters tile updates as you build the line-up (#4319)

The "1st-half starters" tile on the match prep screen was drawn once when the
page loaded: picking a 3-2-1 left it at "x/11", and placing six players left
it at "0/6", until a reload. It now follows the pitch: placing or removing a
first-half player, copying the first half to the second, marking a player
unavailable, or picking another formation updates the count and the target
at once, and the tile turns green when every position is filled — the same
value the page shows after a reload.
