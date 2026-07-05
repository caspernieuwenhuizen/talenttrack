# Reliable plugin updates: auto-install + missing-token notice (#2262)

Bump: patch

TalentTrack now installs its own updates automatically once a new release
is detected — no click needed. It also shows a clear admin notice when the
GitHub token is missing from wp-config.php: without a token the update
check runs unauthenticated and GitHub rate-limits it (HTTP 403) after a few
tries, which is why updates sometimes stopped being detected. The notice
explains the one-line fix (`define( 'TT_GITHUB_PAT', 'ghp_…' );`).
