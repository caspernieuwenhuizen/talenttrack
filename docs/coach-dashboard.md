---
title: Coach dashboard (frontend)
group: frontend
summary: What coaches see in the frontend tile grid.
audience: [user]
order: 20
---

# Coach dashboard

When you log in as a coach you land on a tile grid. Each tile opens one part of the app. Use the **← Back** link at the top of every page to return here.

## Layout on desktop

On a wide screen (1024px and up) the **Today's work** section splits into two columns. The work groups run down the left in a fixed order — **Performance → People → Planning & tactics → Development → Reference**, then any remaining groups (such as Tasks or Analytics). Your personal shortcuts gather on the right in a sticky **My work** panel: compact rows with an icon and a title, one per personal tile, that stay in view as you scroll the left column. On narrower screens everything collapses to a single column and the **My work** panel moves to the bottom. The collapsed **Setup & administration** section stays full-width below. Only setup screens appear there: a group that holds both — Trials, say, with its cases list and its trial tracks — now splits, so the cases list stays in Today's work and the tracks move down.

## This week

At the top of **Today's work** a **This week** panel lists every activity of the current week on the teams you coach, in date order. The week starts on the first day of the week your academy has set in General settings (Monday unless it is set to Sunday), the same week the team planner shows. Past days stay in the list, so nothing that still needs doing drops off on Wednesday. If you coach more than one team, each row also names its team. On a wide screen (1024px and up) the panel sits at the top of the right-hand column, above **My work**; on a phone or tablet it comes first, before the tile groups.

Each row shows the day, the type, the activity and its time and place, and below that what it still needs:

- **A training that has passed** shows *Attendance recorded* or *Attendance not recorded*. When it is not recorded, **Mark attendance** opens the attendance for that training straight away.
- **An upcoming training** shows how many players are expected out of the squad (when a planned squad was captured), and whether a training plan is attached: *Training plan ready* or *No training plan yet*. Tap the row to open the activity.
- **Today's training** shows the same, with today's date highlighted and a **Mark attendance** button.
- **A match** is marked with a coloured edge and shows the opponent, home or away, the time to be present, the kick-off and the place. Below that: how many players are available and how many are unavailable, and how far the line-up is (*Line-up 9 of 11*, or *Line-up complete*). **Match preparation** opens the preparation; on match day, once a preparation exists, the button reads **Start match**. **Activity** opens the activity itself. A match already marked completed shows like a past day instead: whether attendance is recorded, **Mark attendance** when it is not (unless the match is dated after today), and **Activity**, with no preparation, no start and no availability or line-up.

*Unavailable* is a count only. The panel never says why a player cannot play.

A week without a match says *No match this week.* under the list. A week with no activities on your teams shows a single line saying so instead of rows.

### Players who need attention

Below the list, a section can point at individual players on your teams:

- **Unavailable and returning this week**: players an injury keeps out today, as *Morris Adriaanse · Unavailable*, the same neutral word the planning screens use. This line covers the teams whose injuries you may read and the teams whose activities you may change. If you may read your team's injuries, it also says when they are expected back (*back ~ 8 Nov*), or *returning · this week* when that falls in the window. Showing that date is recorded in the audit log, as reading the injury overview is. The injury itself, the body part and any notes never show here. Scouts and read-only observers get this line only when your academy switches it on for them.
- **Missed several trainings**: players who missed each of their team's last three recorded trainings. Missed counts the way the attendance report counts it: absent, excused, injured or suspended. (The player status attendance score sets injured and suspended aside; this line, like the report, counts them as missed.)
- **Trial players this week**: prospects on trial with your team, as a first name and last initial with the days the team has an activity (*Thomas K. · Tue, Thu*). Only people who may see prospects get this line.
- **Birthdays**: players with a birthday this week, as a first name and the day (*Duco · Fri 30*). Never the age or the year.

A player is named only when you may open their profile, and the name links to it. Anyone else on the line is counted instead (*2 players unavailable*), except on Birthdays, which leaves them out entirely: a count would tell you that a child you cannot see has a birthday this week. The section hides when it has nothing to say.

By default the head coach sees all four on a desktop and the unavailable line on a phone, the assistant coach sees the unavailable line, missed trainings and birthdays on a desktop, the team manager birthdays, and the head of development missed trainings and trial players. Your academy can change that per role and device under **Players who need attention** in the panel's settings.

### Setting up the panel

Your academy chooses what the panel shows, per role and per device, under **Configuration → Default dashboard → This week panel**. Pick a role with the buttons at the top, set it up, pick the next role, and press **Save** once for all of them. The change shows the next time the dashboard is opened.

- **Show the panel to this persona** switches the panel on or off for that role. By default the head coach and the assistant coach see it. Someone with several roles sees the panel while acting in a role that has it, and not while acting in another.
- **Also on phones** off keeps the panel to tablets and desktops (768px and wider). On a phone the tiles then start at the top.
- **Which week, which teams**:
  - **Time window** is the calendar week, or today and the six days after it.
  - **Teams** is *Their own teams* (the teams that user works with) or *All academy teams*. All academy teams only applies to someone who may see every team's activities, such as the head of development. Anyone else still sees their own teams.
  - **Days already past** can be shown, folded into one line, or hidden. A folded line reads like *Tue, Thu · 2 activities · attendance recorded* and opens on a tap or with Enter.
  - **Team name on each row** names the team when the week covers more than one team.
  - **Peek at next week** lists the first two activities of the next seven days below the list.
