Bump: patch

**Head coaches no longer see the Scouting visits tile.** Outbound scouting
visits are the scout's work; a head coach had no reason to be looking at them.

The reason this took a proper fix rather than a permission tweak: the visits
tile and the onboarding-pipeline funnel were authorised on the same thing, so
taking one away took the other with it — and the funnel is deliberately part of
a head coach's job, showing the prospects coming into their own age group. The
two now have separate switches. A head coach keeps the funnel exactly as
before, the visits tile disappears from their dashboard, and typing the address
by hand does not get them in either. Scouts, heads of development and academy
admins see no change.
