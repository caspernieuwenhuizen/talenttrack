# TalentTrack v3.108.4 — Pilot-batch follow-up III: PDP wizard player-context + eval subcategories rendering (#0089 F7 + A3)

Follow-up to v3.108.3. Two more items from `ideas/0089`.

## What landed

### (1) F7 — PDP creation form skips team-selection when launched from a player profile

The "+ New PDP" CTA on the player file passes `?tt_view=pdp&action=new&player_id=N`; the form previously still showed the team-filter dropdown as the first control even though the player was already determined.

`FrontendPdpManageView::renderForm()` now:

- Reads `?player_id` from the URL (new `$preset_player`).
- Suppresses `show_team_filter` on the `PlayerSearchPickerComponent` when the preset player is set.
- Pre-selects the picker via the existing `selected` parameter.

The picker stays editable (the operator can still reassign), but the team-first ergonomic step is gone for the common entry path.

### (2) A3 — Evaluation wizard subcategories rendering

The `tt_eval_categories` schema has supported a `parent_id` hierarchy since the initial eval-categories migration, and the seed ships ~21 subcategories across the 4 main categories (Technical / Tactical / Physical / Mental). The wizard just never rendered them.

`RateActorsStep::render()` now:

- Pulls every active subcategory in one query, keyed by `parent_id`.
- Renders an expandable `<details class="tt-rate-subs">` block under each main row with the subs as detailed sub-criteria (e.g. "↳ Passing accuracy" under "Technical").
- Subs are collapsed by default so the quick-rate ergonomic stays primary.

`validate()` already accepted any `(player_id, category_id)` tuple, so subcategory ratings persist into `tt_eval_ratings` through the existing flow with no schema or REST changes.

CSS additions in `FrontendWizardView::enqueueWizardStyles()`:

- `.tt-rate-subs` — left-rule + indent indicating the hierarchy.
- `.tt-rate-subs-toggle` — disclosure summary, smaller + muted.
- `.tt-rate-row--sub` — slightly de-emphasised label weight + size.

## Out of scope (still tracked in `ideas/0089-feat-pilot-batch-followups.md`)

- F2 my-evaluations scores not displaying after wizard submit
- F4 goal save error "goal does no longer exist"
- F6 double-activity row verification
- A4 team-overview HoD widget (First/Last/Status/PDP/Attendance)
- A5 broad detail-page visual refresh (most surfaces hit by v3.108.3 already)
- A7 upgrade-to-Pro CTA discoverability
- K1-K5 KPI / widget data investigation

## Affected files

- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — F7 player-context preselect
- `src/Modules/Wizards/Evaluation/RateActorsStep.php` — A3 subcategory query + nested rendering
- `src/Shared/Frontend/FrontendWizardView.php` — A3 CSS for `.tt-rate-subs*`
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

1 new translatable string ("Detailed %s") for the subcategory disclosure summary.
