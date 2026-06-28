# Parent dashboard is now anchored on the child, not empty player-self tiles (#1992)

Bump: patch

On the legacy tile-grid dashboard a parent saw an empty "Werk van vandaag"
column plus a "MIJN WERK" rail of player-self tiles that all denied (the
parent has no own player record). The grid had no parent-awareness.

A parent viewer (no own player, at least one linked child) now lands on a
child-scoped surface: the child's name and photo anchor the screen, a
curated parent tile subset is shown (development, player card, evaluations,
activities, development plan), each tile carries the child's `?player_id=N`
so the me-views resolve and authorize that child, and the empty
work-of-today column is hidden. A child switcher appears when the parent is
linked to more than one child. Which tiles and which child are domain
decisions (`ParentDashboardTiles` / `ParentChildResolver`), kept out of the
view.
