# Per-report feature toggles for the Reports module (#1995)

Bump: minor

The Reports module now exposes a feature toggle per report on the Modules &
features screen — the eight standard reports plus the two wp-admin reports
(10 in all) — mirroring the Export module's per-tile toggles. They ship on, so
a fresh upgrade shows every report. Switching one off hides its launcher tile
(frontend launcher + wp-admin Reports page) and rejects a direct link to that
report. The whole-module Reports toggle still works; when off, the ten
sub-toggles disappear. State is per-academy (`tt_feature_state`, `club_id`).
