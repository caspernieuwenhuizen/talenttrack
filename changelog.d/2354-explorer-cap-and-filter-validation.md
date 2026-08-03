# Explorer: visible row-cap notice + filter validation (#2354)

Bump: patch

The dimension explorer now surfaces its hidden 5000-row cap: when a drill-down hits the limit, a notice under the table tells the user the tail is being dropped and to group the data to aggregate larger sets. Filters are also validated against the KPI's declared explore dimensions — a filter for a dimension the KPI doesn't offer is ignored instead of being applied, so the filters shown on screen always match the ones applied to CSV/PDF exports.
