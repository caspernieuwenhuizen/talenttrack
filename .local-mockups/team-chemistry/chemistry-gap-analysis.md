# Chemistry - gap analysis between current engine and new spec

Companion to chemistry-engine-spec.md (target) and chemistry-logic.md (current).
Quantifies what the rework requires.

## Top 5 biggest differences (ranked)

### 1. Player attribute data model - 10 groups required vs ~2 today (FOUNDATIONAL)

The new engine needs Role, Physical, Technical, Tactical, Mental, Behaviour, Development,
Experience, Demographic, Footedness for every player. The current schema carries roughly:

- Footedness: tt_players.position_side_preference (left/right/NULL, no 'both')
- Demographic: tt_players.date_of_birth
- Experience: implicit - derivable from tt_attendance joined to tt_activities

The other seven groups are absent or live in tt_evaluations (free-text ratings, not structured
per-attribute scores).

Impact: ~30-40 columns added to tt_players (or a normalised tt_player_attributes table per
group). The compatibility / behaviour / mental / tactical scores cannot be computed without
the data - they would all return null or a guess until the schema lands. This single piece is
the largest dependency in the rework.

### 2. All-pairs vs 3-nearest - 3.4x more links

Current engine: each slot links to its 3 nearest by Euclidean distance. 11 slots -> ~16 unique
pairs.

New spec: every pair scored. 11 players -> N*(N-1)/2 = 55 pairs.

Impact: 3.4x more pair computations (still O(N^2), trivial at the scale). UI implication is
bigger: rendering 55 lines on the pitch is unreadable. The chemistry mockup needs a filter
strategy (e.g., show only strongest+weakest, or layer them by unit), separate from the
engine work.

### 3. 5 weighted components vs 3 additive bonuses

Current per-pair: sum of bonuses (coach +2, same-line +1, side +/-1), clamped 0..3.

New per-pair: weighted average of 5 sub-scores (Compatibility 35% + Familiarity 25% +
Development 10% + Behaviour 15% + Performance 15%), each 0..100.

Impact: every sub-score is itself a function of multiple attributes - Compatibility alone
draws on Groups A/B/C/D/E/J. The new engine is a fan-out of sub-engines. Each sub-engine
becomes its own definition-of-done item in the rework spec.

### 4. Time-averaged Team Chemistry (new concept)

Current: snapshot only. There is no time dimension.

New: Team Chemistry = average of Lineup Chemistry over the last N matches / season.

Impact: requires a new tt_team_chemistry_snapshots table writing one row per match (or per
blueprint save) so the time-window aggregate can compute. Plus a config toggle for the
window length (5 / 10 / season-to-date).

### 5. Configurable weighting + Position Relationship Matrix

Current: every constant is hard-coded (NEAREST_K=3, COACH_PAIR_BONUS=2.0, etc.).

New: component weights AND the Position Relationship Matrix are configurable.

Impact: two new admin surfaces:
- Component weights editor (5 sliders summing to 100%).
- Position Relationship Matrix editor (probably a grid: rows = position types, columns =
  position types, cells = 0.2/0.5/0.8/1.0 buckets).

Plus a tt_chemistry_config table or namespace on tt_config.

## Other notable differences (not in top 5 but worth flagging)

| Aspect | Current | New |
|---|---|---|
| Score scale | 0..100 team, 0..3 per pair | 0..100 everywhere |
| Buckets | 3 (green/amber/red) + neutral | 6 (Exceptional / Strong / Good / Moderate / Weak / Poor) |
| Unit chemistry | does not exist | required (GK / DEF / MID / ATT) |
| Explainability | per-link reasons array | adds: component scores, strongest/weakest, improvement recommendations |
| Side preferences | binary L/R + NULL | spec mentions left/right/both - 'both' value needed |
| Coach pairings | first-class +2.0 bonus | not explicitly listed; subsumed into Familiarity / Performance |
| Y-band thresholds | 0.30 / 0.65 / 0.90 (arbitrary) | replaced by Position Relationship Matrix (no y-bands) |
| Player-attribute storage | mostly free-text evaluations | structured per-attribute scores per group |
| Development score | does not exist | required (age/maturity/potential diffs) |
| Performance score | does not exist | required (shared match outcomes, goal diff, ppm) |

## Rework scope estimate

Splitting into phases the executor can ship sequentially:

- Phase 1 - Schema foundation: add the 10 attribute groups to tt_players (or a new
  tt_player_attributes normalised table) + tt_team_chemistry_snapshots time-series table
  + tt_position_relationship_matrix editable matrix + tt_chemistry_config weights table.
  No engine change yet. Patch bumps; multiple migrations.

- Phase 2 - Sub-engines: implement each of the 5 component scorers (Compatibility,
  Familiarity, Development, Behaviour, Performance) as standalone classes, each unit-tested
  against synthetic data. No engine integration yet.

- Phase 3 - PairChemistryEngine v2: orchestrator that calls the 5 sub-engines, applies the
  configurable weights, returns the 0..100 per-pair score. Replaces BlueprintChemistryEngine.

- Phase 4 - Aggregators: Unit Chemistry + Lineup Chemistry + Team Chemistry roll-ups,
  including the time-window aggregation for Team. New snapshot-writer hook on blueprint save
  + match completion.

- Phase 5 - Admin surfaces: component-weight editor + Position Relationship Matrix editor
  on the Configuration page.

- Phase 6 - Explainability: strongest/weakest relationships + improvement recommendations
  surfaced on the team-chemistry page (the mockup's reasoning panel is the visual target).

- Phase 7 - Data entry workflow: forms / wizards for operators to populate the 10 attribute
  groups per player. Without this, the engine runs on NULL data.

## Recommendation

Phase 1 + Phase 7 are the load-bearing dependencies. Until both ship, the new engine has
nothing to compute. Suggest filing Phase 1 + Phase 7 as their own issues with ready-for-dev
and keeping #1017 as the umbrella that gates on them.

Phases 2-6 can ship serially after Phase 1 lands and Phase 7 has at least a minimum-viable
data set (a few seeded test players with all 10 groups filled) to validate against.
