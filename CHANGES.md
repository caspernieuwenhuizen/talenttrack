# TalentTrack v2.13.0 — Weighted overall rating per evaluation

## What's new

Every evaluation now has a single headline number — the **overall rating** — computed as a weighted mean of the four main category effective ratings. The weights are configurable per age group, so "Tactical counts 40% for U16 but only 20% for U10" can be expressed once and applied automatically to every evaluation under each age group's players.

## How it works

When the evaluation form, detail view, or evaluation list needs an overall rating for a given evaluation, the compute pipeline runs like this:

1. Resolve the evaluation's player → team → age group
2. Look up the age group's configured weight set (four percentages, one per main category, summing to 100)
3. If no weights configured, use equal fallback (25/25/25/25 for four mains)
4. For each main category, read its effective rating: direct if the coach entered one, else mean of subcategory ratings, else skip (the null case)
5. Compute the weighted mean: Σ(effective × weight) ÷ Σ(weight_of_rated), rounded to 1 decimal
6. Return the value plus metadata: whether configured weights were used, how many mains contributed, what age group resolved

The algorithm is the same on the server (`EvalRatingsRepository::overallRating`) and in the live-preview JavaScript on the evaluation form, so what the coach sees while editing matches what the detail view shows after save.

## Weight configuration

**New admin page**: TalentTrack → Category Weights. One section per active age group, each with four weight inputs (one per main category). Real-time "Total: X%" indicator that's green at exactly 100% and red otherwise. Save button is disabled while the sum isn't 100, with a hint showing the current total. Server-side validation mirrors the client check — hard validation either way, no silent normalization.

Each section also shows a status badge:
- **Configured** (green dot) — weights have been saved for this age group
- **Equal fallback in use** (gray dot) — no weights saved; overall ratings for this age group's players use equal weights

A "Reset to equal" link on each configured section deletes the weight rows, returning the age group to fallback.

## Where the overall rating appears

Every place a rating was previously shown, the overall now surfaces alongside:

### Evaluation form (live preview)
A blue-accent card above the per-category fieldsets shows the current weighted overall. Updates on every input event:
- Typing a direct main rating
- Typing any subcategory rating
- Switching a main to/from subcategory mode
- Changing the player selection (which changes the age group, which changes the weights)

The live preview labels the mode — "(weighted by age group)" vs "(equal weights)" — and flags partial evaluations with "M of N categories rated".

### Evaluation detail view (headline card)
A larger version of the same card appears at the top of the ratings section. The per-main breakdown table with subcategory rows sits below as before.

### Evaluation list (new "Overall" column)
Sits between "Coach" and "Actions". Shows the computed value or `—` for unrated evaluations. Appends a small `*` for rows using equal-fallback weights (hover tooltip clarifies). All overalls on the page are computed in a single batch (`overallRatingsForEvaluations` — three SQL roundtrips total regardless of row count), so the column adds effectively no page-load cost.

## Compute-on-read, not stored

No `overall_rating` column on `tt_evaluations`. The overall materializes at read time only. This means:

- Changing weights for an age group immediately changes the overall for every evaluation under it — no stale cache to invalidate
- Adding/removing main categories immediately shifts the denominator
- The detail view always reflects current algorithm; no background job to keep things in sync
- One extra query per display (three SQL roundtrips in the batched list case)

## Schema

**New table `tt_category_weights`:**

```
id                 BIGINT UNSIGNED PK
age_group_id       BIGINT UNSIGNED  — references tt_lookups.id (lookup_type='age_group')
main_category_id   BIGINT UNSIGNED  — references tt_eval_categories.id (parent_id IS NULL)
weight             TINYINT UNSIGNED  — 0–100 percentage
created_at, updated_at
UNIQUE KEY (age_group_id, main_category_id)
```

Indexes on `age_group_id` and `main_category_id` individually, plus the unique composite key.

**Migration 0009** creates the table. No data migration — the table starts empty, and equal-fallback covers every evaluation until weights are explicitly configured.

## Edge cases handled

- **Player with no team**: age group unresolvable → equal fallback
- **Team with no age group set**: same — equal fallback
- **Age group has partial weight set** (say only three of four mains configured): the missing main's weight is treated as 0, so it contributes nothing even if a coach rated it. Not recommended but doesn't crash.
- **All four mains unrated on an evaluation**: overall returns `null`, list column shows `—`, detail view hides the headline card
- **Weights change after evaluations exist**: all existing evaluations recompute their overall on next display, using the new weights. No migration needed.
- **Main category deactivated**: its weight stops contributing. If the admin reactivates it later, weights resume without intervention.

## Files in this release

### New
- `src/Infrastructure/Evaluations/CategoryWeightsRepository.php`
- `src/Modules/Evaluations/Admin/CategoryWeightsPage.php`
- `database/migrations/0009_category_weights.php`

### Modified
- `talenttrack.php` — version 2.13.0
- `src/Core/Activator.php` — `tt_category_weights` added to `ensureSchema`
- `src/Infrastructure/Evaluations/EvalRatingsRepository.php` — `overallRating()`, `overallRatingsForEvaluations()`, `resolveAgeGroupForEvaluation()`
- `src/Modules/Evaluations/EvaluationsModule.php` — registers save/reset handlers
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — live preview card on form, headline card on detail view, new Overall column on list, batched lookup for the column
- `src/Shared/Admin/Menu.php` — Category Weights submenu entry
- `languages/talenttrack-nl_NL.po` + `.mo` — 24 new strings

### Deleted
(none)

## Install

Extract the patch ZIP into `/wp-content/plugins/`. Deactivate + reactivate the plugin. Activation sequence:

1. `ensureSchema()` creates `tt_category_weights` on fresh installs
2. Migration 0009 creates the table on upgrades (idempotent — no-op if the table exists)
3. No seed — weights start empty, equal-fallback handles everything until an admin configures weights

## Verify

1. **TalentTrack → Category Weights** — new menu entry. One section per age group, all showing "Equal fallback in use" initially with inputs pre-filled at 25/25/25/25.
2. Edit any age group's weights: try `40/20/20/20`. Total indicator shows red "80%, must equal 100 (current: 80)". Change to `40/30/20/10` — total turns green, save enabled. Save. Section now shows "Configured" status.
3. Open an existing evaluation for a player whose team is in that age group. Overall card at top shows the weighted value using your 40/30/20/10 weights.
4. Evaluations list — Overall column shows the new value. Hover a row's overall to see the "Weighted by age group" tooltip.
5. Reset to equal via the link. The player's overall now reverts to equal-weights (the `*` marker appears in the list).

## Out of scope (noted for later)

- Per-evaluation-type weight overrides (Match vs Training)
- Per-team weight overrides
- Weight profiles (reusable named weight sets)
- Historical weight audit trail (snapshot weights per evaluation at save time)
- Overall rating on player/coach dashboard cards — comes with Epic 2
- Sortable/filterable list column — the current column is display-only

## Note

This release completes the "ratings infrastructure" Epic 3 was supposed to deliver. The plugin now has: custom fields on every entity (2.11.0), subcategories with either/or UX (2.12.0 — recovery through 2.12.2), live subcategory-to-main averaging preview (2.12.2), and now a weighted overall per evaluation. Epic 2 (player rate cards, stats dashboards, trend charts) can build on this cleanly — every evaluation has a consistent, unambiguous, translatable, live-updated rating surface at every level of detail the UI might need.
