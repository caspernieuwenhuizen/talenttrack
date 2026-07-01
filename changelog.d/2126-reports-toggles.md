# Reports module: on/off toggles for the attendance, minutes-per-team and rate-card reports (#2126)

Bump: patch

The Reports module-settings page now exposes a toggle for all 15 reports, not
just 10. The three attendance reports (team, player, leaderboard), the
minutes-played-per-team report and the rate cards were on the Reports launcher
but had no feature toggle, so an academy could not switch them off. They now
join the per-report catalog like the others: switching one off hides its
launcher tile and rejects a direct `?tt_view=…` link. All five default to on,
so existing installs keep showing them until an admin turns them off. No schema
change — the toggle state already accommodates new catalog keys.
