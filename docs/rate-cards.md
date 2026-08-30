---
title: Player rate cards
group: analytics
summary: Deep per-player dashboards with trends and charts.
audience: [user, admin]
views: [rate-cards]
order: 40
---

# Player rate cards

A **rate card** is a one-glance view of a player's current standing and recent progress.

## What it shows

- **The card** — colour by position, overall rating, key attributes, name, team and photo.
- **Headline numbers** — the most recent rating, the rolling average over the last five evaluations, the all-time average, and the total number of evaluations.
- **Radar chart** — the main categories drawn on a spider web so you can see the shape of the player's profile.
- **Trend line** — the rolling average over time, so you can spot dips, plateaus and climbs.

## How to find it

Open the **Rate cards** tile, then pick the player you want to see.

The team and player pickers offer what your team scope allows, and the page now
holds a hand-typed `team_id` or `player_id` in the URL to the same scope — a
link to a player outside your teams is refused rather than opened. A rate card
is a longitudinal judgement about one child, so it stays with the people
responsible for that child. `tt_view_reports` is required to open the surface at
all.

## Filters

- **Date range** — restrict to a specific period.
- **Evaluation type** — for example only matches, or only training.

The filters apply to all four panels at once, so the picture stays consistent.
