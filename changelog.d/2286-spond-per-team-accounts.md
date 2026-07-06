# Spond: per-team accounts that override the club login (#2286)
Bump: minor

A team can now sync with its own Spond account instead of the club-wide one.
Each team on the Spond page shows which account it uses ("Uses club account" or
"Own account: <email>"); expand its Account panel to set a per-team email +
password, which overrules the club login for that team's syncs. Leave the email
blank (or hit "Use club account") to fall back to the club account. Per-team
passwords are encrypted at rest and each team keeps its own cached token; the
resolution (`CredentialsManager::forTeam`) is the single seam the sync,
preview and monitor all use.
