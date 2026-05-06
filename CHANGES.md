# TalentTrack v3.100.0 — Team Blueprint Phase 2: squad-plan flavour, tiers, trial overlay, heatmap (#0068)

Phase 2 of the Team Blueprint epic. Phase 1 (v3.98.0) shipped match-day blueprints — single starting XI, single player per slot. Phase 2 adds the second flavour: **squad plan** — for next-season planning, trial decisions, and "where are the gaps in our depth?" coaching conversations.

Match-day blueprints from v3.98.0 keep working unchanged. Squad-plan is a flavour pick on the new-blueprint wizard.

## What's new for coaches

### Two flavours

The new-blueprint wizard's Setup step now asks:

- **Match-day lineup** — one starting XI for an upcoming match. Single player per slot. (Phase 1 behaviour.)
- **Squad plan** — planning towards next season or trial decisions. Three tiers per slot (primary / secondary / tertiary) and a trial overlay.

Flavour locks at create time (changing flavour after the fact would either drop tiers or fan out match-day rows; not worth the complexity).

### Tiered depth chart (squad-plan only)

Below the pitch on a squad-plan blueprint, a depth-chart table:

```
Slot   Primary    Secondary  Tertiary
GK     Lucas      Jonas      —
LB     Eve        Mira       Jamal
LCB    Sam        —          —
...
```

Each cell is a drop target. Drag a roster chip onto any cell to fill that tier. Drag a depth-chart chip back to the roster panel to remove. Pitch slots also drop-accept (they target the primary tier).

The same player can't sit in two slots or tiers on one blueprint — the writer strips any prior assignment when you drop them somewhere new, so dragging Lucas from `GK / Primary` onto `LB / Secondary` empties `GK / Primary` automatically.

### Trial overlay

Squad-plan rosters get a *Trials* divider in the sidebar listing trial players assigned to this team (`tt_players.status='trial'` on this team's roster). Trial chips have a yellow border + a small `TRIAL` badge. Drag-drop is identical to regular roster chips, so a coach can stage trial players in tier 2 / 3 of a slot to make the "should we sign this kid?" conversation visible against the depth chart.

Per the locked decision: only trials assigned to this team — no global trial overlay.

### Coverage heatmap

A *Show coverage heatmap* button on squad-plan blueprints flips the pitch into a depth-coverage view:

- **Red** — 0 tiers covered (uncovered)
- **Orange** — 1 (primary only, no backup)
- **Yellow** — 2 (primary + secondary, no third)
- **Green** — 3 (full depth)

Each slot shows `N/3` so the coach can read the page at a glance: where are the gaps? `← Back to lineup view` returns to the editor.

### Match-day stays simple

The depth chart, trial section, and heatmap are all gated on `flavour === squad_plan`. Existing match-day blueprints render exactly as v3.98.0. The new `tier` column defaults to `primary` so old assignments load as single-tier maps that flatten cleanly into the existing UX.

## Schema (migration 0072)

```sql
ALTER TABLE tt_team_blueprint_assignments
    ADD COLUMN tier VARCHAR(10) NOT NULL DEFAULT 'primary' AFTER slot_label;
DROP INDEX uk_slot ON tt_team_blueprint_assignments;
ALTER TABLE tt_team_blueprint_assignments
    ADD UNIQUE KEY uk_slot_tier (blueprint_id, slot_label, tier);
```

Idempotent (guards on column + key existence). Existing match-day rows pick up `tier='primary'` via the column DEFAULT — no separate backfill needed.

## REST contract changes

| Endpoint | Change |
| --- | --- |
| `POST /teams/{id}/blueprints` | Accepts `flavour` (`match_day` default, or `squad_plan`). |
| `PUT /blueprints/{id}/assignment` | Body gains `tier` (`primary` | `secondary` | `tertiary`; default `primary`). Returns the recomputed `blueprint_chemistry` over the primary tier. |
| `GET /blueprints/{id}` | `assignments` payload shape changes to `{slot: {tier: player_id}}` (was `{slot: player_id}`). `BlueprintChemistryEngine` consumes the primary tier only. |
| `PUT /blueprints/{id}/assignments` | Bulk replace accepts both shapes — flat `{slot: player_id}` is treated as primary; nested `{slot: {tier: player_id}}` writes per-tier. |

## Architecture

`BlueprintChemistryEngine` is unchanged — chemistry is always computed over the **starting XI** (primary tier only), even on a squad-plan blueprint. Tier 2 and 3 are depth signal, not lineup signal. New `TeamBlueprintsRepository::loadPrimaryLineup()` gives the engine the flat slot→player_id shape it expects.

The drag-drop JS is now tier-aware: every drop reads `data-tier` from the target (defaults to `primary` for pitch slots) and includes it in the REST body. Pitch slots only target primary. Depth-chart cells target their column's tier.

## License gate + caps

No change. Same `team_chemistry` Pro feature flag, same `tt_view_team_chemistry` (read) / `tt_manage_team_chemistry` (manage) caps as Phase 1.

## What's not in this PR

- **Phase 3** — Comments via Threads module (per-blueprint discussion thread).
- **Phase 4** — Mobile drag-drop polish (long-press fallback) + share-link.
- **Squad-plan chemistry weighting**: chemistry only scores the primary tier. A future "what if my secondary tier rotates in?" view would compute chemistry across the secondary lineup too — not warranted yet.

## Files touched

- `database/migrations/0072_team_blueprint_tiers.php` (new)
- `src/Modules/TeamDevelopment/Repositories/TeamBlueprintsRepository.php` (tier constants + tier-aware writes + `loadPrimaryLineup`)
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php` (depth chart + heatmap + trial chips + flavour pill)
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` (`flavour` on create, `tier` on assignment)
- `src/Modules/Wizards/TeamBlueprint/SetupStep.php` (flavour radio)
- `src/Modules/Wizards/TeamBlueprint/ReviewStep.php` (flavour line in summary)
- `assets/css/frontend-team-blueprint.css` (depth-chart styles + heatmap palette + trial chip variant)
- `assets/js/frontend-team-blueprint.js` (tier param on every drop + depth-chart cell drop targets)
- `languages/talenttrack-nl_NL.po` (27 new msgids)
- `docs/team-blueprint.md` + `docs/nl_NL/team-blueprint.md` (Squad plan section)
- `talenttrack.php` + `readme.txt` + `SEQUENCE.md` (3.99.0 → 3.100.0)

## Renumbering note

Initial commit was tagged v3.99.0 + migration 0071. Mid-rebase, the parallel-agent ship of #0081 children 2b/3/4 took the v3.99.0 slot + migration 0071 (`0071_trial_cases_rolling_membership`). Renumbered to v3.100.0 + migration 0072.
