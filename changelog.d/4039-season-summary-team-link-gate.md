# Season summary: no link to a team page the reader is refused (#4039)

The season summary linked every team in its per-team table and offered an
*Active teams* KPI tile, and a read-only observer clicking either met "You do
not have access to this surface". The `teams` slug had no cross-view-link gate,
so it fell through the permissive fallback; the KPI tiles checked a bare
capability, which on this surface is the wrong question — the `teams` tile
declares the `team_roster_panel` entity, while `tt_view_teams` maps to
`team:read`, so the observer passes the capability and dispatch still refuses.

The team name now renders as plain text for a reader the dispatcher would
refuse, and the KPI tiles gate on their destination slug through
`CrossViewLink`. The `teams` gate asks `DashboardShortcode::dispatchAllows()` —
the dispatcher's own predicate — so the affordance and the destination cannot
drift. The observer's seat is unchanged: #3580 decided not to widen it, and
this gates the affordance instead.

The CI lint that exists to prevent this now also matches
`RecordLink::detailUrlFor…()` call sites, which build a `tt_view=` URL
indirectly and were invisible to it. It stays diff-only, so existing call sites
are unaffected.
