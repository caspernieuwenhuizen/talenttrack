# TalentTrack v2.9.0 — Sprint 1F: Roles as data + admin UI

## What's new

Sprint 1E (v2.8.0) introduced `AuthorizationService` with entity-scoped decisions backed by hardcoded rules. Sprint 1F makes those rules **data-driven**: roles live in the database, each role carries a permission set, and people get roles with scope. An admin UI makes the whole system testable and understandable.

## Mental model

Three tables define the authorization system. Each answers a different question.

**`tt_roles`** — "What kinds of people do we have?"
- 9 seeded system roles: `club_admin`, `head_of_development`, `head_coach`, `assistant_coach`, `manager`, `physio`, `scout`, `parent`, `player`
- `is_system = 1` on all seeded roles → permission sets are read-only in UI

**`tt_role_permissions`** — "What can each kind of person do?"
- Rows like `(head_coach, players.view)`, `(head_coach, evaluations.create)`, `(head_coach, team.manage)` …
- Naming convention: `<domain>.<action>`
- Wildcards supported: `*.*` (everything), `<domain>.*` (domain-wide)

**`tt_user_role_scopes`** — "Who IS what, and where?"
- Rows like `(person_id=5, role_id=3_head_coach, scope_type=team, scope_id=12)` — "Jan is head coach of team 12"
- `scope_type` values: `global`, `team`, `player`, `person`
- Optional `start_date` and `end_date` for temporal scoping
- Optional `granted_by_person_id` for delegation audit

## Decision flow

When the service is asked "can user U do action A on entity E?":

1. Resolve U → person_id via `tt_people.wp_user_id`
2. Load all **active** role-scopes for that person (start/end dates honored)
3. Add permissions from the **legacy bridge**: existing `tt_team_people` assignments and `tt_teams.head_coach_id` auto-grant equivalent permissions
4. Add permissions from **derived sources**: if the WP user is linked to a `tt_players` row, grant `player` role scoped to that player
5. Walk the combined scope list. A scope matches if it's global OR matches the target entity exactly. Within a matching scope, check if any granted permission satisfies the request (with wildcard support).
6. Fire `tt_auth_check` for audit. Return the answer.

## Permission matrix (seed data)

| Role | Permissions | Scope types |
|------|-------------|-------------|
| `club_admin` | `*.*` | global |
| `head_of_development` | players.view, evaluations.*, reports.view, config.view, people.view, teams.view, goals.view, sessions.view | global |
| `head_coach` | players.view/edit, evaluations.*, sessions.view/manage, goals.view/manage, team.manage, people.view | team |
| `assistant_coach` | players.view, evaluations.view/create/edit_own, sessions.view, goals.view | team |
| `manager` | players.view/edit, team.manage, sessions.view/manage, goals.view, people.view | team |
| `physio` | players.view, sessions.view | team |
| `scout` | players.view, evaluations.view/create/edit_own | global OR team |
| `parent` | players.view_own_children, evaluations.view_own_children, goals.view_own_children | player |
| `player` | players.view_own, evaluations.view_own, goals.view_own | (auto-derived, not grantable) |

The matrix lives in `Activator::defaultRoleDefinitions()`. It seeds on first activation and isn't overwritten on subsequent activations — once data exists in `tt_roles`, the seeder skips.

## Admin UI

**TalentTrack → Roles & Permissions** (`tt_manage_settings` required)
- List of all 9 roles with counts of permissions and assignments
- Click a role → detail view showing the permission matrix grouped by domain, plus a table of current assignments with revoke buttons

**TalentTrack → Permission Debug** (`tt_manage_settings` required)
- Pick any WordPress user, see:
  - Their WP role(s) and whether they're an administrator (admin override fires)
  - Their linked `tt_people` record
  - Every resolved scope with **source attribution** (`role_scope` / `legacy_team_people` / `legacy_head_coach_id` / `derived_player_link`)
  - The permissions granted within each scope
  - A flattened view showing the union of permissions grouped by scope
- Read-only; zero state changes

**Person edit page** (TalentTrack → People → Edit)
- New "Role assignments" section below the existing Team assignments
- Table of current grants with revoke buttons
- Grant form: select role → scope options filter dynamically to what makes sense (head_coach can only be team-scoped, parent can only be player-scoped, etc.) → optional dates → submit
- Uses JS to hide inapplicable scope dropdowns and sync the hidden `scope_id` field based on scope_type

## What the UI lets you test

The full design loop:

1. Create a WP user for a test coach (e.g. `jan_coach`)
2. Create a `tt_people` record for Jan with `wp_user_id` linked
3. In Jan's Person edit page, grant him `head_coach` role scoped to Team 12
4. Log in as Jan. Try viewing/editing players — should work for Team 12 players only
5. Open Permission Debug with Jan selected — verify his resolved scopes
6. Revoke the role, check that access is removed
7. Add a legacy assignment via the Staff panel on Team 15 — debug page shows it as `legacy_team_people` source, access automatically works

## Public API — unchanged

Every pilot site from Sprint 1E keeps working without modification:

```php
AuthorizationService::canViewPlayer($user_id, $player_id);
AuthorizationService::canEditPlayer($user_id, $player_id);
AuthorizationService::canEvaluatePlayer($user_id, $player_id);
AuthorizationService::canManageTeam($user_id, $team_id);
AuthorizationService::canAssignStaff($user_id, $team_id);
```

New public primitive available for future work:

