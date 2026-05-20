# TalentTrack v3.110.204 — PDP cycle blocks configurable per season + meeting status "To be planned" / "Future" (closes #814)

## Pilot report

Two combined asks from the 2026-05-20 pilot. They ship together because (B) keys off the block dates defined in (A).

**(A) Configurable cycle blocks per season** — *"PDP Planning, the blocks/cycles should be configurable so there should be a configuration item to organize this... the blocks chosen (2 or 3 or 4) by dates should be shown visibly on a year timeline as blocks and there should be a message if there is any overlap (unwanted) or dates not assigned to a block (unwanted). the blocks should also not pass season boundaries."*

**(B) Meeting status** — *"meeting should have status to be planned until the coach actually confirms that they are planned. Only the next meeting should have the to be planned status, meetings after that should have no status yet. Once the first meeting is done or the start date of the block in which the next meeting falls has passed, the status should go to to be planned."*

## What's in this release

The data-layer scaffolding (`tt_pdp_blocks` migration 0107, `PdpBlocksRepository`, `PdpBlocksRestController`, the `frontend-pdp-blocks.js` / `.css` assets, and the `renderPdpBlocksForm()` admin form body) landed earlier alongside v3.110.197 (#802). This release wires it up end-to-end and ships (B) on top.

### (A) integration

- `PdpModule::boot()` registers `PdpBlocksRestController::init()`.
- `PdpConversationsRepository::createCycle()` takes a new optional `int $season_id = 0`. When the configured block count matches `cycle_size`, conversations seed from the configured block dates via a new `createCycleFromBlocks()` helper:
  - `scheduled_at` = block midpoint
  - `planning_window_start` / `planning_window_end` = block start / end verbatim
  - Otherwise falls through to the legacy even-divide.
- `PdpFilesRestController` + `SeasonCarryover` pass `season_id` on cycle creation.
- `FrontendConfigurationView` adds the `pdp-blocks` sub-route: breadcrumb label, dispatch, tile entry on the config grid, plus the `renderPdpBlocksForm()` body it links to. New `assets/icons/calendar.svg` (Lucide-style outline, `stroke="currentColor"`) for the tile.

### (B) integration

- `FrontendPdpManageView::derivedConvStatus()` is no longer row-local — it takes `(object $conv, array $all_convs, string $today)` and emits a 5-state enum: `signed_off` / `held` / `scheduled` / `to_be_planned` / `future`.
- `to_be_planned` is granted to exactly one row at a time: the lowest-`sequence` un-actioned conversation, gated on either `sequence === 1` OR previous row conducted OR `planning_window_start <= today`. The rest get `future` (rendered muted, dashed border).
- Two new badge classes in `assets/css/public.css`: `.tt-status-to-be-planned` (amber, white) for the actionable row, `.tt-status-future` (transparent, muted, dashed) for the silent rest.

## How to test

1. Open `?tt_view=configuration` — verify "PDP cycle blocks" tile appears in the grid (calendar icon).
2. Open the tile — verify season picker, 2/3/4 radio, date-pair rows, SVG year timeline, validation messages.
3. Try overlapping blocks — verify error message + Save disabled.
4. Try gap between blocks — verify warning message.
5. Try date outside season window — verify error message + Save disabled.
6. Save valid blocks; reload — verify hydration round-trips.
7. Create a new PDP file for that season — verify conversation windows match the configured blocks (not even-divide).
8. Switch to legacy academy with no blocks configured — verify even-divide still works.
9. Open a PDP detail page with mixed conversation states — verify exactly one "To be planned" badge (or zero if all are held/scheduled), rest are "—" with dashed border.
10. Mark first conversation as conducted — verify second conversation flips to "To be planned".

## Player-centricity

Helps answer *"When is this player's next development conversation, and is it on the calendar?"* — the coach now sees at a glance which single meeting needs scheduling next instead of a flat row of "Scheduled" badges.
