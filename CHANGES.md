# TalentTrack v4.0.3 — v3.110.121 rating-scale-flip leftovers (closes #866)

## Why

v3.110.121 (commit `f7c5135`) flipped the global rating scale from 1-5 to the Dutch academic 5-10 scale. The release fixed ~15 surfaces; three more were missed and surfaced in the pilot audit on 2026-05-21. The calculation core is correct (it reads `rating_max` from `tt_config` and normalises); these three spots were hardcoded literals that bypassed config.

All three reproduce on every install with the new scale active.

## Bugs fixed

### A — Behaviour floor unreachable

`MethodologyResolver::shippedDefault()` carried `behaviour_floor_below = 3.0` (the 1-5 midpoint). Under 5-10 a behaviour avg below 3.0 is impossible, so `PlayerStatusCalculator`'s floor-veto never fired for fresh installs.

Fix: read `rating_min` + `rating_max` from `tt_config` and use the midpoint. On the 5-10 scale that's 7.5; on a hypothetical revert to 1-5 it would be 3.0 (current behaviour preserved). The methodology config form (`FrontendPlayerStatusMethodologyView::extractConfig`) now clamps to the active range too. Help text reads "behaviour floor at the midpoint of the active rating scale" instead of the literal "3.0".

### B — Pitch-fit colour thresholds hardcoded 4.0 / 3.0

`PitchSvg` rendered `score >= 4.0` as no-class (= strong/green) and `score >= 3.0` as `tt-fit-mid`. Under 5-10 that's 40% of max showing as strong; weak fits looked good on the team-development pitch view.

Fix: thresholds compute as `rating_max × 0.80` (strong) / `rating_max × 0.50` (mid). On 5-10 that's 8.0 / 5.0; on 1-5 it would be 4.0 / 2.5 (close to the original intent).

### C — Team-fit panel hardcoded `/ 5`

`PlayerTeamFitPanel` displayed scores as `7.8 / 5`. Numerator exceeded denominator — confusing to coaches.

Fix: denominator reads `tt_config.rating_max`. The panel now shows `7.8 / 10`.

## What this is NOT

- Not a calculation bug. `PlayerStatusCalculator` reads `rating_max` correctly.
- Not a migration regression. Migration 0095's data remap was correct.
- Not a per-install data defect. Universal across installs on the new scale.

## Files touched

- `src/Infrastructure/PlayerStatus/MethodologyResolver.php` — shipped default reads midpoint from config.
- `src/Modules/Players/Frontend/FrontendPlayerStatusMethodologyView.php` — form clamps to active range; help text generic.
- `src/Modules/TeamDevelopment/Frontend/PitchSvg.php` — thresholds as percentages of `rating_max`.
- `src/Modules/TeamDevelopment/Frontend/PlayerTeamFitPanel.php` — denominator from config.
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

No migration. No schema change. No REST change. No translation change. Pure runtime-config reads.

## How to test

1. Set a player's behaviour average to 6.0 (below midpoint 7.5); their composite score is above the amber threshold; status should be downgraded green→amber via the floor veto. Pre-fix this was green.
2. Open the team-development pitch view; players with fit scores in the 4.0–4.9 range should render in **red** (`tt-fit-low`). Pre-fix they rendered as strong/green.
3. Open the team-fit panel for any player; denominator reads `/ 10`. Pre-fix it read `/ 5`.

## Why patch (not minor)

Three bug fixes, no new behaviour, no schema. Per the v4.0.0 SemVer rule: patch.
