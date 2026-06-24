# PDP (and team-scoped surfaces) now visible to a player's head coach (#1758)

Bump: patch

A head coach assigned to a team the legacy way could not see their own players' PDP files — the files tab was empty even though the coverage tab counted the PDP, while HoD/admin saw it fine. Cause: the legacy `head_coach_id` backfill (migration 0006) created the `tt_team_people` link but never the `tt_user_role_scopes` team grant that `get_teams_for_coach()` reads, so `coach_owns_player()` returned false. A new idempotent backfill (migration 0171) creates the missing team-scope grant for every team-people link, so legacy and modern assignments converge on the single matrix source of truth. Head coaches now see their team's PDPs (and every other team-scoped surface); HoD/admin visibility is unchanged.
