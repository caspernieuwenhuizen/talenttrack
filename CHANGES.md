# TalentTrack v4.12.6 — Vocabulary constants for tournament + match + MatchExecutionState (PR-set 6 of #988)

Sixth of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) shipped in v4.12.3; this ship covers the tournament-side lookup vocabularies AND consolidates the match-execution state values into the first `Vocabularies\Enums\*` class per the umbrella's locked architectural split. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes (Lookups)**

- `src/Domain/Vocabularies/Lookups/TournamentFormation.php` (new) — eight constants for `tt_tournaments.default_formation` and `tt_tournament_matches.formation`. Backs the `tournament_formation` lookup seeded by migration 0098 in canonical hyphen-numeric form: `F_4_3_3` (`1-4-3-3`), `F_4_4_2` (`1-4-4-2`), `F_3_4_3` (`1-3-4-3`), `F_3_5_2` (`1-3-5-2`), `F_4_2_3_1` (`1-4-2-3-1`), `F_2_3_2` (`1-2-3-2`), `F_2_3_1` (`1-2-3-1`), `F_1_3_1` (`1-1-3-1`). Mirrors the PR-set 1 file shape (`const ALL` + static `isValid()`).
- `src/Domain/Vocabularies/Lookups/TournamentOpponentLevel.php` (new) — four constants for `tt_tournament_matches.opponent_level` in lowercase snake_case: `WEAKER`, `EQUAL`, `STRONGER`, `MUCH_STRONGER`. Each row's meta colour drives the visible pill on the match card.
- `src/Domain/Vocabularies/Lookups/CompetitionType.php` (new) — five constants for the `competition_type` lookup seeded by migration 0013 (`League`, `Cup`) and extended via `LookupCanonicalSeeds` (`Tournament`, `Friendly`, `Indoor`). Stored as TitleCase per the original `competition-type` lookup convention.

**PHP - new vocabulary class (Enums) — first of the code-only sub-namespace**

- `src/Domain/Vocabularies/Enums/MatchExecutionState.php` (new) — five constants for the live-match state machine stored in `tt_match_execution.state`: `NOT_STARTED`, `FIRST_HALF`, `HALF_TIME`, `SECOND_HALF`, `FINISHED`. Adds a `LIVE` subset constant (`FIRST_HALF`, `HALF_TIME`, `SECOND_HALF`) and an `isLive()` helper for the coach-hero "Resume match" lookup. This is the first class in `Vocabularies\Enums\*` (code-only; not operator-editable via the lookups admin) per the umbrella's locked sub-namespace split.

**PHP - MatchExecutionState consolidation per #988's locked decisions**

- `src/Modules/MatchExecution/Repositories/MatchExecutionRepository.php` — introduces five deprecated `STATE_*` constants (`STATE_NOT_STARTED`, `STATE_FIRST_HALF`, `STATE_HALF_TIME`, `STATE_SECOND_HALF`, `STATE_FINISHED`) that alias `MatchExecutionState::*`. Per the umbrella's locked plan, the aliases stay one release as a backward-compatibility shim and are removed in the next minor. Existing internal callers that reference the literal continue to work; new code should reference `MatchExecutionState::*` directly. Also replaces the `'not_started'` literal in `ensureForActivity()` with the constant.
- `src/Modules/MatchExecution/Rest/MatchExecutionRestController.php` — replaces the four `'first_half'` / `'second_half'` / `'half_time'` / `'finished'` literals on the half-lifecycle transitions (`start_half`, `end_half`, `finish`) with the new `MatchExecutionState::*` constants. REST endpoint payload values remain byte-identical.
- `src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php` — replaces the `'not_started'` literal in the fallback-state expression with `MatchExecutionState::NOT_STARTED`.
- `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php` — replaces the three `'first_half'` / `'second_half'` / `'half_time'` literals in `liveMinuteLabel()` with `MatchExecutionState::*` constants.

**Out of scope for this PR-set**

- The two SQL string literal call sites in `MatchExecutionRepository` (`AND e.state IN ('first_half','half_time','second_half')` and `AND ( e.state IS NULL OR e.state = 'not_started' )`) stay as literals per the umbrella's locked plan — DB is the source of truth.
- The tournament lookups are pure data-driven (loaded via `QueryHelpers::get_lookup_names()` for the wizard + match-add form); the new constants classes document the canonical English seeded vocabulary for code-side comparisons without altering the operator-editable surface.
- `tt_lookups` seed values, migrations, .po / .pot files, test fixtures, and JavaScript stay as literals per the spec.

## Why patch

PR-set 6 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The deprecated `STATE_*` aliases on `MatchExecutionRepository` keep any internal callers green while the next minor performs the removal. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach starts a half (1 or 2) from the live-match surface: execution row stores `state = 'first_half'` or `'second_half'`; REST response carries the same value as before.
- Coach ends a half: state moves to `'half_time'` (half 1) or `'finished'` (half 2); same byte values as previous release.
- Coach finishes the match via the explicit Finish endpoint: state moves to `'finished'`; activity row flips to `activity_status_key = 'completed'`.
- Coach-hero "Resume match" CTA on the dashboard: `findLiveForTeams()` query returns the same rows as before (`state IN ('first_half','half_time','second_half')` literal SQL stays unchanged).
- Coach-hero "Start match" CTA: `findStartableForTeams()` query returns the same rows as before (`state IS NULL OR state = 'not_started'` literal SQL stays unchanged).
- New live-match row insert via `ensureForActivity()`: stores `state = 'not_started'`.
- Live-minute label on the coach hero: `'1e 23\''` / `'HT'` / `'2e 67\''` render exactly as before.
- Pre-existing callers (if any) using the new deprecated `MatchExecutionRepository::STATE_*` aliases compile and produce the same stored value as the literal they replaced.

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

