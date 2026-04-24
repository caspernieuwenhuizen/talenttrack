<!-- type: epic -->

# #0018 — Team development + chemistry — epic overview

## Problem

A coach or HoD can see every *individual* player's rating in TalentTrack today. What's missing: a view of how a team *as a team* looks.

- Does this XI match how the club plays? (formation fit)
- Does this XI match how we try to play? (style fit)
- If we switch position assignments, does the fit improve?
- What's the depth at each role?
- Does this trial player actually fit a hole in the squad?

These are questions every academy coach asks informally. The data to answer them already exists (ratings, positions, team rosters). What's missing is the *model* that composes the data into team-level scoring, and the *UI* that makes it scannable.

Distinct from "chemistry" as FIFA means it — this is traceability-first team composition: every score explainable back to specific evaluation ratings.

Who feels it: HoD (squad planning), coaches (weekend team selection), scouts (fit assessment of new players), trial-process reviewers (does this trialist fill a need?).

## Proposal

Five-sprint epic adding `src/Modules/TeamDevelopment/` with a compatibility engine, formation profiles, per-team style configuration, an isometric formation board UI, and a player-side "Team fit" panel for their profile.

Core decisions locked during shaping:
1. **Seed with neutral default + 3 named profiles** (possession, counter, press-heavy). Clubs pick or edit.
2. **Compute fit across all 11 slots** (Option A), but surface as "suggested position" highlight rather than reshuffling the current lineup.
3. **Isometric tilted pitch** for v1 (~1 week over the static 2D baseline).
4. **Nightly recomputation** (per-player fit scores cached for 24h, invalidated on that player's evaluation save).
5. **Starting XI + depth XI** both scored and visualized — depth-at-position becomes a surfaced output.
6. **Pairing overrides** — coach can mark "always start these two together" with a note, factored into chemistry score.

## Scope

Five sprints:

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0018-sprint-1-schema-and-profiles.md` | Schema, seed profiles, capability registration | ~8–10h |
| 2 | `specs/0018-sprint-2-compatibility-engine.md` | Scoring algorithm with traceable outputs | ~14–18h |
| 3 | `specs/0018-sprint-3-formation-board-ui.md` | Isometric pitch UI with drag-drop | ~16–20h |
| 4 | `specs/0018-sprint-4-chemistry-aggregator.md` | Composite score, pairing overrides, depth analysis | ~10–12h |
| 5 | `specs/0018-sprint-5-player-fit-integration.md` | Player profile "Team fit" section, trial module integration | ~8–10h |

**Total: ~56–70 hours of driver time.**

## Out of scope

- **Full 3D animated pitch.** Isometric in v1; 3D is a future idea.
- **Real-time fit updates** on every evaluation change. Nightly cache + invalidation-on-save is enough.
- **Multi-XI optimization algorithms** ("find the best XI given these constraints"). Human-driven; tool surfaces data, coach decides.
- **Match-result prediction** based on fit scores. Different feature entirely.
- **Chemistry from social/psychological data.** Only evaluation-derived signals.
- **Comparison across clubs.** Per-club only.

## Acceptance criteria

The epic is done when:

- [ ] HoD can view the formation board for any team with all 11 positions filled from current roster.
- [ ] Each player in a slot has a visible fit score, traceable to specific evaluation ratings.
- [ ] Coach can drag players to different slots; fit scores update.
- [ ] Team chemistry composite score is shown with a breakdown (formation fit + style fit + paired-chemistry + depth).
- [ ] Depth at each position is visible (second and third choices ranked).
- [ ] Coach can mark paired-player overrides with a note; the chemistry score reflects the pairing.
- [ ] A trial player (from #0017) can be virtually slotted into the formation; the fit score for that slot shows against the current incumbent.
- [ ] A player's own profile has a "Team fit" section showing their best-fit positions.

## Notes

### Traceability principle

Every score must be explainable. A UI tooltip on any fit score shows: "Based on ratings: Passing 4.2, Positioning 4.1, Decision-making 3.9 → 4.07 weighted against CDM role profile (weight: 0.35/0.30/0.35)."

No black-box outputs. If a score isn't traceable, it's not shipped.

### Cross-epic interactions

- **#0014** — player profile rebuild (Part A) — embeds the "Team fit" panel from this epic's Sprint 5.
- **#0017** — trial module — Sprint 4's decision panel references fit scores from this epic's compatibility engine ("Trialist X scores 4.3 as CDM vs incumbent's 3.9").
- **#0019** — frontend-first migration — this epic's UI builds on #0019's shared components (FormationBoard is the main new UI component introduced here).
- **#0006** — team planning module — overlaps conceptually (team-level coaching tool) but operates on different data (activities vs compositions). Coexist without direct integration.

### Depends on

- #0019 Sprint 1–3 (components, list tables, player/team frontend). This epic needs those surfaces in place.

### Blocks

- #0017's decision panel enhancement (Sprint 4 of that epic references fit scores).
