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
 no minutes to record. The squad here is the attendance you **recorded**, not
 the one you planned — a player you selected for the match but never marked
 present stays hatched. Mark their attendance first if that's wrong.
- **"Attendance not recorded yet"** across a whole column means nobody's
 attendance was recorded for that match, so nobody is in its squad yet, even
 if you saved a line-up in match prep. Tap **Record attendance** in that
 column to take the register, then come back to enter the minutes. A
 tournament match takes its register when you complete it in the tournament
 planner, so its column has no button.
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

One thing is deliberately *not* recorded when you type a goal in afterwards:

- **No minute.** A goal logged live knows it was scored in the 34th minute; a
 goal you remember on Sunday does not, and the grid does not invent one. Such
 a goal appears in the player's totals but not on the match timeline with a
 clock against it.

**Typing a name still never rewrites the score.** The score is what happened;
who scored is what we know about it. The two are entered separately — the
score in its own row above the grid, described next.

## Recording the score

Above the player rows, each match column carries two boxes: **your goals** and
**the opponent's**. Fill them in the same pass as the minutes and press the
same Save.

This is the number the **Attributed / score** row at the bottom has always
compared against. Until now the grid asked the question and gave you nowhere to
answer it, so every match not run on the live sheet showed a bare count.

Three things behave the way the rest of the product does:

- **Empty is not 0–0.** Clearing a box records *no result*, not a goalless
 draw. A match with no result counts as played and is left out of the
 won/drawn/lost record, which is what keeps the team's record honest.
- **A match run on the live sheet shows its score as text, not a box.** That
 score follows the match sheet's goal log, so changing it means correcting a
 goal in the post-match review. Minutes on the same column *do* stay editable
 — a minute correction survives a recount, a score correction would not.
- **A tournament has no score boxes.** A tournament day is several matches and
 one scoreline cannot describe it, so those columns show a dash.

The same score appears on the match's own page and is written through the same
place — see [Recording the result](activities.md#recording-the-result). Two
ways in, one number.

**An assist attaches to a goal.** Recording one does not add a goal to your
team's total — it names who set up a goal that is already there. If there is
no goal free to attach it to, the grid records a goal with **no scorer**,
which is the honest version of "somebody finished his pass and I can't
remember who".

**Correcting a number down** never erases history: the goal is marked as
reversed rather than deleted, and typed entries are undone before
live-recorded ones, so a correction can never destroy something that was
actually watched at the time.

If a correction does reach a live-recorded goal — because there were no typed
ones left to undo first — **the match sheet's score follows it down.** That
score is a reading of the goal log, so a goal taken off the log is a goal off
the scoreboard; the two cannot end up disagreeing about the same match.

### How many goals and assists a match allows

**The score is the limit.** When a match has a score, no player can have more
goals or more assists than your team scored, and together the players' goals,
and their assists, cannot add up to more than that score either. A match that
ended 2–1 allows at most 2 goals and 2 assists in total. When a match has no
score yet, a player can have at most 15 goals and 15 assists.

A box above the limit is outlined in red and says the most it can hold, and
Save is refused for that match with a message naming the match and the limit.
Nothing of that match's goals and assists is saved until the numbers fit;
fix the box, or record the score first. If you type the score and the goals
in the same pass, the score is saved first, so the new limit counts.

**Correcting numbers that are too high.** Before this limit existed, minutes
were sometimes typed into the **A** column by mistake, which gave a player 70
assists in one match. To correct it, open the grid for that period and lower
the **A** box to the real number, or clear it. The extra assists are removed,
together with the "no scorer" goals that were created to carry them, and the
player's totals in the reports drop to match. Lowering one player is always
allowed, even while other players in the same match are still too high.

### Minutes against the match

The **Minutes** row at the bottom of the grid checks, per match, whether all
the match's minutes are in. It is the same check the Minutes-audit overview
makes, in the same words and colours:

- **The number** is the minutes entered against what the match holds:
 players a side times the match length. `795 / 770` on an 11-a-side match of
 70 minutes means 25 minutes too many, usually a substitution counted twice.
- **The chip** says **Complete** when every player in the squad has minutes
 and the total adds up to a whole match, **Incomplete** when either check
 fails, and **Not recorded** when no minutes are entered at all.
- **The reason** under an Incomplete chip says which check failed: *Players in
 the squad without minutes*, *45 minutes short of a full match*, or *25
 minutes more than the match holds*.

The row updates as you type, so you see the answer before you press Save, and
after Save it gives the same answer as the audit. The squad it counts is the
audit's: a player you selected but never marked present has no box here, but
still counts as a player without minutes until you record his attendance. A
player you marked absent does not count, until you give him minutes: from
then on he is part of the squad, as he is in the audit.

It is information, not a rule. Nothing stops you saving: a match can really
run short, after a red card or an injury with no substitute. A tournament day
shows the minutes of its matches added up, as the audit does, without a target
and with the **Roll-up** chip, because its minutes belong to its matches in the
tournament planner.

### Attributed / score

The bottom row of the grid reads, per match, how many goals have a scorer
against them versus the recorded score — `2/3` means one goal in that match
is still nobody's.

It is information, not a rule: fewer scorers than goals never stops you
saving, and is often simply true. More scorers than goals is the one thing the
grid refuses, see the limit above. It is there so that the score and the goal log cannot
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
