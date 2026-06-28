# Strava integration — consent capture + audit (#2060)

Bump: minor

Adds the consent gate for connecting a Strava account (epic #2002, Gate 2).
Connecting now requires an explicit, audit-logged consent acknowledgement,
and the consent is recorded before any redirect to Strava — enforced on the
server, so the authorization step cannot be reached without it. The recorded
consent (and when it was given) is surfaced on the connection status.

Per the product decision of 2026-06-28, consent is captured on the player's
own profile rather than a parent's view — a deliberately simpler flow whose
minor-safeguarding trade-off is recorded for future legal review.
