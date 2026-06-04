# Audit 2 — REST write-path integrity

**Date:** 2026-06-03
**Scope:** Every `$wpdb->insert(...)` and `$wpdb->update(...)` call in
- `src/Infrastructure/REST/**/*.php`
- `src/Modules/**/Rest/*.php`
- `src/Shared/Frontend/**/*View.php` (the few that still write via `handlePost`)

**Reference:** #1176 (audit spec). Reproduces the diagnostic shape of #1148
(off-roster attendance) as static analysis: does every child-of-parent write
validate (a) parent row exists in current club and (b) submitted FK
references belong to that parent's scope?

## Summary

Most REST writes that target root entities (`tt_players`, `tt_teams`,
`tt_tournaments`, `tt_activities`, `tt_goals`, `tt_lookups`,
`tt_functional_roles`, `tt_eval_categories`, `tt_people`) already scope their
`UPDATE` to `club_id = CurrentClub::id()`. The attendance write was fixed in
v4.20.5 (#1148) and the guard is still in place.

The audit surfaced **three handlers with the exact #1148 shape**: a submitted
foreign-key column is written into a child row without any check that the
referenced parent belongs to the writer's scope. One of them is cross-club
exploitable (it skips `club_id` entirely from the `WHERE`), the other two
allow cross-team-within-club data mixing.

The audit also surfaced two cross-club archive paths (`delete_eval`,
`add_player_to_team`) and one inline-create path (`FrontendTrialsManageView`)
that accept arbitrary `player_id` from POST without validating club
membership.

A small shared helper would close most of these in one place — see
**Refactor suggestion** below.

## Findings table

| # | File:line | Table written | Submitted FKs | Parent scope validated? | Submitted-FK scope validated? | Severity |
|---|-----------|---------------|---------------|-------------------------|-------------------------------|----------|
| F1 | `src/Infrastructure/REST/EvaluationsRestController.php:494` (`update_eval`) | `tt_evaluations` | `player_id`, `eval_type_id` | **No — `WHERE id=%d` only, no `club_id`** | No (player_id not re-checked) | **Critical** |
| F2 | `src/Infrastructure/REST/EvaluationsRestController.php:569` (`delete_eval`) | `tt_evaluations` (archive) | n/a | **No `club_id` in WHERE** | n/a | High |
| F3 | `src/Infrastructure/REST/GoalsRestController.php:304` (`create_goal`) | `tt_goals` | `player_id` | n/a (self-contained insert) | **No — any `player_id` accepted** | **Critical** |
| F4 | `src/Infrastructure/REST/TournamentsRestController.php:571` (`update_assignments`) | `tt_tournament_assignments` | `player_id` | Yes (match→tournament→club) | **No — any `player_id` accepted** | High |
| F5 | `src/Infrastructure/REST/TournamentsRestController.php:1394` (`upsertSquadRow` via `replace_squad` / `update_squad_member`) | `tt_tournament_squad` | `player_id` | Yes (tournament→club) | **Partial — player checked for `club_id` only, not for tournament's team** | Medium |
| F6 | `src/Infrastructure/REST/TeamsRestController.php:345` (`add_player_to_team`) | `tt_players` (sets `team_id`) | `team_id` (path param) | **Player scoped — but `team_id` not validated** | **No — any `team_id` accepted, including other clubs** | High |
| F7 | `src/Shared/Frontend/FrontendTrialsManageView.php:114` (`handlePost` → `TrialCasesRepository::create`) | `tt_trial_cases` | `player_id` (POST) | n/a (self-contained) | **No — any existing `player_id` accepted** | Medium |
| F8 | `src/Infrastructure/REST/EvaluationsRestController.php:641` (`write_ratings`) | `tt_eval_ratings` | `evaluation_id` (caller-supplied internally), `category_id` | Yes (caller is the eval-create / update handler) | category_id not bounded to eval_type's allowed list, but rated values are clamped | Low (informational) |
| F9 | `src/Infrastructure/REST/TournamentsRestController.php:951` (auto-planner commit) | `tt_tournament_assignments` | `player_id` | Yes | Internal — generated server-side from squad | OK |
| F10 | `src/Infrastructure/REST/ActivitiesRestController.php:911` (`write_attendance`) | `tt_attendance` | `player_id` | Yes (activity team via `club_id`) | **Yes — v4.20.5 roster guard still in place** | OK (already fixed) |
| F11 | `src/Infrastructure/REST/ActivitiesRestController.php:1006` (`add_guest`) | `tt_attendance` (guest row) | `guest_player_id` | Yes (activity scoped by `club_id`) | Yes (linked player checked for `club_id`) | OK |
| F12 | `src/Infrastructure/REST/PlayersRestController.php:437` (`update_player`) | `tt_players` | n/a (no FK in payload) | Yes (`club_id` in WHERE) | n/a | OK |
| F13 | `src/Infrastructure/REST/TeamsRestController.php:307` (`update_team`) | `tt_teams` | n/a | Yes | n/a | OK |
| F14 | `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php:249/251/307/309` (`put_formation`, `put_style`) | `tt_team_formations`, `tt_team_playing_styles` | `team_id` | Yes (via `QueryHelpers::get_team`) | n/a | OK (note: tables lack `club_id`; SaaS scaffold gap, separate concern) |
| F15 | `src/Infrastructure/REST/TournamentsRestController.php:401/423/672/1085/1170` (tournament + match updates) | `tt_tournaments`, `tt_tournament_matches`, `tt_tournament_squad` | various | Yes (`club_id` in WHERE) | n/a or scoped | OK |
| F16 | `src/Infrastructure/REST/FunctionalRolesRestController.php:128/167/240/241` | `tt_functional_roles` | self-contained | Yes | n/a | OK |
| F17 | `src/Infrastructure/REST/LookupsRestController.php:204/233`, `EvalCategoriesRestController.php:177/178`, `CustomFieldsRestController.php:186/187`, `LookupNormalisationRestController.php:133` | various lookup tables | self-contained | Yes (`club_id` in WHERE) | n/a | OK |
| F18 | `src/Infrastructure/REST/PeopleRestController.php:218` (`archive_person`) | `tt_people` | n/a | Yes | n/a | OK |
| F19 | `src/Modules/MatchExecution/Rest/MatchExecutionRestController.php:252` | match execution rows | (deferred — internal flow, not yet stressed) | unverified | unverified | Info (re-audit when matchday usage grows) |

## Patterns / repeated gaps observed

1. **The "scoped UPDATE" idiom is well-established** — most root-entity
   `update_*` handlers correctly use
   `[ 'id' => $id, 'club_id' => CurrentClub::id() ]` in the WHERE. The
   handlers that miss it (F1, F2) are the outliers; both are recent
   evaluations refactors and look like simple omissions, not deliberate
   design choices.

2. **The "submitted-FK trust" pattern is the live #1148 echo.** A handler
   validates the parent (good) but then accepts whatever `player_id` /
   `team_id` shipped in the payload. F1, F3, F4, F6, F7 all share this
   shape. The fix recipe is identical to v4.20.5 attendance: SELECT the
   FK's row, filter on `club_id` (and where relevant, the parent's
   `team_id` / roster), drop the write when the lookup misses.

