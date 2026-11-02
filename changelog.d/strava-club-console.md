# Strava club console gets its own matrix entity

The Strava club console (the connections list, the webhook subscription,
the app credentials and the Strava configuration screen) is configuration,
so it now has its own authorization matrix entity, `strava_operator_console`.
By default the academy admin holds it in full and the head of development
reads it club-wide. A head coach reads it for their own teams: the
connections list shows only those teams' players, while the webhook
subscription status and changing the app credentials or the webhook stay
club-wide. A player's own Strava connection
stays on `strava_integration`: players still connect and disconnect their own
account from their profile. Existing installs get the new rows through a
migration that only adds missing rows and leaves edited ones alone.
