# TalentTrack v4.3.21 — Blueprint editor depth-chart foundation (partial #953)

Foundational architecture for the depth-chart rework specced in #953. The full UI port per the reference prototype is **deferred to a follow-up issue**; #953 stays open with the `ready-for-dev` label after this ship.

This split is deliberate: shipping the schema + repository + REST shim + chemistry transparency as a foundation **first** lets the editor UI rewrite (the larger half of the work) land in a separate PR without back-pressure on the data model. Existing rows keep `ref_kind='player'` so the v1 editor continues to work end-to-end against the new schema.

## What this ship lands

### Schema — migration `0129_blueprint_assignment_refs.php`

Adds discriminated-reference columns to `tt_team_blueprint_assignments`:

```sql
ALTER TABLE {prefix}tt_team_blueprint_assignments
  ADD COLUMN ref_kind       VARCHAR(20)  NOT NULL DEFAULT 'player' AFTER tier,
  ADD COLUMN guest_name     VARCHAR(120) DEFAULT NULL              AFTER ref_kind,
  ADD COLUMN guest_position VARCHAR(60)  DEFAULT NULL              AFTER guest_name,
  ADD COLUMN custom_label   VARCHAR(120) DEFAULT NULL              AFTER guest_position;
```

`ref_kind` discriminator: `player` (default, requires `player_id`), `guest` (requires `guest_name`, optional `guest_position`), `custom` (requires `custom_label`). Cross-team picks are still `kind=player` — the linked player's `tt_players.id` is canonical; what makes it "cross-team" is the player's `team_id` ≠ blueprint's `team_id`. Idempotent via dbDelta.

### Repository — `TeamBlueprintsRepository`

- `setAssignment()` + `replaceAssignments()` accept the new ref-object shape via `normaliseRef()` (also handles legacy flat-int).
- Cross-slot player dedupe block **removed** — a player can legally occupy multiple slots / tiers per the depth-chart contract. Cross-cell uniqueness (one entry per `(slot, tier)`) stays as the UNIQUE KEY.
- New `loadAssignmentRefs()` parallels `loadAssignments()`: returns the full ref shape per cell.
- New `slotsMissingPrimary()` surfaces slots with tier-2/3 entries but no tier-primary — consumed by the editor for warning chips (delta #2 in the shaping comment on #953).

### REST controller — `TeamDevelopmentRestController`

- New `coerceAssignmentRef()` shim accepts both canonical `ref` object **and** the legacy flat `player_id` shape.
- Sunset entry in `docs/rest-api.md`: *"Legacy flat `player_id` deprecated v4.3.21 (#953); shim removed in v5.0.0."*
- Applies to `PUT /blueprints/{id}/assignment` (single) and `PUT /blueprints/{id}/assignments` (bulk).

### In-repo callers migrated to the canonical `ref` shape

Per the shaping delta — internal traffic uses the new shape uniformly; the shim handles only documented external API consumers.

| Caller | Change |
|---|---|
| `assets/js/frontend-team-blueprint.js:137` (drag-drop save) | `body.ref = { kind: 'player', player_id: ... }` instead of flat `body.player_id` |
| `assets/js/frontend-team-blueprint.js:544` (tier picker tap-to-swap) | Same shape change |
| `assets/js/frontend-team-chemistry.js:240` (chemistry sandbox "Save as blueprint") | Lineup values are now `{ kind: 'player', player_id: N }` ref objects |

### Chemistry-engine transparency

`loadPrimaryLineup()` docblock documents the primary-only scoring contract; guest / custom cells are skipped for chemistry (no `tt_players.id` to look up). `slotsMissingPrimary()` is the editor's data source for warning chips on positions that have only tier-2/3 entries — addresses the silent score-drop concern from the shaping delta.

The companion `docs/team-blueprints.md` "How chemistry is calculated" subsection ships with the UI follow-up so the docs land alongside the warning-chip visuals.

### Prototype moved into the repo

`C:/Users/caspe/AppData/Local/Temp/tt-blueprint-mockups.html` → `.local-mockups/blueprint-editor/index.html`. Mirrors the existing `.local-mockups/match-execution/` convention. Future iterations diff against it.

## What's deferred (UI follow-up)

The editor-view rewrite is the larger half. Deferred:

- Numbered-circle + 3-slot tier stack per pitch position (replaces the single-player overlay).
- Click-slot dropdown picker with search + "Clear this slot".
- Drag-drop onto tier slots.
- Roster `×N` placement badge.
- "+ Add" 3-tab form (cross-team / guest / custom).
- Formation switch preservation.
- Mobile-first CSS sheet (`frontend-blueprint-editor.css`).
- New JS behaviour file (`blueprint-editor.js`).
- Per-mobile/desktop layout per the prototype.

The existing `FrontendTeamBlueprintsView::renderEditor()` continues to work unchanged because every existing assignment row keeps `ref_kind='player'`.

## Validation

- Existing blueprint pages render unchanged (back-compat preserved by default `ref_kind='player'`).
- `PUT /blueprints/{id}/assignment` accepts both shapes:
  - `{ slot_label, tier, ref: { kind: 'player', player_id: N } }` ← new
  - `{ slot_label, tier, player_id: N }` ← legacy (shim)
- Chemistry-engine: `loadPrimaryLineup()` continues to drive `computeForLineup()` with the same `slot → player_id` map.
- `slotsMissingPrimary()` returns the right list on a blueprint with mixed-tier assignments.

## Why patch

Foundation only; no operator-visible UI change. The follow-up issue carries the UI bump.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.20` → `4.3.21`.
