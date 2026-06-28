# Strava integration — OAuth connect flow (#2056)

Bump: minor

Adds the per-player Strava account connection flow (epic #2002). Players (and
coaches/admins acting on a player) can start a one-time OAuth authorization
that links a Strava account to the player's TalentTrack record. The OAuth
callback authenticates via a signed, time-limited `state` — the one route
that can't use a WordPress nonce — exchanges the code for tokens server-side,
and stores the access + rotating refresh token encrypted at rest, per player.
Disconnecting revokes the grant at Strava and clears the stored tokens.

No activities sync yet — this slice is the connection plumbing; the token
refresh, webhook, and ingest slices follow. Access tokens are never exposed
to the browser; the Strava app client secret is write-only.