- **Week overview**:
  - **Week summary** puts three figures above the list for the window and teams the panel covers: the number of trainings, the number of matches, and the attendance percentage over the activities whose attendance is recorded. Present and late both count as attended, exactly as in the team attendance report, so the two show the same figure for the same dates and teams. With nothing recorded yet it shows *—*. It is on for the head coach on a desktop, and for the head of development and the academy admin on both; switch it on for any other role here.
- **Follow-ups due this week** is a section below the list, one line per follow-up, each a link to where you deal with it. A line shows only when there is something due and the user may open that page, and the section hides when every line is empty:
  - **PDP conversations due**: the PDP talks still to hold in a PDP block that closes this week, on the panel's teams, with the day it closes (*4 players · closes Sun*). PDP planning does not print this total: it is worked out from that page, as each closing block's roster minus the talks held and the talks marked not held. It opens PDP planning.
  - **Evaluations due**: the players not yet evaluated in the evaluation round that is open now: the gaps Evaluation coverage shows in that round's column, counted for the panel's teams (the page does not print this total). It opens Evaluation coverage, so it only shows to someone who may open that report (it covers the whole academy) and when the Evaluation coverage feature is on.
  - **My tasks due this week**: the user's own open tasks with a due date in the window (*2 due*). It opens My tasks.
  - By default the head coach sees all three on a desktop and the PDP line on a phone; the team manager and the academy admin see their tasks on both; the head of development sees the PDP and evaluation lines on a desktop.
- The other sections list every part of a row (**On every row**, **Trainings**, **Matches**) and every button (**Buttons on a row**). Each one has a **Desktop** and a **Phone** box, so a phone can show a shorter panel than a desktop. At most two buttons show on a row, and the first one that applies is the main button.
- Under **Trainings**, **Training theme** shows the theme of the training plan linked to a training as a grey chip, and nothing when no plan is linked. **Attendance after the fact** shows, on a past training whose attendance is recorded, how many of the recorded players were there (*12 of 14 present*); late counts as present. By default the head coach sees both on a desktop and the assistant coach sees the theme. Neither shows on a phone unless you switch it on.
- Under **Matches**, before the match, **Game plan ready** says whether the match preparation has its game plan written. On a completed match: **Result** (*Won 2–1*, from our side whatever the venue, and nothing when no score is stored), **Minutes check** (the minutes entered against what the match holds, such as *795 / 770 min*, with the same verdict and words as the minutes audit and the minutes grid), **Player ratings** (*Ratings 9 of 14*: players rated against players who played) and **Match analysis** (*Analysis written* or *No analysis yet*). Three buttons follow from them on a completed match: **Minutes + stats** when the minutes check is not complete, **Rate players** when players are still unrated, and **Write match analysis** when there is none. Each only shows to someone who may edit that team's activities and open that screen; **Rate players** only to someone who may rate that team's players, and **Write match analysis** not on a match dated after today. **Player ratings** only shows to someone who may read that team's evaluations. The head of development and the academy admin see the **Minutes check** on a desktop too, for every team they see.
- **Reset to defaults** puts the role you are looking at back to its starting settings. Press **Save** to keep that.

The panel only covers teams the user may see, and a button only shows on a row whose team the user may change. A user whose week holds no team never sees the panel, whatever their role. Scouts and read-only observers get the availability counts only when you switch that block on for them.

Until the settings are first saved, every role that already saw the panel keeps the whole panel on a desktop, team managers included. On a phone it leaves out the location and the training plan, and a team manager sees times, location, expected players and the opponent there. A team manager newly switched on after the settings are saved starts from that shorter set on both devices. Blocks that came after that panel, such as the training theme, attendance after the fact and the match blocks, follow their own defaults for each role, also before the first save.

## Tiles you'll use most

### Teams
The teams you coach. Each one shows its top-3 podium and the full roster. Tap any player card to see their detail page.

### My players
Players on the teams you coach. Admins see this tile labelled **Players** and get the full academy roster instead. Tap a card to open the player detail page. The list page also has an **Import from CSV** button.

### Evaluations
Form to record an evaluation. Pick a player, an evaluation type, a date, then rate them on every category. Save and you're done.

### Activities
Log a training, game or other event. Pick the team, the date, optional location and notes, then mark each player's attendance.

### Goals
Add development goals for a player and track their progress.

## Planning & tactics

The forward-looking planning and tactical tools sit in their own **Planning & tactics** group, just after Performance — separated from the day-to-day Evaluations / Activities / Goals work:

- **Team planner** — plan the training week, principle by principle.
- **VCT session designer** — build a training from the exercise library.
- **Team blueprint** — drag-and-drop line-ups with live chemistry.
- **Team chemistry** — formation board with a suggested XI.

## Analytics

### Podium
Top-3 of every team you coach, on one screen.

## Reference

The **Reference** group holds materials you read but don't fill in.

### Methodology
Your academy's principles, formations, positions and set pieces.

## On a phone

Tiles stack into one column on phones, two on tablets. Long tables scroll sideways instead of squeezing into narrow columns.
