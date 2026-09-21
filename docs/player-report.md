---
title: Player report
group: analytics
summary: One document for a conversation with a player — status, evaluations, attendance, playing time, goals and the development plan, over the season so far.
audience: [user]
views: [standard-report]
module: TT\Modules\Reports\ReportsModule
order: 45
capability: tt_view_reports
---

# Player report

Sitting down with a player — a formal development-plan conversation or ten
minutes after training — goes better with the facts on one page. The **player
report** puts one player's season so far together in one document, so you do
not have to open five tabs on their file and remember what each one said.

## Opening it

Two ways, and both open the same report:

- From the player's file: open the **Player card** tab and choose **Player
  report**. The report opens on that player, and a **Back to** link returns you
  to the file.
- From **Reports → Development & performance → Player · Report**, then pick a
  player.

The report opens on **the season so far** — from the start of the current season
up to today. Use the period control to choose another window: last week, last
month, this month, the whole season, or any date range.

## What goes in it

It opens on the sections a one-to-one conversation needs:

- **Status** — where the player stands now, and what the status was worked out
  without if some of the evidence is missing.
- **Talking points** — what the data suggests raising.
- **Evaluations** — the latest and average rating, per category, and what each
  evaluation said.
- **Attendance** — activities, present, absent and excused.
- **Playing time** — matches and minutes played, and the player's share of the
  minutes the team played. For staff it also shows the average share of
  teammates who play the same position and of the whole team, and whether the
  player sits above or below them.
- **Goals** — the player's goals, and whether each one moved in the period.
- **Development plan** — the player's development plan conversations, and what
  was agreed at the last one.
- **Notes** — ruled lines on the printed copy, to write on during the
  conversation.

Tick more sections when the conversation needs them: **Match by match**,
**Tests**, **Journey**, **Injuries**, **Behaviour**, **Potential** and **Staff
notes**. Then press **Update report**: ticking, unticking, reordering and
changing the printed copy all wait for that button, so choosing three sections
is one reload, not three. Until you press it, a line beside the button says
your changes are not applied yet: the report below, the PDF and the snapshot
still show the last applied selection. Once applied, the address in your browser
changes with it, so a colleague who opens the link sees the same report.

Put the sections in the order your conversation goes: drag a ticked section to
its place, or use its up and down arrows (on a phone, the arrows are the way to
do it). A section you just ticked can be moved straight away, before you press
**Update report**. The report on screen and the printed copy follow that order, and a saved
view, a monthly schedule and a snapshot keep it. The header always comes first.
Attendance and playing time print side by side when they are next to each other.

**Evaluations** can be shown two ways. By default the per-category table lists
the main categories. When an evaluation in the period rated subcategories, the
panel offers a choice under **Evaluations**: **Main categories** or **With
subcategories**. With subcategories, each rated subcategory sits indented under
its main category, with its own latest and average score, on screen and in the
PDF. A main category rated only through its subcategories gets a row too, with
no score of its own. The choice is kept by a saved view, a snapshot, a shared
link and a monthly schedule. When nothing in the period was rated at
subcategory level, the choice is not offered and the report shows the main
categories.

In **Playing time**, the share is the player's minutes divided by the minutes
the team had available in the period: the length of every match the team
recorded minutes for. It is the same number the team minutes report shows for
the same period. Two comparisons follow, for staff only:

- **Same position** — the average share of the teammates who share at least
  one of the player's profile positions, not counting the player. The line names
  those positions and how many teammates are in the group. A player with no
  profile position, or whose positions nobody else on the team plays, gets no
  position line.
- **Team average** — the average share of everyone on the team's current squad,
  the player included. A squad player who did not play counts as 0%.

When the team recorded no match minutes in the period, there are no
percentages.

A player who has no development plan file yet still gets a full report. The
development plan section says there is no file yet rather than disappearing.

In **Journey**, a comment from a match analysis and an evaluation made for a
training or match say which one they were about, for example "Match · against
Willem II · 12 September 2026". On screen it links to the activity when you can
open activities.

## Talking points

The talking points are worked out from what the academy has already recorded;
nobody writes them. They change as the records change, and the most urgent come
first. They say what to raise, never what to conclude:

- **Status** — when the status model marks the player *Watch* or *Needs
  action*, with its reasons. These are the same reasons the team monthly report
  shows for the player.
- **Attendance** — when attendance has dropped clearly against the period of
  the same length before it, for example *"3 of 11 activities missed since
  1 August"*.
- **Playing time** — when the player's share of the minutes the team played is
  below the academy's minutes target.
- **No evaluation** — when a longer period has passed without an evaluation.
- **Tests** — a test that moved the wrong way since the reading before. The
  direction follows the test: a slower sprint counts, a change in height does
  not.
- **Goals** — an open goal whose due date has passed.
- **Back from injury** — a return to play inside the period, a reason to talk
  about load.
- **Transitions** — a move to another age group, team or position.
- **Gaps in the record** — evidence the status model looks for that the academy
  has not recorded for this player yet. That is a gap in the academy's record,
  not in the player.

A talking point needs enough to go on. Attendance is not compared on a handful
of sessions, and playing time is not compared until a few matches have minutes
recorded, so a player who has just arrived is not flagged on too little data.

## Printing it

Choose how the printed copy comes out, then **Download PDF**:

