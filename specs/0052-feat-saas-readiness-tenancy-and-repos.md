<!-- type: feat -->

# #0052 — SaaS-readiness baseline (PR-A) — tenancy scaffold + repository enforcement

This is the first of three independently-shippable PRs that together fulfil the #0052 epic. PR-A is the only one that touches schema, so it must ship before B or C and must run **solo** per `AGENTS.md` (no parallel agents while this branch is in flight).

- PR-A — *this spec* — tenancy column + uuid backfill + `tt_config` schema reshape + `CurrentClub` helper + repository-layer enforcement.
- PR-B — `specs/0052-feat-saas-readiness-rest-and-auth.md` — REST gap closure + auth portability cleanup.
- PR-C — `specs/0052-feat-saas-readiness-assets-cron-openapi.md` — asset/cron audit + OpenAPI contract.

The original idea file (`ideas/0052-epic-saas-readiness-baseline.md`) is deleted in this PR; its audit is preserved here in § Background.

## Problem

`CLAUDE.md` § 3 ("SaaS-ready by construction") sets a forward-looking standard for new code: every tenant-scoped table carries a `club_id`, root entities carry a `uuid`, repositories filter by club in their `where` clauses, and tenant config lives in `tt_config` rather than `wp_options`. That standard binds every PR shipped from now on — but the existing 38 migrations, 42 `tt_*` tables, and ~20+ repositories were written before it. Today there is **zero** tenancy column anywhere in the schema, no UUIDs on root entities, and `tt_config` is keyed only by `key`.

If we keep shipping new features against the new standard while leaving the legacy backfill for "later", later means a SaaS-migration sprint that has to (a) write a migration touching every existing tenant-scoped table, (b) audit every existing repository, and (c) reconcile a `tt_config` schema change against whatever has accumulated by then. That sprint is dramatically cheaper today across 15 tables than later across 30+. The rest of the SaaS-readiness work (REST coverage, auth portability, asset/cron, OpenAPI) can run in parallel with feature work — but tenancy must come first because every other PR-bundle assumes the schema is already scoped.

This PR is the one-time backfill that brings the existing schema into compliance with `CLAUDE.md` § 3, and the repository-layer change that makes the scaffold actually load-bearing.

## Background — audit numbers (frozen as of v3.39.0)

These numbers come from the audit performed when shaping this idea. They are baselines; PR-A is expected to drive them to zero on the schema/repository axes.

- 42 `tt_*` tables across 38 migrations.
- ~15 of those tables hold tenant-scoped data (the canonical list is in § Scope below).
- Zero tables have `club_id` / `tenant_id` / `account_id` today.
- Zero root entities have a persistent `uuid`. (`wp_generate_uuid4()` is used in three places, all for transient DOM IDs.)
- `tt_config` is keyed only by `key` — a second tenant on this install would silently overwrite the first's config.
- `wp_options` and `update_option()` / `get_option()` are used in ~20 places across modules. Some are install-global (cache flags) — fine to leave. Some are tenant-scoped — those move to `tt_config`.

## Decisions locked

These were all settled during the 2026-04-28 inline-Q shaping pass. Recording them here so future-me doesn't relitigate.

1. **Naming: `club_id`.** Reads most naturally for the youth-football-academy domain. Used in `CLAUDE.md` § 3 already. Not `tenant_id` (too generic), not `account_id` (overlaps with the future SaaS billing entity).
2. **UUID format: `CHAR(36)` with dashes.** Friendlier in REST payloads, friendlier in MySQL workbench / `wp db cli` debugging. The performance gap vs `BINARY(16)` is not measurable at our row counts. If we ever measure a real perf problem, the migration to `BINARY(16)` is a follow-up — but it won't happen.
3. **`tt_config` schema migration.** Add `club_id INT UNSIGNED NOT NULL DEFAULT 1` column, drop the existing UNIQUE on `config_key`, add a composite UNIQUE on `(club_id, config_key)`. Existing rows get backfilled to `club_id = 1`. All `ConfigService::get/set/all` calls keep working unchanged.
4. **Sprint 1 blocks; Sprints 3-5 do not.** PR-A is the only PR that touches schema, so it must serialise. Once PR-A lands, PR-B and PR-C may run in parallel (with each other, and with unrelated feature work).
5. **Solo per `AGENTS.md`.** No parallel agents while PR-A is open; the chance of a collision on schema is too high.
6. **No PHPUnit infra; manual smoke + PHPStan + a one-off audit script.** See § Verification.
7. **`tt_user_id` resolver: deferred.** Documented as intent in `docs/access-control.md`; not built in this PR. The future SaaS-migration epic owns it.

## Proposal

Two coordinated migrations + one new infrastructure namespace + a sweep across every tenant-scoped repository.

### Schema — migration `0036_tenancy_scaffold.php`

