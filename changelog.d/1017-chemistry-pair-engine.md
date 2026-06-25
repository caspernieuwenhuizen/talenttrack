# Chemistry rework — pair engine orchestrator (#1017)

Bump: minor

Phase 3 of the chemistry rework (epic #1017): the `PairChemistryEngine` that combines the five Phase-2 sub-engines into a single 0–100 pair-chemistry score using the configurable component weights, plus the `ChemistryProfileLoader` that feeds them real data — each player's attributes + age + footedness, and the pair's shared-history context (shared completed activities/games + team-tenure overlap), pre-loaded once per id set. A `PairResult` carries the score, its spec category (exceptional → poor), the per-component breakdown, and the human reasons. Exposed read-only at `GET /chemistry/pair/{a}/{b}` (gated on viewing both players) so the new engine can be tested on real pairs. It does **not** displace `BlueprintChemistryEngine` yet — the live team surface switches over only once Phase 7 has populated attributes, in Phase 4.
