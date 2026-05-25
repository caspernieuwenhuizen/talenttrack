# TalentTrack v4.2.4 — inputmode retrofit sweep (closes #913)

## Pilot context

The v3.50.0 retrofit (#0056) made `inputmode` mandatory on every numeric input so mobile keyboards open in the right mode. Several surfaces drifted off the rule over time. On Android Chrome a missing `inputmode` pops the alphabetical keyboard first; coaches type a digit then have to flip — documented pilot friction.

The acceptance criterion is the literal grep:

```
grep -rn '<input type="number"' src/ | grep -v 'inputmode='
```

Pre-ship: 38 hits. Post-ship: zero.

## What changed

Added `inputmode` to every offending site. Decision rule:

- **`inputmode="decimal"`** for ratings and measurements that allow non-integers — rating min/max/step config, rating inputs themselves, low-rating-threshold, the shared `RatingInputComponent`.
- **`inputmode="numeric"`** for whole-number positive counters — jersey number, sort/display order, retention days, minutes played, user IDs, monthly char cap, age, height/weight in the WP-admin player form (no explicit `step`).

### Sites touched

Frontend coach-facing surfaces (the pilot impact):

- `src/Shared/Frontend/CoachForms.php:202,264,293` — minutes_played + two rating inputs.
- `src/Shared/Frontend/CoachDashboardView.php:208,213` — same pattern in the legacy dashboard form.
- `src/Shared/Frontend/Components/GuestAddModal.php:91` — anon-guest age (6–19).
- `src/Shared/Frontend/Components/RatingInputComponent.php:58` — shared rating renderer (cascades across every consumer).
- `src/Modules/Wizards/Evaluation/RateActorsStep.php:145,195` — eval wizard. `inputmode="numeric"` already existed on a later line; moved onto the `type="number"` line so the literal acceptance grep passes too.

Workflow forms:

- `src/Modules/Workflow/Forms/PostGameEvaluationForm.php:52` — overall_rating (decimal).
- `src/Modules/Workflow/Forms/PlayerSelfEvaluationForm.php:45` — overall_rating (decimal).

WP-admin methodology pages:

- `src/Modules/Methodology/Admin/PositionEditPage.php:85` — jersey_number.
- `src/Modules/Methodology/Admin/PhaseEditPage.php:65` — phase_number.
- `src/Modules/Methodology/Admin/FootballActionEditPage.php:65` — sort_order.
- `src/Modules/Methodology/Admin/InfluenceFactorEditPage.php:67` — sort_order.
- `src/Modules/Methodology/Admin/LearningGoalEditPage.php:99` — sort_order.

WP-admin configuration + content pages:

- `src/Modules/Configuration/Admin/ConfigurationPage.php:503,611,772,819-821,846,894` — f_user_id, sort_order (×2), rating_min/max/step (decimal), eval_low_rating_threshold (decimal), tile_scale.
- `src/Modules/Configuration/Admin/CustomFieldsPage.php:264` — sort_order.
- `src/Modules/Evaluations/Admin/CategoryWeightsPage.php:100` — weight.
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php:287` — display_order.
- `src/Modules/Evaluations/Admin/EvaluationsPage.php:406,458,491` — minutes_played + two rating inputs.
- `src/Modules/Players/Admin/PlayersPage.php:359,361,370` — height_cm, weight_kg, jersey_number.
- `src/Modules/Backup/Admin/BackupSettingsPage.php:202` — retention.
- `src/Modules/DemoData/Admin/DemoDataPage.php:523` — seed.
- `src/Modules/Translations/Admin/TranslationsConfigTab.php:160,173` — monthly_cap, threshold_pct.

The admin pages aren't the pilot's immediate friction, but the acceptance grep is global and the cost of adding the attribute is zero. Future admin work on mobile inherits the right keyboard.

## Out of scope

- The modern `type="text" + inputmode` pattern that avoids browser-native spinner UI. The #0056 retrofit is additive only; spinner-removal is a separate audit.
- Renaming or re-typing any input — purely an attribute addition.

## Why this is `patch`, not `minor`

UX cleanup enforcing an existing standard (#0056). No new behaviour, no contract change, no schema change. The user-visible diff is "the right keyboard pops up on Android." Patch bump matches the SemVer table in `DEVOPS.md` § "When to bump what".

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.2.3` → `4.2.4`.
