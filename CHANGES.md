# TalentTrack v4.12.5 — Vocabulary constants for reports + journey + scouting (PR-set 5 of #988)

Fifth of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; this ship covers the report / journey / scouting vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/JourneyEventType.php` (new) — fifteen lowercase snake_case constants for `tt_player_events.event_type` per the migration 0037 seed (fourteen v1 canonical types: `JOINED_ACADEMY`, `TRIAL_STARTED`, `TRIAL_ENDED`, `SIGNED`, `RELEASED`, `GRADUATED`, `TEAM_CHANGED`, `AGE_GROUP_PROMOTED`, `POSITION_CHANGED`, `INJURY_STARTED`, `INJURY_ENDED`, `EVALUATION_COMPLETED`, `PDP_VERDICT_RECORDED`, `NOTE_ADDED`) plus `GOAL_SET` (emitted by `JourneyEventSubscriber::on_goal_saved` via the `tt_goal_saved` hook). Mirrors the PR-set 1 file shape (`const ALL` + static `isValid()`).
- `src/Domain/Vocabularies/Lookups/ScoutingVisitStatus.php` (new) — three lowercase constants for `tt_scouting_plan_visits.status`: `PLANNED`, `COMPLETED`, `CANCELLED`.
- `src/Domain/Vocabularies/Lookups/ScheduledReportFrequency.php` (new) — three lowercase constants for `tt_scheduled_reports.frequency`: `WEEKLY_MONDAY`, `MONTHLY_FIRST`, `SEASON_END`. Strings (rather than integers) so a future per-club calendar can extend without a schema migration; migration 0075 documents the three v1 values.
- `src/Domain/Vocabularies/Lookups/ScheduledReportStatus.php` (new) — three lowercase constants for `tt_scheduled_reports.status`: `ACTIVE`, `PAUSED`, `ARCHIVED`.
- `src/Domain/Vocabularies/Lookups/ReportAudienceType.php` (new) — eight lowercase constants for `tt_player_reports.audience`: `STANDARD`, `PARENT_MONTHLY`, `INTERNAL_DETAILED`, `PLAYER_PERSONAL`, `SCOUT`, `TRIAL_ADMITTANCE`, `TRIAL_DENIAL_FINAL`, `TRIAL_DENIAL_ENCOURAGEMENT`. Mirrors the eight values already centralised in `Modules\Reports\AudienceType` as the cross-module canonical reference.

**PHP - literal -> constant replacements**

- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — every `EventEmitter::emit()` positional second-arg literal across `on_evaluation_saved` / `on_goal_saved` / `on_pdp_verdict_signed_off` / `on_player_created` / `on_player_save_diff` / `on_trial_started` / `on_trial_decision_recorded` / `emitStatusTransition` / `emitTeamChange` flows now binds through the new `JourneyEventType::*` constants. Stored event-type values are byte-identical to the previous release.
- `src/Infrastructure/Journey/JourneyBackfillService.php` — the same flows from the rebuild service (`backfillEvaluations` / `backfillPdpVerdicts` / `backfillGoals` / `backfillPlayersJoined` / `backfillTrials`) bind through the new constants.
- `src/Infrastructure/Journey/InjuryRepository.php` — the `injury_started` + `injury_ended` emits bind through the new constants.
- `src/Modules/Prospects/Frontend/FrontendScoutingPlanView.php` — pill colour map keys, the list-row status-key default, and the form-mode status default bind through `ScoutingVisitStatus::PLANNED|COMPLETED|CANCELLED`.
- `src/Modules/Prospects/Frontend/FrontendScoutingVisitDetailView.php` — the detail-row status default binds through `ScoutingVisitStatus::PLANNED`.

**PHP - backward-compat aliases**

- `src/Modules/Prospects/Repositories/ScoutingVisitsRepository.php` — `STATUS_PLANNED` / `STATUS_COMPLETED` / `STATUS_CANCELLED` alias the new vocabulary constants. Every existing internal caller (`ProspectsModule`, `ScoutingVisitsRestController`, `FrontendScoutingPlanView`, etc.) continues to compile and produce the same stored value.
- `src/Modules/Analytics/ScheduledReportsRepository.php` — `FREQUENCY_WEEKLY_MONDAY` / `FREQUENCY_MONTHLY_FIRST` / `FREQUENCY_SEASON_END` and `STATUS_ACTIVE` / `STATUS_PAUSED` / `STATUS_ARCHIVED` alias the new vocabulary constants. `FrontendScheduledReportsView` and `ScheduledReportsActionHandlers` continue to call the repository constants unchanged.
- `src/Modules/Reports/AudienceType.php` — eight constants alias the new `ReportAudienceType::*` constants. `PlayerReportRenderer`, `AudienceDefaults`, `ScoutDelivery`, `ReportConfig`, `ScoutReportsRepository`, and `FrontendScoutMyPlayersView` continue to call `AudienceType::*` unchanged. The Reports-module-local label / describe / `trialLetters` helpers stay in place.

**Out of scope for this PR-set**

- SQL string literals, migration data, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.
- The Reports module's `AudienceType` carries the canonical label / describe helpers; those stay there because they bind to Reports-side gettext strings and the lookup-translator path, not to the vocabulary surface.

## Why patch

PR-set 5 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Save an evaluation, save a goal, sign off a PDP verdict, create a player, change a player's team / position / age-group / status, start a trial, record a trial decision — each path emits the correct `tt_player_events.event_type` byte-for-byte unchanged from v4.12.4.
- Trigger `JourneyBackfillService::rebuildAll()` from the demo-data admin — the rebuilt event types match the migration-0037 seed values.
- Log an injury via `InjuryRepository::create()`; set `actual_return` — `injury_started` then `injury_ended` rows appear with byte-identical event-type values.
- Open `?tt_view=scouting-visits`: list rows show the planned/completed/cancelled status pills coloured blue/green/red as before; the form's default visit status is `planned`. Open `?tt_view=scouting-visit&id=N`: the detail view's status pill renders the stored value.
- Open `?tt_view=scheduled-reports`: the frequency dropdown lists `Weekly (Monday morning)` / `Monthly (first day)` / `Season end (1 July)` with the same `weekly_monday` / `monthly_first` / `season_end` `<option value>` attributes; pause / resume / archive flips a schedule between `active` / `paused` / `archived` correctly; the cron picks up `active` schedules whose `next_run_at` is past.
- Render a report at each of the eight audience values via `PlayerReportRenderer::render( $player_id, AudienceType::SCOUT )` etc. — every path keeps its canonical English label + description + (for the trial-letter audiences) `trialLetters()` membership check.

---

# TalentTrack v4.12.4 — Match prep widen + landscape A4 print + save-indicator + in-place print button (closes #998)

Four bundled UX defects on the head-coach match-preparation surface (`?tt_view=match-prep&activity_id=<id>`), shipping together as one patch because they sit on the same three files.

## What ships

**(1) Widen on-screen** — `.tt-dashboard:has(.tt-match-prep)` lifts the wrapper max-width from 1100px to 1320px on the match-prep route only; every other dashboard view stays at 1100px. Desktop grid columns widen from `12.5rem | 1fr | 20rem` to `14rem | 1fr | 22rem`. Mobile and tablet breakpoints untouched.

**(2) Landscape A4 print CSS** — new `@page { size: A4 landscape; margin: 8mm }` plus an `@media print` block that drops the dashboard chrome (`.tt-breadcrumbs`, `.tt-back-link-wrap`, page-head actions, `.tt-mp-toolbar`) and every overlay (`.tt-mp-picker(-backdrop)?`, `.tt-mp-drawer(-backdrop)?`) so only the spreadsheet renders on paper. Selectors verified against the live markup rather than guessed. Forces the 3-column grid on regardless of print viewport width. Pitch tints, panel-head shading, and "on pitch" green cells preserved via `print-color-adjust: exact`. `break-inside: avoid` on each player row, goal box, and set-piece row prevents page-break splits.

**(3) Save-indicator layout shift** — `.tt-mp-save-state` gains `min-height: 1.4em`, `min-width: 12ch`, `display: inline-flex` so its bounding box stays stable while the textContent toggles between dirty / saving / saved / empty. Pure CSS defence; the JS textContent flip is unchanged.

**(4) Print button** — replaces the toolbar's `<a href="?tt_view=exports&exporter=match_prep_pdf&...">PDF (landscape A4)</a>` with a `<button type="button" data-tt-mp-print>Print (landscape A4)</button>` plus a one-line `window.print()` handler in `frontend-match-prep.js`. The `$pdf_url = add_query_arg([...])` block in `FrontendMatchPrepView::render()` is removed. The browser's "Save as PDF" within the print dialog handles file-output for free. The exports page's match-prep PDF exporter route stays available for direct visits to `?tt_view=exports`. Dutch string `Afdrukken (liggend A4)`.

## Files touched

- `assets/css/frontend-match-prep.css` — wrapper widening, grid column widths, save-state stability, print block.
- `assets/js/frontend-match-prep.js` — `data-tt-mp-print` click handler.
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` — PDF anchor → Print button; drop unused `$pdf_url`.
- `.local-mockups/match-preparation/index.html` — mirror the changes (mockup is design-of-record).
- `languages/talenttrack-nl_NL.po` — add `Print (landscape A4)` → `Afdrukken (liggend A4)`.
- `languages/talenttrack.pot` — add the same `msgid`.
- `docs/match-prep.md` + `docs/nl_NL/match-prep.md` — rewrite "Print to PDF" section to describe browser-print flow.
- `talenttrack.php` + `readme.txt` — version bump to 4.12.4, changelog stanza.

