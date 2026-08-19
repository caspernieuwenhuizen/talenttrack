# The Spond connection page for a team opens again instead of erroring (#2559)

Bump: patch

A head coach who opened **Spond connection** from their team's page got the
site's critical-error screen instead of the panel. The page never worked:
it looked up the team id from a place that did not hold it, so it always
asked for team "nothing" and stopped there.

It now reads the team from the address as the other per-team pages do, and
opens on the connection panel for that team. An address without a usable
team id shows the ordinary "no access to this team's Spond connection"
message rather than an error page.
