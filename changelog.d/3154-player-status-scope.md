# Player-status routes check the player and the team, not just the capability (#3154)

Bump: patch

Three player-status endpoints gated on a bare capability, took an id from the
path, and never looked at it. `tt_view_player_status` goes to both coach roles,
scouts and parents, and the response is the full verdict object per player —
so `GET /players/{id}/status` returned any child's status and breakdown, and
iterating `GET /teams/{id}/player-statuses` walked every squad in the academy.

`POST /players/{id}/behaviour-ratings` was the same missing check on a **write**:
its gate is a feature-flag-plus-capability call that takes no player id, so a
holder could log a behaviour judgement onto any child in the club.

Reading one player's status now asks the same question the player's profile
asks, so a parent still reads their own child and nobody else's. Reading a
team's statuses asks whether the caller may read that team's player statuses —
scoped on player status rather than on teams, so a Head of Development granted
academy-wide status read still gets every board. Logging a behaviour rating
asks whether the caller may edit that player; every role holding
`tt_rate_player_behaviour` already passed that for their own players, so it
narrows rather than locks out.

The team predicate now lives in one place. `CohortBoardRestController` had the
only copy of it; it delegates to the shared version instead, which also gives
it two corrections it was missing — a WordPress settings admin passes without
needing a matrix row, and an archived team the caller coaches still resolves.
