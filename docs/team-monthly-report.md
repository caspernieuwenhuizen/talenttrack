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
- **Pack (up to four pages)** — up to four A4 pages, with room for the full
  player-by-player table and every test reading. Page 1 is the dashboard and
  page 2 the player-by-player table. Page 3 holds the matches, who needs a
  conversation and what changed; the tests, the decisions and data quality
  follow on page 3 when they fit, and start a fourth page when they do not.
  The pack never shortens what you ticked. A page that still runs over
  continues on another sheet, and the printed-size meter says so.
- **Landscape matrix** — one landscape page with every player in a single row,
  the whole squad comparable at a glance.

**The screen shows what prints.** The report under the panel is the printed
copy itself, not a separate web version. On a computer you see the A4 pages
the PDF will have, each marked *"Page 3 of 4"*, with page buttons above them
to jump between pages and **Download PDF** beside them. The landscape matrix
shows one landscape page. On a phone the same sections stack as cards, in the
same order, and each group of cards says where it lands on paper, such as
*"Page 4 of 4 in the PDF"*; wide tables become one row per player, with every
player and every test reading still there. So whatever you can read on screen
is what the meeting gets on paper, and the other way round. On the landscape
matrix, attendance and minutes share are columns of the player-by-player
table rather than sections of their own, and the section list in the panel
says so.

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
section list — or, on a wide screen, in a column to the right of it, with the
printed size and the buttons under the sections.

**Summary or Details.** Every section with two levels of detail offers the
same choice, **Summary** or **Details**, so you learn it once:

| Section | Summary | Details |
| --- | --- | --- |
| Evaluations | category averages, coverage, movers, spread | adds the player-by-category grid |
| Tests | the strip of figures per test | adds each player's result, best to worst |
| Attendance | squad average, who is below 70%, absences by kind | one bar per player |
| Minutes share | median, the target, who is under it | one bar per player |
| Matches | the record and the results | adds scorers and assists, and optionally squads per match |

Attendance, minutes share and matches start on **Details**, tests and
evaluations on **Summary**: that is what each section printed before the
choice existed, so a report you saved earlier prints as it did. The options
that only apply to Details appear under the choice once you pick Details.
Squad status, Needs a conversation, What changed, Decisions and Data quality
are already summaries and have no choice.

Some layouts cannot print a section's Details. Tests and evaluations need the
pack for their player tables, and on the landscape matrix attendance and
minutes share are columns of the player-by-player table. There, **Details**
is greyed out with the reason under it, and the printed copy shows the
summary. Switch the report type and the choice follows. A saved view or a link
that asks for Details on a layout that cannot print it opens with the summary,
and the panel says why.

**Tests** also has **Which tests**: the tests your squad actually took in this
period. Tick the ones the meeting is about, or leave them all unticked to show
every test. Under **Details**, **With change since the previous reading** adds
how much each player moved; it is on unless you untick it. The landscape
matrix prints up to three tests. With more selected, the panel warns you, and
the printed copy names the tests it left out.

**Matches** under **Details** has two tick boxes: **Scorers and assists** (on
by default) and **Squad and minutes per match** (off by default, see *The
match section*).

**Evaluations** has **Which evaluations**: the types from your academy's
evaluation-type list, all ticked by default. Untick a type to leave its
evaluations out of every figure in the section; the section's header then
names the types it counts. Under **Details**, **With subcategories** adds
each subcategory under its main category. It is only offered when something
in the period was rated at subcategory level.

A timed test reads as minutes and seconds (`16:04`), the way it was entered,
and its change is in seconds (`−7 s`). A test where lower or higher is better
lists its players from best to worst; a test without a direction, such as
height, lists them in squad-number order.

If you save the report and a test you picked has no readings next month, its
section still appears and says so. A section that quietly vanished would read
as an oversight. A test that has since been deleted is left out.

**Printed size** shows how many pages the chosen type will print and how full
each page is. On the one-pager, long player lists are shortened to their top and
bottom first, and the agenda to its two most urgent players, before the page is
reported as too full. If it still does not fit, drop a section or switch to the
pack. The pack shortens nothing: it shows a bar for each page, up to four.

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

On paper, **Matches** opens with the record as a row of tiles — played, won,
drawn, lost, goals for and against, and the difference, with the result in its
colour. Under it the results (date, **H** or **A**, opponent, the score in the
colour of the result) sit beside the scorers, ranked by goals and then assists,
each with a bar. A totals row and a line such as *"7 of 8 goals attributed"*
show whether every goal has a scorer entered.

Each test shows its **target** for the team's age group, such as *"Target
O14: ≤ 12:30"*, and every reading its **standing** against it: *on target*,
*just over target* or *well over target* (*under* on a test where higher is
better), in the same words and colours as the test register on the player's
own profile. A test without a better or worse reads *no target*, and the
section says once what that means. A test with no target for the age group
shows neither.

Each test on paper gets a card: its name, which way is better, the date and how
many were tested, then a strip of figures — the squad average and how it moved
since the previous round, the best reading, how many got better or worse, how
many are on target for the age group (when the test has target bands), and the
squad average over the last four rounds. With **Details** chosen, a table
follows, best to worst: each player's reading with a
bar in the colour of their target band, their previous reading, the change
(green when it is an improvement, so a faster time is green), the gap to the
squad average, and **PB** on a personal best. A dashed line marks where the
squad average falls. The one-pager prints the strip only, with the worst
reading in place of the history. On the pack, tests that do not fit under the
agenda move to a fourth page with their tables in full; nothing is left out
to save paper.

