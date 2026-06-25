# Dashboard tile badges for pending actions (#1846)

Bump: patch

Dashboard navigation tiles can now carry a small **count badge** (top-right bubble) for pending actions, via a generic `badge_callback` on the tile. The **My tasks** tile uses it to show your open-task count at a glance — replacing the old `My tasks (3)` label suffix with a proper badge, so the tile label stays clean and the count reads instantly. Phase 6 of the player + parent development hub epic.
