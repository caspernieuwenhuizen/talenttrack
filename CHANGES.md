# TalentTrack v3.95.1 — License gates wired: radar charts, undo bulk, partial restore (#0080 Wave A)

Closes the first wave of the #0080 deferred-polish epic. Three license-feature flags have been sitting in `FeatureMap::DEFAULT_MAP` since v3.86.1 (`radar_charts` / `undo_bulk` / `partial_restore`) but were never enforced at the surfaces that grant them — every tier saw the feature regardless of plan. This PR wires them. Drops `s3_backup` as the spec suggested (no S3 destination ships in the codebase — gate it when the destination ships). Defers the radar visual refresh (A1's risk callout in the spec) and Waves B / C / D to follow-up PRs.

## Why "Wave A only" rather than the full #0080 epic in one PR

The spec sizes #0080 at ~11-15h conventional / ~5-7h compressed across four waves. Bundling all four into one PR puts ~800-1200 LOC across very different surfaces (license gates, demo-data UX, comparison admin, mobile UX, frontend lookup, matrix admin, REST controllers, Excel template) into a single review. Three of the eleven sub-items also have spec-flagged operator-input dependencies (the radar visual refresh has bikeshed risk per spec note 159; B4 mobile RateActorsStep needs Casper's actual phone per note 160; Wave D Excel-sheet rename is a breaking change that needs an in-flight-workbook check per note 161). Splitting Wave A out keeps this PR coherent and lets the spec-flagged items get their operator review without holding up the simple gate work.

## Fix 1 — Radar charts gated centrally inside `radar_chart_svg`

Eleven call sites consume `QueryHelpers::radar_chart_svg()` (player rate card, frontend overview, comparison view, persona dashboard radar widget, coach dashboard, player dashboard, reports admin, evaluations admin, players admin). Rather than wrap each call site in a `LicenseGate::allows( 'radar_charts' )` check, the gate lives inside the function itself:

```php
public static function radar_chart_svg( array $labels, array $datasets, float $max = 5.0 ): string {
    if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
         && ! \TT\Modules\License\LicenseGate::allows( 'radar_charts' )
    ) {
        return \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Radar charts', 'talenttrack' ), 'standard' );
    }
    // ... existing SVG construction ...
}
```

Single chokepoint — every consumer inherits the gate. Free-tier sees the inline upgrade block in place of the chart; Standard / trial / Pro see the chart unchanged. The function signature is unchanged so no consumer needs to be touched. Class-existence guard means installs that disable the License module entirely keep rendering charts (matches the existing `LicenseGate::allows()` "module disabled = no enforcement" convention).

## Fix 2 — Bulk-undo notice + handler

