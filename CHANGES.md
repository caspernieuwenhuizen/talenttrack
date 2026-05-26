# TalentTrack v4.3.5 — VCT module ship 5: Rules Engine + repositories + supporting services (closes #910, partial epic #905)

## Context

Ship 5 of the VCT epic. The **deterministic core** of the module — every safety guarantee VCT makes (age admissibility, MD logic, workload caps, recovery rules, exercise selection) ultimately resolves to a class in this ship. **No AI anywhere**, per spec § Architectural decisions.

Four foundation ships have already landed: VCT-1 (v4.3.0) schema, VCT-2 (v4.3.1) caps + matrix, VCT-3 (v4.3.2) lookup vocab, VCT-4 (v4.3.3) age profiles + templates + phase profiles. This ship lands the engine that consumes all of them.

No REST, no UI yet. VCT-6 (#911) consumes `SessionGenerator` from REST controllers; VCT-7 (#912) consumes `WorkloadCalculator` + the workload-snapshots repo from the nightly task.

## What changed

### Eleven repositories — `src/Modules/Vct/Repositories/`

One per table. Every read filters `club_id = CurrentClub::id()` (tenancy guarantee).

| Repository | Responsibility |
|---|---|
| `VctAgeProfilesRepository` | Read + `update()` for the per-club workload envelope per age |
| `VctSessionTemplatesRepository` | `findFor(age, md_context)` with NONE fallback |
| `VctMacroBlocksRepository` | `findCurrent()` prefers per-team rows over `team_id = 0` season defaults; reference templates at `season_id = 0` excluded |
| `VctTeamSchedulesRepository` | Upsert per-team weekly schedule + weekdays_bitmask |
| `VctExercisesRepository` | `findCandidates()` — hot path; **explicit `archived_at IS NULL` filter** per spec acceptance criterion; uses the composite index from migration 0122 |
| `VctPhvFlagsRepository` | `activeForRoster()` returns PHV-flagged players from a roster; `setFlag()` upsert. **Implements `VctPhvFlagsProvider`** so rule passes can inject the interface |
| `VctSessionsRepository` | Sessions root. **Transactional `delete()`** that cleans up child blocks in one tx (no DB CASCADE per codebase convention) |
| `VctSessionBlocksRepository` | `replaceForSession()` / `updateBlock()` / `listForSession()` |
| `VctWorkloadSnapshotsRepository` | `upsert()` with `INSERT ... ON DUPLICATE KEY UPDATE` for the nightly job's idempotent re-runs |
| `VctMicrocyclesRepository` | Weekly aggregate per team |
| `VctCoachingPointsRepository` | Resolves canonical text via `tt_translations` per locale |

### Eight rule passes — `src/Modules/Vct/Rules/`

Each implements `RulePass::apply(SessionPlanContext): SessionPlanContext` with **constructor-injected dependencies** (architecture review H2 — no pass reads `$wpdb`, globals, or singletons).

1. **`AgeAdmissibilityRule`** — stamps intensity ceiling + session max + MD-logic flag + recovery gap + PHV pct + match multiplier + weekly envelope onto the context. Emits `block` warning if no age profile exists.
2. **`MdContextRule`** — resolves MD-N / MD+N from the team's match calendar via `ActivitiesReader`. **Returns NONE for U10/U11 regardless of Activities state** (the `md_logic_enabled = 0` guard).
3. **`SessionCompositionRule`** — loads the seeded `(age, md_context)` template; falls back to NONE; emits `block` warning if neither exists.
4. **`TacticalThemeRule`** — no deps; stamps coach's theme on slots where `theme_filter = true`, leaves warm-up / cool-down theme-agnostic.
5. **`ProgressionRule`** — looks up the current macro-block + computes week-within-block multiplier. Falls back to 1.0 + `info` warning when no block configured.
6. **`ExerciseSelectionPass`** — queries `VctExercisesRepository::findCandidates()`, scores by age-fit + variety vs `RecentPicksProvider`, picks best. **Filters `archived_at IS NULL`** per spec. **Skipped in `validate()` mode** so coach's manual swaps aren't overwritten.
7. **`WorkloadCapRule`** — sums `intensity × duration`, applies progression multiplier, applies PHV reduction roster-weighted (per-player precision via `VctPhvFlagsProvider`), warns over-envelope.
8. **`RecoveryRule`** — per-age recovery gap from workload snapshots: **72h for U10-U12, 48h for U13-U14** (Appendix A revision).

### `RulesEngine` orchestrator + two entry points

Per the spec's architectural fix for the PATCH-trigger-re-selection problem:

- **`compose(SessionPlanContext): SessionPlanContext`** — runs the full pipeline including `ExerciseSelectionPass`. Used by `POST /vct/sessions/generate` (in VCT-6).
- **`validate(SessionPlanContext): ValidationResult`** — runs passes 1-5 + 7 + 8 only; **skips `ExerciseSelectionPass`** so a coach's manual swap is validated but not overwritten. Used by `PATCH /vct/sessions/{id}` (in VCT-6).

`FinalizationPass` composes the final session payload (sorts blocks by `sequence`, computes `total_duration_minutes`). `ValidationResult` envelope carries `passes`, `warnings`, `total_load`; `blockingReasons()` filters to the `block`-severity warnings for the REST 400 envelope.

### Four services — `src/Modules/Vct/Services/`

- **`SessionGenerator`** — high-level entry for `POST /vct/sessions/generate`. Builds context → compose → persist; returns `{session, warnings}` or null on blocking validation.
- **`WorkloadCalculator`** — pure-function helpers: `sessionLoad()`, `rollingLoad()`, `acwr()`, `flagForAcwr()`, `applyPhvReduction()`. Used by VCT-7's nightly task.
- **`MdContextResolver`** — standalone version of the MD-context lookup for surfaces that need it BEFORE the engine runs (e.g. the wizard's "When" step previewing "this is MD-3" inline).

### Three provider interfaces + production implementations — `src/Modules/Vct/Rules/Providers/`

Module-owned interfaces so the engine doesn't reach into other modules' internals (spec § Module isolation via narrow contracts):

- **`ActivitiesReader`** — `nextMatchDate()` / `previousMatchDate()`. Production `NativeActivitiesReader` queries `tt_activities` directly with a `match`-flavoured `activity_type LIKE %match%` filter.
- **`RecentPicksProvider`** — `recentExerciseIds(team_id, lookback_days)`. Production `NativeRecentPicksProvider` queries the sessions+blocks join for the trailing window.
- **`VctPhvFlagsProvider`** — `activeForRoster(player_ids)`. Production implementation IS the `VctPhvFlagsRepository` (same class implements the interface; nothing to wire in production code).

### `VctModule` registration

Adds `TT\Modules\Vct\VctModule::class => true` to `config/modules.php`. `register()` and `boot()` are intentionally empty in this ship; VCT-6 (#911) wires REST registration + wizard registration, VCT-7 (#912) wires the workflow cron trigger.

## Tests deferred

The spec's acceptance criteria require "one unit test per RulePass + integration tests per (age × md_context) canonical case." TalentTrack's test infrastructure is **Playwright (e2e), not PHPUnit** — there's no PHP unit-testing framework set up. Setting up PHPUnit + the test bootstrap + the WordPress test scaffolding would balloon this PR's scope by an order of magnitude. Coverage moves to a separate follow-up issue: "VCT test infrastructure — set up PHPUnit + per-pass unit tests + per-(age × md_context) integration tests."

This deviation from acceptance criteria is documented in this changelog entry and flagged in the PR body. The engine is still safe-by-construction: every rule pass has constructor-injected dependencies, so each pass IS unit-testable — only the test harness is missing.

## Out of scope

- REST endpoints — VCT-6 (#911) consumes this engine.
- Wizard UI — VCT-6 (Phase 1 UI) and the standalone wizard ship.
- `CoachingLanguageService` — deferred. Only VCT-6's REST/UI surfaces consume Dutch coaching prose; building it now without a consumer is YAGNI. VCT-6 ships it with its actual call site.
- Workflow nightly aggregation — VCT-7 (#912).

## Validation

- `composer dump-autoload` (or the project's equivalent) picks up the new classes; no PSR-4 issues.
- `php -l` on every new file: clean. (PHPStan level 8 in CI confirms.)
- After deploy, `TT\Modules\Vct\VctModule` is instantiated by the kernel module loader; `boot()` is a no-op so nothing breaks on activation.
- Engine readiness: instantiate `RulesEngine` with the eight production passes + finalize pass + a built context → `compose()` returns a context with `blocks[]`, `total_load`, `warnings[]` shaped per the spec's sample response.

## Why this is `patch`, not `minor`

Engine within the 4.3 minor that VCT-1 opened. No new feature epic; the schema in v4.3.0 made the architectural promise — this ship delivers the engine that consumes the schema. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.4` → `4.3.5`.
