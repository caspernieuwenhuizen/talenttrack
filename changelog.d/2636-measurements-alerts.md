# Alerts: a new Measurements alert (#2636)

Bump: minor

This release adds one alert about the testing battery. It is switched on from
the moment you update, for everyone who can act on it, so here is exactly
what you will start seeing:

- **No measurement this season** — a player has nothing recorded in the
  current season's testing battery. Goes to the head coach of their team,
  from 60 days into the season. Growth data is the only part of a player's
  record that is not somebody's opinion, and a season with no measurement
  leaves a permanent hole in the curve: you cannot fill it later, because the
  player has already grown.

The question is "this season", not "recently": a measurement taken before the
current season started does not count, because the academy's testing battery
runs on a season rhythm. The current season is the one marked as current in
your season settings; if none is marked, the alert stays quiet.

The threshold is an academy setting, `alerts_measurement_grace_days`. In week
one of a season this alert would fire for every player in the academy at once,
which is indistinguishable from saying nothing.

You only receive it if you already have access to measurements — the alert
names a player and says what is missing from their record, so it is gated the
same way the measurement screens are.

This is the fourth instalment filling out the alert catalogue.
