# TalentTrack v4.3.0 — VCT module ship 1: schema foundation (closes #906, partial epic #905)

## Context

Foundation ship of the U10–U14 age-aware conditioning-training planner specified in [`specs/0095-feat-vct-module.md`](specs/0095-feat-vct-module.md). The epic is phased into seven implementation issues (#906–#912 / VCT-1 through VCT-7); this PR is VCT-1.

No UI yet, no REST yet, no caps yet — pure schema. VCT-2 through VCT-7 build on these tables.

## What changed

Single new migration: `database/migrations/0122_vct_schema.php`. Creates eleven tables, every `CREATE TABLE` wrapped in a `SHOW TABLES LIKE` guard so the migration is safely re-runnable.

### Tables

1. **`tt_vct_exercises`** — exercise catalogue. UUID-bearing root. MD suitability denormalised to 8 `TINYINT` bit-flag columns (`md_minus_4` … `md_plus_2`, `md_none`) — **not** a JSON column. Composite index `(club_id, archived_at, category, intensity_band, age_min, age_max)` named `idx_candidate_lookup` so the VCT-5 hot-path candidate query seeks on `type=ref` or better. `UNIQUE (club_id, code)`. `seed_revision INT UNSIGNED DEFAULT 0` for future catalogue corrections.
2. **`tt_vct_coaching_points`** — translatable child of exercises. No JSON column; canonical text will live in `tt_translations` keyed on `(table='tt_vct_coaching_points', record_id, locale, column='text')` per the #902 lesson. `UNIQUE (exercise_id, code)`.
3. **`tt_vct_age_profiles`** — per-club workload envelope per age group. UUID-bearing. `match_load_multiplier_per_minute DECIMAL(3,1) DEFAULT 7.0`. `UNIQUE (club_id, age_group)`.
4. **`tt_vct_session_templates`** — slot definitions per (age × MD context). UUID-bearing. `UNIQUE (club_id, age_group, md_context)`.
5. **`tt_vct_sessions`** — coach-generated session, root entity. UUID-bearing. `status ENUM('draft','published','completed','archived') DEFAULT 'draft'`. Nullable `activity_id` (app-level FK to `tt_activities`, set when the session is published; child cleanup pattern documented in the migration header).
6. **`tt_vct_session_blocks`** — filled slots per session. **No DB CASCADE** — `VctSessionsRepository::delete()` (VCT-5) will do child cleanup in a transaction; integration test (VCT-5) will assert no orphans. `exercise_id` nullable so a coach can hand-fill a custom block.
7. **`tt_vct_microcycles`** — weekly aggregate per team. `UNIQUE (club_id, team_id, week_starts_on)`. `match_date` is the headline match anchoring MD-context for the week.
8. **`tt_vct_workload_snapshots`** — per-player load aggregation (nightly job writes; VCT-7). `acwr DECIMAL(3,2)`; accumulators `INT UNSIGNED` (documented bounds: 28-day load typically <25,000 per player; INT UNSIGNED has 4B headroom). `UNIQUE (club_id, player_id, snapshot_date)`.
9. **`tt_vct_team_schedules`** — per-team weekly training-day preferences. UUID-bearing. `weekdays_bitmask TINYINT UNSIGNED` (7 bits, bit 0 = Monday … bit 6 = Sunday). `UNIQUE (club_id, team_id, season_id)`.
10. **`tt_vct_macro_blocks`** — periodization calendar (PDP-blocks pattern from v3.110.193). UUID-bearing. `team_id BIGINT UNSIGNED NOT NULL DEFAULT 0` — `team_id=0` is the club-wide season default; non-zero is a per-team override. `UNIQUE (club_id, team_id, season_id, sequence)` — plain UNIQUE works without `COALESCE` (illegal in MySQL 5.7 UNIQUE).
11. **`tt_player_phv_flags`** — per-player Peak Height Velocity flag, folded into MVP per the design review's biggest-safety-risk callout. `WorkloadCapRule` (VCT-5) will read `is_active` and apply the configured `tt_vct_age_profiles.growth_spurt_load_reduction_pct`. `UNIQUE (club_id, player_id)`.

### Conventions matched

- Every table carries `club_id INT UNSIGNED NOT NULL DEFAULT 1` (CLAUDE.md §4 tenancy scaffold).
- User-facing root entities (`tt_vct_exercises`, `tt_vct_sessions`, `tt_vct_macro_blocks`, `tt_vct_age_profiles`, `tt_vct_team_schedules`, `tt_vct_session_templates`) carry `uuid CHAR(36)` with a `UNIQUE` index for the SaaS port.
- Child / read-model tables (`tt_vct_session_blocks`, `tt_vct_coaching_points`, `tt_vct_microcycles`, `tt_vct_workload_snapshots`, `tt_player_phv_flags`) have no UUID — they reference their parent by integer FK only.
- Indexes lead with `club_id`.
- `archived_at DATETIME NULL` on the soft-delete-relevant tables (`exercises`, `sessions`, `team_schedules`, `macro_blocks`, `coaching_points`).
- `created_at` / `updated_at` per the codebase convention (`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP [ON UPDATE CURRENT_TIMESTAMP]`).

### Architectural notes

- **No FKs is deliberate.** Every existing TalentTrack table uses app-level integrity; adding the first DB-level FK in this codebase would set a new precedent no other module follows.
- **Bit-flag MD suitability** is a denormalisation chosen for index reachability. Eight cheap TINYINT columns trade ~7 bytes per row for the ability to seek on `(club_id, archived_at, category, intensity_band)` without `JSON_CONTAINS` scanning. With 80+ exercises per club at MVP and 800+ projected, it matters.
- **UUID on six root entities** per the SaaS-readiness rule; child / read-model tables don't need one.

## Out of scope

- Seed data — VCT-3 (lookups, #908), VCT-4 (age profiles + templates + phase profiles, #909), and the catalogue seed (separate issue under VCT epic).
- Capability registration — VCT-2 (#907).
- Repositories + domain VOs — VCT-5 (#910).
- REST API — VCT-6 (#911).
- Workflow nightly task — VCT-7 (#912).
- Cross-module `tt_activities` UNIQUE-index race-guard — separate issue against Activities module (per spec).

## Validation

- Migration applies cleanly on a fresh install: `0122_vct_schema` appears in `tt_migrations` with no errors.
- Migration is idempotent: running it a second time is a no-op (every `CREATE` is gated on `SHOW TABLES LIKE`).
- `SHOW CREATE TABLE wp_tt_vct_exercises` confirms the eight MD-suitability TINYINT columns + the `idx_candidate_lookup` composite index.
- `SHOW CREATE TABLE wp_tt_vct_macro_blocks` confirms `team_id BIGINT UNSIGNED NOT NULL DEFAULT 0` and the plain UNIQUE on `(club_id, team_id, season_id, sequence)`.
- `SHOW CREATE TABLE wp_tt_vct_workload_snapshots` confirms `acwr DECIMAL(3,2)` (not 4,2).
- No `CONSTRAINT … FOREIGN KEY` in any of the eleven `SHOW CREATE TABLE` outputs.

## Why this is `minor`, not `patch`

New feature epic lands per `DEVOPS.md` § "When to bump what". The schema is the API change even though no surfaces consume it yet — same precedent as `v4.2.0` for #788 ship 1 ("Foundation ship for the planned-attendance feature… Minor bump — new feature behaviour even though no UI changes yet — the schema is the API change"). Patch resets to 0.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.2.5` → `4.3.0`.
