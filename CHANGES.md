# TalentTrack v3.94.1 — Pilot batch: compare alignment, full-Pro trial, HoD cohort gate, PDP block detail + breadcrumbs

Five operator-reported issues, one ship.

## (1) Player compare — data now lines up under each player

`FrontendComparisonView` rendered each section (FIFA card row → Basic facts → Headline numbers → Main category averages) in its own layout — flex for cards, separate `<table>` per section — so the player columns didn't line up vertically across the page. Replaced with a single CSS Grid (`grid-template-columns: 180px repeat(N, 1fr)`) carrying the header row, the FIFA card cells, and the data rows in one container. Every player now has a dedicated grid column from the card right down through the last category average. New `renderMainBreakdownGrid()` emits the category rows directly into the grid; the legacy `renderMainBreakdown()` is kept (deprecated). Mobile breakpoint shrinks the column widths but keeps the grid intact.

## (2) Trial now unlocks Pro, not Standard

`TrialState::start()` defaulted to `FeatureMap::TIER_STANDARD`; the Pro-only features (`trial_module`, `team_chemistry`, `scout_access`, `s3_backup`) stayed gated during the trial window. Operator clicked "Start trial" expecting every paid feature to light up, found Trials still bouncing on the "not allowed" message. `TrialState::start()` default flipped to `FeatureMap::TIER_PRO`; existing trials in flight keep their original `tier_during` value (read per call) — only NEW trials default to Pro. AccountPage CTA wording: "Start 30-day **Pro** trial" + a longer description naming the unlocked features. `handleStartTrial()` now calls `TrialState::start()` without an explicit tier.

## (3) HoD cohort transitions no longer hardcoded to academy_admin

`FrontendCohortTransitionsView::render()` gated on `current_user_can( 'tt_view_settings' )` — the academy-admin umbrella cap. The matrix grants `cohort_transitions: r:global` to **head_of_development** too, but the cap-only check ignored that grant. HoD logged in → check fails → "head-of-academy access" message → confused user. Replaced the cap check with `QueryHelpers::user_has_global_entity_read( $user_id, 'cohort_transitions' )`, the same helper the v3.91.3 sweep used for the REST list controllers. Three rungs in order of cheapness: `tt_edit_settings` cap, WP `administrator` role, then `MatrixGate::can(..., 'global')`. Matrix-dormant installs bypass via the cap shortcut. Message updated to "you do not have access to cohort transitions".

## (4) PDP planning cells now drill into per-player block status

Cells in `FrontendPdpPlanningView` linked to `?tt_view=pdp&filter[team_id]=N&filter[block]=B&filter[season]=S`. `FrontendPdpManageView` *received* the filters but only used them to render a "Back to Planning" button — the actual list query ignored team / block / season scope and showed every PDP file in the current season. Clicking on "Team U17 / Block 2" landed on the unfiltered list.

New action `?tt_view=pdp-planning&action=block&team_id=N&block=B&season_id=S` renders three columns:

- **Conducted** — players with `conducted_at` set on their block-N conversation.
- **Planned** — players with `scheduled_at` set but no `conducted_at` yet.
- **Missing** — active-roster players with no conversation in this block; the row links to their existing PDP file or to "create new PDP file for this player" depending on whether the file exists.

Cell-click URL changed to point at the new view. Block window dates surfaced inline. Existing `?tt_view=pdp&filter[...]` URLs keep working but the matrix stops sending users there.

## (5) PDP planning gets breadcrumbs

`FrontendPdpPlanningView` had no header / breadcrumb chrome — same surface the v3.92.2 sweep left behind. Added `FrontendBreadcrumbs::fromDashboard( __( 'PDP planning' ) )` at the top of the matrix render, and a 3-level chain on the new block detail (`Dashboard / PDP planning / <Team> — Block N`) using `viewCrumb()` to build the parent crumb back to the matrix with the season preserved.

## Files touched

- `talenttrack.php` — version bump to 3.94.1
- `src/Modules/License/TrialState.php` — default tier flipped Standard → Pro
- `src/Modules/License/Admin/AccountPage.php` — CTA + description rewritten; `handleStartTrial()` uses the default
- `src/Modules/Journey/Frontend/FrontendCohortTransitionsView.php` — matrix-driven gate
- `src/Modules/Pdp/Frontend/FrontendPdpPlanningView.php` — `action=block` route, block detail renderer, breadcrumbs, query helper
- `src/Shared/Frontend/FrontendComparisonView.php` — unified CSS Grid; new `renderMainBreakdownGrid()`
- `languages/talenttrack-nl_NL.po` — 16 new NL msgids
