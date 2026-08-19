# Configuration: export the academy's settings and module state as JSON (#2540)

Bump: minor

**Configuration → Export configuration** downloads this academy's whole
configuration as one JSON file: every setting from `tt_config`, the
install-level values from `wp_options`, and — the part that had no surface
before — which modules and features are switched on or off.

Each module and feature entry carries its human label and the `?tt_view=`
screens it owns, so the file answers "which surfaces does this install
actually have?" rather than just listing class names and booleans. That is
the question worth asking before writing training material for an academy:
a module that is off takes its screens with it.

Integration credentials stored in `tt_config` — the Strava app secret, the
Spond password and token, the DeepL API key, the Google service account —
are replaced with `[redacted]` and collected under `redacted_keys`. The key
name is kept so you can still see that an integration is configured; the
value never leaves the server. No player data is included.

Also available through the API at
`GET /wp-json/talenttrack/v1/exports/config_json?format=json`, gated on
`tt_edit_settings` and recorded in the audit log. Export only for now —
there is no importer yet.
