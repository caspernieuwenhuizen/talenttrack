<!-- type: feat -->

# #0018 Sprint 2 — Compatibility engine with traceable outputs

## Problem

Sprint 1 seeded formations and styles. Now we need the engine that takes a player's ratings + a role profile and outputs a fit score — with full traceability back to the rating inputs.

## Proposal

A pure-logic service (`CompatibilityEngine`) with no UI. Takes (player, slot-role-profile) → fit score + breakdown. Cached per player for 24h; invalidated when that player's evaluation is saved.

## Scope

### CompatibilityEngine

Class: `src/Modules/TeamDevelopment/CompatibilityEngine.php`.

Public interface:
```php
public function fitScore(int $playerId, int $formationTemplateId, string $slotLabel): FitResult;
public function allSlotsFor(int $playerId, int $formationTemplateId): array; // FitResult per slot
public function invalidateCache(int $playerId): void;
```

`FitResult` value object includes: `float score`, `array breakdown` (per-category contributions), `array rationale` (human-readable strings for UI tooltips).

### Scoring algorithm

Given a player's rating per evaluation category and a slot's role profile (weights per category):

1. Load player's rolling-5 rating per category (reuses existing `PlayerStatsService`).
2. For each category in the slot's role profile, multiply player's rating by the profile's weight.
3. Sum contributions → raw fit score (0–5 scale, same as ratings).
4. Apply side-preference modifier if player has a side preference and slot has a side (+0.2 if match, 0 if neutral, -0.2 if mismatch). Configurable constants, not magic numbers.
5. Build the breakdown: per-category contribution with weight, so a tooltip can show "Passing 4.2 × 0.35 = 1.47 → contributes to overall 4.07."
6. Build the rationale: 2–3 human-readable sentences for UI.

### Cache strategy

- Per-player cache: `tt_chemistry_cache` WP option with key `player_{id}_fit_scores`, value = array of all-slot scores for that player.
- TTL: 24 hours.
- Invalidation: hook into evaluation-save action; purge cache for that player.
- Warming: nightly WP-cron recomputes cache for all active-roster players. If cache was hot, this just refreshes; if cold, this populates.

### Style fit

Separately from formation fit: given a team's style blend (possession/counter/press weights, sum to 100), compute how well a player fits the team's overall style.

- Each evaluation category contributes to style axes (a mapping defined as config — e.g. Passing → possession, Physical → counter, Stamina → press).
- Style fit = dot product of player's category ratings and the style-blend-derived weights.

Style fit is separate from formation fit; both are inputs to the chemistry aggregator in Sprint 4.

### Side preferences

If Sprint 1 didn't add the side-preference columns, add them here:
- `tt_players.position_side_preference` (`left`, `right`, `center`, NULL).

Simpler single column than three booleans. UI for editing in Sprint 4's admin surface.

## Out of scope

- **UI.** Sprint 3 exposes the engine visually.
- **Chemistry composite.** Sprint 4.
- **Historical trend** in fit scores. v1 surfaces current only.

## Acceptance criteria

- [ ] `CompatibilityEngine::fitScore()` returns a FitResult for a valid (player, formation, slot).
- [ ] `allSlotsFor()` returns a result per slot in the formation.
- [ ] Breakdown shows per-category contributions.
- [ ] Cache hits don't recompute; TTL honored; invalidation on evaluation save works.
- [ ] Style fit computes correctly for a sample team.
- [ ] Side-preference modifier applies correctly.
- [ ] Unit tests: at least 10 scenarios covering various role profiles and rating distributions.

## Notes

### Sizing

~14–18 hours. Breakdown:
- Core algorithm implementation: ~5 hours
- Cache + invalidation: ~2 hours
- Style fit: ~3 hours
- Side-preference handling (schema if needed, modifier logic): ~2 hours
- Tests: ~3 hours (this is the critical engine — tests matter)
- Nightly cron for warming: ~1 hour

### Depends on

