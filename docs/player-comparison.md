<!-- audience: user -->

# Player comparison

Compare up to 4 players side-by-side. Cross-team is the whole point — comparing a U15 LB against a U13 ST is valid for scouting, transfer decisions, and development conversations.

## Where to find it

**Admin**: `TalentTrack → Player Comparison`. Full-featured version with radar overlay chart, trend overlay chart, and detailed main-category breakdown.

**Frontend (v3.0.0+)**: the **Player comparison** tile on the tile landing page. Streamlined mobile-first version with slot pickers, FIFA card row, basic facts, headline numbers, and main-category breakdown table. Skips the overlay charts — the admin version is still there when you need them.

## Slot pickers

Four slots (p1 / p2 / p3 / p4). Pick any player in any slot; leave empty for fewer than 4. Picker labels show `Lastname, Firstname — Team (Age group)` for disambiguation — useful when two U13 teams each have an "A. Kovač" on the roster.

## Filters apply to all slots

Date from, Date to, Evaluation type — apply uniformly to every picked player so the headline numbers are computed on the same basis. Change a filter, all 4 players recompute.

## What the frontend version shows

- **FIFA card row** — 4 small cards side by side (horizontal scroll on phones)
- **Basic facts table** — Team, Age group, Positions, Foot, Jersey, Height — one column per player
- **Headline numbers** — Most recent, Rolling (last 5), All-time, Evaluation count — one column per player
- **Main category averages** — per-category avg rating, one column per player

## What's on admin but not frontend

- **Radar overlay** — all 4 players drawn on the same spider chart for shape-comparison
- **Trend overlay** — all 4 players' trend lines on the same chart for trajectory-comparison

These use Chart.js with a custom multi-dataset config. Adding them to frontend would be ~200 lines of extra JS — deliberately deferred. Admin users who want them go to `TalentTrack → Player Comparison`.

## Mixed-age notice

When you compare players from different age groups, the view surfaces a notice: overall ratings use per-age-group category weights, so the numbers aren't strictly apples-to-apples. They reflect each player's actual rating as their own coaching staff sees it — which is more useful than a normalized abstraction for most decisions.

## Observer role

Observers have `tt_view_reports` so they can use this tile. Cross-club comparison is exactly what observer role is designed for — a board member or external reviewer reviewing talent across the club, without needing to edit anything.
