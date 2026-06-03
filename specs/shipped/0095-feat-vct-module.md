# 0095 — VCT (Voetbal Conditionele Training) module for U10–U14 youth football

> **Draft 0.2 · 2026-05-25** — folded design-review findings (Standards / Architecture / Data model / Domain reviewers). See `## Decisions log` at the bottom for the five resolved cross-cutting choices.

## Problem

Youth football coaches in Dutch academies have no in-product surface that produces age-safe, football-specific conditioning training sessions. Today they either:

- Copy adult Verheijen methodology directly (workload + intensity inappropriate for pre-puberty / early-puberty players; growth-plate + cardiovascular maturity concerns — see Appendix A);
- Borrow from KNVB youth manuals at a level of abstraction that doesn't produce concrete session plans;
- Build sessions ad-hoc, with no week-to-week progression, no recovery enforcement, no workload bookkeeping;
- Ask AI to invent training plans — risky because LLMs hallucinate methodology that no qualified coach would endorse.

The pilot's ask, recorded 2026-05-25: a planner that respects age, periodization, and football-specific conditioning principles, produces printable session plans in Dutch coaching language, and integrates with the team's existing weekly schedule.

## Proposal

A new `Vct` module (`src/Modules/Vct/`) that produces deterministically-composed training sessions for U10–U14 teams, anchored on a per-team weekly schedule + a per-team-or-season periodization calendar. Architecture is three strict layers:

1. **Rules Engine** — deterministic PHP services. Decides what each session contains, how long it lasts, what intensity ceilings apply, what's blocked by age / MD-context / recovery rules. Encodes domain knowledge as data + rules.
2. **Exercise Database** — operator-curated catalogue of tagged exercises. Each exercise is data, not narrative. The engine selects from this catalogue; AI never invents exercises.
3. **AI Presentation Layer (Phase 2)** — formats a deterministically-composed session into polished Dutch coaching language with strict provenance validators. MVP ships without AI; deterministic NL templates produce coach-friendly prose.

The module fits parallel to `Pdp`, `Players`, `Scouting` under `src/Modules/`. It integrates loosely with existing surfaces (Activities for the calendar, Teams for scope, Players for load attribution, Authorization for caps + matrix, Lookups for vocabulary, Wizards for record creation, Workflow for the nightly load-aggregation cron trigger) via well-defined contracts that do not duplicate any existing entity.

### Integration with team planning (two configuration entities)

Without team-schedule + macro-block configuration, the engine has no anchor: the wizard's date picker is blind, the `ProgressionRule` has nothing to align against, and the `MdContextResolver` can't distinguish "early week" vs "late week" training. Both entities ship in MVP.

**`tt_vct_team_schedules`** — per-team, per-season weekly preferences (e.g. *"U13-1 trains Tuesday + Thursday at 18:30 for 75 min"*). Drives the wizard's date defaults and the resolver's training-day-type computation. Does **not** create Activities automatically — Activities remains the source of truth for actual scheduled events.

**`tt_vct_macro_blocks`** — per-team-or-season periodization calendar, mirroring the proven [PDP blocks pattern](../src/Modules/Pdp/Repositories/PdpBlocksRepository.php) from v3.110.193. An academy defines a sequence of date-bounded blocks (default 6 weeks each); each block carries a `phase_profile_json` array with per-week intensity multipliers (e.g. `[introductie 0.85, opbouw 0.95, opbouw 1.00, piek 1.00, piek 1.00, deload 0.70]`). The engine reads "which block + which week of block is today" to apply the progression multiplier — always inside the age intensity ceiling, never above it.

## Wizard plan

This work introduces **one** new wizard. Other record-creation surfaces invoke CLAUDE.md § 3 / § 6 exemption (a) explicitly — rationale per row below.

| Slug | Existing? | Notes |
| --- | --- | --- |
| `new-vct-session` | new | Five-step wizard. Steps: `vct-session-when` (date picker defaulting to next configured training weekday; age + MD context auto-resolved with override pill), `vct-session-theme` (single-select with one-sentence rationale), `vct-session-duration` (slider from age profile), `vct-session-preview` (engine output as cards with swap/regenerate affordances), `vct-session-review` (load summary + warnings + save/publish/print). Standard `WizardChrome` (Previous / Next / Cancel); Save+Cancel exempt per CLAUDE.md § 6 (c) — wizard chrome covers it. Reachable via `?tt_view=wizard&slug=new-vct-session`. Flat-form fallback at `?tt_view=vct-sessions&action=new`. Wizard's final step hands off to `POST /vct/sessions/generate` via `WizardEntryPoint::urlFor()`. Live-validation per the v3.110.190 pattern (#796): required fields flag red on blur, Next disabled until valid, server-side `validate()` stays authoritative. |
| `new-vct-exercise` | exempt | Exercise CRUD invokes CLAUDE.md § 3 **exemption (a) — vocabulary edits**. Each exercise IS a tagged library/vocabulary row — the spec itself argues "each exercise is data, not narrative" in § Why three layers. HoD library at `?tt_view=vct-library` uses inline create/edit form with Save+Cancel. Cap: `tt_vct_admin_library`. |
| Team schedule editor | exempt | Per-team weekday preferences live on the team detail view as a small inline panel. CLAUDE.md § 6 exemption (a) — settings sub-form on a multi-form page. Save+Cancel applies. Cap: `tt_vct_plan` (team scope). |
| Macro-blocks config | exempt | Configuration tile at `?tt_view=configuration&config_sub=vct-blocks`. CLAUDE.md § 6 exemption (a) — settings sub-form. Reuses the proven PDP-blocks UX (season picker + N date-pair rows + SVG year timeline + live overlap/gap/boundary validation). Cap: `tt_vct_admin_library`. |
| Age-profile editor | exempt | Configuration tile at `?tt_view=configuration&config_sub=vct-age-profiles`. CLAUDE.md § 6 exemption (a) — settings sub-form. Cap: `tt_vct_admin_library`. |