`BulkUndoNotice::maybeRender()` still emits the safety-backup notice for every tier (the backup itself is defensive code, free for all — that's how operators recover from accidents regardless of license). What changes per tier:

- **Standard / trial / Pro** sees the working "Undo via backup →" link as before.
- **Free** sees a paywall variant: *"Bulk-undo is part of the Standard plan. Upgrade to recover from accidents."* — link goes to `?page=tt-account&tab=plan`.

`BackupSettingsPage::handleBulkUndo()` re-checks the gate after the cap + nonce checks, before the destructive-impersonation guard, so direct-URL POST to `tt_backup_bulk_undo` on free tier redirects back with `tt_bk_msg=license_undo_bulk` instead of executing the restore. The redirect-with-msg-key pattern matches the existing `undo_missing` / `undo_failed` shape; admin-post handlers don't return JSON envelopes (REST does).

## Fix 3 — Partial restore picker + handler

`renderPartialRestore()` checks the gate at the top of the method, before the backup-file fetch. Free tier sees `UpgradeNudge::inline( 'Partial restore', 'standard' )` rendered into the page in place of the scope-picker form. The "Partial restore" link in the backups list stays visible regardless of tier (the upgrade path stays discoverable; matches the v3.86.1 ship pattern for trial / scout / team-chemistry features).

`handlePartialExecute()` re-checks the gate after the standard `guard()` (which handles cap + nonce). Free-tier direct-URL POST on the `tt_backup_partial_execute` admin handler redirects back with `tt_bk_msg=license_partial_restore`.

## What's *not* in this PR

- **Radar visual refresh (A1's "while we're in there" item).** Spec note 159 explicitly calls out the risk: don't let a visual rework block the gate ship. Gate ships now with the existing radar visual; the persona-dashboard-token / softer-axes / filled-area-gradient refresh comes as a separate PR.
- **Wave B (UX polish — 5 items).** Demo wipe live JS preview, demo wipe per-batch scope, `UserComparisonPage` N-user + per-cap drilldown, `RateActorsStep` mobile stack layout, frontend lookup drag-reorder. Sized ~5-7h conventional / ~2-3h compressed; ships as the next PR. B4 (mobile `RateActorsStep`) needs Casper's phone testing before locking the breakpoint.
- **Wave C (architectural cleanup — 2 items).** Per-tile "what gates this?" popover on matrix admin + sub-cap refactor on three REST controllers (`ConfigRestController`, `PdpFilesRestController`, `ThreadsRestController`).
- **Wave D (Excel sheet rename — 1 item).** `Sessions` → `Activities` is a breaking change for in-flight workbooks per spec note 161; needs an operator confirmation that no academy is mid-import on a workbook with the legacy sheet name.
- **`s3_backup` gate.** Dropped per the 2026-05-04 shaping — gate it when an S3 destination actually ships.

## Affected files

- `src/Infrastructure/Query/QueryHelpers.php` — gate inserted at the top of `radar_chart_svg()`.
- `src/Modules/Backup/Admin/BulkUndoNotice.php` — paywall variant of the undo link on free tier.
- `src/Modules/Backup/Admin/BackupSettingsPage.php` — `handleBulkUndo`, `renderPartialRestore`, `handlePartialExecute` re-check the gate.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

Two new translatable strings:

- `Bulk-undo is part of the Standard plan. Upgrade to recover from accidents.` (NL: `Bulk-undo is onderdeel van het Standard-plan. Upgrade om fouten te kunnen herstellen.`)
- `Radar charts` (label passed to `UpgradeNudge::inline()`) — already present in the .po as a feature name, no new msgid required.

`Partial restore` is also already in the .po. Net: 1 new NL msgid.

---

# TalentTrack v3.95.0 — Onboarding pipeline child 1: prospects entity + PlayerDataMap registry (#0081)

First of four child PRs for the #0081 onboarding-pipeline epic. **No user-facing UI yet** — child 1 lays the data layer + GDPR scaffold so the workflow templates (child 2), pipeline widget (child 3), and trial-cases rework (child 4) can build on a stable foundation. Recommended sequence is intentionally entity-first: every following child references `tt_prospects` and the `PlayerDataMap` surface introduced here.

## Why land an entity ahead of the UI

Two reasons:

- The lifecycle of a prospect is **chain-of-tasks**, not status-on-row. Until the workflow templates exist, the entity has nothing to be "in". Shipping the table without templates is fine because there's no UI driving writes — the only producer in v3.95.0 is the (intentionally absent) UI layer, the only consumer is the retention cron, and the entity is intentionally inert.
- GDPR retention has to land at the same time as the entity that holds personal data. A prospect row is consent-gathered personal data with no contractual relationship to the academy. Holding the row indefinitely without a purge cron is a compliance liability. Shipping the cron before any UI flow can write rows means there's never a window where prospects accumulate without retention.

## `tt_prospects` and `tt_test_trainings`

Two new tables. Both deliberately small and migration-bounded — see `database/migrations/0066_prospects_entity.php`.

### `tt_prospects`

Identity + scouting context + parent contact (consent-captured at scout time) + transition-out columns (`promoted_to_player_id`, `promoted_to_trial_case_id`). Status of the journey lives in `tt_workflow_tasks`, NOT on this row. The only lifecycle column on the prospect is `archived_at` (with `archived_by` + `archive_reason`) for terminal outcomes — `declined` / `parent_withdrew` / `no_show` / `promoted` / `gdpr_purge`.

The "no status column" decision is the most important architectural choice in this child. The spec walks through the rationale; in short: a status enum on the prospect AND task statuses on the workflow chain creates two state machines that can drift. Querying "give me all prospects in stage X" requires a join through `tt_workflow_tasks` — the queries are slightly more complex; the net is positive.

### `tt_test_trainings`

The scheduled session a prospect is invited to. Many-to-many to prospects routes through workflow tasks (each prospect gets a `confirm_test_training` task whose `TaskContext` carries `test_training_id`), not a join table. Session metadata (`date`, `location`, `age_group`, `coach_user_id`) only.

## Matrix entities + caps

Two new entities seeded in `config/authorization_seed.php`:

| Entity | Player | Parent | Asst Coach | Head Coach | Team Mgr | Scout | HoD | Academy Admin |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prospects` | — | — | — | R team | — | RCD self | RCD global | RCD global |
| `test_trainings` | — | — | — | R team | — | R global | RCD global | RCD global |

Scout's RCD-self scope is enforced at the SQL layer in `ProspectsRepository`, not in the rendering layer — a scout literally cannot see another scout's prospects via any code path. (The "self" enforcement gets exercised once the UI lands in child 2 / child 3; the predicate is in place now so the UI can be thin.)

Six new caps bridged in `LegacyCapMapper`: the read/change/create_delete triplet for both entities (`tt_view_prospects`, `tt_edit_prospects`, `tt_manage_prospects`, plus the same for `test_trainings`).

Migration `0067_authorization_seed_topup_0081` walks the seed and `INSERT IGNORE`s the new tuples into existing installs — same pattern as `0063` (#0079) and `0064` (`my_pdp_panel`). Per `feedback_seed_changes_need_topup_migration.md`: adding rows to the seed file alone doesn't reach existing matrices, because `0026_authorization_matrix.php` only runs on fresh install or via the admin "Reset to defaults" button.

## Daily retention cron

`ProspectRetentionCron` (`hook = tt_prospects_retention_cron`) runs daily, in batches of 50 per tick. Two purge conditions:

- **Stale-no-progress** — `created_at` older than 90 days AND `promoted_to_player_id IS NULL` AND `archived_at IS NULL`. Active-chain protection currently runs on `created_at` only because the chain link (`tt_workflow_tasks.prospect_id`) ships in child 2. Once that lands, this query gets a `LEFT JOIN onto tt_workflow_tasks ON prospect_id` and the `HAVING` clause excludes any prospect with a non-terminal task.
- **Terminal-decline cool-off** — `archived_at IS NOT NULL` AND archived more than 30 days ago AND `archive_reason IN ('declined', 'parent_withdrew', 'no_show')` AND not promoted.

Promoted prospects (`promoted_to_player_id IS NOT NULL`) are protected — promotion turns them into PII for an academy player and `PlayerDataMap` registers the row under the player's identity for the future #0073 erasure flow.

Thresholds are configurable per install via `wp_options.tt_prospect_retention_days_no_progress` (default 90) and `wp_options.tt_prospect_retention_days_terminal` (default 30). Each purge writes one audit row to `tt_authorization_changelog` with `change_type = 'gdpr_prospect_retention_purge'`. Failure is silent and retried next tick — the cron is idempotent (deleting an already-deleted row is a no-op).

## `PlayerDataMap` registry

Net-new infrastructure at `src/Infrastructure/Privacy/PlayerDataMap.php`. The point: when GDPR Article 15 (subject access) or Article 17 (erasure) lands on this install, the operator needs an authoritative answer to *which tables hold this person's data*. Hard-coding that list inside an exporter or eraser invites drift; every new module that adds a PII column has to remember to update the central list.

Static-only registry. Modules register PII tables at boot via:

```php
PlayerDataMap::register(
    string $table,            // 'tt_players' (unprefixed)
    string $player_id_column, // 'id' for the player record itself, otherwise the FK column
    string $purpose,          // human-readable purpose for export manifests
    string $owner_module      // FQCN of the module class
);
```

Two query methods:
- `PlayerDataMap::all()` — full registration list.
- `PlayerDataMap::rowCountsForPlayer( int $player_id )` — runs `SELECT COUNT(*) FROM \`{$table}\` WHERE \`{$column}\` = %d` per registration; returns `[ ['table' => 'tt_evaluations', 'count' => 12, 'purpose' => '...'], ... ]`. Skips silently when a registered table doesn't exist on the install (modules can be disabled, leaving registrations dangling).

`CorePiiRegistrations::register()` registers 13 known core PII tables on every boot (called from `Kernel::boot()` after `bootAll()` so individual modules can register their own first):

- `tt_players` (key column `id`)
- `tt_player_parents` (`player_id`)
- `tt_evaluations` (`player_id`)
- `tt_eval_ratings` (`player_id`)
- `tt_goals` (`player_id`)
- `tt_attendance` (`player_id`)
- `tt_player_events` (`player_id`)
- `tt_player_injuries` (`player_id`)
- `tt_player_team_history` (`player_id`)
- `tt_pdp_files` (`player_id`)
- `tt_player_reports` (`player_id`)
- `tt_trial_cases` (`player_id`)
- `tt_prospects` (`promoted_to_player_id`) — the link column is the promotion FK because a prospect doesn't have a player record until promotion.

`tt_test_trainings` is intentionally NOT registered — it's session metadata, not player-keyed PII. The link from a player back to a test training routes through `tt_workflow_tasks` once child 2 lands, and the eraser walks the parent chain rather than the leaf.

Coverage policy: only tables with a direct, indexed player-id FK land in the central bootstrap. Junction-style tables that reach a player via two hops (e.g. `tt_pdp_conversations` reaches a player only through `tt_pdp_files.player_id`) are not registered — the erasure code walks them via the parent-table registration. Future modules adding new PII columns are expected to register from their own boot path; the central bootstrap is the v1 backfill, not the canonical contract.

## Why ship `PlayerDataMap` in child 1 (and not defer to #0073)

The original plan was option A — inline a small retention helper in child 1 and let #0073 add the registry later. The decision flipped to option B (build the minimal `PlayerDataMap` surface as part of this work) because:

- The retention cron audit-log row already references a player-mapping concept (the prospect's `promoted_to_player_id`); it's clearer if there's a registry the audit can name rather than ad-hoc.
- The registry is ~140 lines including doc; the inline alternative would have been ~40 lines that #0073 would later have to delete.
- Future child-2/3/4 work touches PII tables; the registry being there now means those PRs simply add a `PlayerDataMap::register(...)` call rather than introducing the registry mid-epic.

Building the foundation once and using it consistently saves the round-trip of "ship erasure later, then go back and register every table."

## What's *not* in this PR

- No UI surfaces. No `?tt_view=prospects` view, no admin page, no REST endpoints. Those land in child 2 / child 3 alongside the workflow templates and pipeline widget.
- No workflow templates. The chain (LogProspect → InviteToTestTraining → ConfirmTestTraining → RecordTestTrainingOutcome → ReviewTrialGroupMembership) ships in child 2.
- No `tt_workflow_tasks.prospect_id` column. That's a workflow-engine schema change owned by child 2.
- No GDPR erasure execution. `PlayerDataMap` is the registry; the erasure flow is #0073 territory.
- No bulk-import or self-registration. Both explicitly out of scope per the spec.

## Affected files

- `database/migrations/0066_prospects_entity.php` — new tables.
- `database/migrations/0067_authorization_seed_topup_0081.php` — backfills the two new entities into existing matrices.
- `config/authorization_seed.php` — `prospects` + `test_trainings` rows for head_coach (R team), scout (RCD self / R global), head_of_development (RCD global), academy_admin (RCD global). New `$mod_prospects` shortcut.
- `config/modules.php` — registers `ProspectsModule`.
- `src/Modules/Prospects/ProspectsModule.php` — module shell.
- `src/Modules/Prospects/Repositories/ProspectsRepository.php` — CRUD + soft-archive + duplicate-detection candidate fetch.
- `src/Modules/Prospects/Repositories/TestTrainingsRepository.php` — CRUD.
- `src/Modules/Prospects/Cron/ProspectRetentionCron.php` — daily retention purge.
- `src/Modules/Authorization/LegacyCapMapper.php` — six new cap → entity mappings.
- `src/Modules/Authorization/Admin/MatrixEntityCatalog.php` — two new entity labels for the matrix admin.
- `src/Infrastructure/Privacy/PlayerDataMap.php` — new registry.
- `src/Infrastructure/Privacy/CorePiiRegistrations.php` — initial 13 PII-table registrations.
- `src/Core/Kernel.php` — calls `CorePiiRegistrations::register()` after module boot.
- `languages/talenttrack-nl_NL.po` — 2 new NL strings (`Prospects`, `Test trainings`).
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

---

# TalentTrack v3.94.1 — Pilot batch: compare alignment, full-Pro trial, HoD cohort gate, PDP block detail + breadcrumbs

Five operator-reported issues, one ship.

## (1) Player compare — data now lines up under each player

`FrontendComparisonView` rendered each section (FIFA card row → Basic facts → Headline numbers → Main category averages) in its own layout — flex for cards, separate `<table>` per section — so the player columns didn't line up vertically across the page. Replaced with a single CSS Grid (`grid-template-columns: 180px repeat(N, 1fr)`) carrying the header row, the FIFA card cells, and the data rows in one container. Every player now has a dedicated grid column from the card right down through the last category average. New `renderMainBreakdownGrid()` emits the category rows directly into the grid; the legacy `renderMainBreakdown()` is kept (deprecated). Mobile breakpoint shrinks the column widths but keeps the grid intact.

## (2) Trial now unlocks Pro, not Standard

`TrialState::start()` defaulted to `FeatureMap::TIER_STANDARD`; the Pro-only features (`trial_module`, `team_chemistry`, `scout_access`, `s3_backup`) stayed gated during the trial window. Operator clicked "Start trial" expecting every paid feature to light up, found Trials still bouncing on the "not allowed" message. `TrialState::start()` default flipped to `FeatureMap::TIER_PRO`; existing trials in flight keep their original `tier_during` value (read per call) — only NEW trials default to Pro. AccountPage CTA wording: "Start 30-day **Pro** trial" + a longer description naming the unlocked features. `handleStartTrial()` now calls `TrialState::start()` without an explicit tier.

## (3) HoD cohort transitions no longer hardcoded to academy_admin

`FrontendCohortTransitionsView::render()` gated on `current_user_can( 'tt_view_settings' )` — the academy-admin umbrella cap. The matrix grants `cohort_transitions: r:global` to **head_of_development** too, but the cap-only check ignored that grant. HoD logged in → check fails → "head-of-academy access" message → confused user. Replaced the cap check with `QueryHelpers::user_has_global_entity_read( $user_id, 'cohort_transitions' )`, the same helper the v3.91.3 sweep used for the REST list controllers. Three rungs in order of cheapness: `tt_edit_settings` cap, WP `administrator` role, then `MatrixGate::can(..., 'global')`. Matrix-dormant installs bypass via the cap shortcut. Message updated to "you do not have access to cohort transitions".

## (4) PDP planning cells now drill into per-player block status

Cells in `FrontendPdpPlanningView` linked to `?tt_view=pdp&filter[team_id]=N&filter[block]=B&filter[season]=S`. `FrontendPdpManageView` *received* the filters but only used them to render a "Back to Planning" button — the actual list query ignored team / block / season scope and showed every PDP file in the current season. Clicking on "Team U17 / Block 2" landed on the unfiltered list.

New action `?tt_view=pdp-planning&action=block&team_id=N&block=B&season_id=S` renders three columns:

- **Conducted** — players with `conducted_at` set on their block-N conversation.
- **Planned** — players with `scheduled_at` set but no `conducted_at` yet.
- **Missing** — active-roster players with no conversation in this block; the row links to their existing PDP file or to "create new PDP file for this player" depending on whether the file exists.

Cell-click URL changed to point at the new view. Block window dates surfaced inline. Existing `?tt_view=pdp&filter[...]` URLs keep working but the matrix stops sending users there.

## (5) PDP planning gets breadcrumbs

`FrontendPdpPlanningView` had no header / breadcrumb chrome — same surface the v3.92.2 sweep left behind. Added `FrontendBreadcrumbs::fromDashboard( __( 'PDP planning' ) )` at the top of the matrix render, and a 3-level chain on the new block detail (`Dashboard / PDP planning / <Team> — Block N`) using `viewCrumb()` to build the parent crumb back to the matrix with the season preserved.

## Files touched

- `talenttrack.php` — version bump to 3.94.1
- `src/Modules/License/TrialState.php` — default tier flipped Standard → Pro
- `src/Modules/License/Admin/AccountPage.php` — CTA + description rewritten; `handleStartTrial()` uses the default
- `src/Modules/Journey/Frontend/FrontendCohortTransitionsView.php` — matrix-driven gate
- `src/Modules/Pdp/Frontend/FrontendPdpPlanningView.php` — `action=block` route, block detail renderer, breadcrumbs, query helper
- `src/Shared/Frontend/FrontendComparisonView.php` — unified CSS Grid; new `renderMainBreakdownGrid()`
- `languages/talenttrack-nl_NL.po` — 16 new NL msgids
