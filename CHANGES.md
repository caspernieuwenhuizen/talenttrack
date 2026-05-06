# TalentTrack v3.98.0 — Team Blueprint Phase 1: drag-drop lineups with live chemistry (#0068)

The follow-up to the v3.96.0 chemistry-line ship. Coaches can now build, save, share, and lock match-day lineups on a draggable pitch — with the same FIFA-Ultimate-Team-style chemistry lines updating live on every drop.

Phase 1 ships the **match-day** flavour only. Squad-plan flavour, trial overlay, and a heatmap view land in Phase 2; comments via Threads in Phase 3.

## What's new for coaches

A new tile **Team blueprint** appears in the Performance group on the dashboard, right next to Team chemistry. Click it, pick a team, and you land on a list of saved blueprints for that team. *+ New blueprint* opens a two-step wizard (pick team + formation + name → review → create) and drops you on the editor.

The editor is the centrepiece:

- **Roster sidebar** on the left lists every active player on the team. Each is a draggable chip.
- **Pitch in the middle** shows the formation slots. Drag a chip onto a slot — the player is saved, the chemistry score recomputes, and the green/amber/red lines update.
- **Move a player** by dragging from one slot to another, or drop a chip back onto the roster sidebar to remove from the lineup.
- **Live Link chemistry** headline above the pitch updates after every drop. Same 0–100 math as the chemistry view (`sum(pair_scores) / (scored_pairs × 3) × 100`).
- **Status flow** — draft (private) → shared (visible to staff) → locked (read-only). One *Reopen* click on a locked blueprint sends it back to shared so changes can be made.

When a blueprint is locked, drag-drop is disabled and the assignment endpoints reject writes with HTTP 409. Reopen reverts to shared.

## Schema

Migration **0070** adds two tables:

```sql
tt_team_blueprints (
    id, club_id, uuid, team_id, name, flavour, formation_template_id,
    status, notes, created_by, created_at, updated_by, updated_at
)

tt_team_blueprint_assignments (
    id, club_id, blueprint_id, slot_label, player_id, assignment_notes, updated_at
    UNIQUE (blueprint_id, slot_label)
)
```

The unique on `(blueprint_id, slot_label)` enforces single-occupant slots. Empty slots are simply absent from the assignments table — no `player_id IS NULL` rows. `flavour` defaults to `match_day`; the column exists now to avoid a Phase-2 migration when squad-plan lands. SaaS-readiness scaffold (`club_id`, `uuid`) per CLAUDE.md §4 included on both tables.

## REST contract

| Method | Path | Cap | Notes |
| --- | --- | --- | --- |
| `GET`    | `/teams/{id}/blueprints`         | view   | List for one team, ordered by `updated_at DESC`. |
| `POST`   | `/teams/{id}/blueprints`         | manage | Create (name + formation_template_id required). Lands in `draft` status with empty assignments. |
| `GET`    | `/blueprints/{id}`               | view   | Returns blueprint meta + slots (from formation template) + assignments map + recomputed `blueprint_chemistry`. |
| `PUT`    | `/blueprints/{id}`               | manage | Update meta fields (`name`, `formation_template_id`, `notes`). Rejects with 409 if locked. |
| `DELETE` | `/blueprints/{id}`               | manage | Hard-delete blueprint + assignments. |
| `PUT`    | `/blueprints/{id}/assignment`    | manage | Set / unset one slot. Body: `{ slot_label, player_id? }`. Returns recomputed `blueprint_chemistry`. |
| `PUT`    | `/blueprints/{id}/assignments`   | manage | Bulk replace. Body: `{ assignments: { LB: 12, RB: 7, ... } }`. |
| `PUT`    | `/blueprints/{id}/status`        | manage | Body: `{ status: 'draft'|'shared'|'locked' }`. |

The chemistry payload returned by `GET /blueprints/{id}` and the per-drop `PUT /blueprints/{id}/assignment` is the same `BlueprintChemistryEngine::computeForLineup()` shape introduced in v3.96.0, so any consumer that already reads `blueprint_chemistry` from `GET /teams/{id}/chemistry` works against blueprints unchanged.

