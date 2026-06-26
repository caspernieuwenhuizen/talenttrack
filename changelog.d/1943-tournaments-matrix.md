# Authorization: give the Tournaments planner a matrix entity (#1943)

The admin-only Tournament planner now has a `tournaments` authorization-matrix
entity. The legacy `tt_view_tournaments` / `tt_edit_tournaments` capabilities are
bridged through `LegacyCapMapper`, so the planner's frontend, REST, and add-match
surfaces resolve access from the matrix once it is active instead of from raw
WordPress capabilities. The seed grants only the Academy Admin persona full access
(read + edit + create + delete), exactly reproducing today's admin-only v1 design —
no persona gains or loses access, and WP administrators keep their override. A
backfill migration adds the entity to existing installs.
