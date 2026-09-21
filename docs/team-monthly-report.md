---
title: Team monthly report
group: analytics
summary: One document per team per month for the staff meeting — status, attendance, minutes, what changed and who needs a conversation.
audience: [user]
views: [standard-report]
module: TT\Modules\Reports\ReportsModule
order: 44
capability: tt_view_reports
---

# Team monthly report

Most reports answer one question — attendance, or minutes, or evaluations — on
its own screen. The **team monthly report** puts a squad's month together in one
document, so the monthly staff meeting can run from it instead of from four
open tabs and memory.

Open it from **Reports → Development & performance → Team · Monthly report** and
pick a team. It opens on **last month**: a monthly report is written at the
start of a month about the one that just ended. Use the period control to pick
another window — this month, the season, or any date range.

## Choosing what goes in it

Above the report is a panel with two choices.

**Report type** decides how the printed copy is laid out:

- **One-pager** — one A4 page, a copy for everyone at the table.
- **Three-page pack** — up to three A4 pages, with room for the full
  player-by-player table.
- **Landscape matrix** — one landscape page with every player in a single row,
  the whole squad comparable at a glance.

On screen, every section you pick is always shown in full, whatever the type.

**Sections** are the parts of the report. Tick the ones you want, pick the type,
then press **Update report** to apply them all at once. Until you press it, the
page does not reload, and a line beside the button says your changes are not
applied yet: the report below, the PDF and the snapshot still show the last
applied selection. Once applied, the address in your browser changes with it, so
you can copy the link and a colleague opens exactly the same report. The letterhead —
team, period, head coach — is always included. Its activity count is everything
on the team's calendar for the period, whether or not it has been marked
completed, so it matches what you see on the activities list. Cancelled
sessions are left out.

Some sections can be told more than whether to appear. When you tick a section
that has settings and press **Update report**, its controls appear under the
section list.

**Tests** has two. **Which tests** lists the tests your squad actually took in
this period — tick the ones the meeting is about, or leave them all unticked to
show every test, which is what the report did before. **How much to show**
decides what each test prints:

- **Summary only** — how many were tested, and who improved or declined. The
  default.
- **Readings** — each player's result, with its unit.
- **Change since last time** — how much each player moved since their previous
  result.
- **Readings and change** — both columns.

If you save the report and a test you picked has no readings next month, its
section still appears and says so. A section that quietly vanished would read
as an oversight. A test that has since been deleted is left out.

**Printed size** shows how many pages the chosen type will print and how full
each page is. On the one-pager, long player lists are shortened to their top and
bottom first, and the agenda to its two most urgent players, before the page is
reported as too full. If it still does not fit, drop a section or switch to the
three-page pack.

## Saving your usual report

Most coaches compose the same report every month. Once it looks right, open the
**bookmark** in the period bar and choose **Save current filters**. The saved
view keeps everything: the team, the period, the report type and the sections.
Mark it as your default, and opening the monthly report takes you straight to
it. A period like *last month* is worked out again each time you open it, so
the same view shows September in October and October in November.

The panel tells you when you are looking at one of your saved views. If you
opened your default and changed something, it tells you that too. To keep the
changed version, save it as a new view.

A link that already names a team, period, type or sections always opens exactly
that report, even if you have a default.

Saved views are **personal**. Nobody else sees them, and changing or deleting
one only affects you. A saved view from before a section was added or renamed
still opens: whatever it cannot recognise is left out, and a view that names no
sections shows all of them.

## Printing

**Download PDF** under the panel prints exactly the report you composed: the
same team, period, type and sections. It prints the number of pages the meter
showed, and whatever the one-pager shortened is shortened the same way on paper.
A shortened list says how many players were left out and the range their values
fell in. Every page carries the confidential, staff-only line at the bottom.

What was written prints in full, over as many lines as it needs: what changed,
why a player needs a conversation, and the names of the players without an
evaluation. The page count on the meter includes those lines.

Someone who cannot read a team's reports gets no PDF of that team, even from a
forwarded link.

## Sending it every month

**Schedule monthly** under the panel sets the report up to arrive by email as a
PDF on the 1st of every month. Each one covers the month that just ended, so
the one sent on 1 October is about September. You give the schedule a name and
its recipients; the team, report type and sections are the ones you composed.
The report names players, so send it to staff only.

The schedule keeps **its own copy** of the report. Changing or deleting one of
your saved views later does not change what it sends. To send something
different, archive the schedule and set up a new one.

You can find your schedules under **Analytics → Scheduled reports**. A
schedule that could not send says why there. A schedule **stops by itself**,
rather than sending, when its team has been archived or when the person who
set it up can no longer see that team's reports. Resume it once that has been
put right.

Scheduled reports are part of the Standard plan and above.

## What the sections show

