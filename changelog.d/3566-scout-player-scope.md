# A scout on a trial panel can read the case and submit their assessment (#3566)

A scout assigned to a trial case's panel can now open that case and submit their assessment. Their permissions were written for this all along, but the authorization layer only recognised two ways of being linked to a player — being the player, or being their parent — so every one of a scout's player-level permissions quietly resolved to "no". A scout is now linked to a player through an active panel seat or through their own assignment list.

The case list a scout sees is limited to the panels they actually sit on, and a scout still sees only their own input until the head of development releases the panel's. Being taken off a panel, or a player being released, ends the access straight away.