The dedicated post-creation views (`?tt_view=vct-session&id=N`, `?tt_view=vct-library`) follow the standard `FrontendBreadcrumbs::fromDashboard()` + `tt_back` pattern (CLAUDE.md § 5). The print routes `?tt_view=vct-session&id=N&print=a4|a6` are **sub-renders of the session view**, not standalone routable views — they emit no breadcrumbs of their own per `docs/back-navigation.md`.

## Scope

### Schema (new tables + lookup seeding)

All tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1` (CLAUDE.md § 4 tenancy scaffold). User-facing root entities carry `uuid CHAR(36)` for the SaaS port. **App-level integrity only** — no DB-level `FOREIGN KEY` constraints, matching the codebase convention (see `0001_initial_schema.php`, `0094_scouting_plan_visits.php`, `0107_pdp_blocks.php`).

- **`tt_vct_exercises`** — the deterministic exercise catalogue, **seeded per-club on activation** (no `club_id = 0` shared overlay pattern; matches PDP/lookups convention). `(id, club_id, uuid, code, name_canonical, category, tactical_theme, intensity_band, duration_minutes_min, duration_minutes_max, players_min, players_max, sided_size, age_min, age_max, md_minus_4 TINYINT, md_minus_3 TINYINT, md_minus_2 TINYINT, md_minus_1 TINYINT, md_zero TINYINT, md_plus_1 TINYINT, md_plus_2 TINYINT, md_none TINYINT, equipment_json, diagram_url, verheijen_classification, seed_revision INT UNSIGNED DEFAULT 0, archived_at, created_at, updated_at)`. MD suitability **denormalised to 8 bit-flag columns** (was JSON array) so the candidate-selection query seeks on a composite index instead of scanning JSON. UNIQUE `(club_id, code)`. Index `(club_id, archived_at, category, intensity_band, age_min, age_max)` covers the hot-path query.
- **`tt_vct_coaching_points`** — translatable child table for per-exercise coaching cues. `(id, club_id, exercise_id, sequence, code, archived_at)`. Canonical Dutch text lives in `tt_translations` keyed on `(table='tt_vct_coaching_points', record_id, locale, column='text')` — same pattern as `tt_lookups` translation. Avoids the JSON-trapped-translation anti-pattern landed as #902.
- **`tt_vct_age_profiles`** — per-club workload envelope per age group. `(id, club_id, uuid, age_group, session_minutes_max, intensity_band_max, weekly_load_envelope, md_logic_enabled, min_recovery_hours_between_high, growth_spurt_load_reduction_pct, match_load_multiplier_per_minute DECIMAL(3,1) DEFAULT 7.0, created_at, updated_at)`. UNIQUE `(club_id, age_group)`. Seeded with adjusted defaults per Appendix A (see "Age-restriction matrix" below).
- **`tt_vct_session_templates`** — slot definitions per (age, MD-context). `(id, club_id, uuid, age_group, md_context, slots_json, total_duration_minutes_target, description_nl)`. Per-club seeded on activation.
- **`tt_vct_sessions`** — a coach-generated session. `(id, club_id, uuid, team_id, activity_id NULL, session_date, start_time NULL, age_group, md_context, tactical_theme NULL, total_duration_minutes, total_load, coach_notes, status ENUM('draft','published','completed','archived'), generated_by, generated_at, published_at NULL, completed_at NULL, archived_at NULL)`. `activity_id` is the optional FK to `tt_activities` — set when the session is published. **No DB FK** (app-level integrity); the deletion hook policy is documented below. Indexes on `(club_id, team_id, session_date)`, `(club_id, status)`.
- **`tt_vct_session_blocks`** — filled slots per session. `(id, club_id, session_id, sequence, slot_category, exercise_id NULL, custom_label NULL, duration_minutes, intensity_band, coaching_point_override_codes JSON NULL)`. `exercise_id` nullable so a coach can hand-fill a custom block. **No DB CASCADE** — `VctSessionsRepository::delete()` performs child cleanup in a transaction; integration test asserts no orphans.
- **`tt_vct_microcycles`** — weekly aggregate per team. `(id, club_id, team_id, week_starts_on, match_date NULL, total_load_target, total_load_actual, notes)`. UNIQUE `(club_id, team_id, week_starts_on)`. `match_date` is the **headline match** anchoring MD-context for the week; mid-week cup matches are not modelled here.
- **`tt_vct_workload_snapshots`** — per-player load aggregation (nightly job writes). `(id, club_id, player_id, snapshot_date, session_load_24h INT UNSIGNED, session_load_7d INT UNSIGNED, session_load_28d INT UNSIGNED, acwr DECIMAL(3,2), flag VARCHAR(16) NULL)`. Documented bounds: `session_load_28d` typically < 25,000 per player; INT UNSIGNED has 4B headroom. UNIQUE `(club_id, player_id, snapshot_date)`. **MVP**: table ships + nightly job runs; UI consuming it is Phase 2.
- **`tt_vct_team_schedules`** — per-team weekly training-day preferences. `(id, club_id, uuid, team_id, season_id, weekdays_bitmask TINYINT UNSIGNED NOT NULL DEFAULT 0, default_start_time TIME NULL, default_duration_minutes SMALLINT UNSIGNED NULL, archived_at, updated_by, updated_at)`. `weekdays_bitmask` is a 7-bit field (bit 0 = Monday … bit 6 = Sunday). UNIQUE `(club_id, team_id, season_id)`.
- **`tt_vct_macro_blocks`** — periodization calendar (PDP-blocks pattern). `(id, club_id, uuid, season_id, team_id BIGINT UNSIGNED NOT NULL DEFAULT 0, sequence, label, start_date, end_date, phase_profile_json, archived_at)`. `team_id = 0` is the club-wide season default; non-zero is a per-team override (plain UNIQUE works without COALESCE / generated columns). UNIQUE `(club_id, team_id, season_id, sequence)`. Same overlap/gap/season-boundary validation rules the PdpBlocks REST controller enforces.
- **`tt_player_phv_flags`** — per-player Peak Height Velocity flag (folded into MVP per design-review). `(id, club_id, player_id, is_active TINYINT(1), flagged_at, flagged_by, cleared_at NULL, cleared_by NULL, notes)`. `WorkloadCapRule` reads `is_active` for the player and applies the configured `tt_vct_age_profiles.growth_spurt_load_reduction_pct` to that individual's load contribution. Per-player flag editable on the player profile (HoD/coach-coach scope).

New lookup categories seeded by the activator: `vct_exercise_category`, `vct_tactical_theme`, `vct_md_context`, `vct_intensity_band`, `vct_session_status`. Operator-editable via the existing Lookups admin. **All five locales (nl_NL / fr_FR / de_DE / es_ES + en_US canonical) seeded from day one** — avoids the gap landed as #902.

**Translation seed pattern (locked per memory `feedback_lookup_seed_translations`)**: seed migrations write directly to `tt_translations` using a label map in PHP, not via `.po` backfill. `tt_lookups.translations` was dropped in migration 0087.

### Concrete migration sequence (numbered at merge time from next free slot)

1. `0NNN_vct_schema.php` — all nine tables created. Each `CREATE TABLE IF NOT EXISTS`.
2. `0NNN_vct_capabilities.php` — registers `tt_vct_plan`, `tt_vct_admin_library`, `tt_vct_view_load` via the existing capabilities migration pattern. **None baked into role baselines** (scout/prospects #824 lesson).
3. `0NNN_vct_authorization_seed.php` — appends `vct` / `vct_library` / `vct_workload` entity rows to `tt_auth_matrix` for `head_coach` (team scope), `assistant_coach` (team scope), `head_dev` (global), `administrator` (global). Idempotent via `INSERT IGNORE` on existing unique key.
4. `0NNN_vct_seed_lookups.php` — five new `tt_lookups` categories + direct `tt_translations` rows for nl_NL / fr_FR / de_DE / es_ES on each value.
5. `0NNN_vct_seed_age_profiles_and_templates.php` — per-club age profile defaults + session templates per (age × MD context).
6. `0NNN_vct_seed_phase_profiles.php` — two default phase profiles (4-week + 6-week) as reference rows clubs can clone.
7. `0NNN_vct_seed_exercises.php` — per-club 80-exercise catalogue + per-exercise `tt_vct_coaching_points` rows + their translations. `seed_revision = 1`. Future seed corrections raise the revision and `UPDATE … WHERE seed_revision < N`.
8. `0NNN_vct_workflow_trigger.php` — registers a `cron`-type row in `tt_workflow_triggers` with `cron_expression = '0 2 * * *'` + a `VctWorkloadAggregationTaskTemplate` whose `dispatch()` runs the aggregator.

All idempotent (`IF NOT EXISTS`, `INSERT IGNORE`); safely re-runnable.

### Rules Engine pipeline (dependency-injected per pass)

`RulesEngine` exposes two entry points:

- `compose(SessionPlanContext): Session` — runs the full pipeline including exercise selection (used by `POST /vct/sessions/generate`).
- `validate(Session): ValidationResult` — runs passes 1–5 + 7 + 8 (skips ExerciseSelection); recomputes load + warnings against current rules without changing exercise choices (used by `PATCH /vct/sessions/{id}` to prevent client smuggling without overwriting the coach's manual swaps).

Each pass implements:

```php
interface RulePass {
    public function apply( SessionPlanContext $ctx ): SessionPlanContext;
}
```

with its dependencies injected via constructor — repositories, providers — so each pass is unit-testable with in-memory fakes (no DB fixtures required).

1. **`AgeAdmissibilityRule(VctAgeProfilesRepository)`** — sets intensity ceiling, session max, MD-logic-on/off, recovery-gap hours from `tt_vct_age_profiles`.
2. **`MdContextRule(ActivitiesReader, TeamSchedulesRepository)`** — resolves MD context. U10/U11 return `NONE` regardless of `Activities` state.
3. **`SessionCompositionRule(VctSessionTemplatesRepository)`** — loads template for (age, MD); produces ordered slot list.
4. **`TacticalThemeRule`** — applies coach's theme to slots with a `themeFilter`. Warm-up + cool-down ignore theme.
5. **`ProgressionRule(VctMacroBlocksRepository)`** — finds current macro-block for (team, season, date), computes week-within-block, applies `phase_profile_json` per-week multiplier. If no block configured → multiplier 1.0 + warning.
6. **`ExerciseSelectionPass(VctExercisesRepository, RecentPicksProvider)`** — queries `findCandidates(slot, age, theme, mdContext)` with **explicit `archived_at IS NULL` filter**. Scores by (a) age-fit centeredness, (b) variety vs recent picks, (c) Verheijen-classification weight for conditioning slots. Picks highest. (Skipped in `validate` mode.)
7. **`WorkloadCapRule(VctAgeProfilesRepository, PhvFlagsProvider)`** — sums `block.intensity × block.duration`. Applies per-player PHV multiplier (`growth_spurt_load_reduction_pct`) when any roster member's PHV flag is active for the team. Downgrades if over envelope; warns if can't fit.
8. **`RecoveryRule(WorkloadSnapshotsReader)`** — MD+1 → cap Band 3; MD-1 → cap Band 5; 72h gap between Band 5+ sessions for U10-U12, 48h for U13-U14 (Appendix A adjustment).

`FinalizationPass` composes the `Session`, attaches `total_load`, returns. The pipeline is pure-functional: each pass takes a context, returns a new context, never mutates external state outside its injected dependencies.

### REST API — endpoints with explicit scope check

All under `/wp-json/talenttrack/v1/vct`. Every endpoint declares `permission_callback` with **two** layers: (1) the cap check via `current_user_can()`, (2) the per-team or per-player scope check via `MatrixGate::can()` or a dedicated `AuthorizationService::canPlanForTeam()` helper. `userCanOrMatrix()` is the *cap* helper — it does not scope; the per-resource scope is checked separately.

| Endpoint | Cap | Scope check |
|---|---|---|
| `GET /vct/exercises*` | `tt_vct_plan` | club scope (CurrentClub) |
| `POST/PATCH/DELETE /vct/exercises*` | `tt_vct_admin_library` | club scope |
| `GET /vct/sessions?team_id=N` | `tt_vct_plan` | `MatrixGate::can($uid, 'vct', 'read', 'team', $team_id)` |
| `GET /vct/sessions/{id}` | `tt_vct_plan` | scope check against the session's `team_id` |
| `POST /vct/sessions/generate` | `tt_vct_plan` | scope check against body's `team_id` |
| `PATCH /vct/sessions/{id}` | `tt_vct_plan` | scope check against the session's `team_id` |
| `POST /vct/sessions/{id}/publish` | `tt_vct_plan` | scope check against the session's `team_id` |
| `GET /vct/sessions/{id}/print` | `tt_vct_plan` | scope check against the session's `team_id` |
| `GET/PUT /vct/teams/{id}/schedule` | `tt_vct_plan` | scope check against `team_id` |
| `GET/PUT /vct/macro-blocks` | `tt_vct_admin_library` | club scope |
| `GET /vct/age-profiles` | `tt_vct_plan` | club scope |
| `PATCH /vct/age-profiles/{id}` | `tt_vct_admin_library` | club scope |
| `GET /vct/players/{id}/workload` | `tt_vct_view_load` | per-player scope (player's team) |
| `GET /vct/teams/{id}/workload` | `tt_vct_view_load` | scope check against `team_id` |
| `PATCH /vct/players/{id}/phv-flag` | `tt_vct_plan` | per-player scope |

All `POST` / `PATCH` re-run `RulesEngine::validate()` server-side. Validation failures return:

```json
{
  "error": {
    "code": "vct_validation",
    "reasons": [
      {"code": "over_age_intensity_ceiling", "details": {"requested": 8, "ceiling": 7, "age": "U13"}},
      {"code": "below_recovery_gap", "details": {"required_hours": 72, "last_high_intensity_at": "..."}}
    ]
  }
}
```

(Status 400, envelope matches the existing `RestResponse::error()` pattern from PdpBlocksRestController.)

Sample `POST /vct/sessions/generate` response:

```json
{
  "session": {
    "id": 17,
    "uuid": "f4a1-...",
    "team_id": 8,
    "session_date": "2026-05-28",
    "age_group": "U13",
    "md_context": "MD-3",
    "tactical_theme": "pressing",
    "total_duration_minutes": 85,
    "total_load": 312,
    "status": "draft",
    "blocks": [
      {"sequence": 1, "slot_category": "warmup", "exercise_id": 201, "duration_minutes": 12, "intensity_band": 2},
      {"sequence": 2, "slot_category": "technical", "exercise_id": 412, "duration_minutes": 15, "intensity_band": 3},
      {"sequence": 3, "slot_category": "sided_game", "exercise_id": 603, "duration_minutes": 20, "intensity_band": 5},
      {"sequence": 4, "slot_category": "conditioning", "exercise_id": 712, "duration_minutes": 22, "intensity_band": 6},
      {"sequence": 5, "slot_category": "cool_down", "exercise_id": 901, "duration_minutes": 16, "intensity_band": 1}
    ],
    "warnings": [
      {"code": "near_weekly_envelope", "severity": "info", "details": {"actual_pct": 82}}
    ]
  }
}
```

Capabilities + `LegacyCapMapper` (explicit activity letters per the scout/prospects #824 lesson):

- `tt_vct_plan` → `(entity='vct', activities='rcdp')`. Coaches read/create/delete/publish sessions on their teams.
- `tt_vct_admin_library` → `(entity='vct_library', activities='rcd')`. HoD/admin CRUD on shared library + age profiles + macro-blocks.
- `tt_vct_view_load` → `(entity='vct_workload', activities='r')`. HoD/admin read workload aggregates.

After the seed migration runs, `AuthorizationModule::flushCaches()` is invoked so bridges take effect immediately.

### UI surfaces

All surfaces follow CLAUDE.md § 2 (mobile-first), § 5 (two nav affordances), § 6 (Save+Cancel) and the explicit overrides called out per surface.

**Coach mobile session view** at `?tt_view=vct-session&id=N` — one block per screen at 360px viewport, sticky bottom nav, ≥48×48px touch targets, `padding: env(safe-area-inset-*)` honoured for iOS notches/home-indicator. Standard breadcrumbs + `tt_back` pill. No dark variant in MVP; `prefers-color-scheme` not honoured.

**Coach printable view** at `?tt_view=vct-session&id=N&print=a4|a6` — A4 clipboard sheet + A6 pocket card. Sub-render of the session view; no breadcrumbs of its own. Diagram URLs returned by the REST layer are full URLs (object-storage-friendly per CLAUDE.md § 4); no server-relative paths.

**Team detail panel — "VCT training days"** — inline panel on the existing team detail view. Weekday chips (Ma Di Wo Do Vr Za Zo, toggleable; writes to `weekdays_bitmask`) + default start time (`type="time"`) + default session duration (`type="number" inputmode="numeric"`). Save+Cancel via `FormSaveButton::render( ['cancel_url' => $team_detail_url] )`.

**Configuration tile — "VCT periodization"** at `?tt_view=configuration&config_sub=vct-blocks` — parallel to `pdp-blocks`. Settings sub-form, exempt from Save+Cancel rule per CLAUDE.md § 6 (a) — but still emits a Save button at the bottom of the panel; Cancel is "navigate away from the configuration view." Two scopes per page: season default + per-team override.

**Configuration tile — "VCT age profiles"** at `?tt_view=configuration&config_sub=vct-age-profiles` — settings sub-form, exempt from Save+Cancel per § 6 (a).

**HoD library** at `?tt_view=vct-library` — list with filters (age/category/theme/intensity). Inline add/edit form (vocabulary exemption per § 3 (a)) with Save+Cancel. Coaching points editor uses the existing Lookups admin per-locale name pattern.

**Per-player PHV flag** — checkbox on the player profile, gated on `tt_vct_plan` with per-player scope. Toggle writes to `tt_player_phv_flags`. Audit-logged. Coach can flag; HoD can clear.

**Input convention across all forms** — `type="date"` for dates, `type="time"` for times, `type="number" inputmode="numeric"` for durations + intensity inputs, `type="range"` for sliders. All inputs `font-size ≥ 16px` to prevent iOS auto-zoom. **Viewport meta MUST NOT** include `maximum-scale=1` or `user-scalable=no` — explicit acceptance criterion.

**HoD workload dashboard** (Phase 2) — out of MVP scope.

### Background work

`VctWorkloadAggregationTaskTemplate::dispatch()` is invoked daily at 02:00 UTC by the existing Workflow module's cron-trigger mechanism. The task aggregates per-player session loads from completed sessions over the trailing 28 days; writes one row per (player, date) to `tt_vct_workload_snapshots`. Computes 7d + 28d rolling totals + ACWR. Flags `over_envelope` or `acwr_high` where applicable. Idempotent (`INSERT ... ON DUPLICATE KEY UPDATE`).

**No `wp_schedule_event` registration** — the cron trigger is the chokepoint (CLAUDE.md § 4 SaaS-readiness).

### Integration with Activities

- **Session publish** → checks for an existing Activity for `(team_id, session_date, start_time, activity_type='training')`. If one exists, the wizard's publish step asks "bind to existing activity?" and sets `tt_vct_sessions.activity_id` to the existing row's PK. If none, creates a new Activity row + sets `activity_id`.
- **Race-condition guard** (cross-module ask, tracked as separate issue against Activities module): add a UNIQUE index `(club_id, team_id, session_date, start_time, activity_type)` on `tt_activities`. If a duplicate insert fails with MySQL code 1062, the second writer reads back the existing row and binds to it. This change is outside this spec's scope — flagged as a follow-up.
- **Activity deleted** → `VctModule::boot()` registers an action handler on `tt_activity_deleted`. Handler nulls `tt_vct_sessions.activity_id` for any session bound to the deleted Activity and reverts the session's `status` to `draft`. Session is preserved; coach can re-publish or archive it.
- **Session deleted** → session repository's `delete()` does NOT delete the bound Activity. Activity is the source of truth for attendance (which is owned by Activities); deleting it would lose attendance data. Coach must manually delete the Activity if desired. Documented in `docs/vct.md`.
- **Attendance** for the resulting Activity is managed entirely through the existing Activities/attendance flow. VCT does not touch attendance.

### Coaching language (Dutch)

MVP ships with deterministic Dutch templates only. No AI in MVP. Templates live in `src/Modules/Vct/Services/CoachingLanguageService.php`:

- Per-block: a fixed prose template populated from the exercise's coaching-point codes resolved via `tt_translations` (so the Dutch text comes from the lookup admin, editable per club).
- Per-session: a short narrative computed from (theme, MD context, week-of-block, total load) using a lookup table of Dutch phrasings.
- All strings go through `__()` / `_e()` (CLAUDE.md ship-along rule); Dutch `.po` populated in the same PR; `.pot` regenerated.

Phase 2 introduces an optional AI presenter with strict structural validators. Fallback to the deterministic template on any validator failure.

## Out of scope

- **AI presentation layer** — Phase 2. MVP ships deterministic Dutch templates only.
- **Per-player workload UI / heatmap** — table + nightly job ship in MVP; UI is Phase 2.
- **ACWR alerting / notifications** — Phase 2.
- **Position-specific finishing blocks for U13-U14** — Phase 2.
- **HoD multi-team workload dashboard** — Phase 2.
- **Push notifications / day-of training reminders** — Phase 2 (depends on a notifications module that doesn't yet exist).
- **Player-facing view** — Phase 3.
- **Senior age groups (U15+)** — explicitly out.
- **Wearable integration (HR / GPS)** — Phase 3.
- **Injury / return-to-play gates** — Phase 3.
- **Community / cross-club exercise library sharing** — Phase 3.
- **`tt_activities` UNIQUE-index race-guard** — cross-module ask, separate Activities-module issue.

## Acceptance criteria

### Schema + capabilities

- [ ] Ten new tables created via numbered migrations in the documented sequence: `tt_vct_exercises`, `tt_vct_coaching_points`, `tt_vct_age_profiles`, `tt_vct_session_templates`, `tt_vct_sessions`, `tt_vct_session_blocks`, `tt_vct_microcycles`, `tt_vct_workload_snapshots`, `tt_vct_team_schedules`, `tt_vct_macro_blocks`, plus `tt_player_phv_flags`. Each carries `club_id`; root entities carry `uuid`.
- [ ] All migrations idempotent. Re-runnable safely.
- [ ] No `FOREIGN KEY` constraints (app-level integrity, codebase convention). Session-blocks cleanup performed in `VctSessionsRepository::delete()` transaction; integration test asserts no orphan blocks after session delete.
- [ ] Three new caps registered: `tt_vct_plan`, `tt_vct_admin_library`, `tt_vct_view_load`. **None baked into role baselines.**
- [ ] `LegacyCapMapper` bridges enumerated: `tt_vct_plan → (vct, rcdp)`, `tt_vct_admin_library → (vct_library, rcd)`, `tt_vct_view_load → (vct_workload, r)`.
- [ ] `config/authorization_seed.php` appended for the four personas with correct activity letters + scopes.
- [ ] `AuthorizationModule::flushCaches()` called by the seed migration so bridges take effect on next request.
- [ ] Five lookup categories seeded with **direct `tt_translations` writes** (PHP label map in the seed migration; not via `.po` backfill — the #902 lesson).

### Seed data

- [ ] 80-exercise per-club catalogue seeded on activation with diagrams + coaching points in all five locales. `seed_revision = 1`. Distribution: ~15 warmup / ~15 technical / ~25 sided_game / ~15 conditioning / ~5 finishing / ~5 cool_down.
- [ ] Age profiles seeded with revised defaults per Appendix A (U10: 70min/Band3/MD-off/72h-gap, U11: 85min/Band4/MD-off/72h-gap, U12: 85min/Band5/MD-on simplified/72h-gap, U13: 90min/Band7/MD-on full/48h-gap, U14: 90min/Band7/MD-on full/48h-gap).
- [ ] Session templates seeded per (age × md_context). U10/U11 ship only the `NONE` md_context.
- [ ] Two default phase profiles seeded (4-week + 6-week).
- [ ] Eight tactical themes seeded: `build_up`, `pressing`, `transition`, `counter`, `defending`, `finishing`, `set_pieces`, `1v1_duels`, `possession`, `mixed`.

### Rules Engine

- [ ] Eight rule passes implemented as separate classes implementing `RulePass`. Pipeline runs them in documented order.
- [ ] Each pass takes its dependencies as constructor-injected interfaces; unit-testable with in-memory fakes.
- [ ] `RulesEngine::compose()` vs `RulesEngine::validate()` split implemented; `validate()` skips `ExerciseSelectionPass`.
- [ ] Server-side `validate()` runs on every `POST /vct/sessions/generate` and `PATCH /vct/sessions/{id}`; clients cannot bypass age ceilings or workload caps.
- [ ] `ExerciseSelectionPass` filters `archived_at IS NULL` explicitly; integration test covers archiving an exercise + regenerating + asserting absence.
- [ ] `WorkloadCapRule` reads `tt_player_phv_flags`; when any roster member is PHV-flagged, the configured reduction percentage applies.
- [ ] Pipeline emits structured warnings (not exceptions) for soft violations (over-envelope downgrades, missing macro-block config). Warnings surface in the wizard preview.
- [ ] Each rule pass has a unit test (give a context, assert mutations). Pipeline integration test covers end-to-end composition for at least one canonical case per (age × md_context).

### REST API

- [ ] All endpoints implemented with the **two-layer permission_callback** (cap + scope check) per the table above.
- [ ] Response shapes documented in `docs/rest-api.md` AND in this spec (sample `POST /vct/sessions/generate` response captured here).
- [ ] Validation failures return the `{error: {code, reasons[]}}` envelope.
- [ ] No endpoint uses `__return_true` or `is_user_logged_in()`.
- [ ] `EXPLAIN` on `ExerciseSelectionPass::findCandidates()` shows `type=ref` or better against a 1000-row catalogue.

### UI

- [ ] Coach mobile session view renders at 360px without horizontal scroll, all touch targets ≥ 48px, safe-area-inset honoured on iOS.
- [ ] **Viewport meta does NOT include `maximum-scale=1` or `user-scalable=no`** on any VCT page.
- [ ] All inputs declare correct `type` + `inputmode` + `autocomplete`. Date inputs `type="date"`; time inputs `type="time"`; durations `type="number" inputmode="numeric"`; intensity inputs `type="number" inputmode="numeric"`. Font-size ≥ 16px to prevent iOS auto-zoom.
- [ ] Save+Cancel via `FormSaveButton::render()` with `cancel_url` on the team-schedule panel + exercise editor. Configuration sub-forms (macro-blocks, age-profiles) exempt per CLAUDE.md § 6 (a) — stated in section header.
- [ ] Every routable view emits exactly two nav affordances: `FrontendBreadcrumbs::fromDashboard()` + auto-rendered `tt_back` pill. No `FrontendBackButton`, no "Back to list" link.
- [ ] Cross-entity links use `RecordLink::detailUrlForWithBack()`.
- [ ] No hover-only functionality; JS bundle < 50KB gzipped; `prefers-reduced-motion` respected.
- [ ] Library editor + age-profile editor + macro-blocks editor + team-schedule panel + per-player PHV flag all reachable for the correct cap holders.

### Wizards

- [ ] `new-vct-session` wizard registered in `WizardRegistry`; reachable via `?tt_view=wizard&slug=new-vct-session`.
- [ ] Wizard live-validation per the v3.110.190 pattern (#796): required fields red on blur, Next disabled until valid.
- [ ] Flat-form fallback at `?tt_view=vct-sessions&action=new`. Entry-point gating via `WizardEntryPoint::urlFor()` honours `tt_wizards_enabled`.
- [ ] Exercise CRUD ships as an inline form (vocabulary exemption); rationale documented in this spec.

### Integration tests

- [ ] Publishing a session creates exactly one `tt_activities` row with `activity_type='training'`, correct `team_id` + `session_date` + `start_time` + `duration_minutes`.
- [ ] Publishing when an Activity already exists for the same key prompts the user (does NOT create duplicate).
- [ ] Activity deletion fires the action handler; bound session's `activity_id` is nulled + status reverts to `draft`.
- [ ] Nightly `VctWorkloadAggregationTaskTemplate` produces one snapshot per (player, day) for every completed session in the last 28 days.
- [ ] Generating a session for a team with no `tt_vct_team_schedules` row succeeds + emits a warning.
- [ ] Generating with no `tt_vct_macro_blocks` rows succeeds + emits a warning + uses multiplier 1.0.
- [ ] `PATCH` swapping a block to an out-of-age exercise returns 400 with structured `vct_validation` reason.
- [ ] PHV-flagged player's load contribution is reduced by `growth_spurt_load_reduction_pct` in `WorkloadCapRule`.

### Docs

- [ ] `docs/vct.md` (English; `<!-- audience: user -->` marker for coach-facing content; `audience: admin` for HoD sections; `audience: dev` for architecture notes — split into separate files if needed).
- [ ] `docs/nl_NL/vct.md` (Dutch translation for the `audience: user` content per docs/contributing.md rule).
- [ ] `docs/rest-api.md` updated with all new endpoints.
- [ ] `docs/head-of-development-actions.md` Shipped section adds entry per memory `feedback_persona_polish_shipped_stanza`.
- [ ] `docs/coach-actions.md` Shipped section adds entry.
- [ ] `languages/talenttrack.pot` regenerated; nl_NL `.po` filled in; `.mo` regenerated. fr_FR / de_DE / es_ES may have empty msgstrs for the new strings (runtime English fallback per DEVOPS.md).
- [ ] `SEQUENCE.md` updated: phase status moved forward, remaining work phrased as "still to do", original-estimate-vs-actual hours filled where known.

### Architectural guards

- [ ] No AI call in the rules engine, workload calculator, or any MVP code path.
- [ ] Business logic lives in repositories + domain services; views compose.
- [ ] No `wp_schedule_event` registration; nightly aggregation runs via the Workflow cron-trigger.
- [ ] **Ships as a new feature epic → minor version bump per DEVOPS.md.** Patch reset to 0.
- [ ] **No `Co-Authored-By: Claude` trailers; no `🤖 Generated with Claude Code` footers** in commits or merged PR body.

### Pilot verification on staging

- [ ] Coach with one team configured generates a session → blocks visible, durations sum to total, total_load = Σ (block.intensity × block.duration).
- [ ] U10 generation → no block exceeds Band 3, no MD context applied, session ≤ 70 min.
- [ ] U13 on the day after a match → recovery template only, max Band 3.
- [ ] Macro-blocks configured for a season → engine progression multiplier visibly varies week-to-week within a block.
- [ ] Team schedule (Tue + Thu) configured → wizard date picker auto-suggests the next Tue/Thu.
- [ ] Publish a session → an Activity appears on the team calendar; second publish to same slot prompts bind.
- [ ] Activity deleted → bound session's status reverts to `draft`.
- [ ] HoD edits age-profile intensity ceiling lower → engine immediately respects new ceiling on next generate.
- [ ] PHV-flagged U13 player has reduced individual load contribution in the session's `total_load` breakdown.
- [ ] Switch site locale to French → all VCT vocabulary renders in French (translations live in `tt_translations` from day one).
- [ ] AuthChainDebugPage shows `tt_vct_plan` green via matrix path for head_coach / assistant_coach within their team scope; red outside scope.

## Notes

### Decisions log (resolved during 0.1 → 0.2 design review)

1. **Workflow integration**: cron-trigger row in `tt_workflow_triggers` + `VctWorkloadAggregationTaskTemplate` (not `wp_schedule_event`). SaaS-port chokepoint preserved.
2. **Seed pattern**: per-club seeding on activation. `club_id = 0` shared-overlay pattern rejected (not codebase convention). Clubs edit copies in place.
3. **Wizards vs exemptions**: one wizard (`new-vct-session`); exercise CRUD + three configuration sub-forms invoke CLAUDE.md § 3 / § 6 exemption (a) explicitly with rationale.
4. **Growth-spurt / PHV flag in MVP**: `tt_player_phv_flags` table + per-player checkbox + `WorkloadCapRule` reads the flag. Domain reviewer flagged Phase-2 deferral as the biggest safety risk; resolved by inclusion in MVP.
5. **Coaching points as translatable child table**: `tt_vct_coaching_points` separate from `tt_vct_exercises`; canonical text via `tt_translations`. JSON column dropped. Resolves the #902-pattern translation-trap risk.

### Other resolved design-review items (silent folds)

- Schema: UUID on age_profiles / team_schedules / session_templates; `team_id NOT NULL DEFAULT 0` on macro_blocks (drop COALESCE); ACWR `DECIMAL(3,2)`; `weekdays_bitmask TINYINT UNSIGNED`; `archived_at` on macro_blocks + team_schedules; MD-suitability denormalised to 8 bit-flag columns; `(club_id, archived_at, category, intensity_band, age_min, age_max)` composite index; explicit status enum + transitions on `tt_vct_sessions`; `seed_revision` column on exercises.
- Rules engine: constructor-injected dependencies per pass; `compose()` vs `validate()` split.
- REST: per-endpoint scope-check column; structured 400 error envelope; explicit response-shape sample in spec.
- Standards: viewport zoom-disable explicitly forbidden; audience markers on docs; `.pot` regeneration; minor-version bump; explicit `LegacyCapMapper` activity letters.
- Domain: U10 → 70 min, U11/U12 → 85 min, U10-U12 recovery gap → 72h, `match_load_multiplier_per_minute` tunable on age profile (default 7.0 with sign-off TBD), three new tactical themes.

### Architectural decisions (carried from 0.1)

- **AI does not enter MVP.** Three-layer separation enforces methodology safety in code, not policy.
- **Module isolation via narrow contracts.** VCT depends on Activities (one nullable FK), Players (read), PlayerStatus (Phase 2 read only), Authorization (caps + matrix), Lookups (vocab), Wizards (framework), Workflow (one task template). Each contract one-way.
- **Per-team override on macro-blocks.** `team_id = 0` is the season default; non-zero is per-team override.
- **Default block length = 6 weeks**; 4-week alternative ships as a seeded reference profile.
- **Verheijen youth adaptations encoded in code, not docs.** `tt_vct_age_profiles.intensity_band_max` + `RecoveryRule` + `md_logic_enabled` flag together make adult-Verheijen impossible to apply to U10 without an admin explicitly raising the ceiling.

### What's deliberately conservative

- Intensity ceilings still on the safe side of debated thresholds, but session-minute caps relaxed per domain-reviewer feedback (U10 60→70, U11/U12 75→85). Intensity continues to do the safety work.
- Workload envelope math uses minutes-of-intensity, not RPE or HR.
- 72h recovery gap for U10-U12 (was 48h). Adult literature uses 48h; youth literature (DFB Trainerausbildung) suggests 72h for under-13. Adjusted.
- Match-day load multiplier `7.0` exposed as configurable; conservative default but **needs domain-expert sign-off** for production use.
- ACWR thresholds conservative; tunable per club in Phase 2.

### Risks + mitigations

- **Pilot expects AI to do more than format Dutch.** Mitigation: MVP excludes AI entirely; pilot can't be disappointed by absence.
- **Seed exercise catalogue isn't pedagogically sound.** Mitigation: HoD/pilot-coach review of all 80 exercises before catalogue migration ships; tracked as separate pilot-board issue per memory `feedback_file_issue_on_board`.
- **Macro-block config drift between teams.** Mitigation: per-team override at `tt_vct_macro_blocks.team_id` accommodates legitimate variance.
- **Workload aggregation job missing a night.** Mitigation: idempotent + recomputes trailing 28d every run, self-repairs on next.
- **Per-club exercise catalogue grows unbounded.** Mitigation: `archived_at` soft-delete + `seed_revision` allow library curation; admin can hide deprecated exercises without losing history.
- **PHV flag false-positives over-restrict players.** Mitigation: coach can clear at any time; flag is advisory not absolute; load reduction is configurable (`growth_spurt_load_reduction_pct`).

### What this spec does NOT change

- Activities module schema (the cross-module UNIQUE-index ask is a separate issue).
- Players / Teams schemas (unchanged; PHV flag is a new separate table).
- PlayerStatus methodology (untouched).
- Authorization matrix base structure (only adds new entity rows).
- Any existing wizard or view.

### Verheijen-for-adults → youth adaptations (Appendix A)

| Adult Verheijen principle | Youth U10–U14 adaptation | Why |
| --- | --- | --- |
| 4-day MD microcycle (MD-4 → MD-3 → MD-2 → MD-1) | Compressed to 2–3 active sessions/week; U10/U11 don't use MD logic at all | Youth train 2–3×/week, not daily. Pre-puberty supercompensation limited; full cycle adds complexity without benefit. |
| High-intensity football actions at near-max | Hard intensity ceiling per age (Band 3 for U10, up to Band 7 for U13–U14 — never Band 10) | Growth plates + cardiovascular maturity not adult-equivalent. Anaerobic capacity limited pre-puberty. |
| Speed endurance via repeated 30–90s near-max blocks | Introduced only U13+, in shorter bursts; U10–U12 use game-form repetitions | Lactate clearance age-dependent. Under 13 lactic work has minimal training effect + high recovery burden. |
| Distinct tactical vs physical periodisation | Tactical IS physical at youth level — every session is football-with-purpose | Adults can isolate physical adaptations; youth need integrated games for motor + cognitive learning. |
| Recovery via discrete cool-downs + low-intensity days | Recovery mandatory + dual-purposed as motor-learning windows | Youth need low-stress states for skill consolidation. |
| Adult RPE + HR monitoring | Coach observation + deterministic load envelope (intensity × duration); no HR straps | RPE unreliable in youth; HR overkill at this age. |
| Sided games as a conditioning vehicle | Same — but smaller fields + more touches at younger ages | Aligned with KNVB / DFB youth manuals. |
| Speed work as discrete blocks | Speed via **football actions** (sprints with ball, reaction sprints in 1v1 duels) | Decontextualised sprinting boring + low transfer. |
| 11v11 + position-specific tactical work | Position-specific only U13+, optional, capped at 20–25% of session time | Premature position-locking limits long-term ceiling. |
| Off-season blocks of accumulated load | NO off-season block for youth; replaced by **growth-spurt-aware load reduction** (PHV flag in MVP) | Adolescent growth spurt requires **lower** load during PHV; adult periodization is inverted at this age. |
| 48h recovery between high-intensity sessions | **72h for U10–U12**; 48h for U13–U14 | DFB Trainerausbildung material recommends 72h for under-13. |

### Open questions remaining for design review

1. **MD logic for U12 — partial or full?** Spec currently says "simplified" (MD-3 / MD-1 / MD+1 distinct). Domain-reviewer validated this. Confirm with named youth coach during age-profile sign-off.
2. **Match-day load multiplier (`7.0` default)** — exposed as configurable; needs domain-expert sign-off on the default for production use.
3. **AI vendor for Phase 2** — defer to Phase 2 spec.
4. **HoD/pilot-coach catalogue review owner** — tracked as separate pilot-board issue; migration PR blocked until that issue's checklist is signed off.

### Approval checklist (before implementation begins)

- [ ] Architecture lead sign-off on module placement + integration contracts
- [ ] HoD/coach sign-off on age-profile defaults (intensity ceilings + session-minute caps + recovery gaps reviewed by an actual youth-development coach). **Critical**: confirm 70/85/85/90/90 session caps and 72h gap for U10-U12.
- [ ] Sign-off on `match_load_multiplier_per_minute = 7.0` default
- [ ] Translations lead sign-off on lookup vocabulary lists (vct_exercise_category, vct_tactical_theme, vct_md_context, vct_intensity_band, vct_session_status — all need nl_NL/fr_FR/de_DE/es_ES seeding from day one)
- [ ] Security review of REST shape (per-endpoint scope checks, audit logging coverage, PHV-flag history retention)
- [ ] Design sign-off on wizard flow + mobile session view (mocks before code)
- [ ] Pilot-coach review of seed exercise catalogue (80 exercises across 6 categories — quality > quantity; tracked as separate pilot-board issue)
- [ ] Cross-module agreement on `tt_activities` UNIQUE-index addition (race-guard) tracked as separate issue against Activities owner
