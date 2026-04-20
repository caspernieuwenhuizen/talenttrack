# TalentTrack v2.8.0 — Sprint 1E: Authorization Core

## What's new

Introduces `AuthorizationService` — the central authorization layer for TalentTrack. This is Sprint 1E, the foundation of a 3-sprint RBAC arc designed for enterprise scalability.

### The core service

`src/Infrastructure/Security/AuthorizationService.php` provides entity-scoped authorization decisions combining WP capabilities with team-membership lookups:

```php
AuthorizationService::canViewPlayer($user_id, $player_id);
AuthorizationService::canEditPlayer($user_id, $player_id);
AuthorizationService::canEvaluatePlayer($user_id, $player_id);
AuthorizationService::canManageTeam($user_id, $team_id);
AuthorizationService::canAssignStaff($user_id, $team_id);
```

Also exposes helpers:

```php
AuthorizationService::getPersonIdByUserId($user_id);    // resolve WP user → tt_people
AuthorizationService::getCurrentPersonId();
AuthorizationService::getUserRolesOnTeam($user_id, $team_id);
AuthorizationService::userHasRole($user_id, $role_slug);
```

### How decisions are made (Sprint 1E)

Each method evaluates a rule like:

- **canViewPlayer:** admin, OR scout, OR it's your own record, OR you're staff on the player's team
- **canEditPlayer:** admin, OR head_coach/manager of the player's team
- **canEvaluatePlayer:** admin with evaluate cap, OR scout, OR coach (head/assistant) of the player's team with evaluate cap
- **canManageTeam:** admin, OR head_coach/manager of the team
- **canAssignStaff:** stricter — admin with both tt_manage_players and tt_manage_settings

Rules are hardcoded in PHP. **In Sprint 1F these will be replaced by data-driven evaluation** against `tt_roles`, `tt_role_permissions`, `tt_user_role_scopes` tables. The public API of AuthorizationService stays stable — callers won't notice the change.

### Hooks and filters for extensibility

Every decision passes through filters and fires a hook for audit:

```php
// Per-action filters
add_filter('tt_auth_can_view_player', function($allowed, $user_id, $player_id) {
    // modify decision
});

// Generic filter
add_filter('tt_auth_check_result', function($allowed, $action, $user_id, $entity_type, $entity_id) {
    // intercept any decision
});

// Audit hook — fires for every check
add_action('tt_auth_check', function($action, $user_id, $entity_id, $result) {
    // log it, count it, whatever
});
```

### Per-request caching

Three caches:
- `user_id → person_id` — resolved lazily, retained for the request
- `user_id → (team_id → [roles])` — built once from tt_team_people
- decision results keyed by `user_id:action:entity_type:entity_id`

Automatically flushed on:
- `tt_person_assigned_to_team` — staff assignment changed
- `tt_person_created` — new person record may change user link
- `wp_login`, `wp_logout` — user session transition

## Where it's wired in (pilot sites)

This is a pilot integration — not every `current_user_can()` in the codebase is converted. The places where entity scope actually matters are converted now; the rest follow in Sprint 1F.

### REST — `PlayersRestController`
- `GET /players/{id}` → `canViewPlayer`
- `GET /players` → filters result rows by `canViewPlayer` (row-level security)
- `PUT /players/{id}` → `canEditPlayer`
- `POST /players` → capability-only (no entity to scope against on create)
- `DELETE /players/{id}` → capability-only (destructive)

### Admin — `EvaluationsPage`
- `handle_save` → `canEvaluatePlayer(user, posted_player_id)`
- `handle_delete` → `canEvaluatePlayer(user, evaluation's player_id)`

### Admin — `PlayersPage`
- `handle_save` (existing player) → `canEditPlayer`
- `handle_save` (new player) → capability-only
- `handle_delete` → capability-only

### Admin — `TeamsPage`
- `handle_save` (existing team) → `canManageTeam`
- `handle_save` (new team) → capability-only
- `handle_delete` → capability-only

### Admin — `PeopleModule::handleAssignStaff`
- `canAssignStaff(user, team_id)` — stricter than team management

## What's NOT converted (Sprint 1F backlog)

Roughly 30+ `current_user_can()` calls remain in:
- Shared admin menu registration (`src/Shared/Admin/Menu.php`) — these are pure gate checks, not entity auth
- Frontend dashboards and AJAX handlers — need integration review
- Goals, Sessions, Reports, Configuration, Documentation modules — need review
- Custom Fields admin tab
- Players list page view handler

These keep working exactly as they did before. Sprint 1F will inventory them and either migrate or explicitly keep them as capability-only gates.

## Enterprise RBAC roadmap — where this is heading

### Sprint 1F — Roles as data (NEXT)

Add three tables:

**`tt_roles`** — defines roles that exist in the academy
- id, key, label, description
- Seeded with 9 defaults: club_admin, head_of_development, head_coach, assistant_coach, manager, physio, scout, parent, player

**`tt_role_permissions`** — maps roles to abstract permissions
- role_id, permission
- Permissions follow `domain.action` convention: `players.view`, `evaluations.create`, `team.manage`, etc.

**`tt_user_role_scopes`** — assigns a role to a person, optionally scoped to an entity
- person_id, role_id, scope_type (global/team/player/person), scope_id
- Example: Jan as head_coach scoped to team 12; Alice as parent scoped to player 23

AuthorizationService internals get rewritten to evaluate against this data instead of the hardcoded rules. Public API stays the same — your pilot integrations from Sprint 1E keep working.

Migration: existing `tt_team_people` assignments auto-grant equivalent roles, so no data loss, no manual reassignment needed.

### Sprint 1G — Admin UI (LATER)

- TalentTrack → Roles & Permissions page — view the matrix, edit role permissions
- Per-person role assignment UI — "Assign Jan as head_coach of team 12 from date X to date Y"
- Custom role creation — "Create a role called 'Analyst' with these permissions"

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.8.0`, create release.
3. No deactivate/reactivate needed — no schema changes this sprint.
4. Test: as admin, create/edit players and teams — unchanged.
5. Test: assign yourself as head_coach of a team via People + Staff panel. Create a non-admin WP user, link to a `tt_people` record, assign them as assistant_coach of the same team. They should be able to view players on that team and create evaluations for them, but not delete players or reassign staff.

## Files in this release

### New
- `src/Infrastructure/Security/AuthorizationService.php`

### Modified
- `src/Core/Kernel.php` — registers cache invalidators at boot
- `src/Infrastructure/REST/PlayersRestController.php` — entity-scoped REST auth
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — handle_save/delete use canEvaluatePlayer
- `src/Modules/Players/Admin/PlayersPage.php` — handle_save uses canEditPlayer for updates
- `src/Modules/Teams/Admin/TeamsPage.php` — handle_save uses canManageTeam for updates
- `src/Modules/People/PeopleModule.php` — handleAssignStaff uses canAssignStaff
- `talenttrack.php` — version bump
- `readme.txt` — stable tag + changelog

No changes to tables, translations, or user-facing strings (no new UI this sprint).

## Verify after install

1. Existing admin flows work unchanged (you're an admin with all caps)
2. `GET /wp-json/talenttrack/v1/players/{id}` returns 403 for users without view access
3. In the browser console with an admin session: `fetch('/wp-json/talenttrack/v1/players').then(r => r.json()).then(console.log)` returns your players as normal
4. Audit hook fires: `add_action('tt_auth_check', function($action, $uid, $eid, $ok) { error_log("AUTH: $action uid=$uid eid=$eid ok=" . ($ok?'Y':'N')); });` added to a mu-plugin shows decisions in the error log