## Frontend architecture

- New view class `FrontendTeamBlueprintsView` under `src/Modules/TeamDevelopment/Frontend/`. Three render branches: team picker, per-team list, editor.
- New tile registered via `CoreSurfaceRegistration` (slug `team-blueprints`, entity `team_chemistry_panel` — reusing the chemistry-panel matrix entity rather than minting a new one, since blueprint visibility follows the same scoping).
- New wizard `NewTeamBlueprintWizard` registered in `WizardsModule` per CLAUDE.md §3 (record-creation wizard rule).
- Drag-drop is pure vanilla JS in `assets/js/frontend-team-blueprint.js` (~120 LOC). HTML5 `dragstart` / `dragover` / `drop`. Each drop sends a single `PUT /blueprints/{id}/assignment` and reloads the page from the server response — no client-side diff'ing of slot state.
- CSS in `assets/css/frontend-team-blueprint.css`. Mobile-first per CLAUDE.md §2: stacks at <768px (roster on top, pitch below), side-by-side ≥768px. Touch targets ≥48px. No hover-only functionality.

## Cap / matrix entity reuse

Blueprints use the existing `tt_view_team_chemistry` (read) and `tt_manage_team_chemistry` (manage) caps. Same matrix entity `team_chemistry_panel` for tile visibility. **No seed migration needed** — every persona that already sees the chemistry tile can see the blueprint tile, and every persona that can manage chemistry pairings can manage blueprints. Per `feedback_seed_changes_need_topup_migration.md`, this is the simpler outcome.

If we later want to split visibility (e.g. blueprints visible to assistant coaches who shouldn't see the chemistry composite), we mint a new `team_blueprint_panel` entity with a top-up migration. Not warranted for Phase 1.

## License gate

Same `team_chemistry` Pro-tier feature flag as the chemistry view. Free tier sees `UpgradeNudge::inline()` in place of the editor. Standard / trial / Pro all light up.

## What didn't change

- The chemistry view (`?tt_view=team-chemistry`) is untouched. Coaches who navigated by the auto-suggested XI continue to do so; blueprints are a parallel surface for *coach-authored* lineups.
- `BlueprintChemistryEngine` shipped in v3.96.0 is consumed unchanged. The engine is pure-function `(team_id, slots, lineup)` → chemistry payload — exactly what was promised when it landed.
- No drag-drop on the chemistry view itself. That surface stays auto-suggested by design.

## What's not in this PR

- **Squad-plan flavour** — the second blueprint type (multi-tier position fits, primary/secondary/tertiary, used for next-season planning). Phase 2.
- **Trial overlay** — visualising trial players on a squad-plan blueprint with a distinct chip. Phase 2 (locked decision: only trials assigned to this team are eligible).
- **Comments** — discussing a blueprint with staff via the Threads module. Phase 3.
- **Mobile drag-drop polish** — HTML5 drag-and-drop on touch devices works but is awkward; a long-press-to-pick-up fallback is Phase 4.
- **Share-link** — public URL for parents / external coaches. Phase 4.

## Files touched

- `database/migrations/0070_team_blueprints.php` (new)
- `src/Modules/TeamDevelopment/Repositories/TeamBlueprintsRepository.php` (new)
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php` (new)
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` (extended — 7 new endpoints + handlers)
- `src/Modules/Wizards/TeamBlueprint/{NewTeamBlueprintWizard,SetupStep,ReviewStep}.php` (new)
- `src/Modules/Wizards/WizardsModule.php` (register new wizard)
- `src/Shared/Frontend/DashboardShortcode.php` (`team-blueprints` slug + dispatch)
- `src/Shared/CoreSurfaceRegistration.php` (Team blueprint tile)
- `assets/css/frontend-team-blueprint.css` (new)
- `assets/js/frontend-team-blueprint.js` (new)
- `languages/talenttrack-nl_NL.po` (40 new msgids)
- `docs/team-blueprint.md` + `docs/nl_NL/team-blueprint.md` (new)
- `talenttrack.php` + `readme.txt` + `SEQUENCE.md` (3.97.0 → 3.98.0)
