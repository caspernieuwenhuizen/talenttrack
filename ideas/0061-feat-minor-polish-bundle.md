<!-- type: feat -->

# #0061 — Minor polish + UX bundle

A running punch-list of small bugs and polish items that don't each warrant their own idea file. Bundled into one PR when picked up. Most items have known file pointers — check them before assuming the surface is unchanged.

## Discoverability

- [ ] **Persona / Classic dashboard toggle hard to find.** Lives at `?tt_view=configuration&config_sub=dashboard` (the "Default dashboard" sub-tile inside the Configuration tile-grid). When persona dashboard is on by default, the entry can be hidden depending on the persona's layout. Add a wp-admin entry point too — e.g. a notice on **TalentTrack → Configuration** linking to the frontend chooser, or a top-level Settings panel that mirrors the radio.

## Activity surface

- [ ] **Attendance % calculation looks wrong.** Verify `tt_activities` list view + `Infrastructure\PlayerStatus\PlayerAttendanceCalculator`. Likely candidates: excused-absence handling (currently excluded from numerator + denominator — confirm that's what the user wants), or the activity-list `attendance_pct` derived in `ActivitiesRestController::format_row`.
- [ ] **Activity type dropdown still has English entries.** Per-type translation wires through `LookupTranslator::name()` against the `activity_type` lookup. Two likely culprits: a hardcoded options array somewhere (admin filter dropdown was unwired in v3.47.0 — check for other places), or a stored lookup row whose `translations.nl_NL.name` is empty.
- [ ] **Game subtype (Friendly/Cup/League) dropdown is in English.** `game_subtype` lookup. Same translation pattern as `activity_type`. Check the seed for `translations.nl_NL.name`.
- [ ] **Activity type dropdown duplicate / English entries.** User reports seeing English variants alongside the Dutch ones. Possibly the source seed never ran `nl_NL` translations on every row, or two seed rows ended up with different `name` values for the same concept. Audit `tt_lookups` rows of type `activity_type`.
- [ ] **Activity status should also render as a colour-coded pill.** Pattern shipped for `activity_type` in v3.47.0 via `LookupPill::render()`. Apply the same to `activity_status_key` in both the admin list and the frontend list.
- [ ] **When status = planned, attendance should not be registered.** The attendance roster appears below the activity form regardless of status today. Hide / disable the attendance section when `activity_status_key === 'planned'`. Re-enable when status flips to `completed` or `cancelled` (cancelled probably shouldn't accept attendance either).
- [ ] **Add `draft` to activity_status enum.** Tied to the cancel-button-on-wizard issue below — hitting Cancel could save as `draft` instead of throwing the work away. Update migration 0040's seed (idempotent: only insert if missing) + the wizard's cancel handler.
- [ ] **Delete activity uses native browser `confirm()`** — should be a TalentTrack-styled in-app modal. Both the admin list (`ActivitiesPage`) and the frontend list use `onclick="return confirm(...)"`. Replace with the existing `tt-modal` pattern.

## Wizards

- [ ] **New-evaluation wizard does not use the player picker.** Should reuse `PlayerSearchPickerComponent` (autocomplete) the way the trial-case create form does after v3.49.0. File: `src/Modules/Wizards/Evaluation/PlayerStep.php`.
- [ ] **New-evaluation wizard: evaluation type dropdown is empty.** The `eval_type` lookup either has zero rows or the wizard is querying the wrong type. Verify migration seed + that the step reads `QueryHelpers::get_lookups('eval_type')`. File: `src/Modules/Wizards/Evaluation/TypeStep.php`.
- [ ] **New-activity wizard is missing entirely.** Per `CLAUDE.md` § 3 (wizard-first standard, shipped #0058) every record-creation flow ships with a wizard. Build it: slug `new-activity`, steps probably `team → type+status → date+location → review`. Register in `WizardsModule`. Lift entry-point gating in `FrontendActivitiesManageView` via `WizardEntryPoint::urlFor()`.
- [ ] **Cancel button on activity wizard doesn't work.** v3.46.0's hotfix fixed Cancel for the player/team/eval/goal wizards via `WizardEntryPoint::dashboardBaseUrl()` — unclear why activity isn't covered (the activity wizard doesn't exist yet, but the WizardView's cancel logic should be uniform). Verify `FrontendWizardView::cancel()` handles every registered slug. Also consider: pressing Cancel saves the in-progress work as a `draft`-status activity instead of discarding (ties into the `draft` status above).

## Authorization matrix

- [ ] **Not all tiles show up in the authorization matrix.** Tiles registered via `TileRegistry::register([...])` should all appear in the matrix's tile-permission section. Audit which tiles are missing — likely some `view_slug`-only registrations without a `module_class` or those registered after the matrix builds its list. The Configuration tile is one that the user wants visible there.
- [ ] **Configuration tile can't be hidden via the matrix.** Either it's not listed, or the matrix UI for that row is read-only. Verify the matrix admin (`?tt_view=authorization-matrix`) supports hiding any frontend tile per persona, including Configuration.
- [ ] **Authorization matrix order is illogical.** Re-group rows logically (Players / Teams / Activities / Evaluations / Goals / PDP / Reports / Configuration / Admin) and sort within each group. Today the order looks roughly insertion-time. File: `src/Modules/Authorization/Admin/MatrixView.php` or wherever the matrix renders.

## Cross-references

- **#0046 / v3.46.0** — fixed the wizard Cancel button for new-player / new-team / new-evaluation / new-goal. The activity-wizard work in this bundle should reuse the same pattern.
- **#0050** — activity-type lookup-driven dropdown. The "still English entries" symptom may be a seed-row drift introduced by a later migration; check the seed history.
- **#0058** — wizard-first standard. The missing new-activity wizard is the first concrete enforcement gap on a record-creation flow that landed after #0058 was codified.
- **#0033** — authorization matrix; the tile-coverage + ordering work modifies the matrix admin from that epic.

## Sizing estimate

~6-10h compressed actual under the recent omnibus pattern. Most items are 30-60min individually; the new-activity wizard and the matrix tile-coverage audit are the larger pieces (~2h each).

## Hard decisions to lock at shaping

- Should `cancelled` activities accept attendance? Recommendation: **no**, hide the section with the same logic as `planned`.
- Should `draft` be a hidden internal status (not in the form dropdown) or visible? Recommendation: **hidden** — it's only used by the wizard's Cancel handler.
- For the wp-admin entry point to the persona toggle: **a notice with link** is lower-risk than duplicating the chooser.
