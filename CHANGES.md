# TalentTrack v3.91.4 — Edit row-action on teams / players / people landed on detail page; row actions now cap-gated

Operator clicked **Edit** on a teams list row, expected the edit form, got the read-only detail page. Looked like a coach-vs-permission thing at first — turned out to be a routing bug that bites every persona on every list, not just coaches.

## Bug 1 — Edit row-action shunted to detail view by the dispatcher

`DashboardShortcode::dispatchCoachingView()` had this for the teams (and players, and people) cases:

```php
case 'teams':
    if ( $detail_id > 0 ) {
        FrontendTeamDetailView::render( $detail_id, $user_id, $is_admin );
        break;
    }
    FrontendTeamsManageView::render( $user_id, $is_admin );
    break;
```

The Edit row-action generates `?tt_view=teams&id=42&action=edit`. The dispatcher saw `$detail_id > 0`, hard-routed to `FrontendTeamDetailView`, and the `action=edit` parameter was never read. The manage view's existing `if ( $action === 'edit' )` dispatch at line 64 was unreachable from any row-action click.

### Fix

Dispatcher now reads `$action` first. When `action !== 'edit'`, the detail-view shunt fires as before. When `action === 'edit'`, fall through to the manage view, which already handles the form-render flow when `id` is set. No change needed inside the manage views.

```php
case 'teams':
    if ( $detail_id > 0 && $action !== 'edit' ) {
        FrontendTeamDetailView::render( $detail_id, $user_id, $is_admin );
        break;
    }
    FrontendTeamsManageView::render( $user_id, $is_admin );
    break;
```

Same fix on `players` and `people` cases.

### Players + people Edit row-action also missed `action=edit`

While in there, the players and people Edit row-action hrefs were generating `?tt_view=players&id={id}` (and `?tt_view=people&id={id}`) without `action=edit` at all. Even with the dispatcher fix, those would have stayed broken because they never set the action param. Both now generate `…&id={id}&action=edit` — consistent with the teams shape.

## Bug 2 — Edit / Delete row-actions visible to users without the edit cap

Coaches with `tt_view_teams` but not `tt_edit_teams` saw Edit + Delete row-actions in the list. Clicking Edit landed on the form page, the user submitted, the REST endpoint 403'd. From the operator's perspective: "the button doesn't work."

### Fix

`FrontendListTable::rowActionsForJs()` now reads an optional `cap` field on each row-action. When the current user lacks the cap (`current_user_can( $cap )` returns false, going through MatrixGate when the bridge is active), the action is silently dropped at render time — the operator never sees a button they can't use.

Edit + Delete on the three list views (`teams`, `players`, `people`) now declare:

```php
'cap' => 'tt_edit_teams',   // / tt_edit_players / tt_edit_people
```

`view` row-action stays unconditional (anyone reading the list can also read the detail). `card` (rate card) on players also stays unconditional — it gates inside the rate-card view itself.

## What's not in this PR

- **Activities / Goals / Evaluations / Functional-roles** lists weren't touched here — they all use `FrontendListTable` so the new `cap` field works for them, but their existing row-actions don't declare a cap. Add `cap` declarations in a follow-up sweep if a coach reports the same "button doesn't work" symptom on those.
- **Detail-page Edit affordance** (the link inside `renderDetail()`) already gates on `$can_edit` correctly — that's the `userCanOrMatrix( $user_id, 'tt_edit_teams' )` check at FrontendTeamsManageView line 337. Unchanged.

## Renumbering

v3.91.2 → v3.91.3 → v3.91.4 across two parallel-agent collisions: first the menu-landing + teams-staff-column fix landed mid-CI as v3.91.2, then the REST-list global-scope fix landed mid-CI as v3.91.3.

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
