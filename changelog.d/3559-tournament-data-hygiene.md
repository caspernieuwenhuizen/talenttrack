# Tournament data hygiene: demo periods and positions, a validated opponent level (#3559)

Four faults in tournament data, each of which a per-player view would have put straight in front of families.

The demo generator wrote its period assignments 1-based while the planner counts from 0, where period 0 is the opening lineup. Every player on every demo install therefore had **zero starts**, one assignment per match sat on a period the fixture does not have, and the full-match count was measured against the wrong periods. Its position codes were its own invention — `OF1`…`OF6`, and `DF` / `MF` / `FW` for what a player can cover — none of which any other surface can read, and squad members left out of a period got no bench row at all. Generated tournaments now use the planner's own codes, run from period 0 to the number of substitution windows, and place every squad member in every period.

The opponent-level pill on a match card now takes its colour from the level itself, as the documentation always said it did, so recolouring a level under Configuration recolours the pill. Its text switches between dark and light to stay readable: the seeded amber is 1.8:1 against white, nowhere near legible, and the colour an operator picks next is not something a fixed ink can be right about.

The level is also checked when it is written. It was a plain text column every write path sanitised and none validated, so an import could store any word and the planner would show it. An unknown level is now refused with a message naming the levels that are allowed; an empty one still clears the field.

Finally, the canonical-values map that drives the lookup-normalisation screen keyed this vocabulary as `opponent_level` while the lookup type is `tournament_opponent_level` — one key to the left of the values it described, so the screen offered none.
