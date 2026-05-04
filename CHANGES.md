# TalentTrack v3.91.0 — Tile-entity disambiguation + FR assignment scope sync

Sibling fix to #0071 — closes the last two gaps where the authorization matrix gave a wrong answer at runtime. Implements `specs/0079-feat-tile-entity-disambiguation.md`.

## Why

Scout users were seeing coach-side tiles (My teams, My players, Activities, Podium, Open wp-admin) whose destination view rejected them with *"Dit onderdeel is alleen beschikbaar voor coaches en beheerders."* — three sources of truth (seed/matrix, tile registry, dispatcher) gave three answers to the same question. Separately, head coaches assigned to a team via Functional Roles saw only the Methodology tile because `MatrixGate::userHasAnyScope` reads `tt_user_role_scopes` and the FR write path was never wired to insert the matching row (Sprint 7 of #0071 declared the design intent but the implementation was missing).

## What changed

### Tile-entity disambiguation

- 10 new tile-specific matrix entities: `team_roster_panel`, `coach_player_list_panel`, `people_directory_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `wp_admin_portal`. Distinct from the underlying data entities (`team`, `players`, `evaluations`, …) which keep their REST + repository role.
- Seeded in `config/authorization_seed.php` for `assistant_coach` / `head_coach` / `team_manager` (`r[team]`), `head_of_development` / `academy_admin` (`r[global]`). `wp_admin_portal` is academy-admin-only. Scout, parent, player have no grant on any panel.
- `frontend_admin: r global` removed from scout's seed (the original v3.39.0 strawman holdover).
- Tile registrations in `src/Shared/CoreSurfaceRegistration.php` updated to declare the new entities. The redundant `cap_callback => $is_coach_or_admin_cb` arguments are dropped — matrix-active installs ignore them, and matrix-dormant installs are out of scope per #0071's `tt_authorization_active = 1` rollout.

### Dispatcher refactor

- New helper `TileRegistry::entityForViewSlug(string $slug): ?string` returns the matrix entity declared by the tile that owns the slug.
- New helper `DashboardShortcode::matrixDispatchAllows(string $view, int $user_id): bool` — single matrix-driven gate. When the slug has a tile-declared entity AND `tt_authorization_active = 1`, MatrixGate is the sole authority. Returns true for slugs without a tile entity (fall through to existing dispatch + per-view cap re-checks).
- Hardcoded `$is_coach || $is_admin` (coaching slugs) and `current_user_can('tt_view_reports')` (analytics slugs) gates removed from `DashboardShortcode::render()`. The `me_slugs` branch keeps its `$player` linked-record check — that's a data prerequisite, not an authorisation question.
- Per-class notice strings ("This section is only available for coaches and administrators.", "Your role does not have access to analytics views.") collapsed to one generic *"You do not have access to this surface."* The collapsed copy goes through `__()` for nl_NL.

### Functional Role assignment scope sync

- `PeopleRepository::assignToTeam()` now writes a `tt_user_role_scopes` row (`scope_type='team'`, `scope_id=team`) after a successful FR assignment insert.
- `PeopleRepository::unassign()` reads the assignment's `(team_id, person_id)` before deleting, then re-evaluates whether any other FR assignment for the same pair survives. If none remain, the scope row is removed; if at least one remains, the scope row stays. Multi-role-on-same-team users get one scope row per (person, team) regardless of how many roles they hold.
- New private helper `PeopleRepository::syncTeamScopeRow(int $team_id, int $person_id)` is the single sync chokepoint, called from both write paths. Idempotent.
- New migration `database/migrations/0062_fr_assignment_scope_backfill.php` walks existing FR assignments and inserts the missing scope rows. Idempotent on re-run via a `LEFT JOIN … WHERE urs.id IS NULL` predicate. Renumbered from 0061 because v3.89.3 shipped its own 0061 between PR-creation and rebase.

### Documentation

- `docs/access-control.md` + `docs/nl_NL/access-control.md` gain two short sections: how FR assignments propagate to scope rows, and how tile visibility now uses dedicated entities.
- One new translatable string in `languages/talenttrack-nl_NL.po` (the collapsed dispatcher notice).

## What was NOT touched

- The 10 underlying data entities keep their REST + repository gating role.
- Per-view internal cap re-checks (`tt_view_team_chemistry` inside `team-chemistry` dispatch, `tt_view_pdp` inside PDP views, etc.) stay as defence in depth.
- Per-team customisation of personas and time-bounded permissions remain out of scope as per #0033.
- Existing installs that have customised matrix grants keep them — the new entities have seed defaults but no migration mirrors operator-edited rows from the data entities to the new tile entities.

## Affected files

- `config/authorization_seed.php` — 10 new tile entities × up to 5 personas; scout's `frontend_admin` removed; per-block comments documenting #0079.
- `src/Shared/CoreSurfaceRegistration.php` — 10 tile registrations updated; `$is_coach_or_admin_cb` closure removed.
- `src/Shared/Tiles/TileRegistry.php` — new `entityForViewSlug()` helper.
- `src/Shared/Frontend/DashboardShortcode.php` — new `matrixDispatchAllows()` + `matrixActive()` helpers; six per-class gates collapsed to one generic notice.
- `src/Infrastructure/People/PeopleRepository.php` — `assignToTeam()` and `unassign()` updated with scope-sync calls; new `syncTeamScopeRow()` helper.
- `database/migrations/0062_fr_assignment_scope_backfill.php` — new migration.
- `docs/access-control.md` + `docs/nl_NL/access-control.md` — two new sections.
- `languages/talenttrack-nl_NL.po` — one new msgid + msgstr.
- `talenttrack.php` + `readme.txt` — version bump 3.90.2 → 3.91.0.
- `SEQUENCE.md` — #0079 moves from Ready to Done.
