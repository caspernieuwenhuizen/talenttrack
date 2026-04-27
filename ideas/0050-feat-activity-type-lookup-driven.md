<!-- type: feat -->

# Make the activity Type dropdown lookup-driven (admin-extensible)

The user explicitly asked for this in the v3.31.1 review session: the activity Type dropdown should be configurable via Configuration, the same way Game subtype and Attendance status already are. Today the three values (Training / Game / Other) are hardcoded in the form, validated in the REST controller, and stored as `tt_activities.activity_type_key`. Admins can rename labels via `__()` translations but can't add a fourth type without code changes.

## Why it's not a one-line fix

Two pieces of behaviour switch on `activity_type_key` and need a story:

- **Post-game evaluation workflow (#0022)** fires `tt_activity_completed` when the activity is saved with `Type = Game` (any subtype — Friendly / Cup / League). Trainings and other types never spawn the post-game eval. If an admin adds a 4th type "Tournament", what happens? Default to "no auto-task"? "Same as Game"? "Configurable per-type"?
- **HoD quarterly rollup** splits its 90-day activity volume into Games / Trainings / Other. Adding a 4th type means a 4th column in the rollup, and the existing layout assumes three.

Both are answerable, but the answers are policy decisions, not implementation details.

## Shape of the change (rough)

1. **Migration** seeds an `activity_type` lookup with 3 rows (training / game / other), preserving the lowercased keys today's code switches on.
2. **Storage** — `activity_type_key` keeps the lookup name (lowercased). New admin-added types get their name lowercased too, with collision protection on the existing values.
3. **Form** — both wp-admin (`ActivitiesPage::render_form`) and frontend (`FrontendActivitiesManageView::renderForm`) read options from `QueryHelpers::get_lookup_names('activity_type')`. Conditional Game-subtype + Other-label rows trigger when the selected key matches the seeded `game` / `other` value (the seeded behaviour stays anchored to keys, not labels).
4. **Workflow trigger** — `tt_activity_completed` keeps firing on `key === 'game'`. Admin-added types behave like 'other' for workflow purposes (no auto-task) until per-type policy is added. Document this clearly.
5. **HoD rollup** — bucket "anything not in {game, training}" as "Other" so the layout stays three-column. Admin-added types collapse into the existing Other column. Optional: a follow-up makes the rollup grow a column per-type if there's demand.
6. **Translations** — the `tt_lookups.translations_json` mechanism already covers admin-added types. The three seeded values get .po-shipped translations as today.

## Open questions

- **Should the seeded keys be locked?** I.e. admins can rename / translate Training → "Practice" but can't delete the row, because the workflow trigger depends on the `game` key existing. Recommend yes.
- **Should the "Type" column on the activities list show the seeded Type or a free admin-extensible label?** Both work; recommend showing the lookup `name` (translated), with a fallback to the key when the lookup row has been deleted by an admin who shouldn't have been able to.

## Estimate

- v1 (lookup + migration + form + storage + minimal workflow / rollup compat): **~3-5h**.
- v2 (per-type workflow policy + per-type HoD rollup): **+~3-5h** if the user wants it later.

## Dependencies

- None blocking. Lands cleanly on top of v3.31.1.
- Touches `src/Modules/Activities/Admin/ActivitiesPage.php`, `src/Shared/Frontend/FrontendActivitiesManageView.php`, `src/Infrastructure/REST/ActivitiesRestController.php`, a new migration `0033_activity_type_lookup.php`, and the docs entries for activities.
