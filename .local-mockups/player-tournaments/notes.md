# Player file — Tournaments tab

Shapes `ideas/0094`. Mockup: `index.html` (A coach desktop · B player 360px · C never selected · D parent, not shared).

## Decisions locked 2026-09-18

1. **Minutes come from the rotation plan of completed fixtures**, labelled as such. Per fixture there is no record of what was actually played: `tt_attendance` minutes are per activity (usually one total for the day). The planner locks assignments once a fixture completes, so the plan of a completed fixture is the day's rotation. The page says where the number comes from and points at the Activities tab for recorded minutes.
2. **Visible to staff, the player and their parents.** Coaches and heads of development for players in their teams; the player for their own record; parents for their child, behind a new `tournaments` parent-visibility section. Only this player's figures, never teammates'.
3. **Upcoming minutes on the tab only**, read from the fixture plan (fixtures not yet kicked off are not activities, so no existing upcoming surface can see them).
4. **Compared against the player's own `target_minutes`** from the tournament squad, not a squad average. Only a shortfall is coloured.

## What the code already has (verified 2026-09-18)

- `tt_tournament_squad.target_minutes`, `tt_tournament_assignments` (match, period, player, position; `BENCH`; period 0 = start), `tt_tournament_matches` (opponent, `opponent_level`, duration, windows, `our_score`/`their_score`, `completed_at`).
- `TournamentsRestController::computeTotals()` (~1635-1740) already computes played/expected minutes, starts and full matches per player **inside the REST controller**. That logic moves to a domain service so `/totals` and the new player endpoint agree (CLAUDE.md §4).
- Tournaments are admin-only today (matrix entity `tournaments` granted to `academy_admin` only) and Pro-gated.

## Data problems the tab would surface

- Saving a fixture score wipes the fixture's other fields: **#3557** (filed, ready-for-dev). Must ship first.
- Demo `TournamentGenerator` uses 1-based `period_index` (starts are always 0), fake position codes, no `BENCH` rows, out-of-set `eligible_positions`.
- The planner prints the raw `opponent_level` key (`much_stronger`), untranslated; REST accepts any string; `LookupCanonicalSeeds` keys the lookup as `opponent_level` instead of `tournament_opponent_level`.
- Amber `#f59e0b` level colour fails 4.5:1 with white text; the pill needs a readable ink.

## Found en route, not in scope

- Kicking off a fixture creates a type-`match` activity with no `tournament_id`, in parallel with the optional hand-made `tournament` wrapper, so a day can hold both and the fixtures get the match surfaces #2686 removed from tournaments.
- "Player · Minutes played" report ignores `minutes_override` (`FrontendStandardReportsView.php:559`).
- `docs/activities.md:245` still sends coaches to match prep for single-game tournaments.

## Slices

1. Tournament data hygiene (demo periods/positions, level label + validation + seed key). No dependency.
2. Access: matrix entity + seeds for coach/HoD/player/parent + `tournaments` parent-visibility section. Migration, runs alone.
3. Domain + REST: lift `computeTotals` into a domain service; `PlayerTournamentHistoryQuery`; `GET /players/{id}/tournaments`. Depends on #3557.
4. The tab: `PlayerTournamentsTab`, tab-strip entry + count, stylesheet, docs EN + NL. Depends on 2 and 3.

Wizard plan: exemption, a read-only surface. Save model: none, nothing is written.
