<!-- type: feat -->

# #0035 — Rename sessions → activities, add typed activities (game / training / other)

## Problem

The "Session" concept was scoped before the product had a clear vision of what sessions actually are. In practice, coaches book three different things on the same calendar: **games** (which split further into **friendly / cup / league**), **training**, and **other** (team-building day, club meeting, anything that doesn't fit). Today they all live as undifferentiated `tt_sessions` rows with a free-text title; the workflow engine has to guess "is this a match?" from the title to decide whether to spawn a post-match evaluation.

The fix is two changes bundled in one rename:

1. **Vocabulary** — drop "session" everywhere, use "activity". Sessions sounded like "training session"; activities is the umbrella term that fits all three. Casper's call: "no legacy left behind" — every storage layer, module folder, capability, slug, hook, REST endpoint, frontend view class, demo-data generator, doc file, and translatable string flips. The product is young enough that a clean break is cheaper than the long-term confusion of two vocabularies.

2. **Typing** — `tt_activities` gains an `activity_type_key` (lookup-backed: `game` / `training` / `other`). When `game`, an optional `game_subtype_key` (`friendly` / `cup` / `league`) further narrows. When `other`, a free-text `other_label` carries the user's description. This unlocks downstream value: the workflow's `PostGameEvaluationTemplate` auto-fires on `activity_completed` filtered by `type = 'game'` (no more guess-from-title); the HoD review form splits its 90-day rollup into "X games / Y trainings / Z other" instead of one undifferentiated "sessions" count; #0026's guest attendance + the methodology principle links carry the type through.

## Proposal

A single PR that rebases the entire codebase from "sessions" to "activities" + adds the type / subtype columns. The change is mechanically large (≈40 files touched, ≈210 grep hits) but conceptually simple — it's a find/replace + a migration + four small new form fields.

The pattern from v3.22.0 (single-PR epics, scope-locked via inline shaping) holds: one migration, one module move, one find/replace sweep, one .po edit, one docs rewrite. CI gates catch regressions.

## Scope

### Migration 0026 — `0026_rename_sessions_to_activities`

A single migration that performs every storage-layer change atomically:

1. **Table rename**: `ALTER TABLE tt_sessions RENAME TO tt_activities`.
2. **Column rename on attendance**: `ALTER TABLE tt_attendance CHANGE session_id activity_id BIGINT UNSIGNED NOT NULL`.
3. **New columns on `tt_activities`**: `activity_type_key VARCHAR(50) NOT NULL DEFAULT 'training'`, `game_subtype_key VARCHAR(50) NULL`, `other_label VARCHAR(120) NULL`. Index `idx_activity_type (activity_type_key)`.
4. **Backfill**: every existing row gets `activity_type_key = 'training'` (already the default; the explicit UPDATE is a safety net for hosts where `DEFAULT 'training'` doesn't apply retroactively).
5. **Lookup `activity_type` seeded**: rows for `game`, `training`, `other` (only if absent — admins can extend later via the existing Lookups surface).
6. **Lookup rename**: `UPDATE tt_lookups SET lookup_type = 'game_subtype' WHERE lookup_type = 'competition_type'`. Then insert `Friendly` row if absent.
7. **`tt_evaluations.match_result` column rename**: `ALTER TABLE tt_evaluations CHANGE match_result game_result VARCHAR(50) DEFAULT NULL`.
8. **Capability rename**: grant `tt_view_activities` + `tt_edit_activities` to every role currently holding `tt_view_sessions` / `tt_edit_sessions`; revoke the old caps.
9. **Workflow row rewrite**: `UPDATE tt_workflow_triggers SET template_key = 'post_game_evaluation' WHERE template_key = 'post_match_evaluation'`. Same for `tt_workflow_template_config`. Same for any pending `tt_workflow_tasks` rows.
10. **Workflow event hook rename in seed**: any seeded triggers using `event_hook = 'tt_session_completed'` flip to `tt_activity_completed`.
11. **Admin notice**: a one-time transient flagging "X activities migrated from sessions; reclassify any historical games via the Activities list" — surfaces on the next admin load, dismissible.

### Module rename `Sessions/` → `Activities/`

PSR-4 directory rename. Affects:

- `src/Modules/Sessions/SessionsModule.php` → `src/Modules/Activities/ActivitiesModule.php`
- `src/Modules/Sessions/Admin/SessionsPage.php` → `src/Modules/Activities/Admin/ActivitiesPage.php`
- Class names + namespace: `TT\Modules\Sessions` → `TT\Modules\Activities`. `SessionsModule` → `ActivitiesModule`.
- `config/modules.php` updated.

### Frontend view classes rename

- `src/Shared/Frontend/FrontendMySessionsView.php` → `FrontendMyActivitiesView.php` + class name.
- `src/Shared/Frontend/FrontendSessionsManageView.php` → `FrontendActivitiesManageView.php` + class name.
- `DashboardShortcode::dispatchMeView( 'my-sessions' )` → `'my-activities'`. `dispatchCoachingView( 'sessions' )` → `'activities'`. The `$me_slugs` and `$coaching_slugs` arrays update.
- `FrontendTileGrid` — Me-group "My sessions" tile becomes "My activities" with the same emoji slot; coach group "Sessions" becomes "Activities". Tile slug strings match the new dispatch.

### Activity edit form fields

Three new form fields on the activity edit screen (rendered on both `?action=edit` in wp-admin `ActivitiesPage` and the frontend `FrontendActivitiesManageView`):

1. **Type** (required, dropdown) — populated from `tt_lookups.activity_type`. Default `training`.
2. **Game subtype** (conditional, dropdown) — only shown when `Type = game`. Populated from `tt_lookups.game_subtype` (friendly / cup / league + admin-extended rows). Optional.
3. **Other label** (conditional, free-text) — only shown when `Type = other`. Required when shown. Stored on `tt_activities.other_label`.

Display layer (list view, my-activities, attendance roster, workflow forms): falls back to `LabelTranslator::activityType( $row )` which:
- returns `__( 'Game', 'talenttrack' )` + " (" + subtype label + ")" when type=game and subtype set.
- returns `__( 'Game', 'talenttrack' )` when type=game and subtype null.
- returns `__( 'Training', 'talenttrack' )` when type=training.
- returns the row's `other_label` literal when type=other.
- returns the lookup row's `name` for any admin-added type beyond the three core values.

### Workflow

- `src/Modules/Workflow/Templates/PostMatchEvaluationTemplate.php` → `PostGameEvaluationTemplate.php`. Class + label + key flip. Template key `post_match_evaluation` → `post_game_evaluation`.
- `src/Modules/Workflow/Forms/PostMatchEvaluationForm.php` → `PostGameEvaluationForm.php`.
- `EventDispatcher` subscribes to `tt_activity_completed` (was `tt_session_completed`). The dispatcher filters by `activity_type_key = 'game'` before fanning out — friendly + cup + league all spawn the eval; clubs that only want league evals disable + reconfigure via #0022's admin UI.
- `WorkflowModule::registerShippedTemplates()` registers the renamed class.
- `QuarterlyHoDReviewForm::aggregate()` splits its 90-day session count into `games_count` / `trainings_count` / `other_count`. Renders three lines instead of one.

### REST endpoints

- `/talenttrack/v1/sessions` → `/talenttrack/v1/activities` (and `{id}` sub-resources).
- `/sessions/{id}/guests` → `/activities/{id}/guests` (#0026 guest-attendance).
- `/attendance/{id}` is unchanged at the URL level (entity name is "attendance"), but its body shape stays consistent.
- `assets/js/components/guest-add.js` updates the path it POSTs to.
- `src/Infrastructure/REST/SessionsRestController.php` → `ActivitiesRestController.php`, namespace + class flip.

### Capability sweep

Every `current_user_can( 'tt_view_sessions' )` and `current_user_can( 'tt_edit_sessions' )` call site updates. Migration 0026 grants the new caps + revokes the old in one pass.

### Hook prefix sweep

`do_action( 'tt_session_completed', ... )` → `'tt_activity_completed'`. Any third-party listener (none today) is responsible for switching.

### `tt_save_session` admin-post action → `tt_save_activity`

The `admin_post_tt_save_session` handler renames. The `<form action>` in the activity edit form points at the new action.

### Other touch-points

- `src/Modules/Backup/BackupDependencyMap.php` — `tt_sessions` references → `tt_activities`. `tt_attendance.session_id` references → `activity_id`. The PresetRegistry's "Sessions" preset stays labeled but operates on the renamed table.
- `src/Modules/DemoData/Generators/SessionGenerator.php` → `ActivityGenerator.php`. Generates ~70% training / ~30% game (subtype distributed across friendly / cup / league). Demo dataset seeds `activity_type_key`.
- `src/Modules/DemoData/DemoDataCleaner.php` — table list updates.
- `src/Infrastructure/Archive/ArchiveRepository.php` — `tt_sessions` archive-target updates.
- `src/Modules/Methodology/Repositories/PrincipleLinksRepository.php` — column reference + relationship table name renames.
- `src/Modules/Stats/Admin/UsageStatsPage.php` + `UsageStatsDetailsPage.php` — query + label updates.
- `src/Shared/Admin/Menu.php` — wp-admin "Sessions" menu item label + slug `tt-sessions` → `tt-activities`.
- `src/Shared/Admin/BackNavigator.php` + `BulkActionsHelper.php` — bulk-action defs + back-link targets update.
- `src/Modules/Configuration/Admin/FormSlugContract.php` — slug allow-list updates.
- `src/Shared/Frontend/CoachDashboardView.php` + `CoachForms.php` + `PlayerDashboardView.php` + `FrontendMyEvaluationsView.php` — dashboard card refs + URLs.

### Ship-along

- `docs/sessions.md` → `docs/activities.md`. Same for nl_NL. Cross-refs in `docs/workflow-engine.md`, `docs/methodology.md`, `docs/rest-api.md`, `docs/people-staff.md` updated.
- `HelpTopics::all()` — `sessions` topic key renames to `activities`.
- `languages/talenttrack-nl_NL.po` — every `Session(s)` / `Sessie(s)` string flips to `Activity / Activities` / `Activiteit / Activiteiten`. Plus new strings for the three type labels + game-subtype labels + form chrome (~25 new + ~40 existing flipped).

## Out of scope (v1)

- **Calendar view of activities** — list view only; calendar grid lands later (a separate feature, not blocked by this rename).
- **Per-team activity-type defaults** (e.g. "U17 Selection always defaults to game-cup") — admin can set the type per-row; defaults are deferred.
- **Activity-type-driven evaluation forms** (e.g. game-only fields like minutes-played gated by activity type) — out of scope; current evaluation form keeps all fields visible regardless.
- **Public-facing fixtures list** — internal product only; no public widget.
- **Notifications when an activity flips type retroactively** — silent change.
- **Migration rollback** — single forward direction. The down() method exists in the migration shell but ships unimplemented; rollback is via backup restore.

## Acceptance criteria

- [ ] Migration `0026_rename_sessions_to_activities` runs cleanly on a fresh install AND on an upgraded install with existing `tt_sessions` rows. Existing rows survive with `activity_type_key = 'training'`. The admin notice appears once and is dismissible.
- [ ] `tt_sessions` and `tt_attendance.session_id` no longer exist after the migration; `tt_activities` and `tt_attendance.activity_id` do.
- [ ] `tt_evaluations.match_result` no longer exists; `tt_evaluations.game_result` does, populated from the old column.
- [ ] `tt_lookups` has `lookup_type = 'activity_type'` rows (game / training / other) and `lookup_type = 'game_subtype'` rows (friendly / cup / league). No rows remain with `lookup_type = 'competition_type'`.
- [ ] Capabilities `tt_view_activities` + `tt_edit_activities` exist; old `tt_view_sessions` / `tt_edit_sessions` no longer exist on any role.
- [ ] `?tt_view=sessions` and `?tt_view=my-sessions` no longer dispatch (they hit the "Unknown section" notice). `?tt_view=activities` + `?tt_view=my-activities` work.
- [ ] wp-admin menu shows "Activities" (not "Sessions"). The slug is `tt-activities`. Old `?page=tt-sessions` URL returns the "Page not found" wp-admin error.
- [ ] Activity edit form shows the Type dropdown (default Training). Selecting Game reveals the Subtype dropdown; selecting Other reveals the free-text label field with required validation.
- [ ] Saving a game-type activity + completing it auto-spawns a `post_game_evaluation` task per active player on the team (the workflow's automatic dispatch path now works because the type is known).
- [ ] HoD quarterly review form shows three counts: "Games last 90 days: X", "Trainings: Y", "Other: Z" — replacing the old "Sessions: N".
- [ ] REST endpoints: `GET /talenttrack/v1/activities/123` works; `/sessions/123` returns 404. `POST /activities/{id}/guests` works (#0026 guest flow); `assets/js/components/guest-add.js` posts to the new path.
- [ ] No occurrence of `tt_sessions`, `session_id`, `FrontendMySessionsView`, `FrontendSessionsManageView`, `tt_view_sessions`, `tt_edit_sessions`, `tt-sessions`, `tt_session_completed`, `post_match_evaluation`, `PostMatchEvaluationTemplate`, `tt_save_session`, `SessionsRestController`, `SessionsModule`, `SessionsPage`, `SessionGenerator`, `match_result`, `competition_type` anywhere in `src/`, `database/`, `docs/`, `assets/js/`, `languages/`. CI verifies via a grep gate that fails the build if any of these strings reappear.
- [ ] `docs/activities.md` exists with audience marker; `docs/sessions.md` does not. Same for nl_NL.
- [ ] PHPStan level 8 passes. PHP syntax lint passes. .po validation passes.

## Notes

### Decisions locked during shaping

19 in total (8 from the original revisit + 11 silent decisions surfaced under the no-legacy framing):

1. DB table rename `tt_sessions` → `tt_activities` (was: keep, alias).
2. `tt_attendance.session_id` → `tt_attendance.activity_id` column rename.
3. Module folder rename `src/Modules/Sessions/` → `Activities/` + namespace flip.
4. URL slug rename without alias — `?tt_view=sessions` no longer works.
5. `activity_type` lookup with three seeded rows (game / training / other), admin-extendable.
6. `competition_type` lookup renamed to `game_subtype` + `Friendly` row added; one canonical list shared with the evaluation form.
7. `other_label` column for the free-text variant when type=other; hard-coded special case in the display + form layers.
8. Backfill default for existing rows: `training`. One-time admin notice flags the migration.
9. Workflow template key `post_match_evaluation` → `post_game_evaluation`. Migration rewrites all `tt_workflow_*` rows holding the old key.
10. Hook prefix `tt_session_completed` → `tt_activity_completed`.
11. Capability rename `tt_view_sessions` → `tt_view_activities`, `tt_edit_sessions` → `tt_edit_activities`.
12. REST endpoints `/sessions/` → `/activities/` (and `/sessions/{id}/guests` → `/activities/{id}/guests`).
13. Frontend view class rename: `FrontendMySessionsView` → `FrontendMyActivitiesView`, `FrontendSessionsManageView` → `FrontendActivitiesManageView`.
14. `tt_evaluations.match_result` column rename to `game_result`.
15. `Sessions/Forms/PostMatchEvaluationForm` → `PostGameEvaluationForm`.
16. Demo data: `SessionGenerator` → `ActivityGenerator`, generates ~70% training / ~30% game.
17. Stats split — `QuarterlyHoDReviewForm` shows games / trainings / other separately.
18. wp-admin menu position unchanged; label and slug only change.
19. CI grep gate — any reappearance of the legacy strings (`tt_session`, `session_id`, etc.) fails the build.

### Cross-epic interactions

- **#0022** Workflow & tasks engine — the rename touches the post-match (now post-game) template, the `tt_activity_completed` event hook, the HoD review form's stats split, and three workflow form classes that reference activity type.
- **#0026** Guest-player attendance — REST endpoint `/activities/{id}/guests` + JS update.
- **#0029** Documentation split — `docs/activities.md` ships with `<!-- audience: admin -->` marker; CI gate enforces.
- **#0019** Frontend admin migration — the wp-admin Sessions menu entry was a #0019 surface; renames cleanly.
- **#0034** Custom icon system — the existing `sessions` icon name flips to `activities` (single SVG file rename + a `IconRenderer` registry entry update).

### Sequence position

Phase 1 cleanup. Lands as a single PR. Highest-priority refactor on the post-v3.22.0 backlog because every subsequent feature that touches sessions (#0006 team planning, #0014 player profile rebuild, #0017 trial player module, #0018 team development) inherits the new vocabulary and avoids legacy churn later.

### Estimated effort

**v1 (this spec): ~18-22h actual** in a single PR per the v3.22.0 compression pattern.

| Step | Hours |
| - | - |
| Migration 0026 (table + column + lookup + caps + workflow rows + backfill + admin notice) | 3 |
| Module folder + namespace rename (~25 files) | 2 |
| Capability + hook + REST + frontend-class + admin-action sweep (~40 files) | 2.5 |
| Activity edit form (Type / Subtype / Other-text fields, conditional rendering) | 1.5 |
| List-view filter chips by type | 1 |
| Workflow template + form rename + EventDispatcher hook | 1 |
| Stats split — `QuarterlyHoDReviewForm` games/trainings/other | 1.5 |
| Demo-data generator rename + type assignment | 1 |
| Translations (.po) — ~25 new + ~40 flipped | 3 |
| Docs (en + nl + cross-refs in workflow-engine / methodology / rest-api / people-staff) | 1.5 |
| CI grep gate addition | 0.5 |
| Lint + test pass + manual smoke test | 1.5 |

**v1.5 (deferred):**
- Calendar view of activities (separate idea, ~12h).
- Per-team default type (~3h).
- Activity-type-driven evaluation form fields (~5h).
