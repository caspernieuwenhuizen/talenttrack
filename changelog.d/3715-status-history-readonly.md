# The profile's "history" links open the history (#3715)

The **Status · history** and **Potential · history** links on a player's profile
opened an empty page for any staff member who is not allowed to record behaviour
or potential — a head coach reading a player's file, or anyone at an academy that
has switched both halves off. The screen behind those links refused on the
capture question and returned before it rendered anything, even though the same
person can read the same entries over the API.

It now becomes a read-only history for a viewer who may read that player's file:
the recent behaviour ratings, the current potential band, and the trajectory
behind it, newest first, with no capture form. The line explaining that nothing
is being recorded here stays; a viewer without access to the player still gets
that line and nothing else.
