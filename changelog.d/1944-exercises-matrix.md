# Authorization: give the exercise library a matrix entity (#1944)

The club-global exercise / drill library now has its own `exercises`
authorization-matrix entity, distinct from the `activities` session calendar. The
previously unmapped `tt_manage_exercises` write capability is bridged through
`LegacyCapMapper`, so the library's REST write paths resolve access from the matrix
once it is active instead of from raw WordPress capabilities. The seed grants
read + create + delete to head coaches, assistant coaches, the Head of Development,
and the Academy Admin — exactly reproducing today's raw cap holders, so no persona
gains or loses access. In particular, assistant coaches keep their library write
access (the `tt_coach` role backs both coach personas). A backfill migration adds
the entity to existing installs.
