# TalentTrack v4.3.7 — VCT module ship 7: workflow nightly task via cron trigger (closes #912, **closes epic #905**)

## Context

Final Phase-1 ship of the VCT module. Six foundation ships have landed:

- VCT-1 (v4.3.0) — schema
- VCT-2 (v4.3.1) — caps + matrix + LegacyCapMapper bridges
- VCT-3 (v4.3.2) — lookup vocabularies with direct translations
- VCT-4 (v4.3.3) — age profiles + session templates + phase profiles seeds
- VCT-5 (v4.3.5) — rules engine + repositories + services
- VCT-6 (v4.3.6) — REST API with two-layer permission_callback

This ship closes the epic by registering the nightly workload-aggregation task — the last unfulfilled spec acceptance criterion under the Phase-1 scope.

## What changed

### `VctWorkloadAggregationTaskTemplate` — `src/Modules/Vct/Workflow/`

New class extending `TaskTemplate`. **Server-side aggregation job, not a user-facing task**: `expandTrigger()` runs the work and returns an empty array so the engine creates zero `tt_workflow_tasks` rows.

- `key()` — `'vct_workload_aggregation'`
- `defaultSchedule()` — `{type: 'cron', expression: '0 2 * * *'}` (02:00 UTC daily)
- `defaultAssignee()` — `LambdaResolver(static fn() => [])` makes the no-assignee contract explicit
- `formClass()` — references an existing `FormInterface` for contract satisfaction (never instantiated; zero tasks means zero form renders)
- `entityLinks()` — `[]`

### `aggregate()` — the actual work

1. Walk every `tt_vct_sessions` row with `status = 'completed'` in the trailing 28-day window.
2. Sum each session's block-level load via the existing `WorkloadCalculator::sessionLoad()` from v4.3.5.
3. Attribute load to players via `tt_attendance`: Present = full credit, Absent / Excused / Injured = zero. This is the meaningful-load guarantee from spec § Background work — a player who didn't attend doesn't get the load contribution.
4. Per `(player_id, snapshot_date)`, compute 24h / 7d / 28d rolling loads + ACWR (`7d_load / (28d_load / 4)` per spec § dispatch step 3).
5. Set `flag`:
   - `over_envelope` when 7d load exceeds the age-profile weekly envelope.
   - `acwr_high` when ACWR > 1.5, `acwr_low` when < 0.8.
   - NULL otherwise.
6. Upsert `tt_vct_workload_snapshots` via `INSERT ... ON DUPLICATE KEY UPDATE`. Re-runs are idempotent at the row level; missed nights self-repair on the next 28-day pass.

### Migration 0127 — workflow trigger row

Inserts a row into `tt_workflow_triggers`:

- `template_key = 'vct_workload_aggregation'`
- `trigger_type = 'cron'`
- `cron_expression = '0 2 * * *'`
- `enabled = 1`

Idempotent — existence-check on `(club_id, template_key, trigger_type)` before insert. Operators can disable the schedule via the workflow config UI or override the cron expression; re-runs preserve their edits.

### `VctModule::boot()` — workflow registration

Hooks `init` priority 5 to register the template with `WorkflowModule::registry()`. Same pattern as PdpModule's `registerShippedTemplates`; ensures the template is in the registry before dispatchers (priority 20) tick.

### Why no `wp_schedule_event`

Per spec § Decisions log #1: the Workflow module is the SaaS-port chokepoint for scheduler abstraction. One place that swaps out when VCT migrates to the SaaS frontend, not N per-module cron registrations. CLAUDE.md §4 codifies this.

## Epic closure

With this ship, the VCT Phase 1 epic (#905) closes. Phase 2 work is filed separately:

- **VCT-8** — exercise catalogue editor UI + write path. Gated on pilot-coach review of the seeded catalogue.
- **VCT-9** — new-VCT-session wizard registration + the five-step wizard UI.
- **VCT-10** — mobile session view + printable A4/A6 sub-renders.
- **VCT-11 / VCT-12** — library admin + configuration tiles (macro-blocks, age profiles).
- **Phase 2** — AI presentation layer; workload heatmap UI consuming the snapshots this ship populates; ACWR alerting.

## Validation

- Migration 0127 runs cleanly; `SELECT * FROM wp_tt_workflow_triggers WHERE template_key = 'vct_workload_aggregation'` returns exactly one row with `cron_expression = '0 2 * * *'`, `enabled = 1`.
- Re-running migration 0127 leaves the count at 1 (idempotent).
- After deploy + the CronDispatcher's next hourly tick, the template's `aggregate()` fires on schedule (or sooner if invoked imperatively from a debug surface).
- Seed 5 completed VCT trainings over the last 28 days for one team; call `(new VctWorkloadAggregationTaskTemplate)->aggregate()`; assert `tt_vct_workload_snapshots` has one row per (attending-player, completed-session-date) with non-zero loads.
- Second invocation: row counts unchanged; load values match (idempotent at row level).
- Mark a player Absent for one session in `tt_attendance`; re-aggregate; that player's snapshot for that date has zero contribution from the absent session.

## Why this is `patch`, not `minor`

Workflow trigger within the 4.3 minor. The epic itself doesn't re-bump minor at close because the schema bump (v4.3.0) already declared the epic — per the SemVer table in `DEVOPS.md`, the minor lands on ship 1 and patches roll through subsequent ships of the same epic.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.6` → `4.3.7`.

## Closes

- #912 (VCT-7 / this ship)
- **#905 (VCT Phase 1 epic)**
