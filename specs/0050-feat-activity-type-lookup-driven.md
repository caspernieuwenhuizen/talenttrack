<!-- type: feat -->

# #0050 — Activity Type dropdown is lookup-driven, with per-type workflow policy and per-type HoD rollup

## Problem

The activity Type dropdown (Training / Game / Other) is hardcoded in the form, validated in the REST controller, and stored as `tt_activities.activity_type_key`. Admins can rename labels via `__()` translations but can't add a fourth type without code changes — Game subtype and Attendance status, both lookup-driven, set the contrasting expectation.

The reason it wasn't lookup-driven from the start: two pieces of behaviour switch on the key:

- **Post-game evaluation workflow (#0022)** spawns a coach-evaluation task per active player when an activity is saved with `Type = Game`. Trainings and other types don't.
- **HoD quarterly rollup** splits 90-day activity volume into Games / Trainings / Other.

This spec makes Type lookup-driven AND lifts both behaviours into the lookup row itself, so admin-added types are first-class.

## Proposal

A single PR that lands:

1. An `activity_type` lookup with three locked seed rows.
2. Per-type workflow-template config — admins pick (in the lookup row's edit form) which workflow template fires when an activity of that type is saved. The `game` seed row defaults to `post_game_evaluation`; training and other are blank.
3. Per-type column expansion of the HoD quarterly rollup, with horizontal scroll on narrow viewports.
4. Both Activity forms (wp-admin + frontend) read options from the lookup.
5. Strict REST validation — unknown type values return 400.

The `activity_type_key` storage column stays; it now holds the lowercased lookup `name`. Existing rows already store one of `game / training / other` so no data migration is needed beyond seeding the lookup itself.

## Scope

### 1. Migration `0033_activity_type_lookup.php`

Inserts three rows into `tt_lookups`:

| name      | description           | meta_json                                                            | sort_order |
| --------- | --------------------- | -------------------------------------------------------------------- | ---------- |
| training  | Default activity type | `{"is_locked":1}`                                                    | 10         |
| game      | A match               | `{"is_locked":1,"workflow_template_slug":"post_game_evaluation"}`    | 20         |
| other     | Anything else         | `{"is_locked":1}`                                                    | 30         |

Idempotent — re-running the migration leaves existing rows alone (matches on `lookup_type='activity_type' AND name=?`). Adds rows that are missing.

No data migration on `tt_activities.activity_type_key` because existing values already equal the seeded names.

### 2. Lookup edit form — workflow template field + locked-delete

`ConfigurationPage::tab_lookup` (and the `tt_lookups` admin form generally) gains, when `lookup_type === 'activity_type'`:

- A new `<select>` named **Workflow template on save** with options sourced from `\TT\Modules\Workflow\TemplateRegistry::all()` keyed by their `KEY` constant. Empty option = "no workflow on save". Stored under `meta_json.workflow_template_slug`.
- A `meta_json.is_locked` flag, read but not user-editable. The Delete button is hidden on rows where the flag is set; direct-URL deletion is rejected at the controller level with a 403 + a flash notice.

Other lookup types are unaffected — the conditional renders only when `lookup_type === 'activity_type'`.

### 3. Activity forms read from the lookup

**`src/Modules/Activities/Admin/ActivitiesPage.php` (wp-admin form)** and **`src/Shared/Frontend/FrontendActivitiesManageView.php` (frontend form)**:

- Replace the hardcoded `<option value="training" / "game" / "other">` block with `foreach ( QueryHelpers::get_lookups( 'activity_type' ) as $row )` and render `<option value="<?php echo $row->name; ?>"><?php echo LookupTranslator::label( $row ); ?></option>`.
- Conditional Game-subtype row triggers when the selected key matches the seeded `game` value; conditional Other-label row when the selected key is `other`. Other admin-added types behave like neither: Game-subtype row stays hidden, Other-label row stays hidden.
- The form's inline JS keeps the same `subtype-row` / `other-row` toggle wiring; the JS just compares `sel.value === 'game'` / `'other'` against the seeded names, which are guaranteed to exist by the migration.

The activities-list "Type" column already calls `LabelTranslator::renderTypeBadge()` (or equivalent) — extend it to look up the row by `activity_type_key === lookup.name`, render `LookupTranslator::label($row)` if found, fall back to the raw key otherwise.

### 4. REST validation strict-mode

`src/Infrastructure/REST/ActivitiesRestController.php` — `extract()`:

- Replace the silent `if ( ! in_array( $type, ['game','training','other'], true ) ) $type = 'training';` fallback with a live lookup against `QueryHelpers::get_lookup_names('activity_type')`.
- Unknown values return a 400 from `create_session` / `update_session` with `code=bad_activity_type`, message **"Unknown activity type. Pick one from the configured list."**
- Empty value still defaults to the seeded `training` for back-compat with any client that omits the field.

### 5. Workflow trigger — per-type policy

The `do_action( 'tt_activity_completed', $ctx, $type )` call in both save handlers (`ActivitiesPage::handle_save` and `ActivitiesRestController::create_session` + `update_session`) stays.

`src/Modules/Workflow/Dispatchers/EventDispatcher.php` — the `tt_activity_completed` subscriber currently hardcodes the post-game eval template. Refactor to:

1. Look up the activity-type row by `name = $type`.
2. Read `meta_json.workflow_template_slug`.
3. If set, dispatch that template via `WorkflowEngine::dispatch( $slug, $ctx )`. If empty / missing, no-op.

`PostGameEvaluationTemplate::KEY = 'post_game_evaluation'` is preserved; the seed for `game` points at this slug, so existing behaviour is identical for the seeded set.

### 6. HoD quarterly rollup — per-type columns

`src/Modules/Reports/...` (the live-data form) currently hardcodes Games / Trainings / Other columns in its 90-day rollup table.

Refactor:

1. Replace the hardcoded SUM-CASE-WHEN columns with a `GROUP BY activity_type_key` query.
2. Render one column per type that has at least one row in the window. Order by the lookup's `sort_order` so the layout is stable.
3. The table sits inside a `<div class="tt-table-scroll-x" style="overflow-x:auto;">` so wide tables scroll sideways on phones rather than overflowing the page. The first (label) column is sticky-left so it stays visible during scroll.
4. Translate column headers via `LookupTranslator::label($row)`.

### 7. Translations

Three seeded values get .po-shipped Dutch translations:

- training → Training
- game → Wedstrijd
- other → Overig

Set on `tt_lookups[name=...].translations_json` by the migration. Admin-added types get translated via the existing per-locale lookup-translation block (already shipping since v3.6.0).

### 8. Documentation

- `docs/activities.md` + `docs/nl_NL/activities.md` — replace the "Type" section's "Three types: Training / Game / Other" wording with "Pick a type from the configured list. Your academy can rename the seeded types or add new ones via Configuration → Activity Types." Note that the seeded three can't be deleted because workflow rules depend on them.
- `docs/configuration-branding.md` + nl_NL — mention the new "Activity Types" tile under Lookups & reference data. Note the per-type workflow-template field.

## Out of scope

- **Locked-row UX in the lookup admin** beyond hiding the Delete button — e.g. an inline "🔒 locked" badge on the row, an explanation tooltip. Nice-to-have; defer.
- **A "test fire" affordance** on the workflow-template field to dispatch a one-off task for verification. Defer.
- **Migration of existing `tt_activities` rows** — none needed; current data already matches the seeded names.
- **Cross-academy import / export of admin-added types.** Defer to whatever the eventual blueprint epic (#0018) covers.
- **Deleting / renaming the workflow template that a lookup row points at** — orphan handling is already a Workflow concern; this spec doesn't widen it.

## Acceptance criteria

- [ ] Migration `0033_activity_type_lookup.php` runs cleanly on fresh and upgraded installs. Re-running is a no-op.
- [ ] Configuration → Activity Types tile loads, shows three seeded rows. Delete action is absent on the seeded rows; direct-URL deletion of a seeded row returns 403.
- [ ] Lookup row edit form for `activity_type` shows a "Workflow template on save" `<select>`. Saving the row stores the slug under `meta_json.workflow_template_slug`.
- [ ] Activity create form (wp-admin + frontend) lists the three seeded types (translated). Adding a 4th type via Configuration makes it appear in both forms after a page reload.
- [ ] Saving a wp-admin activity with `Type = game` still spawns the post-game evaluation tasks. Saving with `Type = training` / `Type = other` does not.
- [ ] Saving an activity with an admin-added type whose `workflow_template_slug` is set fires the picked template; with the slug empty, no auto-task.
- [ ] POSTing to `/wp-json/talenttrack/v1/activities` with `activity_type_key = "tournament"` (an unknown name) returns 400 with `code=bad_activity_type`. Posting with `activity_type_key = ""` succeeds and stores `training`.
- [ ] HoD quarterly rollup shows one column per type that has rows in the 90-day window. Headers are translated. The table scrolls horizontally on a 360 px viewport without overflowing the page.
- [ ] CI green: PHPStan, .po validation, no-legacy-sessions grep gate, audience-marker check.

## Notes

### Risks

- **Migration-vs-seed race.** If a club has manually added rows to `tt_lookups[lookup_type='activity_type']` between v3.31.1 and this release (unlikely but possible — admins can use the lookup edit form even with no surface), the migration must not duplicate the seeded names. The idempotency check (`name=?`) handles it.
- **Workflow trigger ordering.** `tt_activity_completed` fires inside the save handler. The lookup-row read is one extra DB query per save — negligible. If profiling later flags it, cache the lookup-to-template map per request.
- **Locked-row deletion bypass.** A persistent admin can hand-edit `meta_json.is_locked = 0` and delete the row. We don't try to make it idiot-proof — the lock is friction, not a security control.

### Implementation order

1. Migration + lookup seed (smallest, anchors everything).
2. Lookup edit form gains the workflow-template field + delete-block.
3. Activity forms read from the lookup.
4. REST validation strict-mode.
5. EventDispatcher refactor + manual smoke test of the post-game eval flow.
6. HoD rollup refactor + responsive scroll.
7. Docs, .po, lint, CHANGES.md, SEQUENCE.md, PR.

## Estimated effort

| Step | Hours |
| - | - |
| 1. Migration + seed | 0.5 |
| 2. Lookup edit form (workflow-template field + locked-delete) | 1.5 |
| 3. Activity forms (wp-admin + frontend) read from lookup | 1.5 |
| 4. REST validation strict-mode | 0.5 |
| 5. EventDispatcher refactor + smoke test | 2 |
| 6. HoD rollup per-type columns + scroll | 2.5 |
| 7. Docs, .po, lint, PR | 1.5 |
| **Total** | **~10h** |

Realistic actual via the v3.22.0+ compression pattern: **~5-7h**. Single PR.

## Dependencies

None blocking. Lands cleanly on top of v3.31.1.

Touches:

- `database/migrations/0033_activity_type_lookup.php` (new)
- `src/Modules/Configuration/Admin/ConfigurationPage.php` (lookup edit form extension)
- `src/Modules/Activities/Admin/ActivitiesPage.php` (form + workflow trigger glue)
- `src/Shared/Frontend/FrontendActivitiesManageView.php` (form)
- `src/Infrastructure/REST/ActivitiesRestController.php` (strict validation)
- `src/Modules/Workflow/Dispatchers/EventDispatcher.php` (per-type policy lookup)
- `src/Modules/Reports/...` (HoD quarterly rollup column expansion)
- `docs/activities.md` + `docs/nl_NL/activities.md`
- `docs/configuration-branding.md` + `docs/nl_NL/configuration-branding.md`
- `languages/talenttrack-nl_NL.po`
