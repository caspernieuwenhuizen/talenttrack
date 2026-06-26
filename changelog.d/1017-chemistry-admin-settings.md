# Chemistry rework — admin settings (#1017)

Bump: minor

Phase 5 of the chemistry rework (epic #1017): a **Chemistry settings** surface (Configuration → tile) where a head of development or academy admin tunes the reworked engine — the **enable toggle** (`chemistry_engine_v2`, off by default), the **five component weights** (normalised to total 100), and the **Position Relationship Matrix** (how strongly each pair of lines interacts, 0–1). All persist via the Phase-1 contract (`tt_config` + the matrix table). Matrix-gated on `team_chemistry` change at global scope; a Save-only settings sub-form (§6 exemption); mobile-first; nl_NL strings.
