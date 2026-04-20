# TalentTrack v2.10.0 — Functional roles, separated from authorization roles

## What was wrong

v2.9.x conflated two different concepts in `tt_team_people.role_in_team`. The string `head_coach` was simultaneously:

1. **What this person's job is on the team** — the label rendered on the Staff panel, the thing a human would pick from a dropdown, a pure organizational concept.
2. **What permissions they get** — a string the Authorization service would reverse-engineer into a set of capabilities.

That conflation made it impossible to say things like "this head coach should *also* have physio-level permissions" without either inventing fake roles (`head_coach_plus_physio` — a combinatorial explosion) or granting a second global role in `tt_user_role_scopes` (wrong: not scoped to the team). It also meant `role_in_team` values couldn't be customized without silently changing permission behavior.

Separately, the Sprint 1F legacy bridge in `AuthorizationService::resolveScopesForUser()` had two hardcoded code paths:

- A PHP map `[ 'head_coach' => 'head_coach', 'other' => 'physio', ... ]` that synthesized scopes from `tt_team_people` on every request.
- A parallel path reading `tt_teams.head_coach_id` (a raw WP user ID column — every other auth path goes through `tt_people`) and granting a head_coach scope.

Both were "working" but neither was configurable and both lived in code, not data.

## The fix

**Two tables, one column, one migration.**

Functional roles are now a real first-class concept in the database, with a configurable mapping to authorization roles:

```
tt_functional_roles                  — head_coach, assistant_coach, manager, physio, other
tt_functional_role_auth_roles        — (functional_role_id, auth_role_id) mapping
tt_team_people.functional_role_id    — new FK column (role_in_team retained for safety)
```

`AuthorizationService::resolveScopesForUser()` now walks `tt_team_people.functional_role_id` → `tt_functional_role_auth_roles` → one scope entry per auth role at team scope. The PHP legacy map is gone. The `tt_teams.head_coach_id` bridge is gone too — the 0006 migration promoted every non-zero value into an explicit `tt_team_people` row on upgrade.

This enables cases like "Head Coach who also does physio": on the Functional Roles admin page, edit **Head Coach** and tick both the `head_coach` and `physio` authorization roles. Every head coach then gets both permission sets, scoped to their team. The organizational view (the Staff panel) still shows them once, under Head Coach — the physio-level permissions show up on the Roles & Permissions detail page with Source = "via Head Coach".

The permissions-in-code story didn't change. What changed is the *path* from "this person is a head coach on U14" to "they have `players.edit` on U14": it now goes through editable data, not hardcoded PHP.

## New: `team_member` authorization role

The `other` functional role previously mapped (implicitly, via the legacy bridge) to `physio` permissions — a compromise. Sprint 1G introduces an explicit `team_member` auth role seeded with the absolute minimum (`players.view`, `sessions.view`) and maps `other` to it by default. If you want `other` to grant more (or less), that's now a couple of clicks on the Functional Roles detail page rather than a schema change.

## UI additions

1. **TalentTrack → Functional Roles** — new admin page. Lists the 5 system functional roles with their mapped authorization roles, assignment count, system flag. Each detail page is a mapping editor: per-auth-role checkbox, save, done. Also shows which people are currently assigned to a team with this functional role.
2. **Roles & Permissions detail page** gains a **Source** column. Direct grants (from `tt_user_role_scopes`) show Source = "Direct" with a Revoke button. Indirect grants (via a functional role) show Source = "via *Head Coach*" (linked to the functional role detail page) with no Revoke — to remove them, either unassign the person from the team or change the mapping.
3. **Staff panel on team edit** — the role dropdown now reads from `tt_functional_roles` instead of a hardcoded PHP constant. Visually identical.
4. **Permission Debug page** — the Source cell for functional-role-derived scopes shows "via **Head Coach**" under the source label.

## Migration path (no data loss)

Existing sites:

