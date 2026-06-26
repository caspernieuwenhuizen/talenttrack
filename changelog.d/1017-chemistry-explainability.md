# Chemistry rework — explainability panel (#1017)

Bump: minor

Phase 6 of the chemistry rework (epic #1017) — and the last phase. Adds a **Chemistry insight** panel to the team-chemistry board (behind the `chemistry_engine_v2` toggle): the reworked Lineup + per-unit (gk/def/mid/att) + windowed Team scores, the **strongest** and **weakest partnerships** in the lineup (colour-coded by category), and plain-language **recommendations** — telling a coach which pairing to strengthen and on which component, or which players still need their attributes rated. `ChemistryExplainer` derives the strongest/weakest/recommendations from the lineup aggregate (each pair now carries its weakest component). Degrades silently if the engine throws or there isn't enough data yet. This completes the rework: define attributes → engine scores → explained on the board.
