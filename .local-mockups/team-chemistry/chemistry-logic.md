# Chemistry logic — how the current engine works

A paper-form walkthrough of every input, weight, and threshold inside `BlueprintChemistryEngine` so the pilot can audit the algorithm end-to-end before deciding what to change. Companion to issue #1017.

> **Read this once. Mark up what feels wrong with strikethroughs / annotations. The annotated paper becomes the spec for the rework.**

## 1 — What this paper covers (and doesn't)

The chemistry surface on `?tt_view=team-chemistry` shows **five numbers** in the KPI cards:

| Card | Number | Where it comes from |
|---|---|---|
| **Team chemistry** | 0–100 | `BlueprintChemistryEngine` (this paper) |
| Formation fit | 0–3 | `ChemistryAggregator::teamChemistry` (separate engine, **not in this paper**) |
| Style fit | 0–3 | `ChemistryAggregator::teamChemistry` (separate engine) |
| Depth score | 0–3 | `ChemistryAggregator::teamChemistry` (separate engine) |
| Data coverage | 0–100% | `ChemistryAggregator::teamChemistry` (separate engine) |

**This paper covers only the headline `Team chemistry` number** (and the green / amber / red link colours on the pitch). The four sub-scores are produced by a different engine (`ChemistryAggregator`) and have their own logic — if those numbers feel wrong too, the rework needs a parallel paper for that engine.

> ⚠️ **Scale mismatch already visible in the mockup**: the headline card shows `2.34 / 3` but the engine returns `team_score` as `0..100`. The mockup needs to either convert to `0..100` or the engine needs to switch to a 0..3 normalisation. Worth deciding alongside the rework.

## 2 — Inputs

```
                              ┌──────────────────────────────────────┐
                              │ Input 1: formation slots             │
                              │   list<{ label, pos.x, pos.y, side }>│  ← from tt_formation_templates.slots_json
                              ├──────────────────────────────────────┤
                              │ Input 2: lineup (primary tier only)  │
                              │   slot_label → player_id             │  ← from tt_team_blueprint_assignments WHERE
                              │                                      │      tier='primary' AND ref_kind='player'
                              ├──────────────────────────────────────┤
                              │ Input 3: coach-marked pairings       │
                              │   list<{ player_a_id, player_b_id }> │  ← from tt_team_chemistry_pairings
                              ├──────────────────────────────────────┤
                              │ Input 4: side preferences            │
                              │   player_id → 'left' | 'right' | NULL│  ← from tt_players.position_side_preference
                              └──────────────────────────────────────┘
                                                  │
                                                  ▼
                              ┌──────────────────────────────────────┐
                              │     BlueprintChemistryEngine         │
                              └──────────────────────────────────────┘
                                                  │
                                                  ▼
                              ┌──────────────────────────────────────┐
                              │ team_score (0..100)                  │
                              │ links: list<{ a, b, score, color }>  │
                              └──────────────────────────────────────┘
```

**Three things the engine does NOT look at** — important to know up-front:

1. **Position eligibility** (the `tt_players` eligible-positions list) is **never consulted**. A striker assigned to GK gets the same chemistry treatment as a goalkeeper assigned to GK — the engine doesn't know.
2. **Recent evaluations / form / fitness** — irrelevant. Sub-scores in the other engine touch this; chemistry doesn't.
3. **Pairings beyond explicit coach marks** — historical "they've played together a lot" data is not mined.

## 3 — Step 1: figure out which slot-pairs to score (adjacency)

The engine computes "nearest neighbours" on the pitch. Each slot picks its **3 closest other slots** by Euclidean distance on (x, y), and that's the chemistry link.

```
For each slot A:
    distances[B] = sqrt( (A.x - B.x)² + (A.y - B.y)² )   for every other slot B
    nearest_3 = the 3 smallest distances
    for each B in nearest_3:
        add unique pair (A, B) to the list   # dedupe by (smaller_label, larger_label)
```

Constants:

| Constant | Value | What it means |
|---|---|---|
| `NEAREST_K` | **3** | Every slot connects to its 3 closest neighbours |

A 4-3-3 formation (11 slots) produces **~16 unique pairs** after dedupe. Visually that's the same density as FIFA Ultimate Team — dense enough to feel meaningful, sparse enough to read.

> ⚠️ **`NEAREST_K = 3` is hard-coded**. If the pilot wants "each slot connects to 5 neighbours" or "each slot connects to every slot in the same line", this is the constant to change.

## 4 — Step 2: score each pair

For every pair (A, B) where **both slots are filled**, sum up the bonuses:

| Bonus | Value | When it applies |
|---|---|---|
| `COACH_PAIR_BONUS` | **+2.0** | The pair `(player_A_id, player_B_id)` exists in `tt_team_chemistry_pairings` |
| `SAME_LINE_BONUS` | **+1.0** | Both slots fall in the same y-band (see §4.1 below) |
| `SIDE_MATCH_BONUS` | **+1.0** | Both players' side preferences match their slot's side |
| `SIDE_MISMATCH_PEN` | **−1.0** | Either player has a side preference that contradicts their slot's side |