1. **Activation ensures the schema.** `ensureSchema()` adds the two new tables and the `functional_role_id` column idempotently via `dbDelta`. The existing `uniq_team_person_role` unique constraint is preserved; a new `uniq_team_person_fnrole` is added alongside it for the transition.
2. **`seedFunctionalRolesIfEmpty()`** inserts the 5 system functional roles and the default 1-to-1 mapping to authorization roles. Only inserts what isn't already there — safe on re-activation.
3. **`seedRolesIfEmpty()`** was retrofitted to top-up missing system roles, so the new `team_member` role lands on Sprint 1F installs that already had the other 9 roles seeded.
4. **Migration `0006_functional_role_backfill`** runs via the MigrationRunner. It translates every `role_in_team` string to the matching `functional_role_id` FK, and promotes every non-zero `tt_teams.head_coach_id` into an explicit `tt_team_people` row (creating a `tt_people` record for the WP user if one doesn't exist).

After upgrade, the permission surface for every existing user is identical to v2.9.x. Nothing they could do before is taken away; the mapping defaults preserve 1-to-1 behavior. The one exception is the `other` functional role, which previously mapped (via the legacy bridge) to `physio` permissions and now maps to the new, leaner `team_member` auth role. If any site was relying on "other" staff having physio-equivalent access, ticking the `physio` box on the `other` functional role's detail page restores it.

Nothing is dropped. `tt_team_people.role_in_team` stays (kept in sync on new assignments), `tt_teams.head_coach_id` stays (no longer read for permissions). A future sprint can drop either once we're sure nothing external is reading them.

## Localization

Following the v2.9.1 pattern: `tt_functional_roles.label` is seeded in English as a stable identifier; display always goes through `FunctionalRolesPage::roleLabel()` / `roleDescription()`, which are `__()`-wrapped lookups keyed on `role_key`. 31 new Dutch translations were added to `talenttrack-nl_NL.po` covering every user-facing string in the new UI. Totals: 483 msgids in the `.po`, 480 in the compiled `.mo` (three header-only entries excluded as usual).

## Files in this release

### New
- `src/Infrastructure/Authorization/FunctionalRolesRepository.php` — data access for functional roles and their auth-role mapping
- `src/Modules/Authorization/Admin/FunctionalRolesPage.php` — list + mapping editor
- `database/migrations/0006_functional_role_backfill.php` — data migration

### Modified
- `talenttrack.php` — version 2.10.0
- `src/Core/Activator.php` — 2 new tables, 1 new column, 1 new auth role seed (`team_member`), `seedFunctionalRolesIfEmpty()`, top-up logic for `seedRolesIfEmpty()`, `defaultFunctionalRoleDefinitions()`
- `src/Infrastructure/Security/AuthorizationService.php` — Sources 2 and 3 rewritten to use functional-role mapping; legacy bridges gone from the resolution path; cache-invalidator listens for `tt_functional_role_mapping_updated`; helpers `getRoleKeyById()`, `dateActive()`
- `src/Infrastructure/People/PeopleRepository.php` — `assignToTeam()` takes `functional_role_id`; `getTeamStaff()` groups by functional role key; legacy `head_coach_id` synthesis removed; `getPersonTeams()` joins functional roles
- `src/Modules/Authorization/AuthorizationModule.php` — menu item + POST handler for the Functional Roles page
- `src/Modules/Authorization/Admin/RolesPage.php` — Source column, indirect grants merged in, `team_member` added to label/description/allowed scopes
- `src/Modules/Authorization/Admin/DebugPage.php` — new `functional_role` source label, "via {functional role}" annotation
- `src/Modules/People/Admin/TeamStaffPanel.php` — dropdown reads from `tt_functional_roles`, grouping by functional role key
- `src/Modules/People/Admin/PeoplePage.php` — team-assignments table labels via `FunctionalRolesPage::roleLabel()`
- `src/Modules/People/PeopleModule.php` — `handleAssignStaff()` reads `functional_role_id`
- `src/Modules/Teams/Admin/TeamsPage.php` — updated warning on the legacy `head_coach_id` field
- `languages/talenttrack-nl_NL.po` + `.mo` — 31 new translations; 483 msgids total

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.10.0`, release.
3. Deactivate and reactivate the plugin. The activator creates the new tables and seeds, then the MigrationRunner runs 0006 to backfill existing data.

## Verify

1. TalentTrack → **Functional Roles** → 5 roles listed, each with one auth role mapped
2. Click **Head Coach** → detail page shows a table of team-scoped auth roles, with `head_coach` ticked; tick `physio`, save; redirect back with "Saved"
3. Check that Head Coach's permissions now include `players.view`, `sessions.view` from the physio role → open TalentTrack → **Permission Debug** → pick a WP user who is a head coach → their resolved scopes now show two entries for that team (one `head_coach`, one `physio`) both with Source = "Via functional role" + "via **Head Coach**"
4. TalentTrack → **Roles & Permissions** → click **Physio** → the assignments table now has a Source column. Your test head coach appears with Source = "via Head Coach", no Revoke button
5. Edit a team → Staff section renders as before; the Add Staff dropdown shows Functional roles
6. Edit a person → Team assignments section renders the functional role label
7. All strings on the new pages render in Dutch when site locale is `nl_NL`

## Still deferred

- Custom functional role creation (custom `role_in_team` labels you can define yourself)
- Editing the permission matrix of custom authorization roles
- Eventually: drop `tt_team_people.role_in_team` and `tt_teams.head_coach_id` once enough time has passed that nothing external is reading them
- Parent-to-player relationships, release automation — unchanged from v2.9.1's backlog
- The `includes/` directory still contains dead v1.x files — untouched, flagged again
