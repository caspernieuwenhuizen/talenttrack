# Updates: hourly release check + a "Check for updates" action (#2405)

Bump: patch

TalentTrack now checks for a new release **every hour** instead of every 12
hours, so a fix reaches a pilot site the same morning it ships rather than up
to half a day later. A **Check for updates** action was also added to the
plugin's row on wp-admin → Plugins: it forces a check on the spot and reports
what it found — the version now available, or that the site is already up to
date. The action is limited to users who may update plugins.
