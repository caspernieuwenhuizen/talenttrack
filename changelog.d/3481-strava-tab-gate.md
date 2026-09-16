# The Strava tab is now shown only to people the academy grants it to (#3481)

The Strava tab on a player's profile was added without a permission check of any
kind, so every reader saw it — including a parent, who was then offered the
consent checkbox and the "Connect Strava" button for their child's account.

Nothing could actually be connected that way: the endpoint behind the button
has always refused anyone who is neither the player nor a staff member with
edit rights. But offering a control that cannot work, on a question as
consequential as sharing a minor's fitness data with the academy, is its own
problem.

The tab now asks the same question every tab beside it asks. A player keeps it
for their own profile and a coach for their squad; a parent no longer sees it,
and a test pins the endpoint's refusal so the boundary does not rest on the
tab being hidden.

A guardian giving consent on behalf of a minor may well be the right thing to
build. If it is, it needs a deliberate grant rather than a missing check.
