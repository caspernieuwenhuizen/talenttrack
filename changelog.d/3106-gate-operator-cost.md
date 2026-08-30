# Channels and integrations now check the plan before they spend (#3106)

Bump: minor

Six features that cost money on every use were still open on every install:
the SMS channel, push notifications, the four daily scheduled nudges, reading
a photographed plan, Spond sync and Strava connect. Each now refuses at the
narrowest point its module has — the SMS channel is not even offered in the
channel picker, and push quietly falls through to email so the notification
still arrives.

**Nothing already imported is touched.** Spond fixtures and Strava activities
stay exactly where they are, readable and exportable, and start working again
if the plan changes.

Two of these run in the background where nobody is watching, so their refusal
is written down rather than shown: a skipped scheduled send appears against
each nudge in the message log's health record, and a refused Spond sync
appears in that team's sync history. Both name the plan, so "the nudges
stopped" has an answer on the screen where you would look for it.

Object-storage backup keeps its place on the to-do list rather than gaining a
gate: there is no such destination to gate yet, and the gate ships with it.
