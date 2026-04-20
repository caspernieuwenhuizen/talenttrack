# TalentTrack v2.7.0 — Sprint 1D: People/Staff domain

## What's new

Introduces a People/Staff domain to support multiple staff per team, flexible roles, and future non-staff people like parents.

### Mental model

- **`tt_people`** holds anyone the system tracks — staff, parents, scouts, external people. A person in this table is just a record; they aren't staff until they're assigned to a team.
- **`tt_team_people`** is the assignment relationship. Being listed here for a given team with a given role is what makes someone "staff" in that context.
- Menu label is **People** because the table represents everyone. The team edit page calls its section **Staff** because that's the assignment context.

### New feature set

**People admin page** (TalentTrack → People)
- List with filters: status (active/inactive), search, and "Only staff" toggle that shows people with at least one team assignment.
- Create / edit / activate / deactivate.
- Fields: first name, last name, email, phone, primary role, linked WordPress user, status.
- `role_type` values: coach, assistant_coach, manager, staff, physio, scout, parent, other. Defaults to `other`.
- Edit screen includes a Team assignments section showing which teams this person is staff on.

**Team-staff assignments** (team edit page)
- New Staff section displays current staff grouped by role: head coach, assistant coach, manager, physio, other.
- Add-staff form lets you select any active person, choose their role on this team, and optionally set start/end dates.
- Multiple people per role allowed.
- Each assignment has an Unassign button.
- Legacy `tt_teams.head_coach_id` is shown as a synthetic row labeled "Legacy" — read-only, with a hint explaining how to migrate.
- Teams list now shows a Staff count column alongside Players.

**Backward compatibility**
- `tt_teams.head_coach_id` is deprecated but still read. `getTeamStaff()` unions assignment rows with a synthetic row for the legacy head coach, so existing data keeps displaying.
- New assignments write only to `tt_team_people`. The legacy column is not updated on save, so it cleanly freezes the old value until a future release drops it.
- Team edit page still has a Head Coach dropdown that writes to `head_coach_id` — noted with a description as legacy.
- Team deletion now also cleans up `tt_team_people` rows for that team, avoiding orphans.

**Hooks**

```php
do_action('tt_person_created', $person);
do_action('tt_person_assigned_to_team', $team_id, $person_id, $role);
```

### Infrastructure cleanup

- `MigrationRunner::scanMigrationFiles()` now filters out `index.php` and anything that doesn't match the `NNNN_name.php` migration naming convention. The phantom "index" row in the Migrations admin page is gone.
- Activator's `markMigrationsApplied()` unchanged; migrations 0001-0004 still auto-marked on activation.

### Capability

All new admin actions require `tt_manage_players` (temporarily reused). A dedicated `tt_manage_people` capability is on the backlog for a future RBAC update.

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` (overwriting existing files).
2. Commit, push, tag `v2.7.0`, create release.
3. WordPress admin → Plugins → **Deactivate TalentTrack → Activate TalentTrack.** This triggers the activator which creates the two new tables via `dbDelta` and marks legacy migrations applied.
4. Done.

## Optional cleanup (via FTP)

Delete this obsolete file if it still exists on your server:

```
/wp-content/plugins/talenttrack/database/migrations/0004_schema_reconciliation.php
```

The Activator has handled that schema reconciliation directly since v2.6.6. The file is harmless if left in place (the migration is already recorded as applied, so the runner will skip it), but removing it keeps the `database/migrations/` directory clean.

## Files in this release

### New
- `src/Modules/People/PeopleModule.php`
- `src/Modules/People/Admin/PeoplePage.php`
- `src/Modules/People/Admin/TeamStaffPanel.php`
- `src/Infrastructure/People/PeopleRepository.php`

### Modified
- `src/Core/Activator.php` — adds `tt_people` and `tt_team_people` to ensureSchema
- `src/Infrastructure/Database/MigrationRunner.php` — index.php filter
- `src/Modules/Teams/Admin/TeamsPage.php` — Staff panel hooked into edit form, Staff count in list, orphan cleanup on delete
- `config/modules.php` — People module registered
- `talenttrack.php` — version bump
- `readme.txt` — stable tag + changelog
- `languages/talenttrack-nl_NL.po` + `.mo` — Dutch strings for new UI

## Verify after install

1. TalentTrack → People exists in the menu, renders an empty list.
2. Add a test person, save — success notice, edit page loads.
3. TalentTrack → Teams → edit any team. Scroll below the main form. Staff section appears.
4. If the team had a legacy head_coach_id set, it shows as a row labeled "Legacy".
5. Assign the test person as e.g. Assistant Coach with a start date. Submit. Person appears in the Staff section grouped under Assistant Coach.
6. Click Unassign — person disappears from Staff section.
7. Teams list shows Staff count column.
8. TalentTrack → Migrations: the phantom "index" row is gone; all 4 migrations show ✓ Applied.

## Deferred backlog

- Parent role views (linking parents to players)
- Dedicated `tt_manage_people` capability
- REST endpoints for People
- Remove obsolete `0004_schema_reconciliation.php` from the repo
- Review `MigrationHelpers.php` — verify usage and remove if orphaned