Someone who cannot read a team's reports gets no PDF of that team, even from a
forwarded link.

## Sending it every month

**Schedule monthly** under the panel sets the report up to arrive by email as a
PDF on the 1st of every month. Each one covers the month that just ended, so
the one sent on 1 October is about September. You give the schedule a name and
its recipients; the team, report type and sections are the ones you composed,
and so are the choices you made per section: Summary or Details, which tests,
which evaluation types. The form lists those choices before you save. When the
report type cannot print a choice, such as test readings on the one-pager, the
form says so, and the PDF prints the summary instead. The report names players, so send
it to staff only.

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

Every section opens with the same header on screen and on paper: a number,
the title, and a short line saying what the section holds, such as *"4 played
· 2 W 1 D 1 L"* or *"5 players · most urgent first"*. The header sits on a
band in the club's colour, so the start of each section is easy to find when
you leaf through the printed copy. The numbers follow the sections you ticked,
in the order the chosen type prints them, so "section 6" is the same section
on screen and on paper. Tick or untick a section and the numbers after it
move with it.

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
  you see a dash, not a zero. Attendance is the squad average the attendance
  section shows above its table, so the two always agree: late counts as
  attended, and it reads 100% only when every player attended everything.
- **Squad status** — how many players are on track, to watch, needing action, or
  without a read yet, and how that looked last period.
- **Evaluations** — the explanation behind the *Evaluated* and *Squad rating*
  tiles. See *The evaluations section* below.
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
  one-pager and the landscape matrix print the summary, and the panel says so
  before you print.
- **Player by player** — every measure for every player in one table. The
  **Injured** column marks a player with an open injury; the **Suspended**
  column says how many activities the player missed through a suspension in
  the period, so those absences are explained.
- **Decisions and actions** — space on the printed copy to write what the
  meeting agrees.
- **Data quality** — what is missing and worth fixing before next month.

On screen, player names link to their profile, and attendance and minutes share
link to the full report they come from. The PDF prints the names as plain text.

Player tables read in **squad-number order**, so a player sits in the same place
on every page and in the printed copy. Players without a number come last,
alphabetically. Four tables are deliberately left alone: **Minutes share** is
ordered by share played, **Needs a conversation** by urgency, **scorers** by
goals and then assists, and a test's readings from best to worst when lower or
higher is better — there the order is itself the finding.

**Each section names the order its rows are in**, above the table. Attendance
reads *"Team average 93%. In shirt-number order."* — it used to say "lowest
first", which it had not been since squad-number order came in, and a coach who
read the top rows as the players who miss sessions was reading shirt numbers.
The players below the amber and red lines are marked in words and colour
wherever they sit, so they stay findable without the sort.

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
say the same thing. The page count of a snapshot includes its notes, so a long
note that moves a section to another page is counted.

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

## The evaluations section

**Evaluations** follows the squad status: where each player stands this month,
per area of the game, and who moved.

**Summary** has four parts:

- **Squad average** and its change against the previous month. It is the same
  number as the *Squad rating* tile when every type is counted, because both
  are worked out from the same ratings.
- **Evaluated**: how many of the squad were evaluated, and the names of those
  who were not; how many evaluations, by how many coaches, and how many of
  each type.
- **Per category**: each main category's squad average with its change, and a
  bar from the lowest to the highest player on the rating scale, with a line
  at the squad average. When the players span the whole scale the range reads
  *wide*.
- **Biggest risers and fallers**: the three players who went up most and the
  three who went down most, measured against their own previous evaluation,
  such as *6,4 → 7,2*.

**Details** adds a table with a row per player in shirt-number order and a
column per main category: the player's average this month in that category,
coloured along the rating scale, with ▲ or ▼ against the previous month. The
last columns are the player's overall average, how many evaluations they had
and the date of the last one. A player without an evaluation this month has a
greyed row saying so, and a squad row closes the table. The colours and the
scale follow your academy's rating scale.

On the pack the section sits on page 1 when it fits there, otherwise on
page 2 with the player-by-player table, or on a page of its own. Nothing is
cut. On a phone each player is one row with a chip per category.

## The match section

The report used to say a great deal about development and nothing about
results, so the score got read off someone's phone. **Matches** puts them in
the document, from what is already recorded.

It has three parts. The record prints at both levels; the other two are tick
boxes under **Details**:

- **Record** — played, won, drawn, lost, goals for and against, and the
  difference.
- **Scorers and assists** — per player over the period, most goals first, then
  most assists. On by default.
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
  remember. The scorers table does keep goals scored at a tournament — it is a
  leaderboard — but the *"7 of 8 goals attributed"* line counts only the matches
  the record counts, so its two numbers always describe the same matches.

If a match has no home-or-away recorded, the opponent is shown without it
rather than guessing which way round the score goes.

## Who can see it

Staff who can read reports for that team. The report names children and
describes their development, so it is marked **confidential — staff only** on
screen and on paper. It is not meant for players or parents.

Academy administrators can switch the report off under **Features**; it then
disappears from the Reports page and cannot be opened from a link.
