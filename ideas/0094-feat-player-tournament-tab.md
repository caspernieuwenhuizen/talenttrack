<!-- type: feat -->

# Player profile — Tournament tab (per-player rollup)

Follow-up to `specs/0093-feat-tournament-planner.md`. The tournament planner ships without a per-player rollup on the player profile; this idea covers that gap.

## What

A new **Tournaments** tab on `?tt_view=player&id=N` showing, for the chosen player:

- A 4-stat strip at the top:
  - **Total minutes** (lifetime across all tournaments)
  - **Starts**
  - **Full matches**
  - **Minutes against stronger opponents** (rollup over matches where `opponent_level` ∈ `stronger` / `much stronger`)
- A per-tournament collapsible section beneath, with per-match rows: opponent name + level pill + scheduled / played minutes + start/sub status + position(s) played.
- An "Upcoming tournament minutes" callout if any future match has the player assigned — *"Saturday: 60 min scheduled across 3 matches."* Surfaces on the player home dashboard too (player + parent self-view) for the day-of.

## Why this is deferred from the v1 ship

The v1 Tournament planner spec (#0093) already exposes the data this tab needs — `GET /tournaments/{id}/totals` returns per-player aggregates, and `tt_tournament_assignments.player_id` is indexed for the player-keyed rollup query. Decision on 2026-05-16: ship the planner first, let the pilot use it on a real tournament, then layer the profile tab on once the data shape proves out. Keeps the v1 PR a reviewable size and delays committing to a profile-tab UX that the pilot hasn't validated yet.

## Scope hint when this shapes

- New REST endpoint: `GET /players/{player_id}/tournament-history` — paginated; per-tournament summary + per-match drill-down.
- New tab in `FrontendPlayerDetailView` (or wherever the player profile tabs assemble; verify when shaping).
- Cap: existing `tt_view_players` covers it; player + parent self-view through the existing per-player ACL.
- Mobile-first: the 4-stat strip should reuse the persona-dashboard KPI strip pattern (already mobile-friendly, three-tier grid).

## Open questions for shaping (don't answer here — answer at shaping time)

- Should the "Upcoming tournament minutes" callout sit on the player's home dashboard widget grid, or only on the profile tab?
- Cohort comparison ("Casper got 5 starts; the squad average was 3.2") — in scope here or its own ship?
- Historic data: when a player is released and re-added later, do their old tournament rollups still count? (Probably yes; the player record persists, assignments stay keyed on player_id regardless.)