A single migration adds the tenancy column to all 15 tenant-scoped tables and the uuid column to the 5 root entities. Idempotent — uses `INFORMATION_SCHEMA.COLUMNS` lookups before each `ALTER TABLE` so re-running on an already-migrated install is a no-op.

**`club_id INT UNSIGNED NOT NULL DEFAULT 1` added to (15 tables):**

- `tt_players`
- `tt_teams`
- `tt_evaluations`
- `tt_eval_ratings`
- `tt_eval_categories`
- `tt_sessions` (the activities table; renamed from sessions in #0035 but the table name stayed)
- `tt_attendance`
- `tt_goals`
- `tt_pdp_files`, `tt_pdp_conversations`, `tt_pdp_verdicts`
- `tt_audit_log`
- `tt_invitations`
- `tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_event_log`
- `tt_methodology_*` (principles / set_pieces / positions / values — one column per applicable table; ones that are reference data and shared across clubs stay un-scoped, see below)
- `tt_player_reports`
- `tt_team_people`, `tt_user_role_scopes` (these are the assignments; absolutely tenant-scoped)
- `tt_trial_*` (the six tables added by #0017)
- `tt_player_events`, `tt_player_injuries` (added by #0053 once that lands; this migration runs after, or #0053 has the column built-in)
- `tt_custom_fields`, `tt_custom_values`
- `tt_seasons`
- `tt_lookups` (debatable — see § Open carry-overs)

The "~15" number from the audit is rounded; the actual table list above is closer to 25 once the per-table accounting is done. Migration enumerates all and adds the column where missing, plus an index `idx_club (club_id)` on each.

**`uuid CHAR(36) NOT NULL` added with `UNIQUE KEY uniq_uuid (uuid)` on (5 root entities):**

- `tt_players`
- `tt_teams`
- `tt_evaluations`
- `tt_sessions`
- `tt_goals`

Backfill: the migration iterates rows where `uuid IS NULL OR uuid = ''` and sets `uuid = wp_generate_uuid4()`. Done in 500-row batches with a sleep guard so a 5,000-player install doesn't lock the DB. After backfill, the column is altered to `NOT NULL`. Idempotent — if a row already has a uuid (e.g. from a partial earlier run), it is left alone.

### Schema — migration `0037_tt_config_tenancy.php`

Separate migration so it can be reverted independently if something blows up.

```sql
ALTER TABLE tt_config ADD COLUMN club_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE tt_config DROP INDEX uniq_config_key;     -- existing UNIQUE on config_key
ALTER TABLE tt_config ADD UNIQUE KEY uniq_club_key (club_id, config_key);
ALTER TABLE tt_config ADD KEY idx_club (club_id);
```

Existing rows already pick up `club_id = 1` from the default. The composite UNIQUE replaces the single-column one — so a future second tenant can have its own row for `theme_primary_color` without colliding.

### Tenant-scoped `wp_options` migration

The audit identified ~20 `wp_options` reads. Each is categorised in this PR as either:

- **Install-global** — stays in `wp_options` (e.g. `tt_db_version`, internal cache invalidation flags, the update-checker last-fetch timestamp).
- **Tenant-scoped** — moves to `tt_config` (e.g. anything a club admin would expect to differ per club: branding fallbacks, default rating scale, default season).

Migration `0037_tt_config_tenancy.php` includes a `migrate_wp_options_to_tt_config()` step that copies each tenant-scoped option's value into `tt_config` keyed by `(1, <key>)` and **does not** delete the `wp_options` row (so a rollback is trivial; cleanup is a follow-up). Code reads switch to `ConfigService::get()`. The full per-option list is in this PR's commit body; the count is roughly 8 tenant-scoped options out of the ~20 inspected.

### New namespace — `src/Infrastructure/Tenancy/`

```php
namespace TT\Infrastructure\Tenancy;

class CurrentClub {
    public static function id(): int {
        // Today: always 1. Tomorrow: read from session / JWT / subdomain.
        return (int) apply_filters( 'tt_current_club_id', 1 );
    }
}
```

The filter is the future SaaS-migration injection point. Hook payloads include `$club_id` so consumers can scope their data.

### Repository-layer enforcement

Every repository under `src/Modules/*/Repositories/` and every direct `$wpdb` query in `src/Modules/*/` and `src/Infrastructure/` is touched. The change is mechanical:

- Read methods (`find()`, `findAll()`, `findByPlayer()`, etc.) gain a `club_id = %d` clause filtering on `CurrentClub::id()`.
- Write methods (`create()`, `update()`) populate `club_id` from `CurrentClub::id()` on insert. Updates filter on `(id = %d AND club_id = %d)`.
- The change is wrapped behind two new helpers in `src/Infrastructure/Query/QueryHelpers.php`:

```php
public static function clubScopeWhere( string $alias = '' ): string {
    $col = $alias ? "{$alias}.club_id" : 'club_id';
    return $wpdb->prepare( "{$col} = %d", CurrentClub::id() );
}

public static function clubScopeInsertColumn(): array {
    return [ 'club_id' => CurrentClub::id() ];
}
```

So the per-repository change is one line in each `where` clause + one array merge in each insert. Mechanical, but ~25 repositories × 2-4 methods each = ~50-100 edit sites. The change is invisible at runtime today (`CurrentClub::id()` always returns `1`, every row has `club_id = 1`).

### `wp_usermeta` audit (deferred to follow-up note)

`wp_usermeta` is also install-global. Some `tt_*` user-meta keys are tenant-scoped (e.g. scout assignments — a scout in tenant A shouldn't see tenant B's assignments). For PR-A we **document** this in `docs/access-control.md` as a known gap; the actual move to a tenant-scoped table happens later when SaaS migration is real. Not load-bearing today since there's only one tenant.

## Acceptance criteria

- [ ] Migration `0036_tenancy_scaffold.php` lands with all listed tables carrying `club_id` and the 5 root entities carrying `uuid`. Idempotent — re-running is a no-op.
- [ ] Migration `0037_tt_config_tenancy.php` lands with `tt_config` schema reshaped and tenant-scoped `wp_options` copied into `tt_config` keyed by `club_id = 1`.
- [ ] `src/Infrastructure/Tenancy/CurrentClub.php` exists and returns `1` (filterable via `tt_current_club_id`).
- [ ] `src/Infrastructure/Query/QueryHelpers.php` exposes `clubScopeWhere()` and `clubScopeInsertColumn()`.
- [ ] Every `src/Modules/*/Repositories/*.php` reads filter by `club_id`; every insert populates `club_id`. Verified by the audit script in § Verification.
- [ ] Every direct `$wpdb` query in `src/` against a tenant-scoped table is updated. Verified by the audit script.
- [ ] Existing functional tests (admin smoke checklist, frontend smoke checklist) pass unchanged.
- [ ] `docs/architecture.md` § Schema + migrations updated with the tenancy + uuid contract.
- [ ] `docs/migrations.md` updated with migration 36 + 37.
- [ ] `docs/access-control.md` updated with the deferred `tt_user_id` resolver intent and the `wp_usermeta` known-gap note.
- [ ] `languages/talenttrack-nl_NL.po` — no new user-facing strings, but if any error-path messaging changes, NL added.
- [ ] `SEQUENCE.md` updated: PR-A row moves from "Ready" to "Done"; PR-B and PR-C remain "Ready".

## Verification

No PHPUnit infrastructure exists, and adding one is out of scope. Three safety nets instead:

1. **PHPStan level 8 + PHP lint.** Already part of CI. Catches column-typo regressions in `INSERT` arrays and any signature drift in `CurrentClub`.
2. **Manual smoke checklist.** Boot the plugin on a fresh install, run the existing happy-path smoke (create club admin, create team, create player, create activity, attendance, eval, goal, PDP file, scout token, trial case). Every operation must leave a row with `club_id = 1`.
3. **`bin/audit-tenancy.php` — one-off audit script.** Ships in this PR. Runs against the live DB and verifies:
   - Every listed tenant-scoped table has a `club_id` column with `NOT NULL DEFAULT 1`.
   - Every row in those tables has `club_id = 1` (not NULL, not 0).
   - Every listed root entity has a `uuid` column populated for every row, all unique.
   - `tt_config` has the composite `(club_id, config_key)` UNIQUE.
   - Returns exit code 0 on success, 1 on failure with a per-row report.

   The script lives under `bin/` (not auto-loaded), is documented in `docs/migrations.md`, and is intended to be run once after the migration completes. Future SaaS-migration sprint can resurrect it.

## Sequencing

- PR-A is **solo** — no parallel agents while open per `AGENTS.md`.
- PR-A blocks PR-B and PR-C from starting.
- Once PR-A is merged and the audit script returns success, PR-B and PR-C may run in parallel (with each other and with unrelated feature work).
- Recommended release: tag a version after PR-A so the schema migration is associated with a specific release line.

## Out of scope (explicitly)

- Any change to `CurrentClub::id()` returning anything other than `1`.
- The `tt_user_id` resolver. Documented intent only.
- Moving `wp_usermeta` keys to a tenant-scoped table. Documented gap only.
- Refactoring view files. (CLAUDE.md § 3 has a separate smell test for this.)
- Any of the work in PR-B (REST/auth) or PR-C (assets/cron/OpenAPI).
- Multi-tenant the WP plugin. The plugin remains single-tenant after this PR.

## Estimated effort

~12-18h actual based on recent compression patterns; tenancy scaffolds are mechanical but high-touch (~25 repositories × small edit each). Solo. Allocate a quiet day with no parallel feature work in flight.
