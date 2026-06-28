# Team profile: squad panel no longer shows archived or trashed players (#2065)

Bump: patch

Archiving a player removed them from active rosters everywhere except the
team profile's squad panel (and, for trial players, the trials sub-panel),
where they kept appearing — an archived or released minor resurfacing in a
roster a coach was browsing. The three player-fetch helpers behind those
panels (`QueryHelpers::get_players()`, `QueryHelpers::get_players_for_teams()`,
and the team-detail trial loader) filtered on `status` alone, which is
orthogonal to the archive/trash lifecycle introduced with the recycle bin.
They now append the canonical active-lifecycle clause
(`ArchiveRepository::filterClause('active', 'p')`), so archived and trashed
players drop out of the squad panel, the trials sub-panel, and coach-dashboard
rosters immediately. Active players are unaffected. Query-layer fix only — no
schema or data changes.
