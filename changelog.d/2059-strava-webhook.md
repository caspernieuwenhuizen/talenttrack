# Strava integration — webhook sync (#2059)

Bump: minor

Wires up live, push-based syncing for the Strava integration (epic #2002).
Instead of polling, TalentTrack registers a single academy-wide webhook
subscription with Strava and reacts to pushes: a new or edited activity is
imported within minutes, a deleted activity is archived, and when an athlete
disconnects from Strava's side their connection is revoked and their imported
activities are archived automatically. The subscription is operator-managed
(create / view / delete), and the validation handshake is answered securely
with a per-install verify token.