No schema, no REST, no behavioural change beyond the four items above.

---

# TalentTrack v4.12.3 — Vocabulary constants for goals + tasks (PR-set 2 of #988)

Second of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; this ship covers the goal-side workflow vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/GoalStatus.php` (new) — six lowercase snake_case constants for `tt_goals.status`: `PENDING`, `PENDING_APPROVAL`, `IN_PROGRESS`, `COMPLETED`, `ON_HOLD`, `CANCELLED`. Mirrors the PR-set 1 file shape (`const ALL` + static `isValid()`). The lowercase snake_case form is the canonical stored value per `LabelTranslator::goalStatus()` and the REST controller's defaults; the `goal_status` lookup row `name` column carries the TitleCase display label, but the table is the operator-facing surface and unaffected here.
- `src/Domain/Vocabularies/Lookups/GoalPriority.php` (new) — three lowercase constants for `tt_goals.priority`: `LOW`, `MEDIUM`, `HIGH`.
- `src/Domain/Vocabularies/Lookups/GoalApprovalDecision.php` (new) — three constants for the approval-form decisions stored in `tt_workflow_tasks.response_json`: `APPROVE`, `AMEND`, `REJECT`. Backs the `goal_approval_decision` lookup seeded by migration 0111.

**PHP - literal -> constant replacements**

- `src/Infrastructure/REST/GoalsRestController.php` — replaces the five raw `'pending_approval'` / `'pending'` literals (default status on create, force-approve gate for player-self-create, status update authorization check) and the `'medium'` priority default with the new `GoalStatus::*` / `GoalPriority::*` constants. REST endpoint payload-side behaviour is unchanged; the stored values are byte-identical to the previous release.
- `src/Modules/Goals/Admin/GoalsPage.php` — replaces the `'pending'` and `'medium'` form-default literals (status / priority dropdown `selected()` calls + the `handle_save` `$_POST` fallback) with the new constants.
- `src/Modules/Development/Notifications/GoalSpawner.php` — the idea-promotion goal materialisation hands `'pending'` / `'medium'` to `wpdb::insert(tt_goals)`; switched to the constants.
- `src/Modules/Workflow/Forms/GoalApprovalForm.php` — `DECISION_APPROVE` / `DECISION_AMEND` / `DECISION_REJECT` class constants now alias `GoalApprovalDecision::APPROVE` / `::AMEND` / `::REJECT` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller continues to compile and produce the same stored decision value. The aliases stay one release before the umbrella's PR-set 8 PHPStan rule lands.

**Out of scope for this PR-set**

- `TT\Modules\Workflow\TaskStatus` already follows the constants-shaped pattern from the original v3.x ship; it carries the canonical six values (`open`, `in_progress`, `completed`, `overdue`, `skipped`, `cancelled`) plus helpers `isActionable()` and `label()`. Consolidating it into `Vocabularies\Lookups\TaskStatus` is a mechanical lift but pulls in two more touch points (`TasksRepository`, `FrontendMyTasksView`, `FrontendTaskDetailView`); deferred to keep this PR-set focused on the *new* constants classes. The existing class continues to be the source of truth for the task-status vocabulary in the meantime.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 2 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a goal via the goals admin: defaults to `priority=medium`, `status=pending`. (Both stored as the lowercase form, unchanged from previous behaviour.)
- Player creates a goal via the player-self-create flow: stored with `status=pending_approval` regardless of payload override.
- Coach approves a pending-approval goal via the inline status dropdown: head-coach-only gate fires; status moves to `pending`.
- Coach uses the workflow goal-approval form: each `approve` / `amend` / `reject` decision serializes to the same byte value as before.
- Idea promoted to in-progress: spawns a `tt_goals` row with `status=pending`, `priority=medium`.

