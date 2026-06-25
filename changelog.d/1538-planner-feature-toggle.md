# Team planner is now a toggleable feature (#1538)

Bump: patch

The week-by-week **Team planner** calendar is now a `FeatureRegistry` feature an academy admin can switch off from the Modules page — for academies that work activity-by-activity and don't want the forward-looking planner. It ships **on by default**, so nothing changes on upgrade; turning it off hides the Team planner tile and gates its `?tt_view=team-planner` route (the Activities log, the backward-looking surface, stays available). First catalogued entry from the FeatureRegistry candidate tracker (#1538), wired with the standard pattern: a `FeatureRegistry::catalog()` entry plus the tile's `feature` key (route gating is automatic via the feature's `view_slugs`).
