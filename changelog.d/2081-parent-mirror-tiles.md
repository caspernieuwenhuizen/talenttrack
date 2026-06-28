# Parent dashboard mirrors the player's tile grid (#2081)

Bump: minor

A parent's dashboard now mirrors their child's own development tiles —
the same Me-group surfaces a player sees, in the same order — relabeled
to the child's first name as an Anglo possessive ("Sven's development",
"Sven's card", "Sven's evaluations"). This replaces the hardcoded
five-tile curation shipped in #1992.

Because the tiles are resolved through the normal tile registry, the
parent surface inherits module and `player_*` feature gating
automatically: switching off a player feature (e.g. `player_goals`)
removes that tile for both the player and the parent, with no
parent-specific list to maintain, and adding a new player Me-tile
surfaces for parents with no extra work. "My tasks" is included so a
parent can help remind their child of pending tasks. Account-level
tiles (settings, password) stay the parent's own — not child-scoped or
relabeled. The child anchor (name + photo), the multi-child switcher,
and `player_id`-scoped URLs (with `canViewPlayer` authorization) are
unchanged.
