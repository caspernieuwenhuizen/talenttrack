# Player/parent dashboard no longer shows the "Features" tile or a Setup section (#1836)

Bump: patch

Follow-up to #1821. The read-only "Features" (NL "Functies") tile — which lists which parts of TalentTrack are switched on — was registered visible to every persona with no capability or matrix entity, so it appeared for players and parents as the lone tile in a "Setup & administration" section. It's now hidden from the player and parent personas, so that section no longer appears on their dashboard. (The functional-roles tile's gating from #1821 is reverted, as the active authorization matrix already gates it on its entity.)
