Fixed age-banded measurement targets never resolving because the player's age group was read from a non-existent `tt_players.age_group` column; it now resolves via the player's team (`tt_teams.age_group`).

Bump: patch
