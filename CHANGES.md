# TalentTrack v2.12.2 — Translation fix + live average preview + two latent bug fixes

## What changed

### Dutch translations now apply to seeded evaluation categories

v2.12.0 and v2.12.1 rendered the 4 main categories (Technical/Tactical/Physical/Mental) and the 21 subcategories as raw strings from the DB, bypassing the translator. The `.po` file had entries for all of them, but they never fired because the display code used plain `esc_html( $cat->label )` instead of running the label through `__()`.

Fixed in 2.12.2. A new `EvalCategoriesRepository::displayLabel()` static helper wraps `__($raw, 'talenttrack')`. Every surface that renders a category or subcategory label now goes through this helper:

- Admin tree at TalentTrack → Evaluation Categories
- Evaluation form — fieldset legends for each main + subcategory rows
- Evaluation detail view — main rating rows + subcategory breakdown rows
- Radar chart legends (centralized fix in `QueryHelpers::player_radar_datasets`, so every radar consumer — player profile, coach dashboard, player dashboard, reports — benefits with one change)
- Subcategory edit form's parent breadcrumb ("This subcategory sits under: …")

Labels that have no `.po` entry — typically admin-added custom subcategories like "Positional awareness" that the seed doesn't cover — pass through unchanged. `__()` returns its input verbatim when the translator has no match, so this is safe for any stored label.

Note on the DB: stored labels remain in English source strings on fresh installs (e.g. `tt_eval_categories.label = 'Short pass'`). On sites that hit the v2.12.0/v2.12.1 recovery path and ran migration 0008 against Dutch `tt_lookups` rows, the main categories are stored in Dutch (`Technisch`). Both cases work — for the Dutch-stored labels, `__('Technisch', 'talenttrack')` finds no translation entry and passes through; for the English-stored labels, the `.po` translates them.

### Live average preview while rating subcategories

New read-only display under each subs block on the evaluation form. Shows "Main category average (computed):" followed by the computed value as the coach types subcategory scores, plus a note "(from N subcategory/ies)". Updates on every input event.

Empty inputs are ignored in the average. The computation is `(sum of entered values) / (count of entered)`, rounded to 1 decimal. Math happens in JavaScript for immediate feedback; the authoritative rollup still happens on read via `EvalRatingsRepository::effectiveMainRating()` — the preview and the real computation use the same algorithm so what the coach sees while editing matches what the detail view shows after save.

Hidden in direct mode (no surface for a preview to summarize). Reappears automatically when the coach clicks "rate subcategories instead". Resets to "—" on mode-switch to direct.

Pre-filled on edit: if you open an existing evaluation that has subcategory ratings, the preview shows the already-computed average on page load, matching what the detail view shows.

### Two latent bug fixes carried forward from the 2.12.0/2.12.1 post-mortem

Neither of these affected your site after the manual cleanup you ran, but both would have kicked in if someone else had hit the same corruption path or a close variant.

**Fix 1 — `repairEvalCategoriesTableIfCorrupt` was too narrow.** The original check in 2.12.1 was: "does the table have the `category_key` column? if yes, bail." On sites where `dbDelta` had added `category_key` to an already-corrupt table in a prior activation, the column was present but the table still had garbage rows and stale `tt_lookups`-shape columns (`name`, `sort_order`). The repair routine saw the column and declared the table healthy.

Fixed: the routine now also treats the presence of stale `name` / `sort_order` columns as a corruption signal, AND treats any row with a blank `category_key` AND blank `label` as a corruption signal. If any of the three signals trigger, the table gets dropped (subject to the safety guard against dropping with referenced ratings). Idempotent — healthy tables still no-op through every check.

**Fix 2 — Migration 0008's "already retargeted?" check was incorrect.** The original check asked "does some row with this ID exist in `tt_eval_categories`?" On the corruption path, old lookup IDs 1-4 coincidentally matched IDs 1-4 of the corrupt blank rows, so the migration declared 28 ratings already-retargeted when in fact they were still pointing at the blanks.

Fixed: the check now asks "is this ID one of the new-category IDs we just inserted in the current migration run?" by looking up the ID in the remap map's value set (`array_flip($remap)`). A rating's `category_id` counts as already-retargeted only if it's among the specific IDs this migration owned. If it's not a remap source and not a remap target, it's an orphan and the migration throws.

## Files in this release

### Modified
- `talenttrack.php` — version 2.12.2
- `src/Infrastructure/Evaluations/EvalCategoriesRepository.php` — new static `displayLabel()` helper
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php` — label translations at 3 display sites
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — label translations on form + detail view; new live-preview UI block and JS
- `src/Infrastructure/Query/QueryHelpers.php` — `player_radar_datasets` translates labels centrally
- `src/Core/Activator.php` — `repairEvalCategoriesTableIfCorrupt` now detects 3 corruption signals
- `database/migrations/0008_eval_categories_hierarchy.php` — `retargetEvalRatings` uses the remap map for already-retargeted detection
- `readme.txt` — stable tag 2.12.2 + changelog entry
- `languages/talenttrack-nl_NL.po` + `.mo` — 3 new strings for the preview UI

### New
(none)

### Deleted
(none)

## Install

Extract the patch ZIP over `/wp-content/plugins/`. Deactivate + reactivate the plugin. No schema changes, no migrations — this release is purely UI + bug fixes. Activation is fast.

Your specific site: nothing will happen during activation because the repair routine sees a healthy schema and no blank rows. The improved detection logic is latent insurance, not an active fix for your case. The live-preview JS activates the next time you open the evaluation form.

## Verify

1. TalentTrack → Evaluation Categories — Technisch/Tactisch/Fysiek/Mentaal as before, but now the 21 subcategories display in Dutch (Korte pass, Lange pass, Aanname, Dribbelen, Schieten, Koppen, etc.) instead of English.
2. Open an evaluation form. Technisch fieldset: click "beoordeel subcategorieën". Enter a few scores. The "Gemiddelde van hoofdcategorie (berekend):" line at the bottom of the block updates as you type — shows "4.0 (van 3 subcategorieën)" after three scores averaging to 4.0.
3. Switch back to direct mode via "beoordeel de hoofdcategorie direct". The preview disappears (the whole subs block is hidden).
4. Edit an existing evaluation that already has subcategory ratings. The average should appear on page load in the correct subs block.
5. Detail view — subcategory labels display in Dutch.
6. Radar chart legend (anywhere it renders) — the four main labels display in Dutch.

## Not in this release

- Drag-and-drop reorder of categories (still deferred)
- Per-subcategory weighting in the rollup (mean only, still)
- Persisted live-preview computation on the server side (the JS in-page matches the server's `effectiveMainRating` algorithm, so save-and-refresh shows the same number; if the two ever diverge, the server always wins)

## Post-mortem footnote

The v2.12.0 / v2.12.1 / v2.12.2 chain is one bug (reserved-word column name) that kept finding new hiding places:
- 2.12.0: original ship with the reserved word
- 2.12.1: rename to fix, but repair detection too narrow, migration check too loose, both latent
- 2.12.2: the two latent bugs fixed + the user-visible polish (translations, live preview) that was never supposed to wait this long

No data loss across the chain — the safeguards held. But this is the second two-release chain in this plugin's history caused by a `dbDelta` quirk. Future schema-changing sprints will include an explicit "upgrade from previous" test path on a representative host before tagging.
