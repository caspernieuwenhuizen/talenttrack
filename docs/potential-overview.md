---
title: Potential overview
group: analytics
summary: Every player in a team or age group with their current potential band, how it has moved, and who set it — editable in place.
audience: [admin]
views: [potential-overview]
module: TT\Modules\Analytics\AnalyticsModule
feature: analytics_potential_overview
capability: tt_view_analytics
order: 31
---

# Potential overview

The **Potential overview** answers a question the rest of the product could not: *show me every player in this age group with their potential band, sorted.* Until this screen existed, the band — First team, Professional elsewhere, Semi-pro, Top amateur, Foundation — appeared nowhere that showed more than one player at a time. You could read it on a player's file, one player at a time, and that was all.

It lives under **Reports**, next to the Cohort decision board and Evaluation coverage, and it is built for periodic review rather than daily use.

## Which player question does this answer?

*Where is this player going?* — and, for the first time, for more than one player at once. A development lead's job is comparative, and the product used to make it serial.

## Choosing a scope

One control, two kinds of answer:

- **A team** — one squad, the operational unit.
- **An age group** — every squad carrying the same age-group label. "The U15s" means one squad to some academies and two or three to others; pick the age group and you get all of them in one list, with a **Team** column so you can still tell them apart.

The list only ever offers the teams and age groups you may read. A coach sees their own squads; academy-wide roles see everything. That is not a display filter — the scope you pick can never widen what you can see.

## What's on each row

| Column | What it is |
| --- | --- |
| Player | Name, linking to their file |
| Team | Only in age-group scope, so you can tell two squads apart |
| Potential | The current band — a dropdown if you may set potential, otherwise the label |
| Movement | Whether the band was raised, lowered, reaffirmed, or is a first entry, and the band it moved from |
| Recorded | When the current band was set, by whom, and how many entries are on record |

Sort by clicking **Player**, **Team**, **Potential** or **Recorded**. Clicking the column you are already sorted by flips the direction. Players with nothing recorded stay at the bottom whichever way the band column is sorted — "lowest band first" is a question about recorded bands, and floating the unassessed to the top would answer a different one.

**Show bands** narrows the list to the bands you tick, including **Not recorded**. Leave every box clear to see the whole squad.

## Players with no band are rows, not absences

This is the part worth reading twice. A list that quietly left out the players nobody has assessed would tell you the opposite of the truth — and those players are usually the reason you opened the screen. They appear, tinted, marked **Not recorded**, and they are counted in the **No band yet** figure at the top.

**Coverage** is the share of players the academy has actually formed a view on. It reads the scope, not your band filter — ticking a box narrows the list, not the squad, and a coverage figure that moved when you filtered would mean nothing.

Players below the age the band is asked at are a third case, and are counted separately. TalentTrack does not ask for a professional ceiling on a twelve-year-old (see [Player status](player-status.md)), so those rows read **Not asked below 13** and are not counted as gaps. A footnote under the summary says how many there are.

## Setting a band from here

If you may record potential, the band cell is a dropdown. Change as many as you like and press **Save bands** once — one commit for the whole squad, and **Cancel** takes you back to the list as it stands without writing anything. That is deliberate: a coach working through a squad on a phone at a pitch gets one commit point rather than twenty silent saves on a connection that may not be there.

What it writes is exactly what the **Set potential** popover on a player's file writes, through the same path and the same rules:

- Leaving a player on **Not recorded** records nothing for them. It is not an instruction to clear anything.
- Choosing the band a player is already on records nothing either — restating a standing judgement is not a change of mind, and it would fill the history with entries that look like revisions.
- A player below the age floor is skipped, and the confirmation says how many were.

Every save appends to the player's potential history, so the trajectory on their file keeps its shape, and their traffic-light status picks the new band up immediately.

## Who can see it

Potential is a staff judgement about a child, and a ranked list of those judgements across a squad is more exposing than the single colour on a team page. Players and parents reach nothing here — not even their own row — regardless of whether your academy shows them the status dot.

Beyond that the screen follows the analytics capability and your team scope, the same as every other report.

## For integrations

`GET /wp-json/talenttrack/v1/reports/potential-overview` returns the same rows the screen shows you, for the same account: `scope` (`team` or `age_group`), `team_id` or `age_group`, optional `bands`, `sort` and `dir`. The response carries the rows, the teams in scope, and the same summary counts. Writes go to `POST /players/{id}/potential`, the same endpoint the player file uses.

`team_id` and `age_group` are taken either plainly or nested as `filter[team_id]` and `filter[age_group]` — the form the rest of the list API uses — and a nested value wins when both are sent. A `team_id` that is not a usable team id is refused with `400 bad_filter`, whose `details.parameter` names the spelling at fault; it is never dropped, because a dropped team filter answers for every squad you may read under a filter somebody had asked for.
