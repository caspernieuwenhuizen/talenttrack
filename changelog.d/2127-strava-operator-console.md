# Strava operator console in Configuration → Integrations (#2127)

Bump: minor

Adds a Strava integration tile to Configuration → Integrations, next to Spond,
opening an operator console (`?tt_view=strava-admin`) where an academy admin
registers the Strava app Client ID + secret, creates or deletes the club-wide
webhook subscription, and sees every player who has connected — their status,
imported-activity count, last activity and last sync. Previously these were
only reachable over the REST API with no UI.

The operator surface is now matrix-gated instead of `manage_options`: viewing
follows the new `tt_view_strava` capability and credential / webhook changes
follow `tt_edit_strava_credentials`, both bridged to the `strava_integration`
matrix entity and tunable per persona. A new `GET /strava/connections` endpoint
backs the roster and never returns tokens or the client secret. A top-up
migration seeds the entity on already-installed sites so admins and heads of
development keep access on upgrade.
