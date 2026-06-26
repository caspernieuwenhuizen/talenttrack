# Chemistry attributes — player data entry (#1017)

Bump: minor

Phase 7 of the chemistry rework (epic #1017, child #1913) — the load-bearing data dependency. Adds a **Chemistry attributes** editor reachable from a player's profile (⋯ menu): the attribute catalogue grouped (physical / technical / tactical / mental / behaviour / development), one 0–100 input per attribute pre-filled with the current value, saved in one nonce-protected POST. Staff who can record evaluations can edit them, matrix-scoped via `canEvaluatePlayer`. With this the reworked engine has real data to score against; un-rated attributes simply don't count (rather than scoring zero). Mobile-first, Save + Cancel, EN + nl docs.
