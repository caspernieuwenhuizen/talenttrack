# Players can connect their own Strava (#2153)

Bump: patch

A logged-in player can now connect their own Strava account from their
profile without hitting a "not authorized" error. The player persona was
missing the `strava_integration` matrix entity, so under matrix gating the
self-service connect flow denied the athlete even on their own record. The
authorization seed now grants the player a self-scoped Strava grant
(`rc[self]`, mirroring `my_profile`), and a re-seed migration backfills it on
existing installs. The self scope means a player can only ever manage their
own connection — never another player's. Coach and admin behaviour are
unchanged.
