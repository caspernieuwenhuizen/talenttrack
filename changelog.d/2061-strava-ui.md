# Strava integration — connect panel on the player profile (#2061)

Bump: minor

Adds the player-facing Strava panel (epic #2002): a mobile-first "Connect with
Strava" surface reachable at its own page (`?tt_view=strava`) and as a Strava
tab on the player profile. It shows connection status, a consent checkbox that
must be ticked before connecting, a disconnect button, and the imported
activities (distance, duration, pace — no heart-rate). Connecting sends the
player through Strava's authorization and brings them back to the profile with
a clear confirmation. Fully translated into Dutch.
