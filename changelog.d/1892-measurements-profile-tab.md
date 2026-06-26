# Measurements on the player profile (#1892)

Bump: patch

A player's measurements now appear in context on their profile: opening a player (`?tt_view=players&id=N`) shows a **Measurements** tab beside Evaluations — the same tests-by-category view with latest value, green/amber/red flag and trend sparkline, with a badge counting how many tests the player has results for. The tab reuses the shared `PlayerMeasurementProfile` service so it renders identically to the standalone Metingen view, and is matrix-scoped (hidden for personas without `measurements` read).
