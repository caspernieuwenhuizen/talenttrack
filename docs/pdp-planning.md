<!-- audience: user -->

# PDP planning windows + HoD dashboard

The **PDP planning** tile shows the head of development a per-team-per-block matrix: how many conversations are planned in their three-week window, and how many have a recorded result once the window has closed.

## How it works

Each PDP cycle block (start / mid / end, depending on the cycle size) carries a **planning window**. By default the window is `scheduled_at ± 10 days` (a 21-day total span); admins can adjust the window length via the `pdp_planning_window_days` config. The window is clamped to the season's bounds.

Coaches plan a conversation date inside its window from the file detail. The HoD's planning dashboard at **PDP planning** (`?tt_view=pdp-planning`) shows the matrix:

- **Rows** = teams in the selected season.
- **Columns** = block index (1, 2, 3 …). The number of columns follows the **configured** block count for the season (Configuration → PDP blocks), not whatever block sequences happen to exist in stored conversations. If a legacy or seed conversation carries a higher block number than the current configuration, it no longer widens the grid — only the configured blocks are shown. (When a season has no blocks configured, the grid falls back to the highest block seen in the data.)
- **Cells** = `<planned-in-window>/<roster-size> · <conducted>/<planned>` once the window has passed.
- **Colour** — green when in-window planning matches the roster; amber when partial; red when the window has closed without enough conducted conversations.

Click any cell to drill into the underlying PDP files filtered by team + block.

## Capabilities

- `tt_view_pdp` — see the dashboard. Granted to coaches and HoD.
- The **PDP planning** tile shows for any role with `tt_view_pdp`; HoDs are the primary audience.

### Who sees which teams

The matrix is **team-scoped**. A head of development (academy-wide PDP read) or an administrator sees **every team**. A team-scoped coach sees **only the teams they're assigned to** — both in the matrix and when drilling into a block; opening another team's block by hand-editing the URL is refused.
