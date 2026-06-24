# Team planner "Principles trained" bar: rebalanced label/bar/count (#1756)

Bump: patch

The "Principles trained — last 8 weeks" coverage rows under the team planner
laid out poorly: cramped principle labels, an over-wide bar, and no room to
read the count. The row grid is rebalanced — the label column flexes wider
(and long labels wrap instead of truncating), the bar track is narrower at a
fixed width, and the activity count sits clearly to the right of the bar with
breathing space. CSS-only; selectors and markup unchanged.
