# Strava integration — token refresh service (#2057)

Bump: minor

Keeps connected players' Strava access tokens fresh (epic #2002). Strava
tokens expire after six hours and the refresh token rotates on every refresh,
so a connection is kept alive two ways: a proactive sweep on the workflow
engine's hourly heartbeat refreshes any token nearing expiry, and an on-demand
refresh runs immediately before an activity sync if needed. The rotated
refresh token is always saved atomically with the new access token. If Strava
rejects a refresh (the grant was revoked), the connection is flagged so the
player can reconnect, instead of retrying a dead token forever.
