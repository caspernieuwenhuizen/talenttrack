# Authorization: route remaining blueprint + player-potential caps through the matrix (#1939)

The Team-blueprint creation wizard and the blueprint comment thread now
resolve access through the `team_chemistry` matrix entity (via
`TeamChemistryAccess`) instead of the raw `tt_*_team_chemistry`
capabilities, completing the #1922 consolidation so the whole blueprint
feature answers from one source. The PlayerStatus "set potential band"
act-cap (`tt_set_player_potential`) is now bridged to the
`player_potential:change` matrix entity, closing a frontend/REST
divergence where its data-cap sibling was already matrix-aware. All three
re-points are access-preserving — the personas who could act before still
can. The behaviour-rating act-cap (`tt_rate_player_behaviour`) was left on
native capability evaluation and flagged on the issue: bridging it would
have revoked assistant-coach access, an effective-access change that needs
a product decision rather than a mechanical bridge.
