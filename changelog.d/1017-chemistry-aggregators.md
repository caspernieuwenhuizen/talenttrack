# Chemistry rework — Unit / Lineup / Team aggregators (#1017)

Bump: minor

Phase 4 of the chemistry rework (epic #1017): rolls the reworked pair scores up into the spec's higher-order numbers. `LineupChemistryAggregator` scores every filled-slot pair (all-pairs), weights them by the configurable Position Relationship Matrix, and returns **Lineup chemistry** (matrix-weighted average) + **Unit chemistry** per gk/def/mid/att. `TeamChemistryAggregator` writes a lineup-chemistry snapshot per blueprint save and averages recent snapshots into **Team chemistry** over a window (last 5 / 10 / season). The reworked numbers surface on the blueprint response as `chemistry_v2` (lineup + unit + windowed team + per-pair breakdown) **behind the `chemistry_engine_v2` toggle (default off)** — the legacy `blueprint_chemistry` stays the live signal until an academy opts in once attributes are populated, and any computation error degrades silently to the old behaviour.
