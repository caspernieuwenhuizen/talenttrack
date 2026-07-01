# Test results browser: fix empty list caused by a bad age-group column (#2165)

Bump: patch

The Testresultaten browser and `GET /measurement-results` returned no rows
because the underlying query referenced `pl.age_group`, a column that does
not exist on `tt_players` — age group lives on `tt_teams`. The query now
reads age group from the team, so the browser lists every player with a
value for the chosen test and the Leeftijdscategorie filter narrows
correctly. Repository-only change; no schema or UI change.
