---
title: Minutes share
group: analytics
summary: What percentage of the minutes a team actually played did each player get, against the academy's target.
audience: [user]
views: [standard-report]
module: TT\Modules\Reports\ReportsModule
order: 45
capability: tt_view_reports
---

# Minutes share

Every other minutes report answers in absolutes: this player has 350 minutes,
that one has 620. **Minutes share** answers the question those numbers hide —
350 minutes looks fine until you know the team played 700.

Open it from **Reports → Playing time → Team · Minutes share**, pick a team,
and you get one row per player: minutes played, minutes available, and the
share between them, with anyone under the academy's target flagged.

## How the available minutes are worked out

Every match the team **played** in the window, at that match's own length.

- **Played** means the same thing it means on the other minutes reports: the
 activity is completed, its date has passed, or it already carries recorded
 minutes. A fixture kicking off this evening is not in the denominator, so
 nobody's share drops on the morning of a match.
- **Its own length** means the match's half length doubled — the value on the
 match prep if one was set, otherwise the default for the team's age
 category, otherwise 35 minutes a half. A U9 team on 30-minute halves has
 600 available over ten matches, not 700.

So ten completed 70-minute matches make **700 available minutes**, and a
player with 350 recorded is on **50%**.

## The denominator does not shrink for absences

The share is of every minute the team played, whether or not the player was
available. A player who missed six weeks injured shows a low share, and that
is the honest number — their injury record is what explains it.

The alternative, quietly shrinking each player's denominator to the matches
they were available for, would hide exactly the case this report exists to
surface: a player who is fit, in the squad, and not getting on.

## The target

Every player should reach a minimum share of the playing time. The default is
**30%**, and an academy can change it under **Configuration → Match minutes**,
beside the per-age-category match lengths — those set the denominator, this
draws the line across it.

A player below the target is flagged with a **▼ below target** marker beside
their share, never by colour alone: these reports get printed, and a red bar
that is only red says nothing in black and white or to a colour-blind reader.

## Reading the KPI strip

| Tile | What it means |
| --- | --- |
| **Matches played** | How many matches fed the denominator. Opens Team · Minutes distribution, which names them and flags any with no minutes recorded. |
| **Available minutes / player** | The denominator every share on the page is measured against. |
| **Median share** | The middle of the squad. Median rather than average on purpose: one player who plays every minute drags an average above a line the rest of the squad is nowhere near. |
| **Below target** | How many players are under the target, flagged gold when that is more than none. |

Rows are sorted **lowest share first**. The players this report is about
should not be at the bottom of a scroll.

## When the report is empty

- *No matches played in this window* — the denominator is zero, so no share
 can be worked out. Widen the window.
- *Matches were played but no minutes are recorded* — the matches are there,
 the minutes are not. Record them from the activity; **Minutes distribution**
 shows which matches are missing them.

## Through the API

`GET /teams/{id}/minutes-share` returns the whole squad;
`GET /teams/{id}/minutes-share/{player_id}` returns one player's row out of
the same answer. Both accept `from` / `to` (`YYYY-MM-DD`) and default to the
rolling twelve months. See [the REST API reference](rest-api.md).
