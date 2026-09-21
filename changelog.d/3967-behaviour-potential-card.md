# Behaviour & potential card on the player profile; head coaches set potential for their squads (#3967)

Bump: minor

A player's profile now has a **Behaviour & potential** card beside Identity. It shows the latest behaviour rating with who gave it and when, the 90-day average the traffic light reads, the current potential band with how long ago it was set and whether it is due a look, and the **Log behaviour** / **Set potential** buttons for whoever may record them. Where nothing is recorded yet the card says so instead of showing an empty box. It replaces the Potential row on the Identity card and is shown to staff only.

Head coaches can now set potential for the players on their own squads; before, only the Head of Development and admins could, so a coach never saw the option. They are refused on any other squad's player, and assistant coaches still cannot set it. Existing installs get the new grant on update. Head coaches also start receiving the *Potential not revisited* reminder for their own teams, because they can now clear it.

The Behaviour & potential screen now checks each player before it saves, the same way the API already did, so it can no longer record against a player the viewer may not edit.