```php
AuthorizationService::userHasPermission(
    $user_id,
    'players.edit',         // permission string
    'team',                  // scope type (optional)
    $team_id                 // scope id (optional)
);
```

## New hooks

```php
do_action('tt_role_granted', $scope_id_pk, $person_id, $role_id, $scope_type, $scope_id);
do_action('tt_role_revoked', $scope_id_pk, $person_id, $role_id, $scope_type, $scope_id);

apply_filters('tt_auth_resolve_permissions', $scopes, $user_id);
```

The filter is the extension point: third-party code can add synthetic scope entries without writing to `tt_user_role_scopes`. Useful for "all authenticated users get X" or context-dependent grants.

Existing hooks continue to work: `tt_auth_check`, `tt_auth_can_view_player`, `tt_auth_can_edit_player`, `tt_auth_can_evaluate_player`, `tt_auth_can_manage_team`, `tt_auth_can_assign_staff`, `tt_auth_check_result`.

## Legacy bridge — how existing data keeps working

No data migration. Two automatic sources keep adding permissions on the fly:

**Source 2: `tt_team_people`**
Each row in this table implies a scoped role grant. The mapping:
- `head_coach` role_in_team → `head_coach` role
- `assistant_coach` → `assistant_coach`
- `manager` → `manager`
- `physio` → `physio`
- `other` → `physio` (read-only)

**Source 3: `tt_teams.head_coach_id`**
Each non-zero value is a team whose legacy head coach is the pointed-at WP user. They get the `head_coach` role scoped to that team.

**Source 4: `tt_players.wp_user_id`**
A WP user linked to a player gets the `player` role scoped to that player. Enables `players.view_own` / `evaluations.view_own` / `goals.view_own` without any manual role grants.

Both the legacy bridge and derived sources show up in the Permission Debug page with explicit source labels so admins can always trace "why can this user do this?"

## Performance notes

- Every decision is cached per request (key: `user_id:action:entity_type:entity_id`)
- Resolved scopes for a user are computed once per request
- Role permissions are cached by role_key once per request
- Cache automatically flushes on: `tt_person_assigned_to_team`, `tt_person_created`, `tt_role_granted`, `tt_role_revoked`, `wp_login`, `wp_logout`

Typical request with repeated auth checks: 1-3 SQL queries at the start of the request, then cache hits for everything else.

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.9.0`, create release.
3. WordPress admin → Plugins → **Deactivate TalentTrack → Activate TalentTrack**. This creates the three new tables and seeds the 9 system roles with their permission matrix.
4. Visit **TalentTrack → Roles & Permissions**. You should see 9 roles listed with their permission counts.
5. Visit **TalentTrack → Permission Debug**, pick yourself. Should show "Administrator override" since you're a WP admin.
6. Pick a test coach user from the debug dropdown to see legacy bridge behavior if they have `tt_team_people` assignments.

## Verify after install

SQL checks:
```sql
SELECT COUNT(*) FROM z06x_tt_roles;                -- 9
SELECT COUNT(*) FROM z06x_tt_role_permissions;     -- should be ~50+
SELECT COUNT(*) FROM z06x_tt_user_role_scopes;     -- 0 (nobody assigned yet)
SELECT * FROM z06x_tt_migrations ORDER BY id DESC; -- 5th row: 0005_authorization_rbac
```

Admin flow check:
1. TalentTrack → People → edit any person → scroll down → "Role assignments" section appears
2. Grant yourself club_admin (global) for testing purposes → redirected to list with success notice
3. TalentTrack → Roles & Permissions → click Club Admin → see your assignment listed
4. Revoke from either page → assignment removed

## Files in this release

### New
- `src/Infrastructure/Authorization/AuthorizationRepository.php` — data access
- `src/Modules/Authorization/AuthorizationModule.php` — module registration
- `src/Modules/Authorization/Admin/RolesPage.php` — role list + detail
- `src/Modules/Authorization/Admin/DebugPage.php` — permission diagnostic
- `src/Modules/Authorization/Admin/RoleGrantPanel.php` — grant form for Person edit page

### Modified
- `src/Core/Activator.php` — 3 new tables + seed matrix
- `src/Infrastructure/Security/AuthorizationService.php` — data-driven internals
- `src/Modules/People/Admin/PeoplePage.php` — renders RoleGrantPanel on edit
- `config/modules.php` — AuthorizationModule registered
- `talenttrack.php` — v2.9.0
- `readme.txt` — changelog
- `languages/talenttrack-nl_NL.po` + `.mo` — 439 entries (baseline + 50 new Sprint 1F strings)

## Sprint 1G backlog (future)

- Custom role creation with permission matrix editor
- Editing system role permissions (currently read-only for safety)
- Bulk role assignments ("assign this role to everyone in team X")
- Scheduled role transitions (e.g. season-based start/end dates with automated workflows)
- Drop legacy bridge / sunset path for `head_coach_id`
- REST endpoints for role assignment
- Role cloning ("new role based on head_coach with these changes")

## Known limitations in v2.9.0

- System role permissions cannot be edited. You can assign/revoke system roles but not change what they grant.
- No custom role creation yet. The 9 seeded roles are the full universe.
- The Roles & Permissions detail page shows assignments but doesn't offer a "grant role to someone" shortcut — you go through the Person edit page. Could add in Sprint 1G if the workflow friction shows up.
- No bulk operations.
- Delete buttons are missing for non-system roles because there's no way to create one yet. The UI scaffolding is in place.
