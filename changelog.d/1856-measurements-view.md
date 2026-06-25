# Measurements & Testing — player Metingen view (#1856)

Bump: minor

Adds the player-facing **Metingen** surface for the Measurements module (epic #1854). A player (and a parent of that player) gets a "My measurements" tile that opens a view of their tests grouped by category — each test showing its latest value, a green/amber/red flag against the age-group target, a sparkline of the trend, and the recurrence. The view is server-rendered straight from the shared `PlayerMeasurementProfile` service, so it shows exactly what the REST API returns; the sparkline is inline SVG (no extra client JS). Visibility is matrix-scoped: a player sees only their own, a parent only their child's; staff reach a player's measurements from the player profile, so the self-dashboard tile is hidden for them. Mobile-first, two nav affordances. The result-entry screen and the "+ New test" wizard follow in the next slice.