The four contributions are **additive**, then clamped to the range `0..3` (so a coach-marked pair on the same line with side fit gets `2 + 1 + 1 = 4` clamped to `3`; a coach-marked pair with a single side mismatch gets `2 + 1 − 1 = 2`).

### 4.1 — Y-band ("line of play") rule

The engine partitions the pitch into four horizontal bands by slot `y` coordinate (0 = top of the pitch, 1 = bottom):

| Band | y range |
|---|---|
| `att` (attack) | `0.00 ≤ y < 0.30` |
| `mid` (midfield) | `0.30 ≤ y < 0.65` |
| `def` (defence) | `0.65 ≤ y < 0.90` |
| `gk` (goalkeeper) | `0.90 ≤ y ≤ 1.00` |

Two slots are "same line" iff they fall in the same band. So a 4-3-3 has:
- One `gk` line (1 slot)
- One `def` line (4 slots)
- One `mid` line (3 slots)
- One `att` line (3 slots)

Pairs **across** bands (e.g. CDM at y=0.55 + CB at y=0.75) do NOT get the same-line bonus — even if they're 0.2 apart on the pitch.

> ⚠️ **Boundaries are arbitrary**. A slot at y=0.29 and a slot at y=0.31 are both midfield-ish but one counts as attack and the other as midfield, so they lose the +1 same-line bonus even though they're 0.02 apart. The `0.30 / 0.65 / 0.90` thresholds are guesses, not measured.

### 4.2 — Side compatibility rule

Each slot has a `side` field in the formation template: `'left'`, `'right'`, or `'center'`. Each player has a `position_side_preference` on `tt_players` (also `'left'`, `'right'`, or NULL).

The function `playerSideCompatibleWithSlot(player_pref, slot_side)` returns:

| Player pref | Slot side | Result |
|---|---|---|
| NULL / empty | any | `null` (neutral — no signal) |
| any | `'center'` | `null` (neutral — centre slots don't care about side) |
| `'left'` | `'left'` | `true` (match) |
| `'right'` | `'right'` | `true` (match) |
| `'left'` | `'right'` | `false` (mismatch) |
| `'right'` | `'left'` | `false` (mismatch) |

Then for the pair (A, B):
- If **both** players evaluate to `true` → `+1.0`
- If **either** evaluates to `false` → `−1.0`
- Anything else (one is `null`, both `null`, etc.) → `0`

> ⚠️ **Side preferences are sparsely populated.** Most players have `position_side_preference IS NULL`, so the side-coherence bonus is almost always 0. The signal that's supposed to differentiate "Joep at LB (left-footed, good fit)" from "Joep at RB (left-footed in a right slot, bad fit)" only fires when the operator has bothered to enter the preference column. **This may be the main reason the chemistry score feels disconnected from reality.**

> ⚠️ **Side is binary**. Players who are "both-footed" (genuinely two-footed, can play either side) get either no preference recorded (`NULL`, neutral) or a guessed value (artificial mismatch on one side). The schema has no `'both'` value.

### 4.3 — Coach-marked pairing bonus

Operator-tagged pairs in `tt_team_chemistry_pairings` give `+2.0` — the biggest single bonus. This is the only signal the operator directly controls, and it dominates the formula.

Two coach-marked players on the same line of play, both with compatible sides, will hit the `3.0` cap easily (`2 + 1 + 1 = 4` clamped to `3`). A coach-marked pair across lines with side mismatch still gets `2 + 0 − 1 = 1` — solid amber.

> ⚠️ **Effectively, chemistry is dominated by what the operator marks.** Without coach pairings, the best a pair can do is `1 + 1 = 2` (same line + side match) — barely green. So a brand-new install with no coach pairings will see mostly amber.

## 5 — Step 3: bucket the link colour

The per-pair score buckets are:

| Score | Colour | Visual |
|---|---|---|
| `score ≥ 2.0` | Green (`COLOR_GREEN`) | Strong fit |
| `1.0 ≤ score < 2.0` | Amber (`COLOR_AMBER`) | Workable |
| `score < 1.0` | Red (`COLOR_RED`) | Poor fit |
| pair has an empty slot | Grey (`COLOR_NEUTRAL`) | Not scored, doesn't count toward team total |

A pair with both slots empty isn't a link at all (skipped). A pair with one slot empty draws as grey neutral — visible on the pitch but excluded from the team score.

## 6 — Step 4: team score

Sum of every **scored** link's points, divided by the maximum possible:

```
team_score = sum(pair_scores) / (scored_pair_count × 3.0) × 100
```

Returned as an integer 0..100. **Returns `null` when no link is scored** (lineup empty or has only one player).

So a team where every pair hits the `3.0` cap → `team_score = 100`. A team where every pair scores `1.5` (amber) → `team_score = 50`. A team where every pair scores `0` → `team_score = 0`.

## 7 — Worked example — 4-3-3 with no coach pairings

Say a default 4-3-3 with 11 players, none coach-marked, half with side preferences recorded:

```
Pair                          coach   same-line   side-coherence   total   bucket
GK ↔ LCB                      0       0           0                0       RED
GK ↔ CCB                      0       0           0                0       RED        (cross-band: GK to DEF; band changes)
LCB ↔ RCB                     0       +1 (def)    +1               2       GREEN
LCB ↔ LB                      0       +1 (def)    +1               2       GREEN
RCB ↔ RB                      0       +1 (def)    +1               2       GREEN
LB ↔ CDM                      0       0           0                0       RED
RB ↔ CDM                      0       0           0                0       RED
CDM ↔ LCM                     0       +1 (mid)    0                1       AMBER
CDM ↔ RCM                     0       +1 (mid)    0                1       AMBER
LCM ↔ RCM                     0       +1 (mid)    0                1       AMBER
LCM ↔ LW                      0       0           +1               1       AMBER
RCM ↔ RW                      0       0           +1               1       AMBER
LCM ↔ ST                      0       0           0                0       RED
RCM ↔ ST                      0       0           0                0       RED
LW ↔ ST                       0       +1 (att)    0                1       AMBER
RW ↔ ST                       0       +1 (att)    0                1       AMBER
```

Sum = 14, divided by `(16 × 3) = 48`, × 100 = **team_score = 29** out of 100.

> ⚠️ **A team with no coach pairings and partial side data can't break ~50.** The dominant signal (coach pairings) is unused; the side signal is partial. The output is "amber-ish red", regardless of how the players actually combine on the pitch.

## 8 — Why scores feel wrong (hypotheses to validate)

Based on the algorithm, here's why the pilot's read of "doesn't work" might be:

1. **No position-eligibility signal**. A striker at GK scores identically to a goalkeeper at GK if both have the same coach-pairings + same-line + side-coherence inputs. The coach sees a green link on a clearly-wrong assignment.
2. **Side preferences sparsely entered**. Without `position_side_preference` populated for most players, the side-coherence signal is effectively dead. Most pairs score `+1.0` (same line, no side bonus, no coach mark) → amber across the board.
3. **Y-band thresholds are guesses**. Slots near a band boundary (e.g. CDM at y=0.66) flip between bands depending on the formation template — same pair could score green on one formation and amber on another, with no logical reason.
4. **Coach pairing dominates**. The `+2.0` bonus is large enough that an entire chemistry score is essentially "did the coach mark these two?" — turning the algorithm into a coach-preference visualisation rather than an analytical one.
5. **Adjacency is purely Euclidean, not tactical**. CDM and ST might be Euclidean-near (centre column) but tactically distant; LW and RW are Euclidean-far but tactically related (mirror positions). The 3-nearest rule misses tactical pairs.
6. **No handling of "both" / two-footed players**. Schema is binary L/R with no "both" — two-footed players appear as either mismatch or neutral depending on what was guessed at data-entry.
7. **No data-coverage weighting**. A pair scored on three solid signals counts the same as a pair scored on guesses + NULL. The output reads as if the algorithm is confident even when it's working from `NULL`.

## 9 — Open questions for the pilot to settle

1. **Position eligibility** — fold it in? (The mockup already established this as a hard constraint for the picker; the engine should follow.)
2. **Coach pairing weight** — `+2.0` is the largest single contribution. Drop to `+1.0` so it's not dominant?
3. **Y-band thresholds** — drop the binary "same line yes/no" and replace with a continuous proximity score (e.g. `+1.0 × (1 - |Δy|)` capped at `+1`)?
4. **Side preferences** — populate via a backfill from existing match-day position data (where the player has played → what side they prefer)? Add a `'both'` value to the schema?
5. **Adjacency** — replace 3-nearest with formation-template-defined edges (the template explicitly lists which slots are tactically connected)?
6. **Data-coverage weighting** — multiply per-pair score by `data_coverage_pct(player_a, player_b)` so pairs with thin data weight less in the team total?
7. **Score scale** — return 0..3 (matches the headline card's `/3` suffix) or stay 0..100 (matches FIFA UT)?

## 10 — Next steps

1. Pilot marks up this paper. Strikethrough things that should change; annotate desired behaviour.
2. The annotated paper becomes the body of #1017's "Locked spec" section.
3. Issue flips to `ready-for-dev`.
4. Executor session ports the new algorithm, plus updates the chemistry mockup's KPI scale (`team_score` → 0..3 if §9.7 lands that way).

## Reference

- Engine source: `src/Modules/TeamDevelopment/BlueprintChemistryEngine.php` (351 LOC; this paper covers ~all of it).
- Pairings repository: `src/Modules/TeamDevelopment/Repositories/PairingsRepository.php`.
- Slot definitions: `tt_formation_templates.slots_json`.
- Side preferences: `tt_players.position_side_preference` column.
- Companion mockup: `.local-mockups/team-chemistry/index.html`.
- Issue: #1017.
