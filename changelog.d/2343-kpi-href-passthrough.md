# Clickable KPI tiles on the standard reports (#2343)

The standard-reports KPI strip can now turn a tile into a drill-down link:
each KPI accepts an optional `href` (and an optional `cap` to gate it, hiding
the link for viewers who lack the capability). Tiles without an `href` render
exactly as before, so no existing report changes. The clickable tile gains a
visible keyboard focus ring and keeps its 48px touch target.
