# Guest visits keep the position and note they were added with (#4037)

Adding a guest who already has a player record — a trialist, or a player
borrowed from another team — stored the visit's position and note as empty,
even though the request carried them and the response came back 200. The
coach's observation of the trial visit was lost with nothing said. Both
fields are now stored whatever the link state, which is what the notes
input on the activity's Guests list and `PATCH /attendance/{id}` have
always assumed. The wp-admin guest panel shows them for linked guests too.

Name and age are unchanged: they stay anonymous-only, because for a linked
guest the player record owns them.
