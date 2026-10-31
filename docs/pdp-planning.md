---
title: PDP planning
group: performance
summary: Per-team-per-block matrix of planned vs conducted PDP conversations. HoD / coach surface.
audience: [user]
views: [pdp-planning]
module: TT\Modules\Pdp\PdpModule
capability: tt_view_pdp_planning
order: 100
---

# PDP planning windows + HoD dashboard

The **PDP planning** tile shows the head of development a per-team-per-block matrix: how many conversations are planned in their three-week window, and how many have a recorded result once the window has closed.

## How it works

Each PDP cycle block (start / mid / end, depending on the cycle size) carries a **planning window**. By default the window is `scheduled_at ± 10 days` (a 21-day total span); admins can adjust the window length via the `pdp_planning_window_days` config. The window is clamped to the season's bounds.

Coaches plan a conversation date inside its window from the file detail. The HoD's planning dashboard at **PDP planning** (`?tt_view=pdp-planning`) shows the matrix:

- **Season** — the screen opens on the season marked as current in the season settings. Only when no season is marked does it pick the season whose dates include today, and failing that the most recent one. The season picker overrides the default.
- **Rows** = teams in the selected season.
- **Columns** = block index (1, 2, 3 …). The number of columns follows the **configured** block count for the season (Configuration → PDP blocks), not whatever block sequences happen to exist in stored conversations. If a legacy or seed conversation carries a higher block number than the current configuration, it no longer widens the grid — only the configured blocks are shown. (When a season has no blocks configured, the grid falls back to the highest block seen in the data.)
- **Cells** = `<planned-in-window>/<roster-size> · <conducted>/<planned>` once the window has passed.
- **Who counts** — every figure in a cell, and every list in the block detail, counts the team's active players only. A player who has been released or archived drops out of all of them, even if they still have a PDP file for the season.
- **The block's window** — each talk has its own window, so a block's window runs from the earliest start to the latest end across the team's talks in it. The block is open while any player's talk window is open. The cell, its colour and the block detail's "Window … → …" line all use this same span.
- **Not held** — a talk the coach marked as not having taken place counts as **not held**: never as planned or conducted, and never as missing. The cell adds "· N not held" when there are any, and a closed window where every remaining talk was held or marked not held reads as done. The block detail lists these players in their own **Not held** column, with the date and the reason.
- **Colour** — green when in-window planning matches the roster; amber when partial; red when the window has closed without enough conducted conversations.

Click any cell to drill into the underlying PDP files filtered by team + block.

## Capabilities

- `tt_view_pdp` — see the dashboard. Granted to coaches and HoD.
- The **PDP planning** tile shows for any role with `tt_view_pdp`; HoDs are the primary audience.

### Who sees which teams

The matrix is **team-scoped**. A head of development (academy-wide PDP read) or an administrator sees **every team**. A team-scoped coach sees **only the teams they're assigned to** — both in the matrix and when drilling into a block; opening another team's block by hand-editing the URL is refused.