3. **Cross-team-within-club is a real boundary too.** F4 and F5 allow
   submitting a `player_id` who is in the same club but on a different
   team. The attendance fix in v4.20.5 enforces same-team for non-guest
   rows; the tournament squad / assignment paths don't (yet). The squad
   case is arguably intentional (a coach loaning a U17 player to play
   for U15 in a tournament), but the assignment path absolutely should
   refuse a player_id not in the tournament's squad — there is no
   business case for assigning random academy players to tournament
   minutes.

4. **`tt_team_formations` / `tt_team_playing_styles` have no `club_id`
   column at all.** Reads + writes filter via `team_id` only. Today
   that's fine (single tenant), tomorrow it's a SaaS-readiness gap and
   conflicts with CLAUDE.md § 4 ("new tables include the tenancy column
   scaffold"). Not audit-2-blocking, but worth a separate idea file.

5. **Theoretical risks not pursued as issue specs:** every controller
   guards behind a `permission_callback` that today enforces the
   capability layer. A user who lacks `tt_edit_evaluations` can't reach
   `update_eval` at all. The findings above assume an attacker is
   already an authenticated, role-bearing user (coach / head coach /
   admin) — and is therefore inside the threat model: a coach in club A
   who hand-crafts JSON to mutate club B's evaluations is exactly the
   shape SaaS multi-tenancy needs to refuse. Filing as concrete data
   integrity bugs, not as theoretical input validation.

## Refactor suggestion

Add a shared helper under `src/Infrastructure/Security/`:

```php
final class ScopeGuard {
    /**
     * Returns the row id if `$row_id` exists in `$table` (`{$prefix}$table`)
     * scoped to the current club; null otherwise. Use as a one-line guard
     * before a child write:
     *
     *   if ( ScopeGuard::assertOwned( 'tt_players', $player_id ) === null ) {
     *       return RestResponse::error( 'forbidden_player', ..., 403 );
     *   }
     */
    public static function assertOwned( string $table, int $row_id, ?int $extra_team_id = null ): ?int;

    /**
     * Asserts the child FK `$player_id` belongs to the parent's team scope.
     * Mirrors the v4.20.5 attendance roster guard. Returns true when the
     * submitted player is on the activity / tournament / team scope.
     */
    public static function playerInTeamScope( int $player_id, int $team_id ): bool;
}
```

The three #1148-shape findings (F1, F3, F4) collapse into three
`ScopeGuard::assertOwned()` calls + one `playerInTeamScope()` call. F6 and
F7 also collapse into single-line guards. Doing this once means the next
new REST controller doesn't re-invent the check (or skip it).

The repo already has `AuthorizationService::isHeadCoachOfPlayer()` and
`QueryHelpers::coach_owns_player()` — `ScopeGuard` would consolidate the
"does this id even belong here?" question separately from the
"is this user allowed to touch it?" question.

## Follow-up issue specs

=== ISSUE SPEC START ===
TITLE: REST `PUT /evaluations/{id}` skips club_id + player scope check (audit 2)
SEVERITY: critical
BODY: `EvaluationsRestController::update_eval` (src/Infrastructure/REST/EvaluationsRestController.php:494) issues `$wpdb->update("{$p}tt_evaluations", $header, [ 'id' => $id ])` with no `club_id` in the WHERE and no `coach_owns_player` check on the submitted `player_id`. This means: (a) a coach in club A who knows an evaluation id from club B can rewrite that row's player_id / date / notes; (b) a coach in their own club can re-point an existing evaluation at any player_id, bypassing the `coach_owns_player` gate that `create_eval` enforces (line 444-447). Same #1148 shape: child rows accept arbitrary FKs without scope check. Fix at src/Infrastructure/REST/EvaluationsRestController.php:494 — mirror `create_eval`: re-run the `coach_owns_player` check on the submitted `player_id`, and add `'club_id' => CurrentClub::id()` to the UPDATE's WHERE. Same fix shape should be applied to `delete_eval` (line 569) which also lacks `club_id` in WHERE. Audit ref: #1176.
=== ISSUE SPEC END ===

=== ISSUE SPEC START ===
TITLE: REST `POST /goals` accepts arbitrary player_id without scope check (audit 2)
SEVERITY: critical
BODY: `GoalsRestController::create_goal` (src/Infrastructure/REST/GoalsRestController.php:304) inserts a `tt_goals` row with `player_id` taken directly from the request payload, validated only to be `> 0`. No check that the player belongs to the current club, and no `coach_owns_player` (or equivalent matrix-cap) check that the writer is allowed to set goals for that player. A coach in club A with `tt_create_goals` can write a goal against any player_id, including players in other clubs — the goal's `club_id` is set to the writer's club, so it appears on the writer's dashboards while pointing at a foreign player record. Same #1148 shape. Fix at src/Infrastructure/REST/GoalsRestController.php:300-304 — add a `QueryHelpers::get_player($data['player_id'])` lookup that returns null for cross-club ids, and for non-admin writers add a `coach_owns_player` / `AuthorizationService::isCoachOfPlayer` check before the insert. Audit ref: #1176.
=== ISSUE SPEC END ===

=== ISSUE SPEC START ===
TITLE: REST `PUT /tournaments/{id}/matches/{m_id}/assignments` accepts off-squad player_ids (audit 2)
SEVERITY: high
BODY: `TournamentsRestController::update_assignments` (src/Infrastructure/REST/TournamentsRestController.php:571) inserts `tt_tournament_assignments` rows with `player_id` straight from the payload, no validation. The parent match is properly scoped (line 544 — `fetchMatch` filters by `club_id`), but the per-row `player_id` is trusted. Result: a coach can assign tournament minutes to any player_id within their club — including players not in the tournament's squad, not on the tournament's team, even archived players. Concrete reproduction of the #1148 pattern: the parent (match) is scope-checked, the child FK (player_id) is not. Fix at src/Infrastructure/REST/TournamentsRestController.php:562-577 — before each insert, verify the submitted `player_id` exists in `tt_tournament_squad WHERE tournament_id = %d AND club_id = %d`. Drop the row with a warning log when it doesn't (same diagnostic shape as v4.20.5 attendance `dropped_off_roster`). Audit ref: #1176.
=== ISSUE SPEC END ===

=== ISSUE SPEC START ===
TITLE: REST `POST /teams/{id}/players` accepts arbitrary team_id, reassigns players across clubs (audit 2)
SEVERITY: high
BODY: `TeamsRestController::add_player_to_team` (src/Infrastructure/REST/TeamsRestController.php:345) does `$wpdb->update($wpdb->prefix.'tt_players', [ 'team_id' => $team_id ], [ 'id' => $player_id, 'club_id' => CurrentClub::id() ])`. The player_id is filtered to the current club (good), but `team_id` is the raw path parameter — never validated to exist or to belong to the current club. A coach can therefore reassign one of their own club's players to a `team_id` belonging to another club. The player row keeps its `club_id` but its `team_id` now points at a foreign team, breaking every JOIN in dashboards, evaluations, attendance, and tournaments — the player disappears from their original team without showing up anywhere coherent. Fix at src/Infrastructure/REST/TeamsRestController.php:342-345 — before the UPDATE, verify `team_id` exists via `QueryHelpers::get_team($team_id)` (which is already club-scoped). Return 404 / 403 when it doesn't. Audit ref: #1176.
=== ISSUE SPEC END ===

=== ISSUE SPEC START ===
TITLE: Trial case create accepts arbitrary player_id, links foreign player to current-club trial (audit 2)
SEVERITY: medium
BODY: `FrontendTrialsManageView::handlePost` (src/Shared/Frontend/FrontendTrialsManageView.php:74-121) reads `player_id` from `$_POST`, optionally inline-creates a new player (correctly scoped to current club), then calls `TrialCasesRepository::create([ 'player_id' => $player_id, ... ])`. When `player_id` is supplied directly (the dropdown path, not the inline-create path), no check that the player belongs to the current club. The resulting `tt_trial_cases` row carries the writer's `club_id` but a cross-club `player_id`, breaking the trial cascade (player status updates at line 130 silently no-op on the foreign row because they're club-scoped). Same #1148 shape on a forward-only entity. Fix at src/Shared/Frontend/FrontendTrialsManageView.php:108 — before the `$cases->create()` call, when `player_id > 0` was supplied via POST, run `QueryHelpers::get_player($player_id)` and short-circuit with an error notice when null. Audit ref: #1176.
=== ISSUE SPEC END ===