- **One-pager** — one A4 page, the copy you bring to a conversation. When
  everything you ticked does not fit, long lists keep their most recent entries
  and say how many more there are, the development plan becomes one line saying
  how many conversations have been held and when the next one is, and the notes
  area keeps three lines.
- **Two-page pack** — up to two A4 pages, with room for every evaluation, match
  and test in the period.

**Printed size** shows how many pages the PDF will have and how full each page
is, and says when the one-pager had to shorten something. On screen every
section you ticked is always shown in full; the layout only shapes the paper.

The PDF carries the same sections, and each section shows you only what it
shows you on screen. It has no photo. Notes, journey entries, talking points
and agreed actions are printed in full, over as many lines as they need;
**Printed size** counts those lines.

Tests show their **score** next to the result: whether the reading is on target
for the player's age group, below it or well below it, or, for a test recorded
as a level, that level in its colour. A test without a target for the age
group shows a dash.

## Scheduling it

A head of development running a monthly round of conversations can have the
reports arrive on their own. On the report, choose **Schedule monthly**, then
who it covers:

- **Every player in the team** — one report per player, each starting on its
  own page, in one PDF. The default.
- **Only this player** — for a player you are keeping a particular eye on.

It is sent on the 1st of every month, over the same period the report had when
you scheduled it, with its own copy of the sections — changing your saved views
later does not change what it sends. The report is written as you would see it,
so send it to staff only.

A schedule stops rather than sends when its team or player is archived or
removed, or when you can no longer read their reports; the schedules screen says
why. In a team round, a single player you can no longer read is left out, and
the schedules screen names them. A round covers at most 30 players.

## Snapshots — a record of the conversation

The report is live: open it tomorrow and a register taken today has moved the
numbers. Under the report, **Save snapshot of this conversation** freezes it as
you see it — the sections, the period and every figure — as a record of what the
conversation was based on.

A snapshot opens from the list under the report. Its numbers never change, but
each section can carry a **note** — what was said about it, what was agreed —
with an explicit **Save note** and a **Cancel** that leaves the stored note
alone. **Download PDF** on a snapshot prints it with its notes.

Only signed-in staff who can open this player's report can open a snapshot of
it. A snapshot has no shareable link; give someone the PDF instead.

## Sharing the report with the player and their parents

Under the report, **Share with the family…** opens a short explanation and a
**Share with the family** button. Sharing freezes a copy of the report, like a
snapshot, and puts it in front of the player and their parents. Families never
make reports themselves: every report they see is one a coach chose to share.

A shared report carries only these sections, and only the ones ticked on the
report. When none of them is ticked, all of them are shared:

- attendance;
- playing time, with the player's own share of the minutes — **without** the
  comparison with the position group and the team. In a small group an average
  is another child's minutes: with two goalkeepers, "the other keepers'
  average" is exactly the other keeper's minutes;
- goals;
- evaluation scores, per category and overall — **without** your written notes;
- tests, only the ones the academy shares publicly.

Everything else stays with staff, whatever is ticked: the status, talking
points, the development plan, staff notes, injuries, the journey, behaviour,
potential and the blank notes area. They are left out of the shared copy itself,
not just hidden on the screen.

### Where the family finds it

The player finds shared reports under **Reports** on their own profile. A parent
finds them under **Reports** on their child's profile. The newest is at the top,
and each one opens the frozen copy.

Each reader sees the sections they can already see on the player's profile. If
the player has chosen to keep their evaluations, goals, playing time or tests
from their parents, a parent's copy leaves that section out. The player always
sees their own. A parent sees the reports of their own children only.

A shared report takes no notes, because nothing written on it would reach the
family. In the list under the report it is marked **Shared with the family**.
Staff can open it there to see what was sent.

## Saving your usual report

Most coaches want the same sections for every conversation. Once the report
looks right, open the **bookmark** in the period bar and choose **Save current
filters**. A saved view keeps the sections and the period, but **not the
player**: open a different player and your view applies to them. Mark it as
your default and every player report opens with your sections.

## Who can see it

The player report is for staff. You can open it for the players on the teams
whose reports you can see; a head of development sees every player. Players and
parents cannot open it; they read the reports you share with them.

Some sections show only what you are allowed to see elsewhere:

- **Injuries** appear only for staff with access to medical information.
- **Journey** and **Tests** show the entries and tests your role may see on the
  player's file.
- **Staff notes** show the notes you can read on the player's file; a note
  written for coaches only stays that way.
- **Development plan** appears only when you can open the player's development
  plan and the academy uses development plans.

A section with nothing to show says so, so an empty section never reads as a
section that was left out.

### Scouts

A scout sees a shorter version of the report on the players assigned to them:
the header, evaluation scores, attendance, playing time and tests. The scores
come without the coach's written notes, playing time comes without the
comparison with teammates, and tests show only the ones the academy shares
publicly. The scout cannot choose other sections; the report
decides this for them.

To email a scout a one-time link, open **Send to a scout…** under the report.
Enter the scout's email address, choose when the link expires and add a message
if you want. The scout gets this same scout version, with the evaluation scores,
attendance and playing time you have ticked, or all three when none of them is.
The scouting PDF is the same document.

### The older report wizard

The report wizard is gone. The staff report, the family report and the scout
link are all this player report now. Old links to the wizard open the player
report, or for a player or parent, the **Reports** tab on the player's profile.

The report is confidential. It describes a minor's development: share it with
the player and their parents only through **Share with the family**, and never
with anyone outside the coaching staff.