Sprint 1 of this epic. Existing `PlayerStatsService` for rolling-rating lookups.

### Blocks

Sprints 3, 4, 5 of this epic.

### Touches

- `src/Modules/TeamDevelopment/CompatibilityEngine.php` (new)
- `src/Modules/TeamDevelopment/FitResult.php` (new)
- Migration (if not done in Sprint 1): side-preference column on `tt_players`
- Cron registration for nightly cache warm

---

# #0018 Sprint 3 — Isometric formation board UI

## Problem

Sprints 1–2 built the data and logic. This sprint delivers the visible product: an isometric tilted pitch where coaches can see their team's formation, see fit scores per slot, drag players between slots, and experiment with different lineups.

## Proposal

A new frontend view, `FrontendTeamFormationBoardView`, accessible from the team edit view. Isometric-tilted SVG pitch with draggable player cards on slots. Real-time fit-score updates as players move.

## Scope

### Surface

- Accessed from `FrontendTeamsManageView` (from #0019 Sprint 3): a "Formation board" button/tile on the team's edit page.
- Renders an isometric tilted pitch (SVG, ~30° tilt for depth).
- Each formation slot rendered at its position on the pitch.
- Each slot shows: role label (CDM, LB, RW, etc.), the player currently assigned (photo + name + overall rating), and fit score for that slot.
- Unassigned slots show "Drop a player here" with the role label.
- A sidebar shows the remaining roster (players not currently in the XI) as draggable cards.

### Interactions

- Drag a player from the roster onto an empty slot → assigns.
- Drag a player from one slot to another → swaps if destination is occupied.
- Drag off-pitch → returns to the roster.
- On any drag-drop: fit scores for all affected slots recompute (fast, from cache).
- Click a player's fit score → tooltip with full breakdown (traceability).

### Visual design

- Isometric tilt: ~30° on the Y axis. Keep the design simple — a green pitch with white pitch markings, slot nodes at formation positions.
- Player cards: 80×100px, photo on top, name+rating below. Slight shadow, rounded corners.
- Fit score overlay: color-graded pill under each player (green ≥4.0, yellow 2.5–4.0, red <2.5, consistent with RatingPillComponent).
- Suggested-position highlight: if a player's `allSlotsFor()` shows a better slot than current, a small gold star indicates "this player would fit better at [slot]."
- Responsive: below 960px, drops tilt (shows top-down 2D) and rearranges sidebar to a collapsible drawer.

### Technical approach

- Pure SVG for the pitch (no canvas/webgl).
- CSS 3D transforms for the tilt.
- Drag-drop via HTML5 DnD API (or a small helper library if that turns out to be painful).
- All state client-side until "Save formation" — the user can experiment without persisting.
- "Save formation" persists the new slot assignments (via new REST endpoint — just a mapping of slot-label → player-id, stored in `tt_team_formation_assignments` — new table introduced here).

### New schema

One new table:
```sql
CREATE TABLE tt_team_formation_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  slot_label VARCHAR(16) NOT NULL,  -- 'CDM', 'LB', etc.
  player_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_team_slot (team_id, slot_label)
);
```

## Out of scope

- **Animations between formations.** Snap changes, no transitions.
- **Multiple saved formations per team** (e.g. "Formation A" vs "Formation B"). One current formation per team.
- **Formation preview for upcoming match** (depth XI, bench). Next sprint.

## Acceptance criteria

- [ ] Board renders the team's current formation with slots at correct positions.
- [ ] Isometric tilt visible on desktop; falls back to 2D below 960px.
- [ ] Drag-drop moves players between slots and roster.
- [ ] Fit scores update after each drag.
- [ ] Tooltip with breakdown on fit score click.
- [ ] "Save formation" persists to `tt_team_formation_assignments`.
- [ ] Responsive; mobile viewport functional.

## Notes

### Sizing

~16–20 hours. Breakdown:
- SVG pitch + slot rendering: ~3 hours
- Isometric tilt + responsive fallback: ~3 hours
- Drag-drop interactions: ~5 hours
- Fit score overlay + tooltip: ~2 hours
- Save-formation flow + new schema: ~2 hours
- Sidebar roster + remaining-players UI: ~2 hours
- Mobile polish + testing: ~3 hours

### Depends on

Sprints 1–2 of this epic. #0019 Sprint 3 (team edit view) for the entry point.

### Blocks

Sprint 4.

### Touches

- `src/Shared/Frontend/FrontendTeamFormationBoardView.php` (new)
- `src/Modules/TeamDevelopment/FormationBoardService.php` (new)
- `includes/REST/TeamDevelopment_Controller.php` — implement PUT for formation assignment
- Migration: `tt_team_formation_assignments`
- CSS: `assets/css/formation-board.css` (new)
- JS: `assets/js/formation-board.js` (new — drag-drop + live updates)

---

# #0018 Sprint 4 — Chemistry aggregator + pairing overrides + depth analysis

## Problem

Individual fit scores per slot are useful but partial. Coaches want a single **team chemistry** composite that tells them "how good is this XI overall?" They also want to capture pairing knowledge the algorithm misses ("these two work well together") and see depth at each position.

## Proposal

A `ChemistryAggregator` service that composes formation fit, style fit, pairing bonuses, and depth score. Admin surfaces for managing pairing overrides and depth analysis views.

## Scope

### ChemistryAggregator

`src/Modules/TeamDevelopment/ChemistryAggregator.php`

Public interface:
```php
public function teamChemistry(int $teamId): ChemistryResult;
public function depthAnalysis(int $teamId): DepthResult;
```

### Composite score formula

Starting weights (configurable per team in admin):
- Formation fit (average across 11 slots): **40%**
- Style fit (average across 11 players): **30%**
- Paired-chemistry bonus (from overrides + "played together" history): **20%**
- Depth score (variance across positions): **10%**

Output: 0–5 composite, plus breakdown string for UI.

### Pairing overrides

New schema:
```sql
CREATE TABLE tt_team_pairing_overrides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  player_a_id BIGINT UNSIGNED NOT NULL,
  player_b_id BIGINT UNSIGNED NOT NULL,
  bonus_amount DECIMAL(3,2) NOT NULL DEFAULT 0.3,  -- contributes to pairing bonus
  note TEXT,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Admin surface: on the formation board, a coach right-clicks (or long-presses on mobile) two players to open "Mark as pairing override" dialog. Enters a note. Saves.

### Depth analysis

`DepthResult` shows, per slot: starter, backup, 3rd choice. Each with their fit score.

Surface: a "Depth" tab on the formation board, showing a table:

| Slot | Starter | Fit | Backup | Fit | 3rd | Fit |
| --- | --- | --- | --- | --- | --- | --- |
| GK | Jan | 4.2 | Tom | 3.8 | Lars | 3.1 |
| CB | ... | | | | | |

Helpful for "do we have depth at CB for injuries / rotations?"

### Style-blend admin

A simple form (per team edit view or under Administration tile group): three sliders (possession / counter / press), must sum to 100. Presets for the three named profiles.

### Formation template editor

For users with `tt_manage_formation_templates`: a surface to create new formation templates or edit existing ones (including seeded ones — with a "reset to default" button for seeded templates).

Editor: form with shape selector (3-5-2, 4-3-3, etc.), slot labels, and per-slot role profile (weights per evaluation category). Not overly complex — a form with sensible validation.

## Out of scope

- **Algorithmic suggestion of pairings** (machine-inferred "these two should play together"). Manual only.
- **Historical "fit score over time"** graphs. v1 surfaces current.
- **Cross-team chemistry** (swapping players between teams for optimal fit across the academy). Future idea.

## Acceptance criteria

- [ ] `ChemistryAggregator::teamChemistry()` returns a composite score with breakdown.
- [ ] Per-team weights for formation/style/pairing/depth are configurable.
- [ ] Pairing override creation works from the formation board.
- [ ] Depth tab shows starter/backup/3rd for each slot.
- [ ] Style-blend admin form persists to `tt_team_playing_styles`.
- [ ] Formation template editor can create/edit templates.
- [ ] Unit tests for aggregator: ≥5 scenarios.

## Notes

### Sizing

~10–12 hours. Breakdown:
- Aggregator logic + tests: ~3 hours
- Pairing overrides (schema + UI on board): ~2 hours
- Depth analysis view: ~2 hours
- Style-blend admin form: ~1.5 hours
- Formation template editor: ~3 hours
- Polish + testing: ~1 hour

### Depends on

Sprints 1–3 of this epic.

### Blocks

Sprint 5.

### Touches

- `src/Modules/TeamDevelopment/ChemistryAggregator.php` (new)
- `src/Shared/Frontend/FrontendFormationTemplatesEditorView.php` (new)
- `src/Shared/Frontend/FrontendTeamPairingOverridesView.php` (new or modal on board)
- Migration: `tt_team_pairing_overrides`
- Formation board UI — depth tab added

---

# #0018 Sprint 5 — Player fit integration with profile + trial module

## Problem

The formation board and aggregator are HoD/coach-facing. Players also want to see "what position fits me best?" — and the trial module (#0017) needs fit scores for the decision panel.

## Proposal

Two integration points:
1. **Player profile "Team fit" panel** — shows the player's best-fit positions with fit scores and rationale.
2. **Trial module integration** — Sprint 4 of #0017 gets a fit-score comparison ("Trialist would score 4.3 as CDM vs incumbent 3.9").

## Scope

### Player profile "Team fit" panel

New section in `FrontendMyProfileView` (from #0014 Part A).

Contents:
- "Your best-fit positions for this season":
  - Top 3 slots by fit score across the current formation (or across all shipped templates if no formation assigned).
  - Each shows slot label, fit score (colored pill), and a breakdown rationale.
- Tooltip: click any position shows the category-contribution breakdown.

Coach view: when a coach views a player's profile (non-self), same panel visible with a "Suggest assignment" button that pre-fills the formation board with this player in the suggested slot.

### Trial module integration

Hooks into #0017's Decision panel (Sprint 4 of that epic).

On the trial case's decision view, add a panel: "Trialist fit against current roster."

- Lists the top 3 positions the trialist would fit.
- For each, compares trialist's fit score vs. the current incumbent's fit score.
- Surfaces "This trialist would be better than your current [slot]" if delta > 0.3.

This is decision-support data, not a decision. Shown to inform, not automate.

## Out of scope

- **Fit-score graph over time.** Current only.
- **Recommended trade deals.** Not relevant to academy context.

## Acceptance criteria

- [ ] Player profile shows top 3 fit positions with scores and breakdowns.
- [ ] Coach viewing a player profile sees the panel + "Suggest assignment" action.
- [ ] Trial decision view includes the fit-vs-incumbent panel.
- [ ] All fit data uses the cache from Sprint 2 (no recompute on every page view).

## Notes

### Sizing

~8–10 hours. Breakdown:
- Player profile panel: ~3 hours
- Coach-view enhancements: ~2 hours
- Trial integration: ~3 hours
- Testing: ~1.5 hours

### Depends on

- Sprints 1–4 of this epic.
- #0014 Part A (profile rebuild) for the panel integration point.
- #0017 Sprint 4 (trial decision view) for the trial integration.

### Blocks

Nothing. End of epic #0018.

### Touches

- `src/Shared/Frontend/FrontendMyProfileView.php` — add Team fit section
- `src/Shared/Frontend/FrontendTrialCaseView.php` — add fit-vs-incumbent panel to Decision tab
- `src/Modules/TeamDevelopment/PlayerFitService.php` (new — wraps CompatibilityEngine for player-view use)
