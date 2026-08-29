---
title: Minutes + statistics
group: match-day
summary: "Record minutes, goals and assists per player, match by match, in one grid."
audience: [user]
views: [minutes-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: minutes_grid
order: 30
---

# Minutes + statistics

**Minutes + statistics** records how many minutes each player got and what
they produced, match by match, across a whole period — the desktop,
spreadsheet-style companion to the [attendance grid](attendance-grid.md),
restricted to matches.

Open it from the **Attendance / Minutes + stats** toggle at the top of the
grid surface, from the **Activities** list, or with the **Minutes +
statistics** button on a match's own page (which opens the grid on that
match). You need permission to edit activities. Like the attendance grid,
it's built for a desktop or laptop.

## What you see

- **Rows are your players** — the active roster of the selected team.
- **Columns are matches** — the team's matches in the selected period, oldest
 on the left, each split into three boxes: **Min**, **G** and **A**.
- **Each editable cell is a box you type into.** Minutes, goals, assists.
 Tab runs Min → G → A → the next match, the way a spreadsheet does. The
 **Total** columns on the right sum each player's minutes, goals and assists
 across the shown period.
- **Hatched cells** mean the player wasn't in that match's squad, so there are
 no minutes to record. Add them to the match first (via attendance) if that's
 wrong.
- A **"live"** badge on a column means those minutes come from a match run
 through the match sheet. You can still correct a figure here — your entry is
 kept as a correction that survives a recount.

## Recording minutes, goals and assists

1. Choose the **team**, the **period** (a quick pill or a custom date range).
2. Fill the boxes in each squad cell. Leave one empty to clear it.
3. Click **Save**. The counter shows how many changes are waiting; edited
 boxes are outlined until you save. **Cancel** leaves without saving.

The minutes you enter here are the same figures the Minutes-audit tool and the
Minutes-played report use, so everything stays in step.

### Goals and assists without the live match sheet

Until now, goals and assists could only be recorded by running a match on the
**live match sheet**. A club that does its admin on a Sunday evening instead
had players whose minutes were complete and whose goals were permanently
blank — on their profile, in the reports, everywhere.

You can now type them straight in. What you record counts exactly the same as
a goal logged live: it reaches the player's record and every report built on
it.

Two things are deliberately *not* recorded when you type a goal in afterwards:

- **No minute.** A goal logged live knows it was scored in the 34th minute; a
 goal you remember on Sunday does not, and the grid does not invent one. Such
 a goal appears in the player's totals but not on the match timeline with a
 clock against it.
- **No change to the score.** The match score stays exactly as it was
 recorded. The score is what happened; who scored is what we know about it,
 and typing a name should never quietly rewrite the result.

**An assist attaches to a goal.** Recording one does not add a goal to your
team's total — it names who set up a goal that is already there. If there is
no goal free to attach it to, the grid records a goal with **no scorer**,
which is the honest version of "somebody finished his pass and I can't
remember who".

**Correcting a number down** never erases history: the goal is marked as
reversed rather than deleted, and typed entries are undone before
live-recorded ones, so a correction can never destroy something that was
actually watched at the time.

### Attributed / score

The bottom row of the grid reads, per match, how many goals have a scorer
against them versus the recorded score — `2/3` means one goal in that match
is still nobody's.

It is information, not a rule: nothing stops you saving, and a mismatch is
often simply true. It is there so that the score and the goal log cannot
quietly drift apart without anyone noticing.

### Showing fewer columns

Three boxes per match is a lot of grid. The **Show** switches above the table
turn the Goals and Assists columns off, which collapses it back to a plain
minutes grid. The choice is yours alone and is remembered the next time you
open the page.

## Turning it off

An administrator can hide the grid under **Settings → Features → Minutes +
statistics**. When it's off, the grid button disappears and the page can't be
opened. The per-match minutes editor is unaffected, and goals already
recorded stay on the players' records.
