# PDP planning matrix available over REST (#4448)

The PDP planning matrix now comes from a domain service,
`PdpPlanningService`, instead of static methods on the planning view. The
matrix is also reachable at `GET /talenttrack/v1/pdp-planning?season_id=N`,
with optional `team_ids[]`. The route returns the same cells and block
windows the page shows. It is gated on the page's `tt_view_pdp_planning`
capability and scoped to the same teams: a head of development sees every
team, a coach only the teams they coach, whatever team ids they ask for.
The page itself is unchanged.
