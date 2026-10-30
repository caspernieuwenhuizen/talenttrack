# Match prep's starters tile and minutes footer follow the formation (#4319, #4346)

The "1st-half starters" tile on the match prep screen was drawn once when the
page loaded: picking a 3-2-1 left it at "x/11", and placing six players left
it at "0/6", until a reload. It now follows the pitch: placing or removing a
first-half player, copying the first half to the second, marking a player
unavailable, or picking another formation updates the count and the target
at once, and the tile turns green when every position is filled — the same
value the page shows after a reload. Only players on a position the current
formation draws count: switching a full 4-3-3 to 3-2-1 reads 6/6, not 11/6,
and the players on the other positions come back when you switch back.
The minutes table footer also counts out of the formation's positions
("6 / 6" on a 3-2-1) instead of always "/ 11", and follows a formation
change (#4346).
