# TalentTrack v4.3.10 — VCT new-training wizard (5-step UI) (closes #944)

## Context

The VCT Phase 1 epic (v4.3.7) shipped the engine + REST + nightly task. The starter catalogue (v4.3.9) gave the engine something to compose. This ship gives the coach a UI to drive it.

Reachable at `?tt_view=wizard&slug=new-vct-session`.

## What changed

### Five wizard steps

| Step | Slug | What it does |
|---|---|---|
| 1. When | `when` | Team picker (coach sees own teams; admin sees all) + date (defaults to tomorrow). Age + MD context auto-resolved on the Preview step. |
| 2. Theme | `theme` | Single-select from `vct_tactical_theme` lookup (10 values). Optional — skip = engine picks theme-agnostic candidates. |
| 3. Duration | `duration` | Number input with `inputmode="numeric"` per CLAUDE.md §2; bounded by the age profile's `session_minutes_max`. |
| 4. Preview | `preview` | Calls `RulesEngine::compose()` server-side, renders the composed blocks + structured warnings. Pure-read; nothing persists. |
| 5. Review | `review` | Final confirmation; submit calls `VctTrainingComposer::generate()` (same path as `POST /vct/sessions/generate`) and redirects to the detail view. |

### Two-layer permission check, defence-in-depth

- Cap layer: `requiredCap() = 'tt_vct_plan'`. The wizard framework enforces this before any step renders.
- Scope layer: `AuthorizationService::canPlanForTeam($uid, $team_id, 'create_delete')` checked at:
  - **When-step validation** — coach can't submit a team they don't have scope for.
  - **Review-step submit** — permission revocation mid-wizard caught at the final step.

### Engine wiring

The Preview + Review steps each wire a fresh `RulesEngine` with all 8 rule passes + the 11 production repositories from v4.3.5. The Preview shows what the coach is about to save; the Review's submit recomposes (same context) and persists via `VctTrainingComposer`.

Preview is read-only; nothing persists until Review submits. The `_vct_preview_md_context` / `_vct_preview_age_group` hidden fields carry the resolved values forward so the Review step can show the same summary without re-resolving.

### `VctModule::boot()` registration

```php
if ( class_exists( WizardRegistry::class ) ) {
    WizardRegistry::register( new NewVctSessionWizard() );
}
```

Defensive `class_exists` so VCT doesn't hard-depend on the Wizards module being enabled (consistent with the existing `if ( class_exists( WorkflowModule::class ) )` guard from #912).

## Out of scope

- Hover-to-swap on Preview (Phase 2 polish).
- Custom-label hand-fill at wizard time (PATCH endpoint already supports it post-save via the detail view).
- A "Save as draft" affordance via `SupportsCancelAsDraft` — the wizard already writes a draft session row on Review-step submit, and a partial wizard's state has nothing useful to half-save.

## Wizard chrome

Previous / Next / Cancel handled by the existing wizard framework. No per-step Save + Cancel buttons needed per CLAUDE.md §6 exemption (c) — wizard chrome covers it.

## Validation

- Log in as head_coach of team T; navigate to `?tt_view=wizard&slug=new-vct-session` → wizard loads.
- Step 1: only team T visible; pick T + tomorrow's date.
- Step 2: theme dropdown populated with all 10 `vct_tactical_theme` values; skip.
- Step 3: duration capped at the age profile max (e.g. 90 for U13).
- Step 4: composed blocks render with picked exercises from the starter catalogue (v4.3.9); warnings surface for missing macro-block etc.
- Step 5: submit → redirect to `?tt_view=vct-session&id=N` (404 until VCT-10 ships the detail view; the session row + blocks are persisted regardless).
- Try with team T' (not your scope): When-step validation returns 403.

## Why this is `patch`, not `minor`

UI surface within the 4.3 minor. No new schema, no new caps, no new REST. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.9` → `4.3.10`.
