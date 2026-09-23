# A team's player count is its active squad (#4025)

`player_count` on `GET /teams` and `GET /teams/{id}` counted every
non-archived player on the team, so trialists, inactive, released and
graduated players all counted toward it. One demo team read `player_count: 21`
while its training roster, its monthly team report and its evaluation coverage
all said 17 — the same disagreement a coach sees between the team card and the
register they take.

The count is now active-only, with binned rows excluded too, which is what
every other squad count in the plugin already means. Trialists are not dropped
from view: a new `trial_count` sits beside it, matching the Trial players card
the team detail already renders. The season summary's *Active players* tile and
its per-team *Players* column, which counted the same way, follow the same rule.

A training plan's suggested `squad_size` is deliberately a different number —
the turnout to plan for, read from recent attendance — and the REST reference
now says so, since reading it as a roster size is what made three honest
numbers look contradictory.
