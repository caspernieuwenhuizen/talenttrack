# Academy toggle to switch off the install-on-mobile prompt (#1994)

Bump: patch

Configuration → General gains a **Show the install-on-mobile prompt** toggle.
Players and parents get a post-login banner inviting them to install the app
on their phone; an academy admin can now switch that banner off for everyone in
the academy. It ships on, so existing installs are unchanged. The setting is
per-academy (`club_id`-scoped via `tt_config`), capability-gated, and saved
through the config REST endpoint.
