# TalentTrack v4.12.8 — Vocabulary constants for player + team (PR-set 4 of #988)

Fourth of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; PR-set 5 (reports + journey + scouting) in v4.12.5; PR-set 6 (tournament + match) in v4.12.6; PR-set 3 (PDP + trial) in v4.12.7; this ship — landing as v4.12.8 — covers the player-side roster vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PlayerStatus.php` (new) — five constants for the lifecycle values stored in `tt_players.status`: `ACTIVE`, `TRIAL`, `INACTIVE`, `RELEASED`, `GRADUATED`. Mirrors the PR-set 1 / 2 / 5 file shape (`const ALL` + static `isValid()`). The five values are the canonical set per `JourneyEventSubscriber::emitStatusTransition()`, `LabelTranslator::playerStatus()`, the `PlayersPage` status dropdown, and the trials / workflow forms that write the column. Lifecycle vs archive: the `archived_at` column from migration 0010 is the soft-delete / bulk-archive marker (NULL vs timestamp); `status` is the orthogonal lifecycle marker, so archived players still carry one of the five values. Migration 0061 already back-filled legacy `status='deleted'` rows from v3.89.1-and-earlier delete paths back to `'active'` (with `archived_at` populated), so the five-value vocabulary is the only stored set on every install. `GRADUATED` is intentionally part of `ALL` even though `PlayersPage`'s status dropdown currently exposes only four of the five values — the `JourneyEventSubscriber` already emits a `graduated` journey event when the column flips to that value, so the vocabulary documents the canonical five-state set; surfacing the fifth dropdown option is a separate UX task.
- `src/Domain/Vocabularies/Lookups/PreferredFoot.php` (new) — three lowercase constants for `tt_players.preferred_foot`: `LEFT`, `RIGHT`, `BOTH`. Backs the `foot_option` lookup (operator-editable, seeded by migration 0001 with TitleCase display labels), but the stored player-record value is the lowercase key per `RosterDetailsStep::validate()`'s `sanitize_key()` + allowlist. The empty-string sentinel ("not specified") is intentionally not part of `ALL` — it represents the absence of one of the three options. Chemistry / compatibility engines that compare against `'left'` / `'right'` slot sides are NOT consumers of this vocabulary — those are `position_side_preference` / `slot_side` comparisons (a different left / right / center vocabulary) and stay out of scope for this PR-set.

**PHP - literal -> constant replacements**

- `src/Modules/Players/Admin/PlayersPage.php` — replaces the four literals in the `$status_options` map (`'active'` / `'inactive'` / `'trial'` / `'released'`), the `selected( $player->status ?? 'active', ... )` default, the `handle_save` `$_POST` fallback, and the `stub` row creation with `PlayerStatus::ACTIVE / INACTIVE / TRIAL / RELEASED` constants. SQL string literal `WHERE pl.status='active'` in `render_list()` is kept as a literal per the spec (DB is the source of truth).
- `src/Modules/Players/PlayerCsvImporter.php` — `status` default on row sanitisation: `'active'` → `PlayerStatus::ACTIVE`.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — trial-player gate on the trials tab empty state: `(string) $player->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Shared/Frontend/FrontendTrialsManageView.php` — inline player-create on the trial-case create form + the status flip on the existing player: both `'trial'` literals → `PlayerStatus::TRIAL`.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the three-arm `emitStatusTransition()` match — status comparisons swap to `PlayerStatus::*` constants. Pairs cleanly with PR-set 5's `JourneyEventType::*` swap on the `EventEmitter::emit()` emit-arg side: this PR-set replaces the `$new === 'released'` LHS comparisons; PR-set 5 already replaced the `'released'` second-positional emit arg with `JourneyEventType::RELEASED`. Result is a fully-typed branch with no raw literals on either side of the assignment.
- `src/Infrastructure/Query/LabelTranslator.php` — `playerStatus()` switch cases swap to `PlayerStatus::*` constants. Adds a `case PlayerStatus::GRADUATED` arm for symmetry (missing previously). The legacy `case 'deleted'` arm is preserved as a literal — it's a historical-display safety net for migration-0061-pre installs that may still surface a value not in the canonical five-state set.
- `src/Modules/Tournaments/Wizard/SquadStep.php` — trial-badge gate on the squad picker: `$pl->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Modules/Wizards/Player/ReviewStep.php` — status assignment on wizard submit: `$path === 'trial' ? 'trial' : 'active'` → `? PlayerStatus::TRIAL : PlayerStatus::ACTIVE`.
- `src/Modules/Wizards/Player/RosterDetailsStep.php` — preferred-foot allowlist in `validate()`: `[ '', 'left', 'right', 'both' ]` → `[ '', PreferredFoot::LEFT, PreferredFoot::RIGHT, PreferredFoot::BOTH ]`.
- `src/Modules/Workflow/Forms/RecordTestTrainingOutcomeForm.php` — the new-player insert on prospect-admission: `'status' => 'trial'` → `PlayerStatus::TRIAL`.
- `src/Modules/Workflow/Forms/AwaitTeamOfferDecisionForm.php` — the accepted-offer update: `[ 'status' => 'active' ]` → `[ 'status' => PlayerStatus::ACTIVE ]`.
- `src/Modules/DemoData/Generators/PlayerGenerator.php` — the seeded player insert + the `tt_player_created` hook payload: both `'status' => 'active'` → `PlayerStatus::ACTIVE`.

**Out of scope for this PR-set**

- `PlayerValue` / `AgeGroup` / `Position` — empirical grep on the codebase surfaced zero PHP-side string-literal comparisons against the eight player-value keys (the 0031 PDP-cycle seed), the U7-U23 / Senior age-group codes (the 0001 + 0051 seeds), or the 11 position abbreviations (the 0001 seed). The values live in `tt_lookups` and are read-only on the operator-facing surface; a constants class would document them without making any literal-to-constant swap. Deferred to a future PR-set if call sites surface — the issue's "every value" rule is satisfied at the call-site replacement layer, not by ahead-of-need declaration.
- `TeamLevel` / `AgeGroupCode` — `tt_teams` has no level / tier column (squad tier sits on `tt_team_blueprint_assignments.tier` per migration 0072, scoped for PR-set 7's `BlueprintTier` enum); the `age_group` column on `tt_teams` is VARCHAR but no equality comparisons surfaced in code.
- `PlayerOnePagerPdfExporter::statusLabel()` — has a defensive 6-value map (`active` / `archived` / `trial` / `released` / `contracted` / `inactive`) for display fallback against historical / drifted values; left as literals because the map intentionally accepts values outside the canonical five-state set and acts as a defensive translation surface, not a vocabulary contract.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 4 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a new player via the admin form: stored with `status=active`. Status dropdown lists Active / Inactive / Trial / Released — unchanged from previous behaviour.
- Coach edits an existing trial player to `status=active` (signing flow): `JourneyEventSubscriber::emitStatusTransition()` writes a `signed` journey event via `EventEmitter::emit()` exactly as before.
- Coach edits a player to `status=released` or `status=graduated`: corresponding journey events fire.
- Player-create wizard, roster path: `status=active`. Trial path: `status=trial`. Preferred-foot dropdown accepts `left` / `right` / `both` and persists the lowercase key.
- CSV bulk import without a `status` column: defaults to `active`.
- Frontend trial-case create with inline new-player: new `tt_players` row carries `status=trial`; the trial case ties to it. Existing-player promotion flips the row to `trial`.
- Tournament wizard squad step: trial players surface with the Trial badge, unchecked by default.
- Workflow form "Record test-training outcome" (prospect admitted): new `tt_players` row carries `status=trial`.
- Workflow form "Await team offer decision" (accepted): existing player row flips to `status=active`.
- Demo-data seed run: every generated player carries `status=active` and the `tt_player_created` hook payload reflects the same.
- LabelTranslator round-trip: `playerStatus('graduated')` returns "Graduated" (previously fell through to `humanise()`); other arms unchanged.

---

# TalentTrack v4.12.7 — Vocabulary constants for PDP + trial (PR-set 3 of #988)

Third of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) shipped in v4.12.3; this ship covers the PDP-cycle and trial-case vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PdpStatus.php` (new) — three lowercase constants for `tt_pdp_files.status`: `OPEN`, `COMPLETED`, `ARCHIVED`. Mirrors the PR-set 1 / 2 file shape (`const ALL` + static `isValid()`). The column is VARCHAR(20) with `DEFAULT 'open'` per migration 0031; `PdpFilesRepository::setStatus()` is the gate that rejects any value outside the three.
- `src/Domain/Vocabularies/Lookups/PdpVerdictDecision.php` (new) — four constants for `tt_pdp_verdicts.decision`: `PROMOTE`, `RETAIN`, `RELEASE`, `TRANSFER`. Backs the `pdp_verdict_decision` lookup seeded by migration 0112 with per-locale translations through `tt_translations`. `PdpVerdictsRepository::upsertForFile()` is the gate.
- `src/Domain/Vocabularies/Lookups/TrialCaseStatus.php` (new) — four constants for `tt_trial_cases.status`: `OPEN`, `EXTENDED`, `DECIDED`, `ARCHIVED`. Backs the `trial_case_status` lookup seeded by migration 0116.
- `src/Domain/Vocabularies/Lookups/TrialCaseDecision.php` (new) — six constants for `tt_trial_cases.decision`: `ADMIT`, `DENY_FINAL`, `DENY_ENCOURAGEMENT`, `OFFERED_TEAM_POSITION`, `DECLINED_OFFERED_POSITION`, `CONTINUE_IN_TRIAL_GROUP`. Backs the `trial_case_decision` lookup seeded by migration 0116. The three rolling-membership decisions (#0081 child 4) sit alongside the classic admit / decline triad — single vocabulary, one canonical list.

**PHP - literal -> constant replacements**

- `src/Modules/Pdp/Repositories/PdpFilesRepository.php` — insert default for new files moves from `'open'` to `PdpStatus::OPEN`; the `setStatus()` allowlist `in_array( $status, [ 'open', 'completed', 'archived' ], true )` becomes `PdpStatus::isValid( $status )`.
- `src/Modules/Pdp/Repositories/PdpVerdictsRepository.php` — drops the private `ALLOWED_DECISIONS` literal array; the `upsertForFile()` gate switches to `PdpVerdictDecision::isValid()`. The `label()` switch cases reference `PdpVerdictDecision::*` constants.
- `src/Modules/Pdp/Rest/PdpVerdictsRestController.php` — drops the private `ALLOWED_DECISIONS` literal array; the PUT-handler validation switches to `PdpVerdictDecision::isValid()`; the error payload's `allowed` key uses `PdpVerdictDecision::ALL`.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — the list-filter `$status_options` keys, the verdict-form `$decisions` keys, and the private `statusLabel()` switch cases all reference the new constants.
- `src/Modules/Pdp/Frontend/FrontendMyPdpView.php` — the read-only verdict `decisionLabel()` switch cases reference `PdpVerdictDecision::*`.
- `src/Modules/Trials/Repositories/TrialCasesRepository.php` — the `STATUS_*` and `DECISION_*` class constants now alias `TrialCaseStatus::*` and `TrialCaseDecision::*` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller compiles and produces the same stored value. The `recordDecision()` allowlist switches from the self-constant triad to the `TrialCaseDecision::ADMIT|DENY_FINAL|DENY_ENCOURAGEMENT` triad; the status / decision label switches reference the new constants directly.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the post-trial-decision branches (signed / released journey events) switch from `'admit'` / `'deny_final'` literals to `TrialCaseDecision::ADMIT` / `TrialCaseDecision::DENY_FINAL`.
- `src/Modules/Trials/TrialGroupTeam.php` — the two `wpdb->prepare()` bindings for the trial-group active-member queries switch from the `'continue_in_trial_group'` literal to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/PersonaDashboard/Kpis/TrialGroupActiveCount.php` — the KPI's active-trial-group-member query binding switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/Workflow/Templates/ReviewTrialGroupMembershipTemplate.php` — the chain-step gate for the `continue_in_trial_group` branch switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.

**Out of scope for this PR-set**

- SQL string literals (`status IN ('open','extended')` in `TrialCasesRepository::findOpenForPlayer` and `listEndingBetween`, `status NOT IN ('completed','archived')` in `SeasonCarryover::copyOpenGoals`) stay as literals — DB is the source of truth, not the PHP layer.
- Form-internal radio-button values in `ReviewTrialGroupMembershipForm` (`offer_team_position`, `decline_final`) stay as form-input literals — they're transient HTML radio values mapped to canonical `TrialCaseDecision::*` values inside `serializeResponse()`, not themselves stored. Replacing them would conflate two vocabularies.
- The local `pdpFileStatusLabel()` switch in `PdpPrintRouter` translates an `'open'`/`'closed'` enum that is separate from the `tt_pdp_files.status` vocabulary — kept local per the existing comment.
- `LookupCanonicalSeeds.php` has stale / drift-prone entries for `pdp_verdict_decision` and `trial_case_status` ("On track / Behind / Ahead / At risk / Released" and "Open / In progress / Decision pending / Accepted / Rejected") that don't match the canonical pools. That's a #987 cleanup item, out of scope for #988.
- Migrations, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 3 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach opens the PDP manage list at `?tt_view=pdp`: the status filter dropdown still shows Open / Completed / Archived; selecting one filters the file list as before.
- Coach opens a PDP file: the verdict-form dropdown still offers the four `promote` / `retain` / `release` / `transfer` decisions with the academy-progression labels; submitting still upserts the verdict.
- Coach records a trial decision via `TrialCasesRepository::recordDecision()` with `admit` / `deny_final` / `deny_encouragement`: stored as before; the journey subscriber emits the signed / released events on `admit` / `deny_final`.
- HoD landing's "Players in trial group" KPI counts trial cases with `decision = 'continue_in_trial_group'` (byte-identical to prior).
- ReviewTrialGroupMembershipTemplate chain-step gates the re-spawn on `decision === 'continue_in_trial_group'` (byte-identical to prior).
- Player / parent opens the read-only PDP at `?tt_view=my-pdp`: the verdict-decision label resolves through `PdpVerdictDecision::*` or the operator-edited `tt_translations` value, identical to prior behaviour.

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

