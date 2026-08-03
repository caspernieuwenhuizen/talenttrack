# Attendance reports: type filter + at-risk drill-down + season-pill state (#2351)

Bump: patch

The team and player attendance reports now surface the silently-seeded
season-default window as an active **This season** pill instead of reading
"Custom range", so the filter bar reflects the window you're actually looking
at on first open. When a coach only sees the teams they're assigned to, the
empty-state message now says the report is limited to those teams, so an empty
window no longer reads as "the academy has no data". On the player report the
inline at-risk ⚠ badge — and each name in the At-risk players panel — is now a
link that drills to the player's missed-activities list (this player, the
report's team, the report's window), matching the existing Activities-count
drill-down and carrying a back hint to the report.
