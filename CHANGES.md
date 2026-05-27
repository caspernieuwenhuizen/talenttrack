# TalentTrack v4.4.0 — Blueprint editor depth-chart UI (closes #953)

UI follow-up to the v4.3.21 foundation ship. Replaces the single-slot pitch overlay with a three-tier depth-chart stack per pitch position and adds cross-team / guest / custom roster augmentation. Matches the in-tree prototype at `.local-mockups/blueprint-editor/index.html` as the design-of-record.

This ship closes #953. The schema, repository ref-shape, REST shim and chemistry-engine transparency landed in v4.3.21; this PR ports the editor UI on top.

## What changes

### Editor surface — `FrontendTeamBlueprintsView::renderEditor`

- Each pitch position now renders a numbered circle + a three-row stack (`primary` / `secondary` / `tertiary`) directly underneath. The previous single-player-overlay layout is gone. Both flavours (match-day and squad-plan) share the same surface.
- Tier is encoded twice per row — by the digit on the left pill AND by the row's border colour (`var(--tt-chem-green-token)` / `var(--tt-chem-amber-token)` / neutral grey) — so the depth chart stays readable without colour.
- Clicking a slot opens a dropdown picker over the roster with a search input. The picker re-uses the JS-side roster augmentation, so cross-team / guest / custom entries appear in the list as soon as they're added.
- Drag-drop still works; the drop target is now the individual tier row rather than the single circle.
- Roster sidebar gains an `×N` badge on each player that reflects the number of placements in the **current formation only** (stale entries from previous formations don't inflate the badge).
- A new **+ Add cross-team / guest / custom** button opens an inline three-tab form (Other team / Guest / Custom). Cross-team adds a sibling-club player to the roster as `ref_kind=player`; Guest adds a name + optional position; Custom adds a free-text label. Augmentations are session-only — they only persist when actually placed in a slot.
- Formation switch dropdown above the pitch lets the coach swap the blueprint's formation template; assignments keyed on slot_label survive the switch (slots in the new formation rehydrate from existing rows; slots that drop out stay in the database silently so a round-trip switch restores them).
- "Clear all slots" toolbar button calls the bulk-replace endpoint with an empty map.

### REST — `GET /blueprints/{id}` hydration

GET responses now include `blueprint.assignment_refs` — the same per-slot/per-tier ref map the repository's `loadAssignmentRefs()` returns, but with display fields denormalised: `display_name` on every kind, plus `team_id` and `team_name` on player refs. The plain `assignments` map stays as the legacy `slot → tier → player_id` shape for callers that only need the primary-tier-player lineup (chemistry, share-link view).

### Chemistry engine — `BlueprintChemistryEngine` docblock

Top-of-class docblock now spells out the contract explicitly: chemistry is computed against the **primary tier only**, and non-player refs (guest / custom) are skipped by construction because there's no `tt_players.id` to look up coach-pairings or side preferences against. The behaviour was already correct (driven by `loadPrimaryLineup()` filtering to `ref_kind='player'`); the docblock makes the contract visible to future readers.

### Warning strip — slots missing tier-1

When a blueprint has tier-2 or tier-3 entries but no tier-1, a warning strip appears above the pitch listing the affected slots: *"Tier-1 unassigned on: ST, CM — chemistry score skips these slots."* Surfaces the silent score-drop the chemistry-only-scores-primary contract would otherwise hide.

### New asset files

- `assets/css/frontend-blueprint-editor.css` — mobile-first per CLAUDE.md §2. Base CSS targets ~360px; `min-width` breakpoints at 480 / 768 / 1024 scale up. All interactive targets ≥ 48px tap area (slot rows are 22px visually but bumped to 48px hit target via a hit-overlay pseudo). No hover-only functionality.
- `assets/js/components/blueprint-editor.js` — pure vanilla JS, no framework. Drives roster render, pitch render, dropdown picker, drag-drop, the inline add-form, and the formation switch.

### Localised config

The view localises a new `TT_BLUEPRINT_EDITOR` config object (separate from the legacy `TT_BLUEPRINT` so neither contract depends on the other). Carries the slot list, hydrated assignment refs, team roster, sibling teams (for the cross-team picker), and i18n strings.

## Files touched

- `talenttrack.php` — version bump 4.3.21 → 4.4.0.
- `readme.txt` — Stable tag 4.3.21 → 4.4.0.
- `src/Modules/TeamDevelopment/BlueprintChemistryEngine.php` — docblock only.
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` — `get_blueprint` adds `assignment_refs` hydration.
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php` — `renderEditor()` rewrite; new `renderBlueprintEditor()`, `renderEditorToolbarFormation()`, `localiseBlueprintEditor()`, `hydrateAssignmentRefsForEditor()`. Old `renderRosterChips`/`overlaySlotDropTargets`/`renderDepthChart` remain in the file as unused legacy helpers (deferred removal — the heatmap path could still call them; safer to ship without dead-code-deletion churn).
- `assets/css/frontend-blueprint-editor.css` — new file.
- `assets/js/components/blueprint-editor.js` — new file.
- `docs/team-blueprint.md` + `docs/nl_NL/team-blueprint.md` — editor section rewritten.
- `languages/talenttrack.pot` + `languages/talenttrack-nl_NL.po` — new msgids with Dutch translations.
- `CHANGES.md` — this file.

## Why minor

New behaviour epic (depth-chart layout, cross-team / guest / custom refs, formation switch, hydrated REST). Patch reset to 0 per SemVer.

## Validation

- Existing blueprints with only tier-1 player refs render correctly: same chemistry score, same lines.
- Switching formation from 4-3-3 → 4-4-2 → 4-3-3 round-trips assignments cleanly (slot labels match, rows survive).
- Click-slot dropdown picker filters by name + position + team name.
- `+ Add → Other team` adds a sibling player to the roster; placing them sends a `ref={kind:player,player_id:N}` body that the repository persists with the same `ref_kind='player'` discriminator.
- `+ Add → Guest` and `Custom` augmentations stay session-only until placed; placement persists via the new ref-aware columns from migration `0129`.
- Roster `×N` badge updates after every placement.
- "Clear all slots" toolbar button wipes every assignment row via the bulk endpoint.
- Chemistry warning strip appears when any slot has tier-2/3 without tier-1.