- **Data coverage** — how many of the period's completed trainings and matches
  have an attendance register, and which do not. It sits above the numbers
  because every percentage below depends on it. When nothing is missing it says
  so in green, rather than simply not appearing. It also names, on a line of
  its own, any session whose date has passed and that nobody marked
  **completed** — those produce no attendance, no minutes and no evaluations,
  so they count towards nothing below until you close them. That is a different
  job from taking a missing register, which is why it reads separately.
- **Headline numbers** — activities, attendance, median minutes share,
  evaluation coverage, squad rating and the number of players needing attention,
  each compared with the period before. Where there is nothing to compare with,
  you see a dash, not a zero.
- **Squad status** — how many players are on track, to watch, needing action, or
  without a read yet, and how that looked last period.
- **Attendance** and **Minutes share** — per player, with links to the full
  attendance and minutes reports. Minutes share lists the **whole squad**: a
  player who was available but never got on the pitch shows 0 minutes and
  counts toward the median, because they are the player this table is meant
  to flag. Its target line is the academy's minutes-share target, the same one
  the Minutes share report uses.
- **Matches** — results and match statistics. See below.
- **Needs a conversation** — the players the status model flags, most urgent
  first, with what the data says about them. This is the meeting's agenda.
- **What changed** — injuries, position and team changes, development-plan
  decisions, signings and departures.
- **Tests** — test rounds held, how many players took part, and who improved or
  declined since their last result. Which tests, and whether you also see the
  readings themselves, is up to you — see *Choosing what goes in it*. The
  one-pager always prints the summary, because a readings table would not fit.
- **Player by player** — every measure for every player in one table.
- **Decisions and actions** — space on the printed copy to write what the
  meeting agrees.
- **Data quality** — what is missing and worth fixing before next month.

Player names link to their profile, and each figure links to the report it came
from.

Player tables read in **squad-number order**, so a player sits in the same place
on every page and in the printed copy. Players without a number come last,
alphabetically. Two tables are deliberately left alone: **Minutes share** is
ordered by share played and **Needs a conversation** by urgency — there the
order is itself the finding.

## Snapshots for the meeting

The report is a view of **current** data. Open it on the 3rd, discuss it on the
5th, and a register taken in between has moved the numbers under the
discussion — so what the meeting decided cannot be reproduced afterwards.

**Save snapshot for the meeting**, under the panel, freezes what you are
looking at. A snapshot keeps the numbers as they were, with its own address, so
reopening it next season shows what the meeting actually saw rather than what is
true today. Your team's recent snapshots are listed beside the button.

**Notes live on a snapshot, not on the live report.** Each section gets one
note — *"three weeks out, back in full training from the 18th"* — and the notes
stay editable after the snapshot is taken while the numbers do not. That is the
point: you draft before the meeting and write down what was decided during it.
Each note records who last wrote it and when. Clearing a note and saving removes
it. The PDF of a snapshot prints the notes, so the printed copy and the screen
say the same thing.

### Snapshots cannot be shared by link

**A snapshot is only readable by someone who is signed in and can already see
that team's reports.** There is no share link, no token and no public address,
and adding one is not a setting you have missed.

This is deliberately different from match prep and match analysis, which do have
share links. A monthly report names every player in the squad and carries their
attendance, their test results and who needs a conversation — it is the densest
collection of information about children this system produces, and a link that
works for anyone holding it is the wrong shape for that however short its
expiry.

For staff who do not log in, download the PDF and hand it over yourself.

## The match section

The report used to say a great deal about development and nothing about
results, so the score got read off someone's phone. **Matches** puts them in
the document, from what is already recorded.

It has three parts, each with its own tick box in the panel:

- **Record** — played, won, drawn, lost, goals for and against, and the
  difference. On by default.
- **Scorers and assists** — per player over the period. On by default.
- **Squad and minutes per match** — who played in each match and for how long.
  **Off** by default: it is the longest part, and it repeats what the minutes
  section already shows.

Under those, each match is listed with its date, the opponent, whether it was
home or away, and the score.

Two things it deliberately does not do:

- **A match with no score recorded is listed, but not counted** in won, drawn
  or lost, and the section says how many. Treating a missing result as a
  goalless draw would make the record quietly wrong, which is worse than an
  obvious gap. If you want the record complete, fill the score in on the match.
- **Tournaments are left out.** A tournament is a multi-game day, and one score
  line cannot describe one. When any fall in the period the section says so,
  rather than leaving you to wonder why the record does not match what you
  remember.

If a match has no home-or-away recorded, the opponent is shown without it
rather than guessing which way round the score goes.

## Who can see it

Staff who can read reports for that team. The report names children and
describes their development, so it is marked **confidential — staff only** on
screen and on paper. It is not meant for players or parents.

Academy administrators can switch the report off under **Features**; it then
disappears from the Reports page and cannot be opened from a link.
