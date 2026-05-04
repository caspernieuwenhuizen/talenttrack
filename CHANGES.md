# TalentTrack v3.91.3 — REST list controllers honour the matrix's global-scope grants

Found on the pilot install. After granting scout `team_roster_panel: r[global]` via the matrix admin page (per the v3.91.0 / v3.91.1 fix), Brian (Scout) clicked Mijn teams. Tile rendered. Dispatcher allowed. Page loaded. Team list was empty.

## Why

`TeamsRestController::list_teams` had a hardcoded coach-scope filter:

```php
if ( ! current_user_can( 'tt_edit_settings' ) ) {
    $coach_teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
    // SQL: WHERE t.id IN (...coach_teams)
}
```

Scout has no coach assignments → empty `$coach_teams` → empty list returned. Same architectural rot the dispatcher had before #0079: a hardcoded role-class check that doesn't know about the matrix. The operator's matrix grant of `team:r[global]` to scout never reached the SQL filter.

Same pattern in two more controllers:

- `GoalsRestController::list_goals` — line 129
- `ActivitiesRestController::list_sessions` — line 164

`EvaluationsRestController::list_evals` doesn't have a coach-scope filter at all (already returns all evaluations), so no fix needed there. `EvaluationsRestController::create_eval` line 70 is a write-side `coach_owns_player` check — that stays unchanged. Write-side ownership is a separate concern; scouts should not create evaluations on arbitrary players.

## Fix

New helper `QueryHelpers::user_has_global_entity_read(int $user_id, string $entity): bool`. Three rungs:

1. `tt_edit_settings` cap → admin shortcut. Same as the legacy gate.
2. WP `administrator` role → defensive belt-and-braces; mirrors the bypass every other matrix consumer applies.
3. `MatrixGate::can($user_id, $entity, 'read', 'global')` → the new rung. Returns true only when the user's persona has a global-scope read row for this entity in `tt_authorization_matrix`.

The 3 list controllers replace `if ( ! current_user_can( 'tt_edit_settings' ) )` with `if ( ! QueryHelpers::user_has_global_entity_read( get_current_user_id(), <entity> ) )`. Personas with a global-scope grant bypass the coach-scope filter and see the full club; coaches with team-scope grants still hit the coach-scope branch.

After this update: scout's Teams page lists every team in the club; same for Goals (when granted `goals:r[global]`) and Activities (`activities:r[global]`). No FR re-assignment, no cap-flag tweaking, just the matrix admin page.

## What was NOT touched

- `EvaluationsRestController::create_eval` — write-side `coach_owns_player` check stays. Write-side ownership is a separate question.
- The coach-scope branch itself — coaches with team-scope grants still see only their teams. The change is purely additive: it adds a global-scope bypass on top of the existing flow.
- `get_team`, `get_goal`, etc. (single-record fetchers) — these query by ID and don't filter by ownership. Scopes are enforced at the `MatrixGate::can` layer for direct entity-page hits.

## Affected files

- `src/Infrastructure/Query/QueryHelpers.php` — new `user_has_global_entity_read()` helper.
- `src/Infrastructure/REST/TeamsRestController.php` — `list_teams` swaps the cap check for the helper.
- `src/Infrastructure/REST/GoalsRestController.php` — `list_goals` same.
- `src/Infrastructure/REST/ActivitiesRestController.php` — `list_sessions` same.
- `talenttrack.php` + `readme.txt` — version bump 3.91.2 → 3.91.3.
- `CHANGES.md` — this entry.
- `SEQUENCE.md` — Done row added.

## Test plan

- [ ] On the pilot install, after this update: Brian (Scout) sees every team in the club on `?tt_view=teams`.
- [ ] Kevin Raes (Hoofdtrainer of Hedel JO13-1) still sees only his team on `?tt_view=teams` — coach-scope path unchanged.
- [ ] Academy admin still sees every team via the admin shortcut.
- [ ] Granting scout `goals:r[global]` and `activities:r[global]` on the matrix admin page surfaces full club lists on those pages too.
